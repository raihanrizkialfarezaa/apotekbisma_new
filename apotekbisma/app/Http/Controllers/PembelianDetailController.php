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

class PembelianDetailController extends Controller
{
    public function __construct()
    {
        // Normalisasi stok minus menjadi 0 saat controller diinisialisasi
        $this->normalizeNegativeStock();
    }

    /**
     * Normalisasi stok produk yang minus menjadi 0
     */
    private function normalizeNegativeStock()
    {
        // Update semua produk yang memiliki stok negatif menjadi 0
        Produk::where('stok', '<', 0)->update(['stok' => 0]);
    }

    public function index()
    {
        $id_pembelian = session('id_pembelian');
        
        // If no session data, check if there's an active incomplete transaction
        if (!$id_pembelian) {
            // Look for the most recent incomplete transaction
            $incompletePembelian = Pembelian::where('no_faktur', 'o')
                ->orWhere('no_faktur', '')
                ->orWhereNull('no_faktur')
                ->orWhere('total_harga', 0)
                ->orWhere('bayar', 0)
                ->latest()
                ->first();
            
            if ($incompletePembelian) {
                // Set session for the incomplete transaction
                session(['id_pembelian' => $incompletePembelian->id_pembelian]);
                session(['id_supplier' => $incompletePembelian->id_supplier]);
                $id_pembelian = $incompletePembelian->id_pembelian;
            } else {
                // Clear any stale data and redirect to pembelian page
                session()->forget(['id_pembelian', 'id_supplier']);
                return redirect()->route('pembelian.index')->with('info', 'Silakan pilih supplier terlebih dahulu untuk memulai pembelian.');
            }
        }
        
        // Cek apakah pembelian ada
        $pembelian = Pembelian::find($id_pembelian);
        if (!$pembelian) {
            session()->forget(['id_pembelian', 'id_supplier']); // Clear invalid session data
            return redirect()->route('pembelian.index')->with('error', 'Transaksi pembelian tidak ditemukan atau sudah tidak valid.');
        }
        
        // Cek apakah supplier session sesuai dengan data pembelian
        $session_supplier = session('id_supplier');
        if ($session_supplier != $pembelian->id_supplier) {
            // Update session to match the found transaction
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
        
        // Set session untuk editing
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
            // dd($item->produk['harga_jual']);
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
                
                $this->ensureProdukHasRekamanStok($produk);
                
                $stok_sebelum = $produk->stok;
                
                $existing_detail = PembelianDetail::where('id_pembelian', $request->id_pembelian)
                                                  ->where('id_produk', $request->id_produk)
                                                  ->first();
                
                if ($existing_detail) {
                    $existing_detail->jumlah += 1;
                    $existing_detail->subtotal = $existing_detail->harga_beli * $existing_detail->jumlah;
                    $existing_detail->save();
                } else {
                    $detail = new PembelianDetail();
                    $detail->id_pembelian = $request->id_pembelian;
                    $detail->id_produk = $produk->id_produk;
                    $detail->harga_beli = $produk->harga_beli;
                    $detail->jumlah = 1;
                    $detail->subtotal = $produk->harga_beli;
                    $detail->save();
                }
                
                $stok_baru = $stok_sebelum + 1;
                $produk->stok = $stok_baru;
                $produk->save();
                
                $pembelian = \App\Models\Pembelian::find($request->id_pembelian);
                $this->ensurePembelianHasWaktu($pembelian);
                
                $waktu_transaksi = $pembelian && $pembelian->waktu ? $pembelian->waktu : Carbon::now();
                
                $existing_rekaman = RekamanStok::where('id_pembelian', $request->id_pembelian)
                                               ->where('id_produk', $request->id_produk)
                                               ->first();
                
                if ($existing_rekaman) {
                    $existing_rekaman->stok_masuk += 1;
                    $existing_rekaman->stok_sisa = $stok_baru;
                    $existing_rekaman->waktu = $waktu_transaksi;
                    $existing_rekaman->save();
                } else {
                    RekamanStok::create([
                        'id_produk' => $produk->id_produk,
                        'id_pembelian' => $request->id_pembelian,
                        'waktu' => $waktu_transaksi,
                        'stok_masuk' => 1,
                        'stok_awal' => $stok_sebelum,
                        'stok_sisa' => $stok_baru,
                        'keterangan' => 'Pembelian: Penambahan stok dari supplier'
                    ]);
                }
                
                return $stok_baru;
            }, 3);
            
            return response()->json('Data berhasil disimpan', 200);
            
        } catch (\Illuminate\Database\QueryException $e) {
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
            Log::error('General error in pembelian detail store: ' . $e->getMessage(), [
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json('Error: ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, $id)
    {
        set_time_limit(90);
        ini_set('memory_limit', '256M');
        
        try {
            $detail = PembelianDetail::where('id_pembelian_detail', $id)->first();
            
            if (!$detail) {
                return response()->json(['message' => 'Detail pembelian tidak ditemukan'], 404);
            }
            
            $session_id_pembelian = session('id_pembelian');
            if (!$session_id_pembelian || $session_id_pembelian != $detail->id_pembelian) {
                session(['id_pembelian' => $detail->id_pembelian]);
                
                $pembelian = \App\Models\Pembelian::find($detail->id_pembelian);
                if ($pembelian) {
                    session(['id_supplier' => $pembelian->id_supplier]);
                }
            }
            
            $input_jumlah = $request->input('jumlah');
            
            if ($input_jumlah === null || $input_jumlah === '') {
                return response()->json(['message' => 'Jumlah harus diisi'], 400);
            }
            
            if (!is_numeric($input_jumlah)) {
                return response()->json(['message' => 'Jumlah harus berupa angka'], 400);
            }
            
            $new_jumlah = (int) $input_jumlah;
            
            if ($new_jumlah < 1) {
                return response()->json(['message' => 'Jumlah harus minimal 1'], 400);
            }
            
            if ($new_jumlah > 10000) {
                return response()->json(['message' => 'Jumlah tidak boleh lebih dari 10000'], 400);
            }
            
            $old_jumlah = $detail->jumlah;
            $selisih = $new_jumlah - $old_jumlah;
            
            if ($selisih == 0) {
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
                
                $stok_sebelum = $produk->stok;
                $stok_baru = $stok_sebelum + $selisih;
                
                if ($stok_baru > 2147483647) {
                    throw new \Exception('Stok hasil akan melebihi batas maksimum');
                }
                
                if ($stok_baru < 0) {
                    $stok_baru = 0;
                }
                
                $produk->stok = $stok_baru;
                $produk->save();
                
                $detail->jumlah = $new_jumlah;
                $detail->subtotal = $detail->harga_beli * $new_jumlah;
                $detail->save();
                
                $pembelian = \App\Models\Pembelian::find($detail->id_pembelian);
                $waktu_transaksi = $pembelian && $pembelian->waktu ? $pembelian->waktu : Carbon::now();
                
                $rekaman_stok = RekamanStok::where('id_pembelian', $detail->id_pembelian)
                                           ->where('id_produk', $detail->id_produk)
                                           ->orderBy('id_rekaman_stok', 'desc')
                                           ->first();
                
                if ($rekaman_stok) {
                    $rekaman_stok->update([
                        'waktu' => $waktu_transaksi,
                        'stok_masuk' => $new_jumlah,
                        'stok_awal' => $stok_baru - $new_jumlah,
                        'stok_sisa' => $stok_baru,
                        'keterangan' => 'Pembelian: Update jumlah transaksi'
                    ]);
                } else {
                    RekamanStok::create([
                        'id_produk' => $produk->id_produk,
                        'id_pembelian' => $detail->id_pembelian,
                        'waktu' => $waktu_transaksi,
                        'stok_masuk' => $new_jumlah,
                        'stok_awal' => $stok_baru - $new_jumlah,
                        'stok_sisa' => $stok_baru,
                        'keterangan' => 'Pembelian: Update jumlah transaksi'
                    ]);
                }
                
                return [
                    'jumlah' => $new_jumlah,
                    'subtotal' => $detail->subtotal,
                    'stok_tersisa' => $stok_baru
                ];
            }, 5);
            
            return response()->json([
                'message' => 'Data berhasil diperbarui',
                'data' => $result
            ], 200);
            
        } catch (\Illuminate\Database\QueryException $e) {
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
            Log::error('General error in pembelian detail update: ' . $e->getMessage(), [
                'detail_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Terjadi kesalahan sistem. Silakan coba lagi.'], 500);
        }
    }

    public function updateEdit(Request $request, $id)
    {
        $detail = PembelianDetail::where('id_pembelian_detail', $id)->first();
        
        if (!$detail) {
            return response()->json('Detail pembelian tidak ditemukan', 404);
        }
        
        $produk = Produk::where('id_produk', $detail->id_produk)->first();
        
        if (!$produk) {
            return response()->json('Produk tidak ditemukan', 404);
        }
        
        // Normalisasi stok jika negatif sebelum operasi
        if ($produk->stok < 0) {
            $produk->stok = 0;
            $produk->save();
        }
        
        // Cari rekaman stok yang sesuai dengan detail pembelian ini
        $rekaman_stok = RekamanStok::where('id_pembelian', $detail->id_pembelian)
                                   ->where('id_produk', $detail->id_produk)
                                   ->where('stok_masuk', $detail->jumlah) // Match dengan jumlah yang sama
                                   ->where('created_at', '>=', Carbon::now()->subMinutes(5)) // Dalam 5 menit terakhir
                                   ->orderBy('created_at', 'desc')
                                   ->first();
        
        $old_jumlah = $detail->jumlah;
        $new_jumlah = $request->jumlah;
        $selisih = $new_jumlah - $old_jumlah;
        
        // Hitung stok baru
        $new_stok = $produk->stok + $selisih;
        
        // Pastikan stok tidak negatif
        if ($new_stok < 0) {
            $new_stok = 0;
        }
        
        if ($rekaman_stok) {
            $pembelian = \App\Models\Pembelian::find($detail->id_pembelian);

            $rekaman_stok->update([
                'waktu' => $pembelian && $pembelian->waktu ? $pembelian->waktu : Carbon::now(),
                'stok_masuk' => $new_jumlah,
                'stok_sisa' => $new_stok,
            ]);
        } else {
            $pembelian = \App\Models\Pembelian::find($detail->id_pembelian);

            RekamanStok::create([
                'id_produk' => $detail->id_produk,
                'id_pembelian' => $detail->id_pembelian,
                'waktu' => $pembelian && $pembelian->waktu ? $pembelian->waktu : Carbon::now(),
                'stok_masuk' => $new_jumlah,
                'stok_awal' => $produk->stok,
                'stok_sisa' => $new_stok,
            ]);
        }
        
        // Update stok produk dengan nilai yang sudah dinormalisasi
        $produk->stok = $new_stok;
        $produk->update();
        
        // Update detail pembelian
        $detail->jumlah = $new_jumlah;
        $detail->subtotal = $detail->harga_beli * $new_jumlah;
        $detail->update();
        
        return response()->json('Data berhasil diperbarui', 200);
    }

    public function destroy($id)
    {
        try {
            Log::info('PembelianDetailController@destroy called', ['id' => $id, 'user_id' => auth()->id()]);
        } catch (\Exception $e) {
            // ignore logging failures
        }
        DB::beginTransaction();
        
        try {
            $detail = PembelianDetail::find($id);
            
            if (!$detail) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Detail tidak ditemukan'], 404);
            }
            
            $produk = Produk::find($detail->id_produk);
            if ($produk) {
                // Catat stok sebelum perubahan
                $stokSebelum = $produk->stok;
                
                // Kurangi stok berdasarkan jumlah yang ada di detail
                $produk->stok = $stokSebelum - $detail->jumlah;
                $produk->save();
                
                // Hapus rekaman stok yang terkait (yang merepresentasikan penambahan)
                RekamanStok::where('id_pembelian', $detail->id_pembelian)
                           ->where('id_produk', $detail->id_produk)
                           ->where('stok_masuk', $detail->jumlah)
                           ->delete();

                $pembelian = \App\Models\Pembelian::find($detail->id_pembelian);

                RekamanStok::create([
                    'id_produk' => $produk->id_produk,
                    'waktu' => $pembelian && $pembelian->waktu ? $pembelian->waktu : now(),
                    'stok_keluar' => $detail->jumlah,
                    'stok_awal' => $stokSebelum,
                    'stok_sisa' => $produk->stok,
                    'keterangan' => 'Penghapusan detail pembelian: Pengurangan stok'
                ]);
            }
            
            $detail->delete();
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response(null, 204);
    }

    public function getProdukData(Request $request)
    {
        // Handle CSRF token refresh request
        if ($request->has('refresh_token')) {
            return response()->json([
                'csrf_token' => csrf_token()
            ]);
        }
        
        // Handle session keep-alive ping
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

    private function ensureProdukHasRekamanStok($produk)
    {
        $hasRekaman = RekamanStok::where('id_produk', $produk->id_produk)->exists();
        
        if (!$hasRekaman) {
            RekamanStok::create([
                'id_produk' => $produk->id_produk,
                'waktu' => Carbon::now(),
                'stok_masuk' => $produk->stok,
                'stok_awal' => 0,
                'stok_sisa' => $produk->stok,
                'keterangan' => 'Auto-created: Rekaman stok awal produk'
            ]);
        }
    }

    private function ensurePembelianHasWaktu($pembelian)
    {
        if (!$pembelian->waktu) {
            $pembelian->waktu = $pembelian->created_at ?? Carbon::today();
            $pembelian->save();
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
