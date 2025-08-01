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
        
        // If no session data, redirect to pembelian page
        if (!$id_pembelian) {
            return redirect()->route('pembelian.index')->with('error', 'Silakan pilih supplier terlebih dahulu untuk memulai pembelian.');
        }
        
        // Cek apakah pembelian ada
        $pembelian = Pembelian::find($id_pembelian);
        if (!$pembelian) {
            session()->forget('id_pembelian');
            session()->forget('id_supplier');
            return redirect()->route('pembelian.index')->with('error', 'Transaksi pembelian tidak ditemukan.');
        }
        
        $produk = Produk::orderBy('nama_produk')->get();
        $supplier = Supplier::find(session('id_supplier'));
        $diskon = $pembelian->diskon ?? 0;

        if (! $supplier) {
            abort(404);
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
        $produk = Produk::where('id_produk', $request->id_produk)->first();
        if (! $produk) {
            return response()->json('Data gagal disimpan', 400);
        }

        // Normalisasi stok jika negatif
        if ($produk->stok < 0) {
            $produk->stok = 0;
            $produk->save();
        }

        // Selalu buat entry baru untuk memungkinkan produk yang sama ditambahkan berulang kali
        // Ini akan memungkinkan pengelompokan berdasarkan nama produk saat ditampilkan
        $detail = new PembelianDetail();
        $detail->id_pembelian = $request->id_pembelian;
        $detail->id_produk = $produk->id_produk;
        $detail->harga_beli = $produk->harga_beli;
        $detail->jumlah = 1; // Set default jumlah ke 1
        $detail->subtotal = $produk->harga_beli * 1;
        $detail->save();

        // UPDATE STOK: Tambah stok karena ini pembelian (default jumlah = 1)
        $produk->stok = $produk->stok + 1;
        $produk->save();

        // Buat rekaman stok untuk tracking
        RekamanStok::create([
            'id_produk' => $produk->id_produk,
            'id_pembelian' => $detail->id_pembelian,
            'waktu' => Carbon::now(),
            'stok_masuk' => 1,
            'stok_awal' => $produk->stok - 1,  // stok sebelum penambahan
            'stok_sisa' => $produk->stok,
        ]);

        return response()->json('Data berhasil disimpan', 200);
    }

    public function update(Request $request, $id)
    {
        try {
            $detail = PembelianDetail::find($id);
            
            if (!$detail) {
                return response()->json(['message' => 'Detail pembelian tidak ditemukan'], 404);
            }
            
            $produk = Produk::where('id_produk', $detail->id_produk)->first();
            
            if (!$produk) {
                return response()->json(['message' => 'Produk tidak ditemukan'], 404);
            }
            
            // Validasi input jumlah
            $new_jumlah = (int) $request->jumlah;
            if ($new_jumlah < 1) {
                return response()->json(['message' => 'Jumlah harus minimal 1'], 400);
            }
            
            if ($new_jumlah > 10000) {
                return response()->json(['message' => 'Jumlah tidak boleh lebih dari 10000'], 400);
            }
            
            // Normalisasi stok jika negatif
            if ($produk->stok < 0) {
                $produk->stok = 0;
                $produk->save();
            }
            
            $old_jumlah = $detail->jumlah;
            $selisih = $new_jumlah - $old_jumlah;
            
            // Update stok produk berdasarkan selisih (pembelian menambah stok)
            $new_stok = $produk->stok + $selisih;
            
            // Pastikan stok tidak negatif
            if ($new_stok < 0) {
                $new_stok = 0;
            }
            
            $produk->stok = $new_stok;
            $produk->save();
            
            // Update detail pembelian
            $detail->jumlah = $new_jumlah;
            $detail->subtotal = $detail->harga_beli * $new_jumlah;
            $detail->save();
            
            // Update atau buat rekaman stok
            $rekaman_stok = RekamanStok::where('id_pembelian', $detail->id_pembelian)
                                       ->where('id_produk', $detail->id_produk)
                                       ->where('stok_masuk', $old_jumlah)
                                       ->first();
            
            if ($rekaman_stok) {
                // Update rekaman stok yang sudah ada
                $rekaman_stok->update([
                    'waktu' => Carbon::now(),
                    'stok_masuk' => $new_jumlah,
                    'stok_awal' => $new_stok - $new_jumlah,  // stok sebelum penambahan
                    'stok_sisa' => $new_stok,
                ]);
            } else {
                // Buat rekaman stok baru jika belum ada
                RekamanStok::create([
                    'id_produk' => $produk->id_produk,
                    'id_pembelian' => $detail->id_pembelian,
                    'waktu' => Carbon::now(),
                    'stok_masuk' => $new_jumlah,
                    'stok_awal' => $new_stok - $new_jumlah,  // stok sebelum penambahan
                    'stok_sisa' => $new_stok,
                ]);
            }
            
            return response()->json([
                'message' => 'Data berhasil diperbarui',
                'data' => [
                    'jumlah' => $new_jumlah,
                    'subtotal' => $detail->subtotal,
                    'stok_tersisa' => $new_stok
                ]
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Error updating pembelian detail: ' . $e->getMessage());
            return response()->json(['message' => 'Terjadi kesalahan: ' . $e->getMessage()], 500);
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
            // Update rekaman stok yang sudah ada
            $rekaman_stok->update([
                'waktu' => Carbon::now(),
                'stok_masuk' => $new_jumlah,
                'stok_sisa' => $new_stok,
            ]);
        } else {
            // Buat rekaman stok baru jika tidak ditemukan
            RekamanStok::create([
                'id_produk' => $detail->id_produk,
                'id_pembelian' => $detail->id_pembelian,
                'waktu' => Carbon::now(),
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
        $detail = PembelianDetail::find($id);
        
        if (!$detail) {
            return response()->json(['success' => false, 'message' => 'Detail tidak ditemukan'], 404);
        }
        
        $produk = Produk::find($detail->id_produk);
        if ($produk) {
            // Kurangi stok berdasarkan jumlah yang ada di detail
            $jumlah_dikurangi = $detail->jumlah;
            $new_stok = $produk->stok - $jumlah_dikurangi;
            
            // Pastikan stok tidak negatif
            if ($new_stok < 0) {
                $new_stok = 0;
            }
            
            $produk->stok = $new_stok;
            $produk->save();
            
            // Hapus rekaman stok yang terkait
            RekamanStok::where('id_pembelian', $detail->id_pembelian)
                       ->where('id_produk', $detail->id_produk)
                       ->where('stok_masuk', $jumlah_dikurangi)
                       ->delete();
        }
        
        $detail->delete();

        return response(null, 204);
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
}
