<?php

namespace App\Http\Controllers;

use App\Imports\ObatImport;
use App\Models\Kategori;
use App\Models\PembelianDetail;
use App\Models\RekamanStok;
use Illuminate\Http\Request;
use App\Models\Produk;
use Barryvdh\DomPDF\Facade as PDF;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;

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

    public function data(Request $request)
    {
        $query = Produk::leftJoin('kategori', 'kategori.id_kategori', 'produk.id_kategori')
            ->select('produk.*', 'nama_kategori');

        // Filter berdasarkan kondisi stok
        if ($request->filter_stok) {
            switch ($request->filter_stok) {
                case 'habis':
                    $query->where('produk.stok', '<=', 0);
                    break;
                case 'menipis':
                    $query->where('produk.stok', '=', 1);
                    break;
                case 'kritis':
                    $query->where('produk.stok', '<=', 1);
                    break;
                case 'normal':
                    $query->where('produk.stok', '>', 1);
                    break;
            }
        }

        $produk = $query->get();

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
                $stokDisplay = '<span class="' . ($produk->stok <= 0 ? 'text-danger' : ($produk->stok == 1 ? 'text-warning' : 'text-success')) . '">';
                $stokDisplay .= '<strong>' . format_uang($produk->stok) . '</strong>';
                $stokDisplay .= '</span>';
                
                // Tambahkan icon peringatan
                if ($produk->stok <= 0) {
                    $stokDisplay .= ' <i class="fa fa-ban text-danger" title="Stok habis - tidak dapat dijual"></i>';
                } elseif ($produk->stok == 1) {
                    $stokDisplay .= ' <i class="fa fa-warning text-warning" title="Stok menipis - segera lakukan pembelian"></i>';
                }
                
                return $stokDisplay;
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
                $buttons = '<div class="btn-group" role="group">';
                
                $buttons .= '<button type="button" onclick="editForm(`'. route('produk.update', $produk->id_produk) .'`)" class="btn btn-xs btn-info btn-flat" title="Edit Produk" data-toggle="tooltip"><i class="fa fa-pencil"></i> Edit</button>';
                
                // Tambahkan button "Update Stok Manual" 
                $buttons .= '<button type="button" onclick="updateStokManual('. $produk->id_produk .', \''. addslashes($produk->nama_produk) .'\', '. $produk->stok .')" class="btn btn-xs btn-success btn-flat" title="Update Stok Manual" data-toggle="tooltip"><i class="fa fa-refresh"></i> Stok</button>';
                
                // Tambahkan button "Kartu Stok"
                $buttons .= '<a href="'. route('kartu_stok.detail', $produk->id_produk) .'" class="btn btn-xs btn-primary btn-flat" title="Lihat Kartu Stok" data-toggle="tooltip" target="_blank"><i class="fa fa-file-text-o"></i> Kartu Stok</a>';
                
                $buttons .= '<button type="button" onclick="deleteData(`'. route('produk.destroy', $produk->id_produk) .'`)" class="btn btn-xs btn-danger btn-flat" title="Hapus Produk" data-toggle="tooltip"><i class="fa fa-trash"></i></button>';
                
                // Tambahkan button "Beli Sekarang" untuk produk dengan stok <= 1
                if ($produk->stok <= 1) {
                    $buttons .= '<button type="button" onclick="beliProduk('. $produk->id_produk .')" class="btn btn-xs btn-warning btn-flat" title="Beli Sekarang!" data-toggle="tooltip"><i class="fa fa-shopping-cart"></i> Beli</button>';
                }
                
                $buttons .= '</div>';
                
                return $buttons;
            })
            ->rawColumns(['aksi', 'kode_produk', 'select_all', 'stok'])
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
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $produk = Produk::find($id);
        
        $produk->update($request->all());

        return response()->json('Data berhasil disimpan', 200);
    }

    /**
     * Update stok produk secara manual dengan rekaman yang proper
     */
    public function updateStokManual(Request $request, $id)
    {
        $request->validate([
            'stok' => 'required|integer|min:0',
            'keterangan' => 'nullable|string|max:500'
        ], [
            'stok.required' => 'Stok wajib diisi',
            'stok.integer' => 'Stok harus berupa angka',
            'stok.min' => 'Stok tidak boleh negatif',
            'keterangan.max' => 'Keterangan maksimal 500 karakter'
        ]);

        $produk = Produk::find($id);
        if (!$produk) {
            return response()->json('Produk tidak ditemukan', 404);
        }

        $stok_lama = $produk->stok;
        $stok_baru = $request->stok;
        $selisih_stok = $stok_baru - $stok_lama;

        // Update stok produk
        $produk->stok = $stok_baru;
        $produk->save();

        // DISABLED: Auto-tracking moved to explicit transaction controllers
        // RekamanStok tracking is now handled by PenjualanController, PembelianController, etc.
        // Manual stock changes should be done through proper transaction flow
        
        return response()->json([
            'success' => true,
            'message' => 'Stok berhasil diperbarui',
            'data' => [
                'stok_lama' => $stok_lama,
                'stok_baru' => $stok_baru,
                'selisih' => $selisih_stok
            ]
        ], 200);
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

    public function beliProduk($id)
    {
        $produk = Produk::find($id);
        
        if (!$produk) {
            return response()->json(['error' => 'Produk tidak ditemukan'], 404);
        }

        // Ambil supplier default atau supplier terakhir yang mensupply produk ini
        $pembelianDetail = \App\Models\PembelianDetail::with('pembelian.supplier')
                                                       ->where('id_produk', $id)
                                                       ->orderBy('id_pembelian_detail', 'desc')
                                                       ->first();
        
        $supplierId = null;
        if ($pembelianDetail && $pembelianDetail->pembelian && $pembelianDetail->pembelian->supplier) {
            $supplierId = $pembelianDetail->pembelian->id_supplier;
        } else {
            // Jika tidak ada riwayat pembelian, ambil supplier pertama yang tersedia
            $supplier = \App\Models\Supplier::orderBy('nama')->first();
            $supplierId = $supplier ? $supplier->id_supplier : null;
        }

        if (!$supplierId) {
            return response()->json(['error' => 'Tidak ada supplier yang tersedia'], 400);
        }

        // Redirect ke halaman pembelian dengan supplier yang sudah dipilih
        $url = route('pembelian.create', $supplierId);
        return response()->json(['redirect' => $url]);
    }
}
