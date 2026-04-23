<?php

namespace App\Http\Controllers;

use App\Imports\ObatImport;
use App\Models\Kategori;
use App\Models\PembelianDetail;
use App\Models\RekamanStok;
use App\Exceptions\UnsafeStockMutationException;
use App\Services\StockRuntimeIntegrityService;
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
    private const AUTHORITATIVE_LOW_STOCK_THRESHOLD = 20;

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
        $recordsTotal = Produk::count();

        $query = Produk::leftJoin('kategori', 'kategori.id_kategori', 'produk.id_kategori')
            ->select('produk.*', 'nama_kategori');

        $this->applyProdukStockFilter($query, $request->input('filter_stok'));
        $this->applyProdukSearchFilters($query, $request);

        $recordsFiltered = (clone $query)->count();

        $this->applyProdukOrdering($query, $request);

        $start = max(0, intval($request->input('start', 0)));
        $length = intval($request->input('length', 10));
        if ($length >= 0) {
            $query->skip($start)->take($length);
        }

        $produk = $this->applyAuthoritativeDisplayStockOverlay($query->get());

        $data = [];
        foreach ($produk->values() as $index => $item) {
            $data[] = $this->buildProdukDataTableRow($item, $start + $index + 1);
        }

        return response()->json([
            'draw' => intval($request->input('draw', 0)),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
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

        if ($produk) {
            $authoritativeStockMap = app(StockRuntimeIntegrityService::class)
                ->previewCurrentSellableStockMap([intval($produk->id_produk)], null, true);
            if (isset($authoritativeStockMap[intval($produk->id_produk)])) {
                $rawCurrentStock = intval($authoritativeStockMap[intval($produk->id_produk)]['raw_current_stock'] ?? $produk->stok);
                $displayStock = intval($authoritativeStockMap[intval($produk->id_produk)]['display_stock'] ?? $produk->stok);
                $resolvedDisplayStock = $rawCurrentStock < 0 ? $rawCurrentStock : $displayStock;
                $produk->setRawAttributes(array_merge($produk->getAttributes(), [
                    'stok' => $resolvedDisplayStock,
                ]), true);
                $produk->stok_raw_otoritatif = $rawCurrentStock;
            }
        }

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
                    'Penyesuaian Stok Manual via Edit Produk: ' . trim((string) $validated['keterangan_stok'])
                );

                $this->rebuildAfterManualStockMutation($produk->id_produk, 'edit stok produk manual');
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

            $keteranganFinal = 'Penyesuaian Stok Manual';
            if ($keteranganRaw !== '') {
                $keteranganFinal = 'Penyesuaian Stok Manual: ' . $keteranganRaw;
            }

            $this->createStockOpnameRecord($produk->id_produk, $stok_lama_otoritatif, $stok_baru, $keteranganFinal);
            $this->rebuildAfterManualStockMutation($produk->id_produk, 'stock opname manual');
            
            DB::commit();
            
            Cache::forget($idempotencyKey);

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
        if (intval($stokBaru) < 0) {
            throw new UnsafeStockMutationException('Penyesuaian stok manual tidak boleh menghasilkan stok negatif.');
        }

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

    private function rebuildAfterManualStockMutation(int $idProduk, string $contextLabel): void
    {
        app(StockRuntimeIntegrityService::class)->rebuildAndValidate(
            [$idProduk],
            $contextLabel,
            false
        );
    }

    private function resolveAuthoritativeOldStock(int $idProduk, int $fallbackStock): int
    {
        $authoritativeStockMap = app(StockRuntimeIntegrityService::class)
            ->previewCurrentSellableStockMap([$idProduk], null, false);

        if (isset($authoritativeStockMap[$idProduk])) {
            return intval($authoritativeStockMap[$idProduk]['raw_current_stock'] ?? $fallbackStock);
        }

        return $fallbackStock;
    }

    private function applyProdukStockFilter($query, ?string $filterStok): void
    {
        switch ((string) $filterStok) {
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

    private function applyProdukSearchFilters($query, Request $request): void
    {
        $globalSearch = trim((string) data_get($request->input('search'), 'value', ''));
        $columnIdSearch = trim((string) data_get($request->input('columns'), '2.search.value', ''));
        $columnNameSearch = trim((string) data_get($request->input('columns'), '3.search.value', ''));

        if ($columnIdSearch !== '') {
            if (is_numeric($columnIdSearch)) {
                $query->where('produk.id_produk', intval($columnIdSearch));
            } else {
                $query->whereRaw('CAST(produk.id_produk AS CHAR) like ?', ['%' . $this->escapeLikeValue($columnIdSearch) . '%']);
            }
        }

        if ($columnNameSearch !== '') {
            $likeName = '%' . $this->escapeLikeValue($columnNameSearch) . '%';
            $query->where('produk.nama_produk', 'like', $likeName);
        }

        if ($globalSearch === '') {
            return;
        }

        $likeValue = '%' . $this->escapeLikeValue($globalSearch) . '%';
        $query->where(function ($builder) use ($globalSearch, $likeValue) {
            $builder->where('produk.nama_produk', 'like', $likeValue)
                ->orWhere('produk.kode_produk', 'like', $likeValue)
                ->orWhere('kategori.nama_kategori', 'like', $likeValue)
                ->orWhere('produk.merk', 'like', $likeValue)
                ->orWhere('produk.batch', 'like', $likeValue)
                ->orWhere('produk.expired_date', 'like', $likeValue);

            if (is_numeric($globalSearch)) {
                $builder->orWhere('produk.id_produk', intval($globalSearch))
                    ->orWhere('produk.harga_beli', intval($globalSearch))
                    ->orWhere('produk.harga_jual', intval($globalSearch))
                    ->orWhere('produk.stok', intval($globalSearch));
            }
        });
    }

    private function applyProdukOrdering($query, Request $request): void
    {
        $columnIndex = intval(data_get($request->input('order'), '0.column', 2));
        $direction = strtolower((string) data_get($request->input('order'), '0.dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $sortableColumns = [
            2 => 'produk.id_produk',
            3 => 'produk.nama_produk',
            4 => 'kategori.nama_kategori',
            5 => 'produk.merk',
            6 => 'produk.harga_beli',
            7 => 'produk.harga_jual',
            8 => 'produk.expired_date',
            9 => 'produk.batch',
            10 => 'produk.stok',
        ];

        $sortColumn = $sortableColumns[$columnIndex] ?? 'produk.id_produk';
        $query->orderBy($sortColumn, $direction);

        if ($sortColumn !== 'produk.id_produk') {
            $query->orderBy('produk.id_produk', 'asc');
        }
    }

    private function buildProdukDataTableRow($produk, int $rowNumber): array
    {
        return [
            'DT_RowIndex' => $rowNumber,
            'select_all' => '<input type="checkbox" name="id_produk[]" value="' . intval($produk->id_produk) . '">',
            'id_produk' => intval($produk->id_produk),
            'nama_produk' => (string) ($produk->nama_produk ?? ''),
            'nama_kategori' => (string) ($produk->nama_kategori ?? ''),
            'merk' => (string) ($produk->merk ?? ''),
            'harga_beli' => format_uang($produk->harga_beli),
            'harga_jual' => format_uang($produk->harga_jual),
            'expired_date' => $produk->expired_date == null ? 'Belum ditambahkan tanggal kadaluarsa' : $produk->expired_date,
            'batch' => $produk->batch == null ? 'Belum ditambahkan nomor batch' : $produk->batch,
            'stok' => $this->buildProdukStockHtml($produk),
            'aksi' => $this->buildProdukActionHtml($produk),
        ];
    }

    private function buildProdukStockHtml($produk): string
    {
        $displayStock = intval($produk->stok);
        $rawAuthoritativeStock = isset($produk->stok_raw_otoritatif)
            ? intval($produk->stok_raw_otoritatif)
            : $displayStock;

        $stokDisplay = '<span class="' . ($displayStock <= 0 ? 'text-danger' : ($displayStock == 1 ? 'text-warning' : 'text-success')) . '">';
        $stokDisplay .= '<strong>' . format_uang($displayStock) . '</strong>';
        $stokDisplay .= '</span>';

        if ($rawAuthoritativeStock < 0) {
            $stokDisplay .= ' <i class="fa fa-exclamation-triangle text-danger" title="Stok valid sudah 0; draft aktif membuat proyeksi minus"></i>';
        } elseif ($displayStock <= 0) {
            $stokDisplay .= ' <i class="fa fa-ban text-danger" title="Stok habis valid setelah baseline"></i>';
        } elseif ($displayStock == 1) {
            $stokDisplay .= ' <i class="fa fa-warning text-warning" title="Stok menipis - segera lakukan pembelian"></i>';
        }

        return $stokDisplay;
    }

    private function buildProdukActionHtml($produk): string
    {
        $buttons = '<div class="btn-group btn-group-xs" role="group">';

        $buttons .= '<button type="button" onclick="editForm(`'. route('produk.update', $produk->id_produk, false) .'`)" class="btn btn-info" title="Edit Produk"><i class="fa fa-pencil"></i></button>';
        $buttons .= '<button type="button" onclick="updateStokManual('. intval($produk->id_produk) .', \''. addslashes((string) $produk->nama_produk) .'\', '. intval($produk->stok) .')" class="btn btn-success" title="Update Stok"><i class="fa fa-cubes"></i></button>';
        $buttons .= '<a href="'. route('kartu_stok.detail', $produk->id_produk, false) .'" class="btn btn-primary" title="Kartu Stok" target="_blank"><i class="fa fa-list-alt"></i></a>';
        $buttons .= '<button type="button" onclick="deleteData(`'. route('produk.destroy', $produk->id_produk, false) .'`)" class="btn btn-danger" title="Hapus"><i class="fa fa-trash"></i></button>';

        if (intval($produk->stok) <= 1) {
            $buttons .= '<button type="button" onclick="beliProduk('. intval($produk->id_produk) .')" class="btn btn-warning" title="Beli Sekarang"><i class="fa fa-cart-plus"></i></button>';
        }

        $buttons .= '</div>';

        return $buttons;
    }

    private function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function applyAuthoritativeDisplayStockOverlay($produkCollection)
    {
        $candidateIds = collect($produkCollection)
            ->filter(function ($produk) {
                return intval($produk->stok ?? 0) <= self::AUTHORITATIVE_LOW_STOCK_THRESHOLD;
            })
            ->pluck('id_produk')
            ->map(function ($id) {
                return intval($id);
            })
            ->filter(function ($id) {
                return $id > 0;
            })
            ->unique()
            ->values()
            ->all();

        if (empty($candidateIds)) {
            return $produkCollection;
        }

        $authoritativeStockMap = app(StockRuntimeIntegrityService::class)
            ->previewCurrentSellableStockMap($candidateIds, null, true);

        foreach ($produkCollection as $produk) {
            $productId = intval($produk->id_produk ?? 0);
            if ($productId <= 0 || !isset($authoritativeStockMap[$productId])) {
                continue;
            }

            $rawCurrentStock = intval($authoritativeStockMap[$productId]['raw_current_stock'] ?? $produk->stok);
            $displayStock = intval($authoritativeStockMap[$productId]['display_stock'] ?? $produk->stok);
            $resolvedDisplayStock = $rawCurrentStock < 0 ? $rawCurrentStock : $displayStock;
            $produk->setRawAttributes(array_merge($produk->getAttributes(), [
                'stok' => $resolvedDisplayStock,
            ]), true);
            $produk->stok_raw_otoritatif = $rawCurrentStock;
        }

        return $produkCollection;
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
        $cutoff = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');

        $pembelianDrafts = DB::table('pembelian_detail as pd')
            ->join('pembelian as p', 'pd.id_pembelian', '=', 'p.id_pembelian')
            ->where('pd.id_produk', $idProduk)
            ->where('pd.jumlah', '>', 0)
            ->whereRaw('COALESCE(p.waktu_datang, p.waktu, p.created_at) > ?', [$cutoff])
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
            ->whereRaw('COALESCE(p.waktu, p.created_at) > ?', [$cutoff])
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
