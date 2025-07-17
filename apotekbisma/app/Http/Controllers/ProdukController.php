<?php

namespace App\Http\Controllers;

use App\Imports\ObatImport;
use App\Models\Kategori;
use App\Models\PembelianDetail;
use Illuminate\Http\Request;
use App\Models\Produk;
use Barryvdh\DomPDF\Facade as PDF;
use Maatwebsite\Excel\Facades\Excel;

class ProdukController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $kategori = Kategori::all()->pluck('nama_kategori', 'id_kategori');

        return view('produk.index', compact('kategori'));
    }

    public function importExcel(Request $request)
    {
        $import = Excel::import(new ObatImport, $request->file('excel_file'));
        if ($import) {
            return redirect()->back();
        } else {
            return request()->json(404, 'Gagal');
        }
    }
    public function updateHargaJual(Request $request, $id)
    {
        $produk = Produk::where('id_produk', $id)->first();
        $produk->update([
            'harga_jual' => $request->harga_jual
        ]);
        $detail = PembelianDetail::find($request->id_pembelian_detail);
        if ($request->jumlah == NULL || $request->jumlah == 0) {
            $detail->jumlah = 0;
            $detail->subtotal = $detail->harga_beli * $request->jumlah;
        } else {
            $detail->jumlah = $request->jumlah;
            $detail->subtotal = $detail->harga_beli * $request->jumlah;
        }
        $detail->update();
    }
    public function updateExpiredDate(Request $request, $id)
    {
        $produk = Produk::where('id_produk', $id)->first();
        // dd($request->expired_date);
        $produk->update([
            'expired_date' => $request->expired_date
        ]);
    }
    public function updateBatch(Request $request, $id)
    {
        $produk = Produk::where('id_produk', $id)->first();
        $produk->update([
            'batch' => $request->batch
        ]);
    }
    public function updateHargaBeli(Request $request, $id)
    {
        $produk = Produk::where('id_produk', $id)->first();
        $produk->update([
            'harga_beli' => $request->harga_beli
        ]);
        // $id_pembelian = $request->id_pembelian_detail;
        $detail = PembelianDetail::where('id_pembelian_detail', $request->id_pembayaran_detail)->first();
        // dd($request->all());
        // dd($request->jumlah);
        $jumlah = (int)$request->jumlah;
        $detail->update([
            'jumlah' => $jumlah,
            'harga_beli' => $produk->harga_beli,
            'subtotal' => $produk->harga_beli * $jumlah,
        ]);
    }

    public function importPage()
    {
        return view('produk.importpage');
    }

    public function data()
    {
        $produk = Produk::leftJoin('kategori', 'kategori.id_kategori', 'produk.id_kategori')
            ->select('produk.*', 'nama_kategori')
            // ->orderBy('kode_produk', 'asc')
            ->get();

        return datatables()
            ->of($produk)
            ->addIndexColumn()
            ->addColumn('select_all', function ($produk) {
                return '
                    <input type="checkbox" name="id_produk[]" value="'. $produk->id_produk .'">
                ';
            })
            ->addColumn('kode_produk', function ($produk) {
                return '<span class="label label-success">'. $produk->kode_produk .'</span>';
            })
            ->addColumn('harga_beli', function ($produk) {
                return format_uang($produk->harga_beli);
            })
            ->addColumn('harga_jual', function ($produk) {
                return format_uang($produk->harga_jual);
            })
            ->addColumn('stok', function ($produk) {
                return format_uang($produk->stok);
            })
            ->addColumn('expired_date', function ($produk) {
                if ($produk->expired_date == NULL) {
                    return 'Belum ditambahkan tanggal kadaluarsa';
                } else {
                    return $produk->expired_date;
                }
                
            })
            ->addColumn('batch', function ($produk) {
                if ($produk->batch == NULL) {
                    return 'Belum ditambahkan nomor batch';
                } else {
                    return $produk->batch;
                }
                
            })
            ->addColumn('aksi', function ($produk) {
                return '
                <div class="btn-group">
                    <button type="button" onclick="editForm(`'. route('produk.update', $produk->id_produk) .'`)" class="btn btn-xs btn-info btn-flat"><i class="fa fa-pencil"></i></button>
                    <button type="button" onclick="deleteData(`'. route('produk.destroy', $produk->id_produk) .'`)" class="btn btn-xs btn-danger btn-flat"><i class="fa fa-trash"></i></button>
                </div>
                ';
            })
            ->rawColumns(['aksi', 'kode_produk', 'select_all'])
            ->make(true);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $produk = Produk::latest()->first() ?? new Produk();
        $request['kode_produk'] = 'P'. tambah_nol_didepan((int)$produk->id_produk +1, 6);
        // dd($request->all());

        $produk = Produk::create($request->all());

        return response()->json('Data berhasil disimpan', 200);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $produk = Produk::find($id);

        return response()->json($produk);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $produk = Produk::find($id);
        $produk->update($request->all());

        return response()->json('Data berhasil disimpan', 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $produk = Produk::find($id);
        $produk->delete();

        return response(null, 204);
    }

    public function deleteSelected(Request $request)
    {
        foreach ($request->id_produk as $id) {
            $produk = Produk::find($id);
            $produk->delete();
        }

        return response(null, 204);
    }

    public function cetakBarcode(Request $request)
    {
        $dataproduk = array();
        foreach ($request->id_produk as $id) {
            $produk = Produk::find($id);
            $dataproduk[] = $produk;
        }

        $no  = 1;
        $pdf = PDF::loadView('produk.barcode', compact('dataproduk', 'no'));
        $pdf->setPaper('a4', 'potrait');
        return $pdf->stream('produk.pdf');
    }
}
