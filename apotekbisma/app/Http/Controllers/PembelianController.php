<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Pembelian;
use App\Models\PembelianDetail;
use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\Supplier;
use App\Models\Setting;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade as PDF;
use Illuminate\Support\Facades\Log;
use App\Services\StockDraftCleanupService;
use App\Services\TransactionDateMutationService;

class PembelianController extends Controller
{
    public function index(Request $request)
    {
        $supplier = Supplier::select('id_supplier', 'nama')->orderBy('nama')->get();
        $products = Produk::select('id_produk', 'nama_produk', 'kode_produk')
            ->orderBy('nama_produk', 'asc')
            ->get();

        $filterDefaults = [
            'arrival_date_preset' => $request->get('arrival_date_preset', 'all'),
            'arrival_start_date' => $request->get('arrival_start_date'),
            'arrival_end_date' => $request->get('arrival_end_date'),
            'invoice_start_datetime' => $request->get('invoice_start_datetime'),
            'invoice_end_datetime' => $request->get('invoice_end_datetime'),
            'id_pembelian' => $request->get('id_pembelian', ''),
            'no_faktur' => $request->get('no_faktur', ''),
            'id_supplier' => $request->get('id_supplier'),
            'id_produk' => $request->get('id_produk'),
            'search_text' => $request->get('search_text', ''),
        ];

        return view('pembelian.index', compact('supplier', 'products', 'filterDefaults'));
    }

    public function data(Request $request)
    {
        $pembelian = Pembelian::query()
            ->leftJoin('supplier as supplier_ref', 'supplier_ref.id_supplier', '=', 'pembelian.id_supplier')
            ->select('pembelian.*', 'supplier_ref.nama as supplier_nama')
            ->withCount([
                'detail',
                'detail as incomplete_detail_count' => function ($query) {
                    $query->where('jumlah', '<=', 0);
                },
            ])
            ->orderBy('pembelian.id_pembelian', 'desc');

        [$arrivalStartDate, $arrivalEndDate] = $this->resolveDateRange(
            $request->input('arrival_date_preset', 'all'),
            $request->input('arrival_start_date'),
            $request->input('arrival_end_date')
        );

        $arrivalDateExpression = $this->getPembelianArrivalDateSqlExpression();

        if ($arrivalStartDate && $arrivalEndDate) {
            $pembelian->whereRaw('DATE(' . $arrivalDateExpression . ') >= ?', [$arrivalStartDate])
                ->whereRaw('DATE(' . $arrivalDateExpression . ') <= ?', [$arrivalEndDate]);
        }

        $invoiceStartDateTime = $this->parseDateTimeInput($request->input('invoice_start_datetime'), true);
        $invoiceEndDateTime = $this->parseDateTimeInput($request->input('invoice_end_datetime'), false);

        if ($invoiceStartDateTime) {
            $pembelian->whereRaw('COALESCE(pembelian.waktu, pembelian.created_at) >= ?', [$invoiceStartDateTime]);
        }

        if ($invoiceEndDateTime) {
            $pembelian->whereRaw('COALESCE(pembelian.waktu, pembelian.created_at) <= ?', [$invoiceEndDateTime]);
        }

        $supplierIds = $this->normalizeIdFilter($request->input('id_supplier'));
        if ($supplierIds->isNotEmpty()) {
            $pembelian->whereIn('pembelian.id_supplier', $supplierIds->all());
        }

        $productIds = $this->normalizeIdFilter($request->input('id_produk'));
        if ($productIds->isNotEmpty()) {
            $pembelian->whereHas('detail', function ($query) use ($productIds) {
                $query->whereIn('id_produk', $productIds->all());
            });
        }

        $purchaseIds = $this->normalizeIdFilter($request->input('id_pembelian'));
        if ($purchaseIds->isNotEmpty()) {
            $pembelian->whereIn('pembelian.id_pembelian', $purchaseIds->all());
        }

        $invoiceFilters = $this->normalizeTextFilter($request->input('no_faktur'));
        if ($invoiceFilters->isNotEmpty()) {
            $pembelian->where(function ($query) use ($invoiceFilters) {
                foreach ($invoiceFilters as $invoiceValue) {
                    $query->orWhere('pembelian.no_faktur', 'like', '%' . $this->escapeLikeValue($invoiceValue) . '%');
                }
            });
        }

        return datatables()
            ->eloquent($pembelian)
            ->filter(function ($query) use ($request) {
                $searchValue = trim((string) data_get($request->input('search'), 'value', ''));
                if ($searchValue === '') {
                    return;
                }

                $likeValue = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchValue) . '%';

                $query->where(function ($builder) use ($searchValue, $likeValue) {
                    $builder->where('pembelian.no_faktur', 'like', $likeValue)
                        ->orWhere('supplier_ref.nama', 'like', $likeValue)
                        ->orWhereHas('detail.produk', function ($productQuery) use ($likeValue) {
                            $productQuery->where('produk.nama_produk', 'like', $likeValue)
                                ->orWhere('produk.kode_produk', 'like', $likeValue);
                        });

                    if (is_numeric($searchValue)) {
                        $builder->orWhere('pembelian.id_pembelian', intval($searchValue))
                            ->orWhere('pembelian.total_item', intval($searchValue));
                    }
                });
            }, true)
            ->addIndexColumn()
            ->addColumn('id_pembelian', function ($pembelian) {
                return intval($pembelian->id_pembelian);
            })
            ->addColumn('no_faktur', function ($pembelian) {
                return trim((string) ($pembelian->no_faktur ?? '')) !== '' ? $pembelian->no_faktur : '-';
            })
            ->addColumn('total_item', function ($pembelian) {
                return format_uang($pembelian->total_item ?? 0);
            })
            ->addColumn('total_harga', function ($pembelian) {
                $totalHarga = 'Rp. '. format_uang($pembelian->total_harga ?? 0);

                if ($this->isPembelianIncomplete($pembelian)) {
                    $totalHarga .= ' <span class="label label-warning">Belum Selesai</span>';
                } else {
                    $totalHarga .= ' <span class="label label-success">Selesai</span>';
                }
                
                return $totalHarga;
            })
            ->addColumn('bayar', function ($pembelian) {
                return 'Rp. '. format_uang($pembelian->bayar ?? 0);
            })
            ->addColumn('tanggal', function ($pembelian) {
                return tanggal_indonesia($this->resolvePembelianArrivalDisplayWaktu($pembelian), false);
            })
            ->addColumn('waktu', function ($pembelian) {
                return tanggal_indonesia(($pembelian->waktu != NULL ? $pembelian->waktu : $pembelian->created_at), false);
            })
            ->addColumn('supplier', function ($pembelian) {
                return $pembelian->supplier_nama ?: 'N/A';
            })
            ->editColumn('diskon', function ($pembelian) {
                return ($pembelian->diskon ?? 0) . '%';
            })
            ->addColumn('aksi', function ($pembelian) {
                $isIncomplete = $this->isPembelianIncomplete($pembelian);
                $isCompleted = !$isIncomplete;
                $hasDetail = intval($pembelian->detail_count ?? 0) > 0;
                
                $buttons = '
                <div class="btn-group">
                    <button onclick="showDetail(`'. route('pembelian.show', $pembelian->id_pembelian) .'`)" class="btn btn-xs btn-info btn-flat" title="Lihat Detail"><i class="fa fa-eye"></i></button>';
                
                // Tambahkan button berdasarkan status transaksi
                if ($isIncomplete) {
                    $buttons .= '
                    <button onclick="lanjutkanTransaksi('. $pembelian->id_pembelian .')" class="btn btn-xs btn-warning btn-flat" title="Lanjutkan Transaksi" data-toggle="tooltip"><i class="fa fa-play"></i></button>';
                } elseif ($isCompleted) {
                    $buttons .= '
                    <button onclick="editTransaksi('. $pembelian->id_pembelian .')" class="btn btn-xs btn-success btn-flat" title="Edit Transaksi" data-toggle="tooltip"><i class="fa fa-edit"></i></button>';
                }
                
                // Tambahkan tombol print untuk semua transaksi yang memiliki detail dan sudah selesai
                if ($hasDetail && $isCompleted) {
                    $buttons .= '
                    <button onclick="printReceipt('. $pembelian->id_pembelian .')" class="btn btn-xs btn-primary btn-flat" title="Cetak Bukti" data-toggle="tooltip"><i class="fa fa-print"></i></button>';
                }
                
                $buttons .= '
                    <button onclick="deleteData(`'. route('pembelian.destroy', $pembelian->id_pembelian) .'`)" class="btn btn-xs btn-danger btn-flat" title="Hapus Transaksi"><i class="fa fa-trash"></i></button>
                </div>';
                
                return $buttons;
            })
            ->orderColumn('supplier', 'supplier_ref.nama $1')
            ->orderColumn('tanggal', function ($query, $direction) {
                $query->orderByRaw($this->getPembelianArrivalDateSqlExpression() . ' ' . $direction);
            })
            ->orderColumn('waktu', function ($query, $direction) {
                $query->orderByRaw('COALESCE(pembelian.waktu, pembelian.created_at) ' . $direction);
            })
            ->rawColumns(['aksi', 'total_harga'])
            ->make(true);
    }

    public function create($id = null)
    {
        // Jika ada ID, berarti ini untuk lanjutkan/edit transaksi
        if ($id && request('continue') === 'true') {
            $pembelian = Pembelian::find($id);
            if ($pembelian) {
                session(['id_pembelian' => $pembelian->id_pembelian]);
                session(['id_supplier' => $pembelian->id_supplier]);
                return redirect()->route('pembelian_detail.index');
            }
        }

        // Jika ini adalah transaksi baru (dipanggil dari pilih supplier)
        // Hapus session lama untuk memastikan transaksi baru yang bersih
        if ($id && is_numeric($id)) {
            // Clear any existing session data
            session()->forget(['id_pembelian', 'id_supplier']);
            
            // Cleanup transaksi incomplete yang mungkin tersisa dari session sebelumnya
            app(StockDraftCleanupService::class)->cleanupStalePembelianDrafts();
            
            // Hanya buat record baru jika supplier dipilih untuk transaksi baru
            $pembelian = new Pembelian();
            $pembelian->id_supplier = $id;
            $pembelian->total_item  = 0;
            $pembelian->total_harga = 0;
            $pembelian->diskon      = 0;
            $pembelian->bayar       = 0;
            $pembelian->waktu       = Carbon::now();
            $pembelian->waktu_datang = Carbon::now();
            $pembelian->no_faktur   = 'o'; // Temporary value to indicate incomplete transaction
            $pembelian->save();

            session(['id_pembelian' => $pembelian->id_pembelian]);
            session(['id_supplier' => $pembelian->id_supplier]);

            return redirect()->route('pembelian_detail.index');
        }

        // Redirect back jika tidak ada ID supplier
        return redirect()->route('pembelian.index')->with('error', 'Silakan pilih supplier terlebih dahulu.');
    }

    /**
     * Lanjutkan transaksi pembelian yang sudah ada
     */
    public function lanjutkanTransaksi($id)
    {
        $pembelian = Pembelian::find($id);
        if ($pembelian) {
            session(['id_pembelian' => $pembelian->id_pembelian]);
            session(['id_supplier' => $pembelian->id_supplier]);
            return redirect()->route('pembelian_detail.index');
        }
        
        return redirect()->route('pembelian.index')->with('error', 'Transaksi tidak ditemukan.');
    }

    public function store(Request $request)
    {
        // Validasi server-side
        $request->validate([
            'nomor_faktur' => 'required|string|max:255',
            'total_item' => 'required|integer|min:1',
            'total' => 'required|numeric|min:0',
            'waktu' => 'required'
        ], [
            'nomor_faktur.required' => 'Nomor faktur harus diisi',
            'nomor_faktur.max' => 'Nomor faktur maksimal 255 karakter',
            'total_item.required' => 'Minimal harus ada 1 produk',
            'total_item.min' => 'Minimal harus ada 1 produk',
            'total.required' => 'Total harga harus diisi',
            'total.min' => 'Total harga tidak boleh negatif',
            'waktu.required' => 'Tanggal faktur harus diisi'
        ]);

        DB::beginTransaction();

        try {
            $pembelian = Pembelian::findOrFail($request->id_pembelian);
            
            $detail = PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)->get();
            if ($detail->isEmpty()) {
                DB::rollBack();
                return redirect()->back()->with('error', 'Tidak dapat menyimpan transaksi tanpa produk');
            }

            $hasZeroQuantity = $detail->where('jumlah', '<=', 0)->count() > 0;
            if ($hasZeroQuantity) {
                DB::rollBack();
                return redirect()->back()->with('error', 'Semua produk harus memiliki jumlah lebih dari 0');
            }

            $duplicateCheck = Pembelian::where('no_faktur', $request->nomor_faktur)
                                       ->where('id_pembelian', '!=', $pembelian->id_pembelian)
                                       ->exists();
            if ($duplicateCheck) {
                DB::rollBack();
                return redirect()->back()->with('error', 'Nomor faktur sudah digunakan untuk transaksi lain');
            }

            $pembelian->total_item = $request->total_item;
            $pembelian->total_harga = $request->total;
            $pembelian->diskon = $request->diskon ?? 0;
            $pembelian->bayar = $request->bayar;
            $resolvedInvoiceWaktu = $this->resolveTransactionWaktu(
                $request->waktu,
                $pembelian->waktu ?? $pembelian->created_at ?? Carbon::now()
            );
            $pembelian->waktu = $resolvedInvoiceWaktu;
            $pembelian->waktu_datang = $resolvedInvoiceWaktu;
            $pembelian->no_faktur = $request->nomor_faktur;
            $pembelian->update();
            
            $id_pembelian = $request->id_pembelian;
            
            foreach ($detail as $item) {
                $produk = Produk::find($item->id_produk);
                $existing_rekaman = RekamanStok::where('id_pembelian', $id_pembelian)
                    ->where('id_produk', $item->id_produk)
                    ->first();
                
                if (!$existing_rekaman && $produk) {
                    RekamanStok::create([
                        'id_produk' => $item->id_produk,
                        'waktu' => $this->resolvePembelianStockWaktu($pembelian),
                        'stok_masuk' => $item->jumlah,
                        'id_pembelian' => $id_pembelian,
                        'stok_awal' => $produk->stok - $item->jumlah,
                        'stok_sisa' => $produk->stok,
                    ]);
                }
            }

            try {
                app(TransactionDateMutationService::class)->synchronizeFinalizedPembelian($pembelian);
            } catch (\Throwable $syncException) {
                Log::warning('Sinkronisasi finalized pembelian gagal, fallback ke recalculate per produk', [
                    'id_pembelian' => $pembelian->id_pembelian,
                    'message' => $syncException->getMessage(),
                ]);

                $affectedProductIds = $detail->pluck('id_produk')
                    ->map(function ($id) {
                        return intval($id);
                    })
                    ->filter(function ($id) {
                        return $id > 0;
                    })
                    ->unique()
                    ->values();

                foreach ($affectedProductIds as $produkId) {
                    try {
                        RekamanStok::recalculateStock($produkId);
                    } catch (\Throwable $recalcException) {
                        Log::warning('Fallback recalculate pembelian gagal', [
                            'id_pembelian' => $pembelian->id_pembelian,
                            'id_produk' => $produkId,
                            'message' => $recalcException->getMessage(),
                        ]);
                    }
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }
        
        // Hapus session setelah transaksi selesai
        session()->forget('id_pembelian');
        session()->forget('id_supplier');
        
        return redirect()->route('pembelian.index')->with('success', 'Transaksi pembelian berhasil disimpan');
    }
    public function update(Request $request, $id)
    {
        $request->validate([
            'nomor_faktur' => 'required|string|max:255',
            'total_item' => 'required|integer|min:1',
            'total' => 'required|numeric|min:0',
            'waktu' => 'required'
        ], [
            'nomor_faktur.required' => 'Nomor faktur harus diisi',
            'nomor_faktur.max' => 'Nomor faktur maksimal 255 karakter',
            'total_item.required' => 'Minimal harus ada 1 produk',
            'total_item.min' => 'Minimal harus ada 1 produk',
            'total.required' => 'Total harga harus diisi',
            'total.min' => 'Total harga tidak boleh negatif',
            'waktu.required' => 'Tanggal faktur harus diisi'
        ]);

        DB::beginTransaction();
        
        try {
            $pembelian = Pembelian::findOrFail($request->id_pembelian);
            $waktuLama = $this->resolvePembelianStockWaktu($pembelian);
            
            $detail = PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)->get();
            if ($detail->isEmpty()) {
                DB::rollBack();
                return redirect()->back()->with('error', 'Tidak dapat menyimpan transaksi tanpa produk');
            }

            $hasZeroQuantity = $detail->where('jumlah', '<=', 0)->count() > 0;
            if ($hasZeroQuantity) {
                DB::rollBack();
                return redirect()->back()->with('error', 'Semua produk harus memiliki jumlah lebih dari 0');
            }

            $duplicateCheck = Pembelian::where('no_faktur', $request->nomor_faktur)
                                       ->where('id_pembelian', '!=', $pembelian->id_pembelian)
                                       ->exists();
            if ($duplicateCheck) {
                DB::rollBack();
                return redirect()->back()->with('error', 'Nomor faktur sudah digunakan untuk transaksi lain');
            }

            $pembelian->total_item = $request->total_item;
            $pembelian->total_harga = $request->total;
            $pembelian->diskon = $request->diskon ?? 0;
            $pembelian->bayar = $request->bayar;
            $pembelian->no_faktur = $request->nomor_faktur;
            if ($request->waktu != NULL) {
                $resolvedInvoiceWaktu = $this->resolveTransactionWaktu(
                    $request->waktu,
                    $pembelian->waktu ?? $pembelian->created_at ?? Carbon::now()
                );
                $pembelian->waktu = $resolvedInvoiceWaktu;
                $pembelian->waktu_datang = $resolvedInvoiceWaktu;
            }
            
            $pembelian->update();

            app(TransactionDateMutationService::class)->handlePembelianFinalDateChange(
                $pembelian,
                $waktuLama,
                $this->resolvePembelianStockWaktu($pembelian)
            );
            
            DB::commit();
            
            session()->forget('id_pembelian');
            session()->forget('id_supplier');
            
            return redirect()->route('pembelian.index')->with('success', 'Transaksi pembelian berhasil diperbarui');
            
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $detail = PembelianDetail::with('produk')
            ->where('id_pembelian', $id)
            ->join('produk', 'pembelian_detail.id_produk', '=', 'produk.id_produk')
            ->orderBy('produk.nama_produk', 'asc')
            ->select('pembelian_detail.*')
            ->get();

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
        DB::beginTransaction();
        
        try {
            $pembelian = Pembelian::find($id);
            
            if (!$pembelian) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Pembelian tidak ditemukan'], 404);
            }

            $detail = PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)->get();
            $affectedProductIds = [];
            
            foreach ($detail as $item) {
                $produk = Produk::where('id_produk', $item->id_produk)
                    ->lockForUpdate()
                    ->first();
                if ($produk) {
                    $affectedProductIds[] = $produk->id_produk;
                    $stokSebelum = $produk->stok;
                    $stokBaru = $stokSebelum - $item->jumlah;
                    $produk->stok = $stokBaru;
                    $produk->save();
                    
                    RekamanStok::create([
                        'id_produk' => $item->id_produk,
                        'waktu' => now(),
                        'stok_keluar' => $item->jumlah,
                        'stok_awal' => $stokSebelum,
                        'stok_sisa' => $stokBaru,
                        'keterangan' => 'Penghapusan transaksi pembelian: Pengurangan stok'
                    ]);
                }
                
                $item->delete();
            }

            RekamanStok::where('id_pembelian', $pembelian->id_pembelian)->delete();

            $pembelian->delete();

            foreach (array_unique($affectedProductIds) as $produkId) {
                RekamanStok::recalculateStock($produkId);
            }

            DB::commit();
            
            return response()->json(['success' => true, 'message' => 'Pembelian berhasil dihapus dan stok disesuaikan'], 200);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function cleanupIncompleteTransactions()
    {
        $summary = app(StockDraftCleanupService::class)->cleanupStalePembelianDrafts(session('id_pembelian'));

        return response()->json([
            'message' => 'Cleanup completed',
            'summary' => $summary,
        ]);
    }

    public function cancelTransaction($id)
    {
        DB::beginTransaction();

        try {
            $pembelian = Pembelian::where('id_pembelian', $id)
                ->lockForUpdate()
                ->first();

            $deleted = false;

            if ($pembelian && $this->isPembelianIncomplete($pembelian)) {
                $details = PembelianDetail::where('id_pembelian', $id)
                    ->lockForUpdate()
                    ->get();

                $groupedDetails = [];
                foreach ($details as $detail) {
                    $qty = max(0, intval($detail->jumlah ?? 0));
                    if ($qty === 0) {
                        continue;
                    }

                    $productId = intval($detail->id_produk);
                    $groupedDetails[$productId] = ($groupedDetails[$productId] ?? 0) + $qty;
                }

                foreach ($groupedDetails as $productId => $qty) {
                    $produk = Produk::where('id_produk', $productId)
                        ->lockForUpdate()
                        ->first();

                    if (!$produk || intval($produk->stok) < $qty) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => 'Draft pembelian tidak bisa dibatalkan otomatis karena stok produk sudah berubah. Silakan sinkronisasi dulu lalu coba lagi.',
                        ], 409);
                    }
                }

                foreach ($groupedDetails as $productId => $qty) {
                    $produk = Produk::where('id_produk', $productId)
                        ->lockForUpdate()
                        ->first();

                    DB::table('produk')
                        ->where('id_produk', $productId)
                        ->update([
                            'stok' => intval($produk->stok) - $qty,
                            'updated_at' => now(),
                        ]);
                }

                RekamanStok::where('id_pembelian', $id)->delete();
                PembelianDetail::where('id_pembelian', $id)->delete();
                Pembelian::where('id_pembelian', $id)->delete();
                $deleted = true;
            }

            DB::commit();

            if (intval(session('id_pembelian')) === intval($id)) {
                session()->forget(['id_pembelian', 'id_supplier']);
            }

            return response()->json([
                'success' => true,
                'deleted' => $deleted,
                'message' => $deleted
                    ? 'Draft pembelian dibatalkan dan dihapus.'
                    : 'Edit pembelian dibatalkan. Tidak ada draft baru yang dihapus.',
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Cancel pembelian transaction gagal: ' . $e->getMessage(), [
                'id_pembelian' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat membatalkan transaksi.',
            ], 500);
        }
    }

    public function destroyEmpty($id)
    {
        $pembelian = Pembelian::where('id_pembelian', $id)->first();
        
        if (!$pembelian) {
            return response()->json(['error' => 'Pembelian tidak ditemukan'], 404);
        }

        // Hanya hapus jika transaksi benar-benar kosong atau belum selesai
        $pembelian_detail = PembelianDetail::where('id_pembelian', $id)->get();
        $isEmpty = ($pembelian->no_faktur === 'o' || $pembelian->no_faktur === '' || $pembelian->no_faktur === null) &&
                   $pembelian->total_harga == 0;
        
        if ($isEmpty) {
            // Hapus detail dan rekaman stok jika ada
            foreach ($pembelian_detail as $detail) {
                $rekaman_stok = RekamanStok::where('id_pembelian', $id)
                                           ->where('id_produk', $detail->id_produk)
                                           ->first();
                if ($rekaman_stok) {
                    $produk = Produk::find($detail->id_produk);
                    if ($produk) {
                        $produk->stok -= $rekaman_stok->stok_masuk;
                        $produk->update();
                    }
                    $rekaman_stok->delete();
                }
                $detail->delete();
            }
            
            $pembelian->delete();
            
            // Hapus session terkait
            session()->forget('id_pembelian');
            session()->forget('id_supplier');
            
            return response()->json(['message' => 'Empty transaction deleted']);
        }

        return response()->json(['message' => 'Transaction not empty, not deleted']);
    }

    public function notaKecil()
    {
        $setting = Setting::first();
        $pembelian = Pembelian::find(session('id_pembelian'));
        if (! $pembelian) {
            abort(404);
        }
        $detail = PembelianDetail::with('produk')
            ->where('id_pembelian', session('id_pembelian'))
            ->join('produk', 'pembelian_detail.id_produk', '=', 'produk.id_produk')
            ->orderBy('produk.nama_produk', 'asc')
            ->select('pembelian_detail.*')
            ->get();
        
        return view('pembelian.nota_kecil', compact('setting', 'pembelian', 'detail'));
    }

    public function notaBesar()
    {
        $setting = Setting::first();
        $pembelian = Pembelian::find(session('id_pembelian'));
        if (! $pembelian) {
            abort(404);
        }
        $detail = PembelianDetail::with('produk')
            ->where('id_pembelian', session('id_pembelian'))
            ->join('produk', 'pembelian_detail.id_produk', '=', 'produk.id_produk')
            ->orderBy('produk.nama_produk', 'asc')
            ->select('pembelian_detail.*')
            ->get();

        $pdf = PDF::loadView('pembelian.nota_besar', compact('setting', 'pembelian', 'detail'));
        $pdf->setPaper('a4', 'portrait');
        return $pdf->stream('Bukti-Pembelian-'. date('Y-m-d-his') .'.pdf');
    }

    public function printReceipt($id)
    {
        $setting = Setting::first();
        $pembelian = Pembelian::with('supplier')->find($id);
        if (! $pembelian) {
            abort(404);
        }
        $detail = PembelianDetail::with('produk')
            ->where('id_pembelian', $id)
            ->join('produk', 'pembelian_detail.id_produk', '=', 'produk.id_produk')
            ->orderBy('produk.nama_produk', 'asc')
            ->select('pembelian_detail.*')
            ->get();

        // Cek tipe nota dari setting
        if ($setting->tipe_nota == 1) {
            // Nota Kecil
            return view('pembelian.nota_kecil', compact('setting', 'pembelian', 'detail'));
        } else {
            // Nota Besar (PDF)
            $pdf = PDF::loadView('pembelian.nota_besar', compact('setting', 'pembelian', 'detail'));
            $pdf->setPaper('a4', 'portrait');
            return $pdf->stream('Bukti-Pembelian-'. $pembelian->id_pembelian .'-'. date('Y-m-d-His') .'.pdf');
        }
    }

    private function isPembelianIncomplete($pembelian): bool
    {
        $hasDetail = isset($pembelian->detail_count)
            ? intval($pembelian->detail_count) > 0
            : PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)->exists();

        $hasIncompleteDetail = isset($pembelian->incomplete_detail_count)
            ? intval($pembelian->incomplete_detail_count) > 0
            : PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)
                ->where('jumlah', '<=', 0)
                ->exists();

        return !$hasDetail
            || $hasIncompleteDetail
            || intval($pembelian->total_harga ?? 0) <= 0
            || intval($pembelian->bayar ?? 0) <= 0
            || $pembelian->no_faktur === 'o'
            || $pembelian->no_faktur === ''
            || $pembelian->no_faktur === null;
    }

    private function resolveDateRange(string $preset, ?string $startDate, ?string $endDate): array
    {
        $today = Carbon::today();
        $start = null;
        $end = null;

        switch ($preset) {
            case 'today':
                $start = $today->copy();
                $end = $today->copy();
                break;
            case 'yesterday':
                $start = $today->copy()->subDay();
                $end = $today->copy()->subDay();
                break;
            case 'last_7_days':
                $start = $today->copy()->subDays(6);
                $end = $today->copy();
                break;
            case 'last_30_days':
                $start = $today->copy()->subDays(29);
                $end = $today->copy();
                break;
            case 'this_week':
                $start = $today->copy()->startOfWeek(Carbon::MONDAY);
                $end = $today->copy()->endOfWeek(Carbon::SUNDAY);
                break;
            case 'last_week':
                $start = $today->copy()->subWeek()->startOfWeek(Carbon::MONDAY);
                $end = $today->copy()->subWeek()->endOfWeek(Carbon::SUNDAY);
                break;
            case 'this_month':
                $start = $today->copy()->startOfMonth();
                $end = $today->copy()->endOfMonth();
                break;
            case 'last_month':
                $start = $today->copy()->subMonthNoOverflow()->startOfMonth();
                $end = $today->copy()->subMonthNoOverflow()->endOfMonth();
                break;
            case 'this_year':
                $start = $today->copy()->startOfYear();
                $end = $today->copy()->endOfYear();
                break;
            case 'custom':
            case 'all':
            default:
                break;
        }

        if ($preset === 'custom') {
            $parsedStart = $this->parseDateInput($startDate);
            $parsedEnd = $this->parseDateInput($endDate);

            if ($parsedStart) {
                $start = $parsedStart;
            }

            if ($parsedEnd) {
                $end = $parsedEnd;
            }

            if ($start && !$end) {
                $end = $start->copy();
            }

            if ($end && !$start) {
                $start = $end->copy();
            }

            if ($start && $end && $start->greaterThan($end)) {
                [$start, $end] = [$end, $start];
            }
        }

        return [
            $start?->toDateString(),
            $end?->toDateString(),
        ];
    }

    private function parseDateInput(?string $date): ?Carbon
    {
        if (!$date) {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat('Y-m-d', $date);
            return $parsed && $parsed->format('Y-m-d') === $date ? $parsed : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function parseDateTimeInput(?string $dateTime, bool $isStart): ?string
    {
        if (!$dateTime) {
            return null;
        }

        $raw = trim($dateTime);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $parsed = Carbon::createFromFormat('Y-m-d', $raw);
            return ($isStart ? $parsed->startOfDay() : $parsed->endOfDay())->format('Y-m-d H:i:s');
        }

        foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $raw);
                if (in_array($format, ['Y-m-d\TH:i', 'Y-m-d H:i'], true) && !$isStart) {
                    $parsed->second = 59;
                }

                return $parsed->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
            }
        }

        try {
            return Carbon::parse($raw)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeIdFilter($rawValue)
    {
        return collect(is_array($rawValue) ? $rawValue : [$rawValue])
            ->flatMap(function ($value) {
                return explode(',', (string) $value);
            })
            ->map(function ($value) {
                return trim((string) $value);
            })
            ->filter(function ($value) {
                return $value !== '' && is_numeric($value) && (int) $value > 0;
            })
            ->map(function ($value) {
                return (int) $value;
            })
            ->unique()
            ->values();
    }

    private function normalizeTextFilter($rawValue)
    {
        return collect(is_array($rawValue) ? $rawValue : [$rawValue])
            ->flatMap(function ($value) {
                return preg_split('/[\r\n,]+/', (string) $value) ?: [];
            })
            ->map(function ($value) {
                return trim((string) $value);
            })
            ->filter(function ($value) {
                return $value !== '';
            })
            ->unique()
            ->values();
    }

    private function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function getPembelianArrivalDateSqlExpression(): string
    {
        return 'COALESCE(pembelian.waktu_datang, pembelian.waktu, pembelian.created_at)';
    }

    private function resolvePembelianArrivalDisplayWaktu(Pembelian $pembelian): string
    {
        $candidate = $pembelian->waktu_datang
            ?? $pembelian->waktu
            ?? $pembelian->created_at
            ?? Carbon::now();

        return Carbon::parse($candidate)->format('Y-m-d H:i:s');
    }

    private function resolvePembelianStockWaktu(Pembelian $pembelian): string
    {
        return $this->resolvePembelianArrivalDisplayWaktu($pembelian);
    }

    private function resolveTransactionWaktu($value, $fallback = null): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            throw new \InvalidArgumentException('Tanggal faktur harus diisi');
        }

        $fallbackCarbon = $fallback ? Carbon::parse($fallback) : Carbon::now();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return Carbon::createFromFormat('Y-m-d', $raw)
                ->setTimeFrom($fallbackCarbon)
                ->format('Y-m-d H:i:s');
        }

        foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $raw);
                if (in_array($format, ['Y-m-d\TH:i', 'Y-m-d H:i'], true)) {
                    $parsed->second = $fallbackCarbon->second;
                }

                return $parsed->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
            }
        }

        return Carbon::parse($raw)->format('Y-m-d H:i:s');
    }
}

