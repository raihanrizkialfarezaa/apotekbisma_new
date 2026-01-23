<?php

namespace App\Http\Controllers;

use App\Models\Pembelian;
use App\Models\PembelianDetail;
use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\PembelianBatchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class PembelianDetailController extends Controller
{
    private const IDEMPOTENCY_TTL = 10;
    
    public function index()
    {
        $id_pembelian = session('id_pembelian');
        
        if (!$id_pembelian) {
            $incompletePembelian = Pembelian::where('no_faktur', 'o')
                ->orWhere('no_faktur', '')
                ->orWhereNull('no_faktur')
                ->orWhere('total_harga', 0)
                ->orWhere('bayar', 0)
                ->latest()
                ->first();
            
            if ($incompletePembelian) {
                session(['id_pembelian' => $incompletePembelian->id_pembelian]);
                session(['id_supplier' => $incompletePembelian->id_supplier]);
                $id_pembelian = $incompletePembelian->id_pembelian;
            } else {
                session()->forget(['id_pembelian', 'id_supplier']);
                return redirect()->route('pembelian.index')->with('info', 'Silakan pilih supplier terlebih dahulu untuk memulai pembelian.');
            }
        }
        
        $pembelian = Pembelian::find($id_pembelian);
        if (!$pembelian) {
            session()->forget(['id_pembelian', 'id_supplier']);
            return redirect()->route('pembelian.index')->with('error', 'Transaksi pembelian tidak ditemukan atau sudah tidak valid.');
        }
        
        $session_supplier = session('id_supplier');
        if ($session_supplier != $pembelian->id_supplier) {
            session(['id_supplier' => $pembelian->id_supplier]);
        }
        
        $produk = Produk::orderBy('nama_produk')->get();
        $supplier = Supplier::find($pembelian->id_supplier);
        $diskon = $pembelian->diskon ?? 0;

        if (! $supplier) {
            session()->forget(['id_pembelian', 'id_supplier']);
            return redirect()->route('pembelian.index')->with('error', 'Supplier tidak ditemukan. Silakan mulai transaksi baru.');
        }

        return view('pembelian_detail.index', compact('id_pembelian', 'produk', 'supplier', 'diskon', 'pembelian'));
    }
    
    public function editBayar($id)
    {
        $id_pembelian = $id;
        $pembelian = Pembelian::where('id_pembelian', $id)->first();
        
        if (!$pembelian) {
            return redirect()->route('pembelian.index')->with('error', 'Transaksi pembelian tidak ditemukan.');
        }
        
        session(['id_pembelian' => $pembelian->id_pembelian]);
        session(['id_supplier' => $pembelian->id_supplier]);
        
        $produk = Produk::orderBy('nama_produk')->get();
        $detail_pembelian = PembelianDetail::where('id_pembelian', $id)->get();
        $supplier = Supplier::find($pembelian->id_supplier);
        $diskon = $pembelian->diskon ?? 0;
        $tanggal = $pembelian;

        if (! $supplier) {
            abort(404);
        }

        return view('pembelian_detail.editBayar', compact('id_pembelian', 'pembelian', 'tanggal', 'detail_pembelian', 'produk', 'supplier', 'diskon'));
    }

    public function data($id)
    {
        $detail = PembelianDetail::with('produk')
            ->where('id_pembelian', $id)
            ->join('produk', 'pembelian_detail.id_produk', '=', 'produk.id_produk')
            ->orderBy('produk.nama_produk', 'asc')
            ->select('pembelian_detail.*')
            ->get();
        $data = array();
        $total = 0;
        $total_item = 0;

        foreach ($detail as $item) {
            $row = array();
            $row['kode_produk'] = '<span class="label label-success">'. $item->produk['kode_produk'] .'</span';
            $row['nama_produk'] = $item->produk['nama_produk'];
            $row['harga_jual']  = '<input type="number" class="form-control input-sm harga_jual" data-id="'. $item->produk['id_produk'] .'" value="'. $item->produk['harga_jual'] .'">';
            $row['harga_beli']  = '<input type="number" class="form-control input-sm harga_beli" data-id="'. $item->produk['id_produk'] .'" data-uid="'. $item->id_pembelian_detail .'" value="'. $item->produk['harga_beli'] .'">';
            $row['jumlah']      = '<input type="number" class="form-control input-sm quantity" data-id="'. $item->id_pembelian_detail .'" value="'. $item->jumlah .'">';
            $row['expired_date']      = '<input type="date" class="form-control input-sm expired_date" data-id="'. $item->produk['id_produk'] .'" value="'. $item->produk['expired_date'] .'">';
            $row['batch']      = '<input type="text" class="form-control input-sm batch" data-id="'. $item->produk['id_produk'] .'" value="'. $item->produk['batch'] .'">';
            $row['subtotal']    = 'Rp. '. format_uang($item->subtotal);
            $row['aksi']        = '<div class="btn-group">
                                    <button onclick="deleteData(`'. route('pembelian_detail.destroy', $item->id_pembelian_detail) .'`)" class="btn btn-xs btn-danger btn-flat"><i class="fa fa-trash"></i></button>
                                </div>';
            $data[] = $row;

            $total += $item->harga_beli * $item->jumlah;
            $total_item += $item->jumlah;
        }
        $data[] = [
            'kode_produk' => '
                <div class="total hide">'. $total .'</div>
                <div class="total_item hide">'. $total_item .'</div>',
            'nama_produk' => '',
            'harga_beli'  => '',
            'harga_jual'  => '',
            'jumlah'      => '',
            'expired_date'      => '',
            'batch'      => '',
            'subtotal'    => '',
            'aksi'        => '',
        ];

        return datatables()
            ->of($data)
            ->addIndexColumn()
            ->rawColumns(['aksi', 'kode_produk', 'jumlah', 'harga_beli', 'harga_jual', 'expired_date', 'batch'])
            ->make(true);
    }

    public function store(Request $request)
    {
        $idempotencyKey = 'pembelian_store_' . $request->id_pembelian . '_' . $request->id_produk . '_' . auth()->id();
        
        if (Cache::has($idempotencyKey)) {
            return response()->json('Request sedang diproses, mohon tunggu...', 429);
        }
        
        Cache::put($idempotencyKey, true, self::IDEMPOTENCY_TTL);
        
        set_time_limit(60);
        ini_set('memory_limit', '256M');
        
        try {
            $result = DB::transaction(function () use ($request) {
                $produk = Produk::where('id_produk', $request->id_produk)
                                ->lockForUpdate()
                                ->first();
                
                if (!$produk) {
                    throw new \Exception('Data produk tidak ditemukan');
                }
                
                $stok_sebelum = intval($produk->stok);
                
                $existing_detail = PembelianDetail::where('id_pembelian', $request->id_pembelian)
                                                  ->where('id_produk', $request->id_produk)
                                                  ->first();
                
                $jumlah_tambahan = 1;
                
                $pembelian = Pembelian::find($request->id_pembelian);
                $this->ensurePembelianHasWaktu($pembelian);
                $waktuWithMicro = Carbon::now()->format('Y-m-d H:i:s.u');
                
                if ($existing_detail) {
                    $old_jumlah = intval($existing_detail->jumlah);
                    $new_jumlah = $old_jumlah + $jumlah_tambahan;
                    
                    $existing_detail->jumlah = $new_jumlah;
                    $existing_detail->subtotal = $existing_detail->harga_beli * $new_jumlah;
                    $existing_detail->save();
                    
                    $stok_baru = $stok_sebelum + $jumlah_tambahan;
                    DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stok_baru]);
                    
                    $existing_rekaman = DB::table('rekaman_stoks')
                        ->where('id_pembelian', $request->id_pembelian)
                        ->where('id_produk', $request->id_produk)
                        ->lockForUpdate()
                        ->first();
                    
                    if ($existing_rekaman) {
                        $originalStokAwal = intval($existing_rekaman->stok_awal);
                        $newStokMasuk = intval($existing_rekaman->stok_masuk) + $jumlah_tambahan;
                        $newStokSisa = $originalStokAwal + $newStokMasuk;
                        
                        DB::table('rekaman_stoks')
                            ->where('id_rekaman_stok', $existing_rekaman->id_rekaman_stok)
                            ->update([
                                'stok_masuk' => $newStokMasuk,
                                'stok_sisa' => $newStokSisa,
                                'updated_at' => now()
                            ]);
                    } else {
                        DB::table('rekaman_stoks')->insert([
                            'id_produk' => $produk->id_produk,
                            'id_pembelian' => $request->id_pembelian,
                            'waktu' => $waktuWithMicro,
                            'stok_masuk' => $new_jumlah,
                            'stok_keluar' => 0,
                            'stok_awal' => $stok_sebelum,
                            'stok_sisa' => $stok_baru,
                            'keterangan' => 'Pembelian: Penambahan stok dari supplier',
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                    
                    return ['stok_baru' => $stok_baru, 'produk_id' => $produk->id_produk];
                } else {
                    $detail = new PembelianDetail();
                    $detail->id_pembelian = $request->id_pembelian;
                    $detail->id_produk = $produk->id_produk;
                    $detail->harga_beli = $produk->harga_beli;
                    $detail->jumlah = $jumlah_tambahan;
                    $detail->subtotal = $produk->harga_beli;
                    $detail->save();
                    
                    $stok_baru = $stok_sebelum + $jumlah_tambahan;
                    DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stok_baru]);
                    
                    DB::table('rekaman_stoks')->insert([
                        'id_produk' => $produk->id_produk,
                        'id_pembelian' => $request->id_pembelian,
                        'waktu' => $waktuWithMicro,
                        'stok_masuk' => $jumlah_tambahan,
                        'stok_keluar' => 0,
                        'stok_awal' => $stok_sebelum,
                        'stok_sisa' => $stok_baru,
                        'keterangan' => 'Pembelian: Penambahan stok dari supplier',
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                    
                    return ['stok_baru' => $stok_baru, 'produk_id' => $produk->id_produk];
                }
            }, 3);
            
            Cache::forget($idempotencyKey);
            
            // PENTING: Jangan panggil atomicRecalculateAndSync setelah pembelian!
            // Stok dan rekaman sudah dihitung dengan benar di dalam transaction.
            // Memanggil recalculate akan menimpa nilai yang sudah tepat.
            
            return response()->json('Data berhasil disimpan', 200);
            
        } catch (\Illuminate\Database\QueryException $e) {
            Cache::forget($idempotencyKey);
            Log::error('Database error in pembelian detail store: ' . $e->getMessage(), [
                'request_data' => $request->all(),
                'sql' => $e->getSql() ?? 'N/A',
                'bindings' => $e->getBindings() ?? []
            ]);
            
            if (strpos($e->getMessage(), 'Deadlock') !== false) {
                return response()->json('Database sedang sibuk. Silakan coba lagi.', 503);
            } elseif (strpos($e->getMessage(), 'Lock wait timeout') !== false) {
                return response()->json('Request timeout. Silakan coba lagi.', 503);
            } else {
                return response()->json('Terjadi kesalahan database', 500);
            }
            
        } catch (\Exception $e) {
            Cache::forget($idempotencyKey);
            Log::error('General error in pembelian detail store: ' . $e->getMessage(), [
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json('Error: ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, $id)
    {
        $idempotencyKey = 'pembelian_update_' . $id . '_' . auth()->id();
        
        if (Cache::has($idempotencyKey)) {
            return response()->json(['message' => 'Request sedang diproses, mohon tunggu...'], 429);
        }
        
        Cache::put($idempotencyKey, true, self::IDEMPOTENCY_TTL);
        
        set_time_limit(90);
        ini_set('memory_limit', '256M');
        
        try {
            $detail = PembelianDetail::where('id_pembelian_detail', $id)->first();
            
            if (!$detail) {
                Cache::forget($idempotencyKey);
                return response()->json(['message' => 'Detail pembelian tidak ditemukan'], 404);
            }
            
            $session_id_pembelian = session('id_pembelian');
            if (!$session_id_pembelian || $session_id_pembelian != $detail->id_pembelian) {
                session(['id_pembelian' => $detail->id_pembelian]);
                
                $pembelian = Pembelian::find($detail->id_pembelian);
                if ($pembelian) {
                    session(['id_supplier' => $pembelian->id_supplier]);
                }
            }
            
            $input_jumlah = $request->input('jumlah');
            
            if ($input_jumlah === null || $input_jumlah === '') {
                Cache::forget($idempotencyKey);
                return response()->json(['message' => 'Jumlah harus diisi'], 400);
            }
            
            if (!is_numeric($input_jumlah)) {
                Cache::forget($idempotencyKey);
                return response()->json(['message' => 'Jumlah harus berupa angka'], 400);
            }
            
            $new_jumlah = (int) $input_jumlah;
            
            if ($new_jumlah < 1) {
                Cache::forget($idempotencyKey);
                return response()->json(['message' => 'Jumlah harus minimal 1'], 400);
            }
            
            if ($new_jumlah > 10000) {
                Cache::forget($idempotencyKey);
                return response()->json(['message' => 'Jumlah tidak boleh lebih dari 10000'], 400);
            }
            
            $old_jumlah = intval($detail->jumlah);
            $selisih = $new_jumlah - $old_jumlah;
            
            if ($selisih == 0) {
                Cache::forget($idempotencyKey);
                return response()->json([
                    'message' => 'Data berhasil diperbarui',
                    'data' => [
                        'jumlah' => $new_jumlah,
                        'subtotal' => $detail->subtotal,
                        'stok_tersisa' => $detail->produk->stok ?? 0
                    ]
                ], 200);
            }
            
            $result = DB::transaction(function () use ($detail, $new_jumlah, $old_jumlah, $selisih) {
                $produk = Produk::where('id_produk', $detail->id_produk)
                                ->lockForUpdate()
                                ->first();
                
                if (!$produk) {
                    throw new \Exception('Produk tidak ditemukan');
                }
                
                $stok_sebelum = intval($produk->stok);
                $stok_baru = $stok_sebelum + $selisih;
                
                if ($stok_baru > 2147483647) {
                    throw new \Exception('Stok hasil akan melebihi batas maksimum');
                }
                
                if ($stok_baru < 0) {
                    throw new \Exception('Tidak dapat mengurangi jumlah! Stok akan menjadi minus. Stok saat ini: ' . $stok_sebelum . ', akan dikurangi: ' . abs($selisih) . '. Produk mungkin sudah terjual.');
                }
                
                DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stok_baru]);
                
                $detail->jumlah = $new_jumlah;
                $detail->subtotal = $detail->harga_beli * $new_jumlah;
                $detail->save();
                
                $pembelian = Pembelian::find($detail->id_pembelian);
                $waktu_transaksi = $pembelian && $pembelian->waktu ? $pembelian->waktu : Carbon::now();
                
                $rekaman_stok = DB::table('rekaman_stoks')
                    ->where('id_pembelian', $detail->id_pembelian)
                    ->where('id_produk', $detail->id_produk)
                    ->lockForUpdate()
                    ->first();
                
                if ($rekaman_stok) {
                    $originalStokAwal = intval($rekaman_stok->stok_awal);
                    $newStokSisa = $originalStokAwal + $new_jumlah;
                    
                    DB::table('rekaman_stoks')
                        ->where('id_rekaman_stok', $rekaman_stok->id_rekaman_stok)
                        ->update([
                            'waktu' => $waktu_transaksi,
                            'stok_masuk' => $new_jumlah,
                            'stok_sisa' => $newStokSisa,
                            'keterangan' => 'Pembelian: Update jumlah transaksi',
                            'updated_at' => now()
                        ]);
                } else {
                    $stokAwal = $stok_baru - $new_jumlah;
                    $waktuWithMicro = Carbon::now()->format('Y-m-d H:i:s.u');
                    
                    DB::table('rekaman_stoks')->insert([
                        'id_produk' => $produk->id_produk,
                        'id_pembelian' => $detail->id_pembelian,
                        'waktu' => $waktuWithMicro,
                        'stok_masuk' => $new_jumlah,
                        'stok_keluar' => 0,
                        'stok_awal' => $stokAwal,
                        'stok_sisa' => $stok_baru,
                        'keterangan' => 'Pembelian: Update jumlah transaksi',
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
                
                return [
                    'jumlah' => $new_jumlah,
                    'subtotal' => $detail->subtotal,
                    'stok_tersisa' => $stok_baru,
                    'produk_id' => $produk->id_produk
                ];
            }, 5);
            
            Cache::forget($idempotencyKey);
            
            // PENTING: Jangan panggil atomicRecalculateAndSync setelah update pembelian!
            // Stok dan rekaman sudah dihitung dengan benar di dalam transaction.
            // Memanggil recalculate akan menimpa nilai yang sudah tepat.
            
            return response()->json([
                'message' => 'Data berhasil diperbarui',
                'data' => $result
            ], 200);
            
        } catch (\Illuminate\Database\QueryException $e) {
            Cache::forget($idempotencyKey);
            Log::error('Database error in pembelian detail update: ' . $e->getMessage(), [
                'detail_id' => $id,
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings()
            ]);
            
            if (strpos($e->getMessage(), 'Deadlock') !== false) {
                return response()->json(['message' => 'Database sedang sibuk. Silakan coba lagi.'], 503);
            } elseif (strpos($e->getMessage(), 'Lock wait timeout') !== false) {
                return response()->json(['message' => 'Request timeout. Silakan coba lagi.'], 503);
            } else {
                return response()->json(['message' => 'Terjadi kesalahan database. Silakan coba lagi.'], 500);
            }
            
        } catch (\Exception $e) {
            Cache::forget($idempotencyKey);
            Log::error('General error in pembelian detail update: ' . $e->getMessage(), [
                'detail_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Terjadi kesalahan sistem. Silakan coba lagi.'], 500);
        }
    }

    public function updateEdit(Request $request, $id)
    {
        $idempotencyKey = 'pembelian_updateedit_' . $id . '_' . auth()->id();
        
        if (Cache::has($idempotencyKey)) {
            return response()->json('Request sedang diproses...', 429);
        }
        
        Cache::put($idempotencyKey, true, self::IDEMPOTENCY_TTL);
        
        DB::beginTransaction();
        
        try {
            $detail = PembelianDetail::where('id_pembelian_detail', $id)->first();
            
            if (!$detail) {
                DB::rollBack();
                Cache::forget($idempotencyKey);
                return response()->json('Detail pembelian tidak ditemukan', 404);
            }
            
            $produk = Produk::where('id_produk', $detail->id_produk)->lockForUpdate()->first();
            
            if (!$produk) {
                DB::rollBack();
                Cache::forget($idempotencyKey);
                return response()->json('Produk tidak ditemukan', 404);
            }
            
            $old_jumlah = intval($detail->jumlah);
            $new_jumlah = intval($request->jumlah);
            $selisih = $new_jumlah - $old_jumlah;
            
            $new_stok = intval($produk->stok) + $selisih;
            
            if ($new_stok < 0) {
                DB::rollBack();
                Cache::forget($idempotencyKey);
                return response()->json('Tidak dapat mengurangi jumlah! Stok akan menjadi minus. Stok saat ini: ' . intval($produk->stok) . ', akan dikurangi: ' . abs($selisih) . '. Produk mungkin sudah terjual.', 400);
            }
            
            DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $new_stok]);
            
            $pembelian = Pembelian::find($detail->id_pembelian);
            $waktu_transaksi = $pembelian && $pembelian->waktu ? $pembelian->waktu : Carbon::now();
            
            $rekaman_stok = DB::table('rekaman_stoks')
                ->where('id_pembelian', $detail->id_pembelian)
                ->where('id_produk', $detail->id_produk)
                ->lockForUpdate()
                ->first();
            
            if ($rekaman_stok) {
                $originalStokAwal = intval($rekaman_stok->stok_awal);
                $newStokSisa = $originalStokAwal + $new_jumlah;
                
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $rekaman_stok->id_rekaman_stok)
                    ->update([
                        'waktu' => $waktu_transaksi,
                        'stok_masuk' => $new_jumlah,
                        'stok_sisa' => $newStokSisa,
                        'updated_at' => now()
                    ]);
            } else {
                $stokAwal = $new_stok - $new_jumlah;
                $waktuWithMicro = Carbon::now()->format('Y-m-d H:i:s.u');
                
                DB::table('rekaman_stoks')->insert([
                    'id_produk' => $detail->id_produk,
                    'id_pembelian' => $detail->id_pembelian,
                    'waktu' => $waktuWithMicro,
                    'stok_masuk' => $new_jumlah,
                    'stok_keluar' => 0,
                    'stok_awal' => $stokAwal,
                    'stok_sisa' => $new_stok,
                    'keterangan' => 'Pembelian: Edit jumlah pembelian',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
            
            $detail->jumlah = $new_jumlah;
            $detail->subtotal = $detail->harga_beli * $new_jumlah;
            $detail->update();
            
            DB::commit();
            
            Cache::forget($idempotencyKey);
            
            // PENTING: Jangan panggil atomicRecalculateAndSync setelah update edit!
            // Stok dan rekaman sudah dihitung dengan benar di dalam transaction.
            // Memanggil recalculate akan menimpa nilai yang sudah tepat.
            
            return response()->json('Data berhasil diperbarui', 200);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Cache::forget($idempotencyKey);
            Log::error('Error in updateEdit pembelian: ' . $e->getMessage());
            return response()->json('Error: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        $idempotencyKey = 'pembelian_destroy_' . $id . '_' . auth()->id();
        
        if (Cache::has($idempotencyKey)) {
            return response()->json(['success' => false, 'message' => 'Request sedang diproses...'], 429);
        }
        
        Cache::put($idempotencyKey, true, self::IDEMPOTENCY_TTL);
        
        try {
            Log::info('PembelianDetailController@destroy called', ['id' => $id, 'user_id' => auth()->id()]);
        } catch (\Exception $e) {
        }
        
        DB::beginTransaction();
        
        try {
            $detail = PembelianDetail::find($id);
            
            if (!$detail) {
                DB::rollBack();
                Cache::forget($idempotencyKey);
                return response()->json(['success' => false, 'message' => 'Detail tidak ditemukan'], 404);
            }
            
            $produkId = $detail->id_produk;
            $produk = Produk::lockForUpdate()->find($detail->id_produk);
            
            if ($produk) {
                $stokSebelum = intval($produk->stok);
                $stokBaru = $stokSebelum - intval($detail->jumlah);
                
                if ($stokBaru < 0) {
                    DB::rollBack();
                    Cache::forget($idempotencyKey);
                    return response()->json([
                        'success' => false, 
                        'message' => 'Tidak dapat menghapus pembelian! Stok produk saat ini: ' . $stokSebelum . ', akan dikurangi: ' . intval($detail->jumlah) . '. Hasil akan minus. Produk mungkin sudah terjual.'
                    ], 400);
                }
                
                DB::table('produk')->where('id_produk', $produkId)->update(['stok' => $stokBaru]);
                
                DB::table('rekaman_stoks')
                   ->where('id_pembelian', $detail->id_pembelian)
                   ->where('id_produk', $detail->id_produk)
                   ->delete();
            }
            
            $detail->delete();
            
            DB::commit();
            
            Cache::forget($idempotencyKey);
            
            // PENTING: Jangan panggil atomicRecalculateAndSync setelah delete!
            // Stok dan rekaman sudah dihitung dengan benar di dalam transaction.
            // Memanggil recalculate akan menimpa nilai yang sudah tepat.
            
        } catch (\Exception $e) {
            DB::rollBack();
            Cache::forget($idempotencyKey);
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response(null, 204);
    }

    public function getProdukData(Request $request)
    {
        if ($request->has('refresh_token')) {
            return response()->json([
                'csrf_token' => csrf_token()
            ]);
        }
        
        if ($request->has('ping')) {
            return response()->json(['status' => 'ok']);
        }
        
        $produk = Produk::orderBy('nama_produk')->get();
        
        $data = [];
        foreach ($produk as $key => $item) {
            $data[] = [
                'id' => $item->id_produk,
                'no' => $key + 1,
                'kode_produk' => $item->kode_produk,
                'nama_produk' => $item->nama_produk,
                'stok' => $item->stok,
                'harga_beli' => $item->harga_beli,
                'stok_badge_class' => $item->stok == 0 ? 'bg-red' : ($item->stok <= 5 ? 'bg-yellow' : 'bg-green'),
                'stok_text' => $item->stok == 0 ? 'Stok Habis - Harus Beli Dulu' : ($item->stok <= 5 ? 'Stok Menipis' : ''),
                'stok_icon' => $item->stok == 0 ? 'fa-exclamation-triangle' : ($item->stok <= 5 ? 'fa-warning' : ''),
                'stok_text_class' => $item->stok == 0 ? 'text-danger' : ($item->stok <= 5 ? 'text-warning' : '')
            ];
        }
        
        return response()->json($data)
               ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
               ->header('Pragma', 'no-cache')
               ->header('Expires', '0');
    }

    public function loadForm($diskon, $total)
    {
        $bayar = $total - ($diskon / 100 * $total);
        $data  = [
            'totalrp' => format_uang($total),
            'bayar' => $bayar,
            'bayarrp' => format_uang($bayar),
            'terbilang' => ucwords(terbilang($bayar). ' Rupiah')
        ];

        return response()->json($data);
    }

    private function ensurePembelianHasWaktu($pembelian)
    {
        if (!$pembelian->waktu) {
            $pembelian->waktu = $pembelian->created_at ?? Carbon::now();
            $pembelian->save();
        }
    }
    
    private function atomicRecalculateAndSync($produkId)
    {
        try {
            $lockKey = 'stock_recalc_' . $produkId;
            $lock = Cache::lock($lockKey, 30);
            
            if ($lock->get()) {
                try {
                    $stokRecords = DB::table('rekaman_stoks')
                        ->where('id_produk', $produkId)
                        ->orderBy('waktu', 'asc')
                        ->orderBy('created_at', 'asc')
                        ->orderBy('id_rekaman_stok', 'asc')
                        ->get();

                    if ($stokRecords->isEmpty()) {
                        $lock->release();
                        return;
                    }

                    $runningStock = 0;
                    $isFirst = true;
                    $updates = [];

                    foreach ($stokRecords as $record) {
                        $needsUpdate = false;
                        $updateData = [];

                        if ($isFirst) {
                            $runningStock = intval($record->stok_awal);
                            $isFirst = false;
                        } else {
                            if (intval($record->stok_awal) != $runningStock) {
                                $updateData['stok_awal'] = $runningStock;
                                $needsUpdate = true;
                            }
                        }

                        $calculatedSisa = $runningStock + intval($record->stok_masuk) - intval($record->stok_keluar);

                        if (intval($record->stok_sisa) != $calculatedSisa) {
                            $updateData['stok_sisa'] = $calculatedSisa;
                            $needsUpdate = true;
                        }

                        if ($needsUpdate) {
                            $updates[$record->id_rekaman_stok] = $updateData;
                        }

                        $runningStock = $calculatedSisa;
                    }

                    foreach ($updates as $recordId => $updateData) {
                        DB::table('rekaman_stoks')
                            ->where('id_rekaman_stok', $recordId)
                            ->update($updateData);
                    }
                    
                    $finalStock = max(0, $runningStock);
                    DB::table('produk')
                        ->where('id_produk', $produkId)
                        ->update(['stok' => $finalStock]);
                        
                } finally {
                    $lock->release();
                }
            }
        } catch (\Exception $e) {
            Log::error('atomicRecalculateAndSync error: ' . $e->getMessage(), [
                'produk_id' => $produkId
            ]);
        }
    }
    
    public function batchUpdate(Request $request)
    {
        set_time_limit(180);
        ini_set('memory_limit', '1G');
        
        try {
            $updates = $request->input('updates', []);
            
            if (empty($updates)) {
                return response()->json(['message' => 'Tidak ada data untuk diupdate'], 400);
            }
            
            if (count($updates) > 100) {
                return response()->json(['message' => 'Maksimal 100 item per batch'], 400);
            }
            
            $batchService = new PembelianBatchService();
            $result = $batchService->bulkUpdateStok($updates);
            
            if (!empty($result['errors'])) {
                Log::warning('Batch update completed with errors', $result['errors']);
            }
            
            return response()->json([
                'message' => 'Batch update completed',
                'data' => $result
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Batch update failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'Batch update gagal: ' . $e->getMessage()
            ], 500);
        }
    }
}
