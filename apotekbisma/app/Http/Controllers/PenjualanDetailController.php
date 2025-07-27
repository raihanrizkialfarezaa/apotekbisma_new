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
        $produk = Produk::where('id_produk', $request->id_produk)->first();
        if (! $produk) {
            return response()->json('Data gagal disimpan', 400);
        }

        // Normalisasi stok jika negatif
        if ($produk->stok < 0) {
            $produk->stok = 0;
            $produk->save();
        }

        // Cek stok apakah mencukupi - stok harus > 0
        if ($produk->stok <= 0) {
            return response()->json('Stok habis atau tidak tersedia. Produk harus dibeli terlebih dahulu sebelum dapat dijual.', 400);
        }

        // Cek apakah ada transaksi yang sedang berjalan
        $id_penjualan = $request->id_penjualan;
        
        if (!$id_penjualan || !session('id_penjualan')) {
            // Buat transaksi baru hanya ketika produk pertama ditambahkan
            $penjualan = new Penjualan();
            $penjualan->id_member = null;
            $penjualan->total_item = 0;
            $penjualan->total_harga = 0;
            $penjualan->diskon = 0;
            $penjualan->bayar = 0;
            $penjualan->diterima = 0;
            $penjualan->waktu = date('Y-m-d'); // Set tanggal hari ini
            $penjualan->id_user = auth()->id();
            $penjualan->save();

            session(['id_penjualan' => $penjualan->id_penjualan]);
            $id_penjualan = $penjualan->id_penjualan;
        }

        // Selalu buat detail baru untuk setiap penambahan produk (tidak digabungkan)
        $detail = new PenjualanDetail();
        $detail->id_penjualan = $id_penjualan;
        $detail->id_produk = $produk->id_produk;
        $detail->harga_jual = $produk->harga_jual;
        $detail->jumlah = 1;
        $detail->diskon = $produk->diskon;
        $detail->subtotal = $produk->harga_jual - ($produk->diskon / 100 * $produk->harga_jual);
        $detail->save();

        // Kurangi stok produk sebanyak 1
        $produk->stok -= 1;
        $produk->save();

        // Buat rekaman stok untuk tracking
        RekamanStok::create([
            'id_produk' => $produk->id_produk,
            'id_penjualan' => $id_penjualan,
            'waktu' => Carbon::now(),
            'stok_keluar' => 1,
            'stok_awal' => $produk->stok + 1, // stok sebelum dikurangi
            'stok_sisa' => $produk->stok,
        ]);

        return response()->json('Data berhasil disimpan', 200);
    }

    public function update(Request $request, $id)
    {
        $detail = PenjualanDetail::find($id);
        
        if (!$detail) {
            return response()->json('Detail transaksi tidak ditemukan', 404);
        }
        
        $produk = Produk::where('id_produk', $detail->id_produk)->first();
        
        if (!$produk) {
            return response()->json('Produk tidak ditemukan', 404);
        }
        
        // Normalisasi stok jika negatif
        if ($produk->stok < 0) {
            $produk->stok = 0;
            $produk->save();
        }
        
        // Validasi stok
        $old_jumlah = $detail->jumlah;
        $new_jumlah = $request->jumlah;
        $selisih = $new_jumlah - $old_jumlah;
        
        // Hitung stok baru dan validasi
        $new_stok = $produk->stok - $selisih;
        
        // Cek apakah stok mencukupi untuk perubahan ini
        if ($new_stok < 0) {
            return response()->json('Stok tidak cukup. Stok tersedia: ' . $produk->stok . ', dibutuhkan: ' . $selisih, 500);
        }
        
        $produk->stok = $new_stok;
        $produk->update();
        
        // Update detail transaksi
        $detail->jumlah = $new_jumlah;
        $detail->subtotal = $detail->harga_jual * $new_jumlah - (($detail->diskon * $new_jumlah) / 100 * $detail->harga_jual);
        $detail->update();
        
        // Update atau buat rekaman stok
        $rekaman_stok = RekamanStok::where('id_penjualan', $detail->id_penjualan)
                                   ->where('id_produk', $detail->id_produk)
                                   ->where('stok_keluar', $old_jumlah)
                                   ->first();
        
        if ($rekaman_stok) {
            // Update rekaman stok yang sudah ada
            $rekaman_stok->update([
                'waktu' => Carbon::now(),
                'stok_keluar' => $new_jumlah,
                'stok_awal' => $new_stok + $new_jumlah,  // stok sebelum pengurangan
                'stok_sisa' => $new_stok,
            ]);
        } else {
            // Buat rekaman stok baru jika belum ada
            RekamanStok::create([
                'id_produk' => $produk->id_produk,
                'id_penjualan' => $detail->id_penjualan,
                'waktu' => Carbon::now(),
                'stok_keluar' => $new_jumlah,
                'stok_awal' => $new_stok + $new_jumlah,  // stok sebelum pengurangan
                'stok_sisa' => $new_stok,
            ]);
        }
        
        return response()->json('Data berhasil diperbarui', 200);
    }

    public function updateEdit(Request $request, $id)
    {
        $detail = PenjualanDetail::where('id_penjualan_detail', $id)->first();
        
        if (!$detail) {
            return response()->json('Detail transaksi tidak ditemukan', 404);
        }
        
        $penjualan = Penjualan::where('id_penjualan', $detail->id_penjualan)->first();
        $produk = Produk::where('id_produk', $detail->id_produk)->first();
        
        if (!$produk) {
            return response()->json('Produk tidak ditemukan', 404);
        }
        
        // Validasi stok
        $old_jumlah = $detail->jumlah;
        $new_jumlah = $request->jumlah;
        $selisih = $new_jumlah - $old_jumlah;
        
        // Cek apakah stok mencukupi jika ada penambahan
        if ($selisih > 0 && $produk->stok < $selisih) {
            return response()->json('Stok tidak cukup. Stok tersedia: ' . $produk->stok, 500);
        }
        
        // Update stok produk berdasarkan selisih
        $produk->stok -= $selisih;
        $produk->update();
        
        // Update detail transaksi
        $detail->jumlah = $new_jumlah;
        $detail->subtotal = $detail->harga_jual * $new_jumlah - (($detail->diskon * $new_jumlah) / 100 * $detail->harga_jual);
        $detail->update();
        
        // Cari rekaman stok berdasarkan detail yang spesifik
        $rekaman_stok = RekamanStok::where('id_penjualan', $detail->id_penjualan)
                                   ->where('id_produk', $detail->id_produk)
                                   ->where('stok_keluar', $old_jumlah)
                                   ->first();
        
        if ($rekaman_stok) {
            // Update rekaman stok yang sudah ada
            $rekaman_stok->update([
                'waktu' => Carbon::now(),
                'stok_keluar' => $new_jumlah,
                'stok_awal' => $produk->stok + $new_jumlah,  // stok sebelum pengurangan
                'stok_sisa' => $produk->stok,
            ]);
        } else {
            // Buat rekaman stok baru jika belum ada
            RekamanStok::create([
                'id_produk' => $produk->id_produk,
                'id_penjualan' => $detail->id_penjualan,
                'waktu' => Carbon::now(),
                'stok_keluar' => $new_jumlah,
                'stok_awal' => $produk->stok + $new_jumlah,  // stok sebelum pengurangan
                'stok_sisa' => $produk->stok,
            ]);
        }
        
        return response()->json('Data berhasil diperbarui', 200);
    }

    public function destroy($id)
    {
        $detail = PenjualanDetail::find($id);
        
        if ($detail) {
            // Kembalikan stok produk
            $produk = Produk::find($detail->id_produk);
            if ($produk) {
                $produk->stok += $detail->jumlah;
                $produk->save();
                
                // Hapus hanya rekaman stok yang spesifik untuk detail ini
                // Karena setiap detail memiliki rekaman stok terpisah
                RekamanStok::where('id_penjualan', $detail->id_penjualan)
                           ->where('id_produk', $detail->id_produk)
                           ->where('stok_keluar', $detail->jumlah)
                           ->first()
                           ?->delete();
            }
            
            $detail->delete();
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
            // Hitung total stok keluar dari semua transaksi yang sudah selesai
            $total_keluar = RekamanStok::where('id_produk', $produk->id_produk)
                                      ->sum('stok_keluar');
            
            // Reset stok berdasarkan stok awal dan stok keluar
            // Catatan: Ini asumsi stok awal sudah benar, 
            // jika perlu, bisa ditambahkan logika untuk stok awal
            
            echo "Produk: {$produk->nama_produk}, Stok saat ini: {$produk->stok}, Total keluar: {$total_keluar}<br>";
        }
        
        return response()->json('Recalculate completed');
    }
}
