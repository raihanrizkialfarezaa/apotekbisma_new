<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Pembelian;
use App\Models\PembelianDetail;
use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\Supplier;
use Carbon\Carbon;

class PembelianController extends Controller
{
    public function index()
    {
        $supplier = Supplier::orderBy('nama')->get();

        return view('pembelian.index', compact('supplier'));
    }

    public function data()
    {
        $pembelian = Pembelian::with('supplier')->orderBy('id_pembelian', 'desc')->get();
        
        return datatables()
            ->of($pembelian)
            ->addIndexColumn()
            ->addColumn('total_item', function ($pembelian) {
                return format_uang($pembelian->total_item ?? 0);
            })
            ->addColumn('total_harga', function ($pembelian) {
                return 'Rp. '. format_uang($pembelian->total_harga ?? 0);
            })
            ->addColumn('bayar', function ($pembelian) {
                return 'Rp. '. format_uang($pembelian->bayar ?? 0);
            })
            ->addColumn('tanggal', function ($pembelian) {
                return tanggal_indonesia($pembelian->created_at, false);
            })
            ->addColumn('waktu', function ($pembelian) {
                return tanggal_indonesia(($pembelian->waktu != NULL ? $pembelian->waktu : $pembelian->created_at), false);
            })
            ->addColumn('supplier', function ($pembelian) {
                return $pembelian->supplier ? $pembelian->supplier->nama : 'N/A';
            })
            ->editColumn('diskon', function ($pembelian) {
                return ($pembelian->diskon ?? 0) . '%';
            })
            ->addColumn('aksi', function ($pembelian) {
                return '
                <div class="btn-group">
                    <button onclick="showDetail(`'. route('pembelian.show', $pembelian->id_pembelian) .'`)" class="btn btn-xs btn-info btn-flat"><i class="fa fa-eye"></i></button>
                    <a href="'. route('pembelian_detail.editBayar', $pembelian->id_pembelian) .'" class="btn btn-xs btn-info btn-flat"><i class="fa fa-pencil"></i></a>
                    <button onclick="deleteData(`'. route('pembelian.destroy', $pembelian->id_pembelian) .'`)" class="btn btn-xs btn-danger btn-flat"><i class="fa fa-trash"></i></button>
                </div>
                ';
            })
            ->rawColumns(['aksi'])
            ->make(true);
    }

    public function create($id)
    {
        $pembelian = new Pembelian();
        $pembelian->id_supplier = $id;
        $pembelian->total_item  = 0;
        $pembelian->total_harga = 0;
        $pembelian->diskon      = 0;
        $pembelian->bayar       = 0;
        $pembelian->waktu       = Carbon::now();
	$pembelian->no_faktur       = 'o';
        $pembelian->save();

        session(['id_pembelian' => $pembelian->id_pembelian]);
        session(['id_supplier' => $pembelian->id_supplier]);

        return redirect()->route('pembelian_detail.index');
    }

    public function store(Request $request)
    {
        // Validasi server-side
        $request->validate([
            'nomor_faktur' => 'required|string|max:255',
            'total_item' => 'required|integer|min:1',
            'total' => 'required|numeric|min:0',
            'waktu' => 'required|date'
        ], [
            'nomor_faktur.required' => 'Nomor faktur harus diisi',
            'total_item.required' => 'Minimal harus ada 1 produk',
            'total_item.min' => 'Minimal harus ada 1 produk',
            'total.required' => 'Total harga harus diisi',
            'waktu.required' => 'Tanggal faktur harus diisi'
        ]);

        $pembelian = Pembelian::findOrFail($request->id_pembelian);
        
        // Cek apakah ada detail pembelian
        $detail = PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)->get();
        if ($detail->isEmpty()) {
            return redirect()->back()->with('error', 'Tidak dapat menyimpan transaksi tanpa produk');
        }

        // Cek apakah semua produk memiliki jumlah > 0
        $hasZeroQuantity = $detail->where('jumlah', '<=', 0)->count() > 0;
        if ($hasZeroQuantity) {
            return redirect()->back()->with('error', 'Semua produk harus memiliki jumlah lebih dari 0');
        }

        $pembelian->total_item = $request->total_item;
        $pembelian->total_harga = $request->total;
        $pembelian->diskon = $request->diskon;
        $pembelian->bayar = $request->bayar;
        $pembelian->waktu = $request->waktu;
        $pembelian->no_faktur = $request->nomor_faktur;
        $pembelian->update();
        
        $id_pembelian = $request->id_pembelian;
        
        if (count($detail) > 1) {
            foreach ($detail as $item) {
                $produk = Produk::find($item->id_produk);
                $stok = $produk->stok;
                
                RekamanStok::create([
                    'id_produk' => $item->id_produk,
                    'waktu' => Carbon::now(),
                    'stok_masuk' => $item->jumlah,
                    'id_pembelian' => $id_pembelian,
                    'stok_awal' => $produk->stok,
                    'stok_sisa' => $stok + $item->jumlah,
                ]);
                $produk->stok += $item->jumlah;
                $produk->update();
            }
        } elseif (count($detail) == 1) {
            $details = PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)->first();
            $cek = RekamanStok::where('id_pembelian', $id_pembelian)->get();
            
            if (count($cek) <= 0) {
                $produk = Produk::find($details->id_produk);
                $stok = $produk->stok;
                RekamanStok::create([
                    'id_produk' => $details->id_produk,
                    'waktu' => Carbon::now(),
                    'stok_masuk' => $details->jumlah,
                    'id_pembelian' => $id_pembelian,
                    'stok_awal' => $produk->stok,
                    'stok_sisa' => $stok + $details->jumlah,
                ]);
                $produk->stok += $details->jumlah;
                $produk->update();
            } else {
                $produk = Produk::find($details->id_produk);
                $stok = $produk->stok;
                $sums = $details->jumlah - $stok;
                if ($sums < 0 && $sums != 0) {
                    $sum = $sums * -1;
                } else {
                    $sum = $sums;
                }
                
                $rekaman_stok = RekamanStok::where('id_pembelian', $pembelian->id_pembelian)->first();
                $rekaman_stok->update([
                    'id_produk' => $produk->id_produk,
                    'waktu' => Carbon::now(),
                    'stok_masuk' => $rekaman_stok->stok_masuk + $sum,
                    'stok_sisa' => $rekaman_stok->stok_sisa + $sum,
                ]);
                $produk->stok += $sum;
                $produk->update();
            }
        }
        
        return redirect()->route('pembelian.index')->with('success', 'Transaksi pembelian berhasil disimpan');
    }
    public function update(Request $request, $id)
    {
        // Validasi server-side
        $request->validate([
            'nomor_faktur' => 'required|string|max:255',
            'total_item' => 'required|integer|min:1',
            'total' => 'required|numeric|min:0',
            'waktu' => 'required|date'
        ], [
            'nomor_faktur.required' => 'Nomor faktur harus diisi',
            'total_item.required' => 'Minimal harus ada 1 produk',
            'total_item.min' => 'Minimal harus ada 1 produk',
            'total.required' => 'Total harga harus diisi',
            'waktu.required' => 'Tanggal faktur harus diisi'
        ]);

        $pembelian = Pembelian::findOrFail($request->id_pembelian);
        
        // Cek apakah ada detail pembelian
        $detail = PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)->get();
        if ($detail->isEmpty()) {
            return redirect()->back()->with('error', 'Tidak dapat menyimpan transaksi tanpa produk');
        }

        // Cek apakah semua produk memiliki jumlah > 0
        $hasZeroQuantity = $detail->where('jumlah', '<=', 0)->count() > 0;
        if ($hasZeroQuantity) {
            return redirect()->back()->with('error', 'Semua produk harus memiliki jumlah lebih dari 0');
        }

        $pembelian->total_item = $request->total_item;
        $pembelian->total_harga = $request->total;
        $pembelian->diskon = $request->diskon;
        $pembelian->bayar = $request->bayar;
        $pembelian->no_faktur = $request->nomor_faktur;
        if ($request->waktu != NULL) {
            $pembelian->waktu = $request->waktu;
        }
        
        $pembelian->update();
        
        return redirect()->route('pembelian.index')->with('success', 'Transaksi pembelian berhasil diperbarui');
    }

    public function show($id)
    {
        $detail = PembelianDetail::with('produk')->where('id_pembelian', $id)->get();

        return datatables()
            ->of($detail)
            ->addIndexColumn()
            ->addColumn('kode_produk', function ($detail) {
                return '<span class="label label-success">'. $detail->produk->kode_produk .'</span>';
            })
            ->addColumn('nama_produk', function ($detail) {
                return $detail->produk->nama_produk;
            })
            ->addColumn('harga_beli', function ($detail) {
                return 'Rp. '. format_uang($detail->harga_beli);
            })
            ->addColumn('jumlah', function ($detail) {
                return format_uang($detail->jumlah);
            })
            ->addColumn('subtotal', function ($detail) {
                return 'Rp. '. format_uang($detail->subtotal);
            })
            ->rawColumns(['kode_produk'])
            ->make(true);
    }

    public function destroy($id)
    {
        $pembelian = Pembelian::where('id_pembelian', $id)->first();
        
        if (!$pembelian) {
            return response()->json(['error' => 'Pembelian tidak ditemukan'], 404);
        }

        $pembelian_detail = PembelianDetail::where('id_pembelian', $id)->get();
        
        // Jika ada detail pembelian, hapus stok dan rekaman stok
        if (count($pembelian_detail) > 0) {
            if (count($pembelian_detail) == 1) {
                $produk = Produk::find($pembelian_detail[0]->id_produk);
                $rekamstok = RekamanStok::where('id_pembelian', $id)->where('id_produk', $pembelian_detail[0]->id_produk)->first();
                
                if ($produk && $rekamstok) {
                    $produk->stok -= $pembelian_detail[0]->jumlah;
                    $rekamstok->delete();
                    $produk->update();
                }
            } else {
                foreach ($pembelian_detail as $row) {
                    $produk = Produk::find($row->id_produk);
                    $rekamstok = RekamanStok::where('id_pembelian', $id)->where('id_produk', $row->id_produk)->first();
                    
                    if ($produk && $rekamstok) {
                        $produk->stok -= $row->jumlah;
                        $rekamstok->delete();
                        $produk->update();
                    }
                }
            }
        }
        
        $pembelian->delete();

        return response(null, 204);
    }

    public function cleanupIncompleteTransactions()
    {
        // Membersihkan transaksi yang tidak lengkap (tanpa detail atau no_faktur kosong)
        $incompleteTransactions = Pembelian::where('no_faktur', '=', 'o')
            ->orWhere('no_faktur', '=', '')
            ->orWhereNull('no_faktur')
            ->get();

        foreach ($incompleteTransactions as $transaction) {
            $details = PembelianDetail::where('id_pembelian', $transaction->id_pembelian)->get();
            if ($details->isEmpty()) {
                $transaction->delete();
            }
        }

        return response()->json(['message' => 'Cleanup completed']);
    }

    public function destroyEmpty($id)
    {
        $pembelian = Pembelian::where('id_pembelian', $id)->first();
        
        if (!$pembelian) {
            return response()->json(['error' => 'Pembelian tidak ditemukan'], 404);
        }

        // Hanya hapus jika transaksi benar-benar kosong
        $pembelian_detail = PembelianDetail::where('id_pembelian', $id)->get();
        $isEmpty = $pembelian_detail->isEmpty() && 
                  ($pembelian->no_faktur === 'o' || $pembelian->no_faktur === '' || $pembelian->no_faktur === null) &&
                  $pembelian->total_harga == 0;
        
        if ($isEmpty) {
            $pembelian->delete();
            return response()->json(['message' => 'Empty transaction deleted']);
        }

        return response()->json(['message' => 'Transaction not empty, not deleted']);
    }
}

