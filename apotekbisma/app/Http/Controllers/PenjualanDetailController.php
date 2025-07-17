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

        $detail = new PenjualanDetail();
        $detail->id_penjualan = $request->id_penjualan;
        $detail->id_produk = $produk->id_produk;
        $detail->harga_jual = $produk->harga_jual;
        $detail->jumlah = 0;
        $detail->diskon = $produk->diskon;
        $detail->subtotal = $produk->harga_jual - ($produk->diskon / 100 * $produk->harga_jual);;
        $detail->save();

        return response()->json('Data berhasil disimpan', 200);
    }

    public function update(Request $request, $id)
    {
        $detail = PenjualanDetail::find($id);
        // dd($detail);
        $produk = Produk::where('id_produk', $detail->id_produk)->first();
        if ($produk->stok >= $request->jumlah) {
            $detail->jumlah = $request->jumlah;
            $detail->subtotal = $detail->harga_jual * $request->jumlah - (($detail->diskon * $request->jumlah) / 100 * $detail->harga_jual);
            $detail->update();
        } else {
            return response()->json('Stok tidak cukup', 500);
        }
        
    }

    public function updateEdit(Request $request, $id)
    {
        // $detail = PenjualanDetail::find($id);
        $detail = PenjualanDetail::where('id_penjualan_detail', $id)->first();
        $penjualan = Penjualan::where('id_penjualan', $detail->id_penjualan)->first();
        // dd($detail->id_produk);
        $produk_id = Produk::where('id_produk', $detail->id_produk)->first();
        $rekaman_stok = RekamanStok::where('id_penjualan', $detail->id_penjualan)->where('id_produk', $detail->id_produk)->first();
        // DB::enableQueryLog();
        $cari = RekamanStok::where('id_produk', $produk_id->id_produk)->where('id_penjualan', $request->id_penjualan)->first();
        // dd(DB::getQueryLog());
        if ($rekaman_stok != NULL) {
            if ($rekaman_stok->id_produk == $produk_id->id_produk) {
                $sum = $request->jumlah - $detail->jumlah;
                // dd($sum);
                if ($produk_id->stok >= $sum) {
                    // dd($sum);
                    if ($sum < 0 && $sum != 0) {
                        $rekaman_stok->update([
                            'id_produk' => $produk_id->id_produk,
                            'waktu' => Carbon::now(),
                            'stok_keluar' => $rekaman_stok->stok_keluar -= $sum,
                            'stok_sisa' => $rekaman_stok->stok_sisa -= $sum,
                        ]);
                        $produk = Produk::find($detail->id_produk);
                        $positive = $sum * -1;
                        $produk->stok += $positive;
                        $produk->update();
                        $detail->jumlah = $request->jumlah;
                        $detail->subtotal = $detail->harga_jual * $request->jumlah - (($detail->diskon * $request->jumlah) / 100 * $detail->harga_jual);
                        $detail->update();
                    } elseif($sum >= 1 && $sum != 0) {
                        $rekaman_stok->update([
                            'id_produk' => $produk_id->id_produk,
                            'waktu' => Carbon::now(),
                            'stok_keluar' => $rekaman_stok->stok_keluar += $sum,
                            'stok_sisa' => $rekaman_stok->stok_sisa += $sum,
                        ]);
                        $produk = Produk::find($detail->id_produk);
                        $produk->stok -= $sum;
                        $produk->update();
                        $detail->jumlah = $request->jumlah;
                        $detail->subtotal = $detail->harga_jual * $request->jumlah - (($detail->diskon * $request->jumlah) / 100 * $detail->harga_jual);
                        $detail->update();
                    }
                } else {
                    return response()->json('Stok tidak cukup', 500);
                }
                
                
            }
        } else {
            if ($produk_id->stok >= $request->jumlah) {
                $produk = Produk::find($detail->id_produk);
                $sum = $request->jumlah;
                RekamanStok::create([
                    'id_produk' => $produk_id->id_produk,
                    'id_penjualan' => $detail->id_penjualan,
                    'waktu' => Carbon::now(),
                    'stok_keluar' => $sum,
                    'stok_awal' => $produk->stok,
                    'stok_sisa' => $produk->stok -= $sum,
                ]);
                $produk->stok -= $sum;
                $produk->update();
                $detail->jumlah = $request->jumlah;
                $detail->subtotal = $detail->harga_jual * $request->jumlah - (($detail->diskon * $request->jumlah) / 100 * $detail->harga_jual);
                $detail->update();
            } else {
                return response()->json('Stok tidak cukup', 500);
            }
        }
        
        
        // dd($sum);
        
        
    }

    public function destroy($id)
    {
        $detail = PenjualanDetail::find($id);
        $detail->delete();

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
}
