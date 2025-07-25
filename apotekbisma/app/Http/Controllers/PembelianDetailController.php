<?php

namespace App\Http\Controllers;

use App\Models\Pembelian;
use App\Models\PembelianDetail;
use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PembelianDetailController extends Controller
{
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

        // Selalu buat entry baru untuk memungkinkan produk yang sama ditambahkan berulang kali
        // Ini akan memungkinkan pengelompokan berdasarkan nama produk saat ditampilkan
        $detail = new PembelianDetail();
        $detail->id_pembelian = $request->id_pembelian;
        $detail->id_produk = $produk->id_produk;
        $detail->harga_beli = $produk->harga_beli;
        $detail->jumlah = 1; // Set default jumlah ke 1
        $detail->subtotal = $produk->harga_beli * 1;
        $detail->save();

        return response()->json('Data berhasil disimpan', 200);
    }

    public function update(Request $request, $id)
    {
        $detail = PembelianDetail::find($id);
        $detail->jumlah = $request->jumlah;
        $detail->subtotal = $detail->harga_beli * $request->jumlah;
        $detail->update();
        
        return response()->json('Data berhasil diperbarui', 200);
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
        
        // Ambil atau buat rekaman stok
        $rekaman_stok = RekamanStok::where('id_pembelian', $detail->id_pembelian)
                                   ->where('id_produk', $detail->id_produk)
                                   ->first();
        
        $old_jumlah = $detail->jumlah;
        $new_jumlah = $request->jumlah;
        $selisih = $new_jumlah - $old_jumlah;
        
        if ($rekaman_stok) {
            // Update rekaman stok yang sudah ada
            $rekaman_stok->update([
                'waktu' => Carbon::now(),
                'stok_masuk' => $new_jumlah,
                'stok_sisa' => $produk->stok + $selisih,
            ]);
        } else {
            // Buat rekaman stok baru
            RekamanStok::create([
                'id_produk' => $detail->id_produk,
                'id_pembelian' => $detail->id_pembelian,
                'waktu' => Carbon::now(),
                'stok_masuk' => $new_jumlah,
                'stok_awal' => $produk->stok,
                'stok_sisa' => $produk->stok + $selisih,
            ]);
        }
        
        // Update stok produk
        $produk->stok += $selisih;
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
        
        // Jika ada rekaman stok terkait, kembalikan stok produk
        $rekaman_stok = RekamanStok::where('id_pembelian', $detail->id_pembelian)
                                   ->where('id_produk', $detail->id_produk)
                                   ->first();
        
        if ($rekaman_stok) {
            $produk = Produk::find($detail->id_produk);
            if ($produk) {
                // Kurangi stok yang sebelumnya ditambahkan
                $produk->stok -= $rekaman_stok->stok_masuk;
                $produk->update();
                
                // Hapus rekaman stok
                $rekaman_stok->delete();
            }
        }
        
        $detail->delete();

        return response(null, 204);
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
