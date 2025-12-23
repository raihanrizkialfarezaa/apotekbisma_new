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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
                $buttons = '<div class="btn-group btn-group-xs" role="group">';
                
                $buttons .= '<button type="button" onclick="editForm(`'. route('produk.update', $produk->id_produk) .'`)" class="btn btn-info" title="Edit Produk"><i class="fa fa-pencil"></i></button>';
                
                $buttons .= '<button type="button" onclick="updateStokManual('. $produk->id_produk .', \''. addslashes($produk->nama_produk) .'\', '. $produk->stok .')" class="btn btn-success" title="Update Stok"><i class="fa fa-cubes"></i></button>';
                
                $buttons .= '<a href="'. route('kartu_stok.detail', $produk->id_produk) .'" class="btn btn-primary" title="Kartu Stok" target="_blank"><i class="fa fa-list-alt"></i></a>';
                
                $buttons .= '<button type="button" onclick="deleteData(`'. route('produk.destroy', $produk->id_produk) .'`)" class="btn btn-danger" title="Hapus"><i class="fa fa-trash"></i></button>';
                
                if ($produk->stok <= 1) {
                    $buttons .= '<button type="button" onclick="beliProduk('. $produk->id_produk .')" class="btn btn-warning" title="Beli Sekarang"><i class="fa fa-cart-plus"></i></button>';
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
        
        if (!$produk) {
            return response()->json('Produk tidak ditemukan', 404);
        }

        $stok_lama = $produk->stok;
        $stok_baru = isset($request->stok) ? intval($request->stok) : $stok_lama;

        $produk->update($request->all());

        if ($stok_baru !== $stok_lama) {
            $this->ensureProdukHasRekamanStok($produk);
            $this->sinkronisasiStokProduk($produk, 'Perubahan Stok Manual via Edit Produk');
        }

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

        $this->ensureProdukHasRekamanStok($produk);

        $stok_lama = $produk->stok;
        $stok_baru = $request->stok;
        $selisih_stok = $stok_baru - $stok_lama;

        $produk->stok = $stok_baru;
        $produk->save();

        $keteranganFinal = 'Perubahan Stok Manual';
        if (!empty($request->keterangan)) {
            $keteranganFinal = 'Perubahan Stok Manual: ' . $request->keterangan;
        }

        $this->sinkronisasiStokProduk($produk, $keteranganFinal);

        return response()->json([
            'success' => true,
            'message' => 'Stok berhasil diperbarui dan disinkronkan',
            'data' => [
                'stok_lama' => $stok_lama,
                'stok_baru' => $stok_baru,
                'selisih' => $selisih_stok
            ]
        ], 200);
    }

    private function sinkronisasiStokProduk($produk, $keterangan = 'Update stok manual')
    {
        try {
            $currentTime = Carbon::now();
            
            // Ambil rekaman stok terakhir untuk produk ini
            $latestRekaman = DB::table('rekaman_stoks')
                ->where('id_produk', $produk->id_produk)
                ->orderBy('waktu', 'desc')
                ->orderBy('id_rekaman_stok', 'desc')
                ->first();
            
            $stokProduk = intval($produk->stok);
            $stokSisaTerakhir = $latestRekaman ? intval($latestRekaman->stok_sisa) : 0;
            
            // Hanya buat rekaman baru jika ada perbedaan stok
            if ($stokProduk !== $stokSisaTerakhir) {
                $selisih = $stokProduk - $stokSisaTerakhir;
                
                DB::table('rekaman_stoks')->insert([
                    'id_produk' => $produk->id_produk,
                    'waktu' => $currentTime,
                    'stok_awal' => $stokSisaTerakhir,
                    'stok_masuk' => $selisih > 0 ? $selisih : 0,
                    'stok_keluar' => $selisih < 0 ? abs($selisih) : 0,
                    'stok_sisa' => $stokProduk,
                    'keterangan' => $keterangan,
                    'created_at' => $currentTime,
                    'updated_at' => $currentTime
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error sinkronisasi stok produk: ' . $e->getMessage());
        }
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
            $supplier = \App\Models\Supplier::orderBy('nama')->first();
            $supplierId = $supplier ? $supplier->id_supplier : null;
        }

        if (!$supplierId) {
            return response()->json(['error' => 'Tidak ada supplier yang tersedia'], 400);
        }

        $url = route('pembelian.create', $supplierId);
        return response()->json(['redirect' => $url]);
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
}
