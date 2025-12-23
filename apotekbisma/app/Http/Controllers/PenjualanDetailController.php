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
        $produk = Produk::orderBy('nama_produk')->get();
        $member = Member::orderBy('nama')->get();
        $diskon = Setting::first()->diskon ?? 0;

        // Cek apakah ada transaksi yang sedang berjalan
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
            $produk = Produk::where('id_produk', $request->id_produk)->lockForUpdate()->first();
            if (! $produk) {
                DB::rollBack();
                return response()->json('Data produk tidak ditemukan', 400);
            }

            $this->ensureProdukHasRekamanStok($produk);

            if ($produk->stok <= 0) {
                DB::rollBack();
                return response()->json('Stok habis! Produk tidak dapat dijual karena stok saat ini: ' . $produk->stok, 400);
            }

            $id_penjualan = $request->id_penjualan;
            
            if (!$id_penjualan) {
                $id_penjualan = session('id_penjualan');
            }
            
            $jumlah_tambahan = 1;

            if ($produk->stok < $jumlah_tambahan) {
                DB::rollBack();
                return response()->json('Tidak dapat menambah produk! Stok tersedia: ' . $produk->stok, 400);
            }
            
            if (!$id_penjualan) {
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
                    return response()->json('Transaksi tidak ditemukan', 400);
                }
                
                $this->ensurePenjualanHasWaktu($penjualan);
            }

            $stok_sebelum = $produk->stok;

            $detail = new PenjualanDetail();
            $detail->id_penjualan = $id_penjualan;
            $detail->id_produk = $produk->id_produk;
            $detail->harga_jual = $produk->harga_jual;
            $detail->jumlah = $jumlah_tambahan;
            $detail->diskon = $produk->diskon;
            $detail->subtotal = $produk->harga_jual - ($produk->diskon / 100 * $produk->harga_jual);
            $detail->save();

            $stok_baru = $stok_sebelum - $jumlah_tambahan;
            if ($stok_baru < 0) {
                DB::rollBack();
                return response()->json('Error: Stok tidak mencukupi untuk transaksi ini', 400);
            }
            
            $produk->stok = $stok_baru;
            $produk->save();

            RekamanStok::create([
                'id_produk' => $produk->id_produk,
                'id_penjualan' => $id_penjualan,
                'waktu' => $penjualan ? ($penjualan->waktu ?? Carbon::now()) : Carbon::now(),
                'stok_keluar' => $jumlah_tambahan,
                'stok_awal' => $stok_sebelum,
                'stok_sisa' => $stok_baru,
                'keterangan' => 'Penjualan: Transaksi penjualan produk'
            ]);

            if ($produk->fresh()->stok != $stok_baru) {
                DB::rollBack();
                return response()->json('Error: Inkonsistensi stok terdeteksi', 500);
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
            return response()->json('Error: ' . $e->getMessage(), 500);
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
            
            $new_jumlah = (int) $request->jumlah;
            if ($new_jumlah < 1) {
                DB::rollBack();
                return response()->json(['message' => 'Jumlah harus minimal 1'], 400);
            }
            
            if ($new_jumlah > 10000) {
                DB::rollBack();
                return response()->json(['message' => 'Jumlah tidak boleh lebih dari 10000'], 400);
            }
            
            $old_jumlah = $detail->jumlah;
            $selisih = $new_jumlah - $old_jumlah;
            
            $stok_sebelum = $produk->stok;
            
            $total_lain_di_keranjang = PenjualanDetail::where('id_penjualan', $detail->id_penjualan)
                                                     ->where('id_produk', $produk->id_produk)
                                                     ->where('id_penjualan_detail', '!=', $detail->id_penjualan_detail)
                                                     ->sum('jumlah');
            
            $stok_tersedia = $stok_sebelum + $old_jumlah;
            
            $total_dibutuhkan = $new_jumlah + $total_lain_di_keranjang;
            
            if ($total_dibutuhkan > $stok_tersedia) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Tidak dapat mengubah jumlah! ' . 
                                'Stok tersedia: ' . $stok_tersedia . ', ' .
                                'Item lain di keranjang: ' . $total_lain_di_keranjang . ', ' .
                                'Maksimal untuk item ini: ' . max(0, $stok_tersedia - $total_lain_di_keranjang)
                ], 400);
            }
            
            $stok_baru = $stok_sebelum - $selisih;
            if ($stok_baru < 0) {
                DB::rollBack();
                return response()->json(['message' => 'Error: Stok tidak mencukupi'], 400);
            }
            
            $produk->stok = $stok_baru;
            $produk->save();
            
            $detail->jumlah = $new_jumlah;
            $detail->subtotal = $detail->harga_jual * $new_jumlah - (($detail->diskon * $new_jumlah) / 100 * $detail->harga_jual);
            $detail->update();
            
            $penjualan = \App\Models\Penjualan::find($detail->id_penjualan);
            $waktu_transaksi = $penjualan && $penjualan->waktu ? $penjualan->waktu : Carbon::now();
            
            $rekaman_stok = RekamanStok::where('id_penjualan', $detail->id_penjualan)
                                       ->where('id_produk', $detail->id_produk)
                                       ->first();
            
            if ($rekaman_stok) {
                $rekaman_stok->update([
                    'waktu' => $waktu_transaksi,
                    'stok_keluar' => $new_jumlah,
                    'stok_awal' => $stok_sebelum + $old_jumlah,
                    'stok_sisa' => $stok_baru,
                    'keterangan' => 'Penjualan: Update jumlah transaksi'
                ]);
            } else {
                RekamanStok::create([
                    'id_produk' => $produk->id_produk,
                    'id_penjualan' => $detail->id_penjualan,
                    'waktu' => $waktu_transaksi,
                    'stok_keluar' => $new_jumlah,
                    'stok_awal' => $stok_sebelum + $old_jumlah,
                    'stok_sisa' => $stok_baru,
                    'keterangan' => 'Penjualan: Update jumlah transaksi'
                ]);
            }
            
            if ($produk->fresh()->stok != $stok_baru) {
                DB::rollBack();
                return response()->json(['message' => 'Error: Inkonsistensi stok terdeteksi'], 500);
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Jumlah berhasil diperbarui. Stok tersisa: ' . $stok_baru,
                'data' => [
                    'jumlah' => $new_jumlah,
                    'subtotal' => $detail->subtotal,
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
        DB::beginTransaction();
        
        try {
            $detail = PenjualanDetail::where('id_penjualan_detail', $id)->first();
            
            if (!$detail) {
                DB::rollBack();
                return response()->json('Detail transaksi tidak ditemukan', 404);
            }
            
            $penjualan = Penjualan::where('id_penjualan', $detail->id_penjualan)->first();
            $produk = Produk::where('id_produk', $detail->id_produk)->lockForUpdate()->first();
            
            if (!$produk) {
                DB::rollBack();
                return response()->json('Produk tidak ditemukan', 404);
            }
            
            $old_jumlah = $detail->jumlah;
            $new_jumlah = $request->jumlah;
            $selisih = $new_jumlah - $old_jumlah;
            
            if ($selisih > 0 && $produk->stok < $selisih) {
                DB::rollBack();
                return response()->json('Stok tidak cukup. Stok tersedia: ' . $produk->stok, 500);
            }
            
            $stok_sebelum_perubahan = $produk->stok;
            
            $produk->stok -= $selisih;
            $produk->save();
            
            $detail->jumlah = $new_jumlah;
            $detail->subtotal = $detail->harga_jual * $new_jumlah - (($detail->diskon * $new_jumlah) / 100 * $detail->harga_jual);
            $detail->save();
            
            $waktu_transaksi = $penjualan && $penjualan->waktu ? $penjualan->waktu : Carbon::now();
            
            $existing_rekaman = RekamanStok::where('id_penjualan', $detail->id_penjualan)
                                          ->where('id_produk', $detail->id_produk)
                                          ->first();
            
            if ($existing_rekaman) {
                $existing_rekaman->update([
                    'waktu' => $waktu_transaksi,
                    'stok_keluar' => $new_jumlah,
                    'stok_awal' => $stok_sebelum_perubahan + $old_jumlah,
                    'stok_sisa' => $produk->stok,
                    'keterangan' => 'Penjualan: Edit transaksi - Update jumlah produk'
                ]);
            } else {
                RekamanStok::create([
                    'id_produk' => $produk->id_produk,
                    'id_penjualan' => $detail->id_penjualan,
                    'waktu' => $waktu_transaksi,
                    'stok_keluar' => $new_jumlah,
                    'stok_awal' => $stok_sebelum_perubahan + $old_jumlah,
                    'stok_sisa' => $produk->stok,
                    'keterangan' => 'Penjualan: Edit transaksi - Update jumlah produk'
                ]);
            }
            
            DB::commit();
            return response()->json('Data berhasil diperbarui', 200);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json('Terjadi kesalahan: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            Log::info('PenjualanDetailController@destroy called', ['id' => $id, 'user_id' => auth()->id()]);
        } catch (\Exception $e) {
        }
        DB::beginTransaction();
        
        try {
            $detail = PenjualanDetail::find($id);
            
            if ($detail) {
                $produk = Produk::lockForUpdate()->find($detail->id_produk);
                $produkId = $detail->id_produk;
                
                if ($produk) {
                    $stokSebelum = $produk->stok;
                    $produk->stok = $stokSebelum + $detail->jumlah;
                    $produk->save();
                    
                    RekamanStok::where('id_penjualan', $detail->id_penjualan)
                               ->where('id_produk', $detail->id_produk)
                               ->delete();
                    
                    RekamanStok::create([
                        'id_produk' => $produk->id_produk,
                        'waktu' => now(),
                        'stok_masuk' => $detail->jumlah,
                        'stok_awal' => $stokSebelum,
                        'stok_sisa' => $produk->stok,
                        'keterangan' => 'Penghapusan detail penjualan: Pengembalian stok'
                    ]);
                }
                
                $detail->delete();
                
                if ($produkId) {
                    RekamanStok::recalculateStock($produkId);
                }
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
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

    /**
     * Method untuk menrecalculate stok produk berdasarkan transaksi yang ada
     * Gunakan ini jika terjadi ketidakselarasan stok
     */
    public function recalculateStock()
    {
        // Ambil semua produk
        $produk_list = Produk::all();
        
        foreach ($produk_list as $produk) {
            $total_keluar = RekamanStok::where('id_produk', $produk->id_produk)
                                      ->sum('stok_keluar');
            
            echo "Produk: {$produk->nama_produk}, Stok saat ini: {$produk->stok}, Total keluar: {$total_keluar}<br>";
        }
        
        return response()->json('Recalculate completed');
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

    private function ensurePenjualanHasWaktu($penjualan)
    {
        if (!$penjualan->waktu) {
            $penjualan->waktu = $penjualan->created_at ?? Carbon::today();
            $penjualan->save();
        }
    }
}
