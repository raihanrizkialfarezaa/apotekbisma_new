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
        $produk = Produk::orderBy('nama_produk')->get();
        $supplier = Supplier::find(session('id_supplier'));
        $diskon = Pembelian::find($id_pembelian)->diskon ?? 0;

        if (! $supplier) {
            abort(404);
        }

        return view('pembelian_detail.index', compact('id_pembelian', 'produk', 'supplier', 'diskon'));
    }
    
    public function editBayar($id)
    {
        $id_pembelian = $id;
	$pembelian = Pembelian::where('id', $id)->first();
        $produk = Produk::orderBy('nama_produk')->get();
        $produk_supplier = Pembelian::where('id_pembelian', $id)->first();
        $detail_pembelian = PembelianDetail::where('id_pembelian', $id)->get();
        $supplier = Supplier::find($produk_supplier->id_supplier);
        $diskon = Pembelian::find($id_pembelian)->diskon ?? 0;
        $tanggal = Pembelian::where('id_pembelian', $id_pembelian)->first();

        if (! $supplier) {
            abort(404);
        }

        return view('pembelian_detail.editBayar', compact('id_pembelian', 'pembelian', 'tanggal', 'detail_pembelian', 'produk', 'supplier', 'diskon'));
    }

    public function data($id)
    {
        $detail = PembelianDetail::with('produk')
            ->where('id_pembelian', $id)
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

        $detail = new PembelianDetail();
        $detail->id_pembelian = $request->id_pembelian;
        $detail->id_produk = $produk->id_produk;
        $detail->harga_beli = $produk->harga_beli;
        $detail->jumlah = 0;
        $detail->subtotal = $produk->harga_beli * 0;
        $detail->save();

        return response()->json('Data berhasil disimpan', 200);
    }

    public function update(Request $request, $id)
    {
        $detail = PembelianDetail::find($id);
        $detail->jumlah = $request->jumlah;
        $detail->subtotal = $detail->harga_beli * $request->jumlah;
        $detail->update();
        dd($detail);
        if($detail) {
            $id_produk = $detail->id_produk;
            $produk = Produk::where('id_produk', $id_produk)->first();
            $stok = $produk->stok;
            RekamanStok::create([
                'id_produk' => $detail->id_produk,
                'waktu' => Carbon::now(),
                'stok_masuk' => $request->jumlah,
                'stok_awal' => $stok,
                'stok_sisa' => $produk->stok += $request->jumlah,
            ]);
        }
    }

    public function updateEdit(Request $request, $id)
    {
        $detail = PembelianDetail::where('id_pembelian_detail', $id)->first();
        // dd($detail->id_produk);
        // dd($detail->id_pembelian);
        // dd($request->jumlah);
        $rekaman_stok = RekamanStok::where('id_pembelian', $detail->id_pembelian)->where('id_produk', $detail->id_produk)->first();
        $cari = RekamanStok::where('id_pembelian', $detail->id_pembelian)->where('id_produk', $detail->id_produk)->first();
        if (!empty($rekaman_stok->id_produk) == $detail->id_produk) {
            $sum = $request->jumlah - $detail->jumlah;
            // dd($sum);
            if ($sum < 0 && $sum != 0) {
                $positive = $sum * -1;
                $id_produk = $detail->id_produk;
                $produk = Produk::where('id_produk', $id_produk)->first();
                $stok = $produk->stok;
                $rekaman_stok->update([
                    'id_produk' => $detail->id_produk,
                    'waktu' => Carbon::now(),
                    'stok_masuk' => $rekaman_stok->stok_masuk -= $positive,
                    'stok_awal' => $stok,
                    'stok_sisa' => $rekaman_stok->stok_sisa -= $positive,
                ]);
                $produk = Produk::find($detail->id_produk);
                $produk->stok -= $positive;
                $produk->update();
            } elseif($sum >= 1 && $sum != 0) {
                $id_produk = $detail->id_produk;
                $produk = Produk::where('id_produk', $id_produk)->first();
                $stok = $produk->stok;
                $rekaman_stok->update([
                    'id_produk' => $detail->id_produk,
                    'waktu' => Carbon::now(),
                    'stok_masuk' => $rekaman_stok->stok_masuk += $sum,
                    'stok_sisa' => $rekaman_stok->stok_sisa += $sum,
                    'stok_awal' => $stok,
                ]);
                $positive = $sum * -1;
                $produk = Produk::find($detail->id_produk);
                $produk->stok += $sum;
                $produk->update();
            }
            
        } else {
            $produk = Produk::find($detail->id_produk);
            $sum = $request->jumlah;
            $stok = $produk->stok;
            RekamanStok::create([
                'id_produk' => $detail->id_produk,
                'waktu' => Carbon::now(),
                'stok_masuk' => $sum,
                'stok_awal' => $produk->stok,
                'stok_sisa' => $stok += $sum,
            ]);
            $produk->stok += $sum;
            $produk->update();
        }
        
        $detail->jumlah = $request->jumlah;
        $detail->subtotal = $detail->harga_beli * $request->jumlah;
        $detail->update();
    }

    public function destroy($id)
    {
        $detail = PembelianDetail::find($id);
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
