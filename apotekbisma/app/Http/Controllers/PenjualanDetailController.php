<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;
use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PenjualanDetailController extends Controller
{
    public function __construct()
    {
    }

    public function index()
    {
        $produk = Produk::orderBy('nama_produk')->get();
        $member = Member::orderBy('nama')->get();
        $diskon = Setting::first()->diskon ?? 0;

        if ($id_penjualan = session('id_penjualan')) {
            $penjualan = Penjualan::find($id_penjualan);
            $memberSelected = $penjualan->member ?? new Member();

            return view('penjualan_detail.detail', compact('produk', 'member', 'diskon', 'id_penjualan', 'penjualan', 'memberSelected'));
        } else {
            if (auth()->user()->level == 1) {
                return redirect()->route('transaksi.baru');
            } else {
                return redirect()->route('home');
            }
        }
    }

    public function data($id)
    {
        $detail = PenjualanDetail::with('produk')
            ->where('id_penjualan', $id)
            ->join('produk', 'penjualan_detail.id_produk', '=', 'produk.id_produk')
            ->orderBy('produk.nama_produk', 'asc')
            ->select('penjualan_detail.*')
            ->get();

        $data = array();
        $total = 0;
        $total_item = 0;

        foreach ($detail as $item) {
            $row = array();
            $row['kode_produk'] = '<span class="label label-success">'. $item->produk['kode_produk'] .'</span';
            $row['nama_produk'] = $item->produk['nama_produk'];
            $row['harga_jual']  = 'Rp. '. format_uang($item->harga_jual);
            $row['jumlah']      = '<input type="number" class="form-control input-sm quantity" data-id="'. $item->id_penjualan_detail .'" value="'. $item->jumlah .'">';
            $row['diskon']      = $item->diskon . '%';
            $row['subtotal']    = 'Rp. '. format_uang($item->subtotal);
            $row['aksi']        = '<div class="btn-group">
                                    <button onclick="deleteData(`'. route('transaksi.destroy', $item->id_penjualan_detail) .'`)" class="btn btn-xs btn-danger btn-flat"><i class="fa fa-trash"></i></button>
                                </div>';
            $data[] = $row;

            $total += $item->harga_jual * $item->jumlah - (($item->diskon * $item->jumlah) / 100 * $item->harga_jual);;
            $total_item += $item->jumlah;
        }
        $data[] = [
            'kode_produk' => '
                <div class="total hide">'. $total .'</div>
                <div class="total_item hide">'. $total_item .'</div>',
            'nama_produk' => '',
            'harga_jual'  => '',
            'jumlah'      => '',
            'diskon'      => '',
            'subtotal'    => '',
            'aksi'        => '',
        ];

        return datatables()
            ->of($data)
            ->addIndexColumn()
            ->rawColumns(['aksi', 'kode_produk', 'jumlah'])
            ->make(true);
    }

    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $idProduk = $request->id_produk;
            if (empty($idProduk)) {
                DB::rollBack();
                return response()->json(['error' => true, 'message' => 'ID Produk tidak valid'], 400);
            }

            $produk = Produk::where('id_produk', $idProduk)->lockForUpdate()->first();
            if (!$produk) {
                DB::rollBack();
                return response()->json(['error' => true, 'message' => 'Data produk tidak ditemukan'], 400);
            }

            $stokSaatIni = intval($produk->stok);
            if ($stokSaatIni <= 0) {
                DB::rollBack();
                return response()->json(['error' => true, 'message' => 'Stok habis! Produk tidak dapat dijual karena stok saat ini: ' . $stokSaatIni], 400);
            }

            $id_penjualan = $request->id_penjualan;
            
            if (empty($id_penjualan)) {
                $id_penjualan = session('id_penjualan');
            }
            
            $jumlah_tambahan = 1;

            if ($stokSaatIni < $jumlah_tambahan) {
                DB::rollBack();
                return response()->json(['error' => true, 'message' => 'Tidak dapat menambah produk! Stok tersedia: ' . $stokSaatIni], 400);
            }
            
            $penjualan = null;
            if (empty($id_penjualan)) {
                $penjualan = new Penjualan();
                $penjualan->id_member = null;
                $penjualan->total_item = 0;
                $penjualan->total_harga = 0;
                $penjualan->diskon = 0;
                $penjualan->bayar = 0;
                $penjualan->diterima = 0;
                $penjualan->waktu = date('Y-m-d');
                $penjualan->id_user = auth()->id();
                $penjualan->save();

                session(['id_penjualan' => $penjualan->id_penjualan]);
                $id_penjualan = $penjualan->id_penjualan;
            } else {
                $penjualan = Penjualan::find($id_penjualan);
                if (!$penjualan) {
                    DB::rollBack();
                    return response()->json(['error' => true, 'message' => 'Transaksi tidak ditemukan'], 400);
                }
                $this->ensurePenjualanHasWaktu($penjualan);
            }

            $stok_sebelum = $stokSaatIni;

            // 1. Always create a NEW PenjualanDetail row (Requirement: "barangnya bertambah dan dikelompokkan" as separate rows)
            $detail = new PenjualanDetail();
            $detail->id_penjualan = $id_penjualan;
            $detail->id_produk = $produk->id_produk;
            $detail->harga_jual = $produk->harga_jual ?? 0;
            $detail->jumlah = $jumlah_tambahan;
            $detail->diskon = $produk->diskon ?? 0;
            $detail->subtotal = ($produk->harga_jual ?? 0) - (($produk->diskon ?? 0) / 100 * ($produk->harga_jual ?? 0));
            $detail->save();

            // 2. Deduct Stock from Product
            $stok_baru = $stok_sebelum - $jumlah_tambahan;
            if ($stok_baru < 0) {
                $stok_baru = 0;
            }
            
            DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stok_baru]);

            // 3. Update OR Create RekamanStok (Aggregated)
            // We must find the existing record for this (Transaction + Product) and increment the outgoing stock.
            $existingRekaman = DB::table('rekaman_stoks')
                ->where('id_penjualan', $id_penjualan)
                ->where('id_produk', $produk->id_produk)
                ->first();
            
            if ($existingRekaman) {
                $newStokKeluar = intval($existingRekaman->stok_keluar) + $jumlah_tambahan;
                $originalStokAwal = intval($existingRekaman->stok_awal); // Keep original stock start
                $newStokSisa = $originalStokAwal - $newStokKeluar;
                if ($newStokSisa < 0) $newStokSisa = 0; // Prevent negative
                
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $existingRekaman->id_rekaman_stok)
                    ->update([
                        'stok_keluar' => $newStokKeluar,
                        'stok_sisa' => $newStokSisa,
                        'updated_at' => now()
                    ]);
            } else {
                // First time for this product in this transaction
                $this->createRekamanStokPenjualan($produk->id_produk, $id_penjualan, $stok_sebelum, $jumlah_tambahan, $penjualan->waktu);
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Produk berhasil ditambahkan ke keranjang. Stok tersisa: ' . $stok_baru,
                'id_penjualan' => $id_penjualan,
                'stok_tersisa' => $stok_baru
            ], 200);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('PenjualanDetailController@store error: ' . $e->getMessage(), [
                'id_produk' => $request->id_produk ?? null,
                'id_penjualan' => $request->id_penjualan ?? session('id_penjualan'),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => true, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $detail = PenjualanDetail::find($id);
            
            if (!$detail) {
                DB::rollBack();
                return response()->json(['message' => 'Detail transaksi tidak ditemukan'], 404);
            }
            
            $produk = Produk::where('id_produk', $detail->id_produk)->lockForUpdate()->first();
            
            if (!$produk) {
                DB::rollBack();
                return response()->json(['message' => 'Produk tidak ditemukan'], 404);
            }
            
            $new_jumlah_row = (int) $request->jumlah;
            if ($new_jumlah_row < 1) {
                DB::rollBack();
                return response()->json(['message' => 'Jumlah harus minimal 1'], 400);
            }
            
            $old_jumlah_row = intval($detail->jumlah);
            $selisih_row = $new_jumlah_row - $old_jumlah_row;
            
            $stok_sekarang = intval($produk->stok);
            
            if ($selisih_row > 0 && $stok_sekarang < $selisih_row) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Tidak dapat mengubah jumlah! Stok tersedia: ' . $stok_sekarang . ', dibutuhkan: ' . $selisih_row
                ], 400);
            }
            
            // 1. Update Product Stock
            $stok_baru = $stok_sekarang - $selisih_row;
            if ($stok_baru < 0) {
                $stok_baru = 0;
            }
            DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stok_baru]);
            
            // 2. Update Detail Row
            $hargaJual = floatval($detail->harga_jual);
            $diskon = floatval($detail->diskon);
            $subtotal = $hargaJual * $new_jumlah_row - (($diskon * $new_jumlah_row) / 100 * $hargaJual);
            
            DB::table('penjualan_detail')
                ->where('id_penjualan_detail', $detail->id_penjualan_detail)
                ->update([
                    'jumlah' => $new_jumlah_row,
                    'subtotal' => $subtotal,
                    'updated_at' => now()
                ]);
            
            // 3. Update RekamanStok (Aggregated)
            // Calculate TOTAL quantity for this product in this transaction across ALL rows
            $totalQtyInTransaction = DB::table('penjualan_detail')
                ->where('id_penjualan', $detail->id_penjualan)
                ->where('id_produk', $produk->id_produk)
                ->sum('jumlah');

            $penjualan = Penjualan::find($detail->id_penjualan);
            $waktu_transaksi = $penjualan && $penjualan->waktu ? $penjualan->waktu : date('Y-m-d H:i:s');
            
            $existingRekaman = DB::table('rekaman_stoks')
                                 ->where('id_penjualan', $detail->id_penjualan)
                                 ->where('id_produk', $detail->id_produk)
                                 ->first();
            
            if ($existingRekaman) {
                $originalStokAwal = intval($existingRekaman->stok_awal);
                $newStokSisa = $originalStokAwal - $totalQtyInTransaction;
                if ($newStokSisa < 0) $newStokSisa = 0;
                
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $existingRekaman->id_rekaman_stok)
                    ->update([
                        'waktu' => $waktu_transaksi,
                        'stok_keluar' => $totalQtyInTransaction, // Set to Aggregated Total
                        'stok_sisa' => $newStokSisa,
                        'updated_at' => now()
                    ]);
            } else {
                // Should not happen usually in update, but for safety
                $stokAwalRekaman = $stok_baru + $totalQtyInTransaction;
                $this->createRekamanStokPenjualan($produk->id_produk, $detail->id_penjualan, $stokAwalRekaman, $totalQtyInTransaction, $waktu_transaksi);
            }
            
            DB::commit();
            
            $this->safeRecalculateStock($produk->id_produk);
            
            return response()->json([
                'message' => 'Jumlah berhasil diperbarui. Stok tersisa: ' . $stok_baru,
                'data' => [
                    'jumlah' => $new_jumlah_row,
                    'subtotal' => $subtotal,
                    'stok_tersisa' => $stok_baru
                ]
            ], 200);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating penjualan detail: ' . $e->getMessage());
            return response()->json(['message' => 'Terjadi kesalahan: ' . $e->getMessage()], 500);
        }
    }

    public function updateEdit(Request $request, $id)
    {
         // Redirect to standard update logic as they are now robust enough
         return $this->update($request, $id);
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        
        try {
            $detail = DB::table('penjualan_detail')->where('id_penjualan_detail', $id)->first();
            
            if ($detail) {
                $produk = Produk::lockForUpdate()->find($detail->id_produk);
                $produkId = $detail->id_produk;
                $idPenjualan = $detail->id_penjualan;
                $jumlahDeleted = intval($detail->jumlah);
                
                if ($produk) {
                    // 1. Return Stock
                    $stokSebelum = intval($produk->stok);
                    $stokBaru = $stokSebelum + $jumlahDeleted;
                    
                    DB::table('produk')->where('id_produk', $produkId)->update(['stok' => $stokBaru]);
                    
                    // 2. Delete Detail Row
                    DB::table('penjualan_detail')->where('id_penjualan_detail', $id)->delete();
                    
                    // 3. Update or Delete RekamanStok
                    // Check if other rows exist
                    $remainingQty = DB::table('penjualan_detail')
                        ->where('id_penjualan', $idPenjualan)
                        ->where('id_produk', $produkId)
                        ->sum('jumlah');
                    
                    if ($remainingQty > 0) {
                        // Update Rekaman to reflect remaining quantity
                        $existingRekaman = DB::table('rekaman_stoks')
                            ->where('id_penjualan', $idPenjualan)
                            ->where('id_produk', $produkId)
                            ->first();

                        if ($existingRekaman) {
                             $originalStokAwal = intval($existingRekaman->stok_awal);
                             $newStokSisa = $originalStokAwal - $remainingQty;
                             if ($newStokSisa < 0) $newStokSisa = 0;
                             
                             DB::table('rekaman_stoks')
                                ->where('id_rekaman_stok', $existingRekaman->id_rekaman_stok)
                                ->update([
                                    'stok_keluar' => $remainingQty,
                                    'stok_sisa' => $newStokSisa,
                                    'updated_at' => now()
                                ]);
                        }
                    } else {
                        // No rows left for this product in this transaction, delete rekaman completely
                        DB::table('rekaman_stoks')
                            ->where('id_penjualan', $idPenjualan)
                            ->where('id_produk', $produkId)
                            ->delete();
                    }
                } else {
                     // Just delete detail if product not found (edge case)
                     DB::table('penjualan_detail')->where('id_penjualan_detail', $id)->delete();
                }
                
                DB::commit();
                
                if ($produkId) {
                    $this->safeRecalculateStock($produkId);
                }
            } else {
                DB::commit();
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in destroy: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response(null, 204);
    }

    public function loadForm($diskon = 0, $total = 0, $diterima = 0)
    {
        $bayar   = $total - ($diskon / 100 * $total);
        $kembali = ($diterima != 0) ? $diterima - $bayar : 0;
        $data    = [
            'totalrp' => format_uang($total),
            'bayar' => $bayar,
            'bayarrp' => format_uang($bayar),
            'terbilang' => ucwords(terbilang($bayar). ' Rupiah'),
            'kembali' => $kembali,
            'kembalirp' => format_uang($kembali),
            'kembali_terbilang' => ucwords(terbilang($kembali). ' Rupiah'),
        ];

        return response()->json($data);
    }

    public function getProdukData()
    {
        $produk = Produk::orderBy('nama_produk')->get();
        
        $data = [];
        foreach ($produk as $key => $item) {
            $data[] = [
                'id' => $item->id_produk,
                'no' => $key + 1,
                'kode_produk' => $item->kode_produk,
                'nama_produk' => $item->nama_produk,
                'stok' => $item->stok,
                'harga_jual' => $item->harga_jual,
                'stok_badge_class' => $item->stok == 0 ? 'bg-red' : ($item->stok <= 5 ? 'bg-yellow' : 'bg-green'),
                'stok_text' => $item->stok == 0 ? 'Stok Habis - Tidak Bisa Dijual' : ($item->stok <= 5 ? 'Stok Menipis' : ''),
                'stok_icon' => $item->stok == 0 ? 'fa-exclamation-triangle' : ($item->stok <= 5 ? 'fa-warning' : ''),
                'stok_text_class' => $item->stok == 0 ? 'text-danger' : ($item->stok <= 5 ? 'text-warning' : '')
            ];
        }
        
        return response()->json($data)
               ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
               ->header('Pragma', 'no-cache')
               ->header('Expires', '0');
    }

    public function recalculateStock()
    {
        $produk_list = Produk::all();
        
        foreach ($produk_list as $produk) {
            $total_keluar = RekamanStok::where('id_produk', $produk->id_produk)
                                      ->sum('stok_keluar');
            
            echo "Produk: {$produk->nama_produk}, Stok saat ini: {$produk->stok}, Total keluar: {$total_keluar}<br>";
        }
        
        return response()->json('Recalculate completed');
    }

    private function createRekamanStokPenjualan($idProduk, $idPenjualan, $stokSebelum, $jumlahKeluar, $waktu)
    {
        $waktuTransaksi = $waktu ?? date('Y-m-d H:i:s');
        if ($waktuTransaksi instanceof \Carbon\Carbon) {
            $waktuTransaksi = $waktuTransaksi->format('Y-m-d H:i:s');
        } elseif (!is_string($waktuTransaksi)) {
            $waktuTransaksi = date('Y-m-d H:i:s');
        }
        
        $stokSisa = $stokSebelum - $jumlahKeluar;
        if ($stokSisa < 0) {
            $stokSisa = 0;
        }
        
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $idProduk,
            'id_penjualan' => $idPenjualan,
            'waktu' => $waktuTransaksi,
            'stok_masuk' => 0,
            'stok_keluar' => $jumlahKeluar,
            'stok_awal' => $stokSebelum,
            'stok_sisa' => $stokSisa,
            'keterangan' => 'Penjualan: Transaksi penjualan produk',
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    private function ensurePenjualanHasWaktu($penjualan)
    {
        if (!$penjualan->waktu) {
            $penjualan->waktu = $penjualan->created_at ?? Carbon::today();
            $penjualan->save();
        }
    }
    
    private function safeRecalculateStock($produkId)
    {
        try {
            RekamanStok::recalculateStock($produkId);
            
            $this->validateAndFixStockSync($produkId);
        } catch (\Exception $e) {
            Log::warning('Recalculate stock warning: ' . $e->getMessage());
        }
    }
    
    private function validateAndFixStockSync($produkId)
    {
        try {
            $produk = DB::table('produk')->where('id_produk', $produkId)->first();
            if (!$produk) return;
            
            $stokProduk = intval($produk->stok);
            
            $lastRekaman = DB::table('rekaman_stoks')
                ->where('id_produk', $produkId)
                ->orderBy('waktu', 'desc')
                ->orderBy('id_rekaman_stok', 'desc')
                ->first();
            
            if (!$lastRekaman) return;
            
            $stokRekaman = intval($lastRekaman->stok_sisa);
            
            if ($stokProduk !== $stokRekaman) {
                Log::warning('Stock mismatch detected and auto-fixed', [
                    'id_produk' => $produkId,
                    'nama_produk' => $produk->nama_produk ?? 'Unknown',
                    'stok_produk' => $stokProduk,
                    'stok_rekaman' => $stokRekaman,
                    'action' => 'auto_sync_to_rekaman'
                ]);
                
                DB::table('produk')
                    ->where('id_produk', $produkId)
                    ->update(['stok' => $stokRekaman]);
            }
        } catch (\Exception $e) {
            Log::error('validateAndFixStockSync error: ' . $e->getMessage());
        }
    }
}
