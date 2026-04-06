<?php

namespace App\Http\Controllers;

use App\Imports\ObatImport;
use App\Models\Kategori;
use App\Models\PembelianDetail;
use App\Models\RekamanStok;
use App\Exceptions\UnsafeStockMutationException;
use Illuminate\Http\Request;
use App\Models\Produk;
use Barryvdh\DomPDF\Facade as PDF;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

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
        if ($produk) {
            $produk->update([
                'harga_jual' => $request->harga_jual
            ]);
        }

        return response()->json(['success' => true]);
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
        if ($produk) {
            $produk->update([
                'harga_beli' => $request->harga_beli
            ]);
        }

        $detail = PembelianDetail::where('id_pembelian_detail', $request->id_pembayaran_detail)->first();
        if ($detail) {
            $hargaBeli = (int) ($produk ? $produk->harga_beli : $request->harga_beli);
            $jumlah = (int) ($detail->jumlah ?? $request->jumlah ?? 0);

            $detail->update([
                'harga_beli' => $hargaBeli,
                'subtotal' => $hargaBeli * $jumlah,
            ]);
        }

        return response()->json(['success' => true]);
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
                
                $buttons .= '<button type="button" onclick="editForm(`'. route('produk.update', $produk->id_produk, false) .'`)" class="btn btn-info" title="Edit Produk"><i class="fa fa-pencil"></i></button>';
                
                $buttons .= '<button type="button" onclick="updateStokManual('. $produk->id_produk .', \''. addslashes($produk->nama_produk) .'\', '. $produk->stok .')" class="btn btn-success" title="Update Stok"><i class="fa fa-cubes"></i></button>';
                
                $buttons .= '<a href="'. route('kartu_stok.detail', $produk->id_produk, false) .'" class="btn btn-primary" title="Kartu Stok" target="_blank"><i class="fa fa-list-alt"></i></a>';
                
                $buttons .= '<button type="button" onclick="deleteData(`'. route('produk.destroy', $produk->id_produk, false) .'`)" class="btn btn-danger" title="Hapus"><i class="fa fa-trash"></i></button>';
                
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

        $data = $request->except('keterangan_stok');
        $produk = Produk::create($data);

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
        $validated = $request->validate([
            'nama_produk' => ['required', 'string', 'max:255', Rule::unique('produk', 'nama_produk')->ignore($id, 'id_produk')],
            'id_kategori' => ['required', 'integer', 'exists:kategori,id_kategori'],
            'merk' => ['nullable', 'string', 'max:255'],
            'harga_beli' => ['required', 'integer', 'min:0'],
            'harga_jual' => ['required', 'integer', 'min:0'],
            'diskon' => ['nullable', 'integer', 'min:0', 'max:100'],
            'expired_date' => ['nullable', 'string', 'max:255'],
            'batch' => ['nullable', 'string', 'max:255'],
            'stok' => ['required', 'integer', 'min:0'],
            'keterangan_stok' => ['nullable', 'string', 'max:500'],
        ]);

        DB::beginTransaction();
        
        try {
            $produk = Produk::where('id_produk', $id)->lockForUpdate()->first();
            
            if (!$produk) {
                DB::rollBack();
                return response()->json('Produk tidak ditemukan', 404);
            }

            $stok_lama = intval($produk->stok);
            $stok_lama_otoritatif = $this->resolveAuthoritativeOldStock($produk->id_produk, $stok_lama);
            $stok_baru = intval($validated['stok']);

            if ($stok_baru !== $stok_lama_otoritatif && empty(trim((string) ($validated['keterangan_stok'] ?? '')))) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Keterangan perubahan stok wajib diisi jika stok diubah melalui Edit Produk.'
                ], 422);
            }

            $this->assertManualStockMutationAllowed(
                $produk->id_produk,
                $stok_lama_otoritatif,
                $stok_baru,
                trim((string) ($validated['keterangan_stok'] ?? ''))
            );

            $produk->fill([
                'nama_produk' => $validated['nama_produk'],
                'id_kategori' => $validated['id_kategori'],
                'merk' => $validated['merk'] ?? null,
                'harga_beli' => $validated['harga_beli'],
                'harga_jual' => $validated['harga_jual'],
                'diskon' => $validated['diskon'] ?? 0,
                'expired_date' => $validated['expired_date'] ?? null,
                'batch' => $validated['batch'] ?? null,
                'stok' => $stok_baru,
            ]);
            $produk->save();

            // Jika ada perubahan stok, buat Stock Opname record
            if ($stok_baru !== $stok_lama_otoritatif) {
                $this->createStockOpnameRecord(
                    $produk->id_produk, 
                    $stok_lama_otoritatif, 
                    $stok_baru, 
                    'Stock Opname via Edit Produk: ' . trim((string) $validated['keterangan_stok'])
                );
            }

            DB::commit();
            return response()->json('Data berhasil disimpan', 200);

        } catch (UnsafeStockMutationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error update produk: ' . $e->getMessage());
            return response()->json('Terjadi kesalahan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update stok produk secara manual dengan rekaman yang proper (Stock Opname)
     */
    public function updateStokManual(Request $request, $id)
    {
        $idempotencyKey = 'stok_manual_' . $id . '_' . auth()->id();
        
        if (Cache::has($idempotencyKey)) {
            return response()->json(['error' => true, 'message' => 'Request sedang diproses, mohon tunggu...'], 429);
        }
        
        Cache::put($idempotencyKey, true, 10);
        
        $request->validate([
            'stok' => 'required|integer|min:0',
            'keterangan' => 'nullable|string|max:500'
        ], [
            'stok.required' => 'Stok wajib diisi',
            'stok.integer' => 'Stok harus berupa angka',
            'stok.min' => 'Stok tidak boleh negatif',
            'keterangan.max' => 'Keterangan maksimal 500 karakter'
        ]);

        DB::beginTransaction();
        
        try {
            $produk = Produk::where('id_produk', $id)->lockForUpdate()->first();
            if (!$produk) {
                DB::rollBack();
                Cache::forget($idempotencyKey);
                return response()->json('Produk tidak ditemukan', 404);
            }

            $stok_lama = intval($produk->stok);
            $stok_lama_otoritatif = $this->resolveAuthoritativeOldStock($produk->id_produk, $stok_lama);
            $stok_baru = intval($request->stok);
            $selisih_stok = $stok_baru - $stok_lama_otoritatif;

            if ($selisih_stok == 0) {
                DB::commit();
                Cache::forget($idempotencyKey);
                return response()->json([
                    'success' => true,
                    'message' => 'Stok tidak berubah',
                    'data' => [
                        'stok_lama' => $stok_lama_otoritatif,
                        'stok_baru' => $stok_baru,
                        'selisih' => 0
                    ]
                ], 200);
            }

            $produk->stok = $stok_baru;
            $keteranganRaw = trim((string) $request->keterangan);

            $this->assertManualStockMutationAllowed(
                $produk->id_produk,
                $stok_lama_otoritatif,
                $stok_baru,
                $keteranganRaw
            );

            $produk->save();

            $keteranganFinal = 'Stock Opname (Penyesuaian Stok Manual)';
            if ($keteranganRaw !== '') {
                $keteranganFinal = 'Stock Opname: ' . $keteranganRaw;
            }

            $this->createStockOpnameRecord($produk->id_produk, $stok_lama_otoritatif, $stok_baru, $keteranganFinal);
            
            DB::commit();
            
            Cache::forget($idempotencyKey);

            // PENTING: Jangan panggil recalculateStock() setelah Stock Opname!
            // Stock Opname adalah "source of truth" yang mengoreksi stok ke nilai yang benar.
            // Memanggil recalculateStock() akan menghitung ulang dari rekaman dan menimpa nilai opname.

            return response()->json([
                'success' => true,
                'message' => 'Stok berhasil diperbarui dan disinkronkan',
                'data' => [
                    'stok_lama' => $stok_lama_otoritatif,
                    'stok_baru' => $stok_baru,
                    'selisih' => $selisih_stok
                ]
            ], 200);
        } catch (UnsafeStockMutationException $e) {
            DB::rollBack();
            Cache::forget($idempotencyKey);
            return response()->json([
                'error' => true,
                'message' => $e->getMessage(),
            ], 422);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Cache::forget($idempotencyKey);
            Log::error('Error updateStokManual: ' . $e->getMessage());
            return response()->json(['error' => true, 'message' => 'Terjadi kesalahan: ' . $e->getMessage()], 500);
        }
    }

    private function createStockOpnameRecord($idProduk, $stokLama, $stokBaru, $keterangan)
    {
        $currentTime = Carbon::now();
        $waktuWithMicro = $currentTime->format('Y-m-d H:i:s.u');
        $selisih = $stokBaru - $stokLama;
        
        RekamanStok::create([
            'id_produk' => $idProduk,
            'waktu' => $waktuWithMicro,
            'stok_awal' => $stokLama,
            'stok_masuk' => $selisih > 0 ? $selisih : 0,
            'stok_keluar' => $selisih < 0 ? abs($selisih) : 0,
            'stok_sisa' => $stokBaru,
            'keterangan' => $keterangan,
            'created_at' => $currentTime,
            'updated_at' => $currentTime
        ]);
    }

    private function resolveAuthoritativeOldStock(int $idProduk, int $fallbackStock): int
    {
        $latestRekaman = DB::table('rekaman_stoks')
            ->where('id_produk', $idProduk)
            ->orderBy('waktu', 'desc')
            ->orderBy('id_rekaman_stok', 'desc')
            ->lockForUpdate()
            ->first();

        if (!$latestRekaman) {
            return $fallbackStock;
        }

        return intval($latestRekaman->stok_sisa);
    }

    private function assertManualStockMutationAllowed(int $idProduk, int $stokLama, int $stokBaru, string $keterangan): void
    {
        if ($stokBaru === $stokLama) {
            return;
        }

        if (mb_strlen($keterangan) < 10) {
            throw new UnsafeStockMutationException('Keterangan stock opname minimal 10 karakter agar jejak audit jelas dan tidak ambigu.');
        }

        $blockingDrafts = $this->findBlockingDraftTransactionsForProduct($idProduk);
        if (!empty($blockingDrafts)) {
            throw new UnsafeStockMutationException('Stock opname manual diblokir karena produk ini masih terlibat pada draft transaksi: ' . implode(', ', $blockingDrafts) . '. Selesaikan atau hapus draft tersebut terlebih dahulu.');
        }
    }

    private function findBlockingDraftTransactionsForProduct(int $idProduk): array
    {
        $pembelianDrafts = DB::table('pembelian_detail as pd')
            ->join('pembelian as p', 'pd.id_pembelian', '=', 'p.id_pembelian')
            ->where('pd.id_produk', $idProduk)
            ->where('pd.jumlah', '>', 0)
            ->where(function ($query) {
                $query->where('p.no_faktur', 'o')
                    ->orWhere('p.no_faktur', '')
                    ->orWhereNull('p.no_faktur')
                    ->orWhere('p.total_harga', '<=', 0)
                    ->orWhere('p.bayar', '<=', 0);
            })
            ->orderBy('p.id_pembelian')
            ->distinct()
            ->limit(3)
            ->pluck('p.id_pembelian')
            ->map(function ($draftId) {
                return 'pembelian#' . intval($draftId);
            })
            ->all();

        $penjualanDrafts = DB::table('penjualan_detail as pd')
            ->join('penjualan as p', 'pd.id_penjualan', '=', 'p.id_penjualan')
            ->where('pd.id_produk', $idProduk)
            ->where('pd.jumlah', '>', 0)
            ->where(function ($query) {
                $query->where('p.total_item', '<=', 0)
                    ->orWhere('p.total_harga', '<=', 0)
                    ->orWhere('p.bayar', '<=', 0)
                    ->orWhere('p.diterima', '<=', 0);
            })
            ->orderBy('p.id_penjualan')
            ->distinct()
            ->limit(3)
            ->pluck('p.id_penjualan')
            ->map(function ($draftId) {
                return 'penjualan#' . intval($draftId);
            })
            ->all();

        return array_values(array_unique(array_merge($pembelianDrafts, $penjualanDrafts)));
    }

    private function sinkronisasiStokProduk($produk, $keterangan = 'Update stok manual')
    {
        try {
            $currentTime = Carbon::now();
            
            $latestRekaman = DB::table('rekaman_stoks')
                ->where('id_produk', $produk->id_produk)
                ->orderBy('waktu', 'desc')
                ->orderBy('id_rekaman_stok', 'desc')
                ->first();
            
            $stokProduk = intval($produk->stok);
            $stokSisaTerakhir = $latestRekaman ? intval($latestRekaman->stok_sisa) : 0;
            
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
                
                try {
                    RekamanStok::recalculateStock($produk->id_produk);
                } catch (\Exception $e) {
                    Log::warning('Recalculate stock warning: ' . $e->getMessage());
                }
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
