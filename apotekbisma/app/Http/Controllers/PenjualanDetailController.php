<?php

namespace App\Http\Controllers;

use App\Exceptions\UnsafeStockMutationException;
use App\Models\Member;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;
use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\Setting;
use App\Services\StockRuntimeIntegrityService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PenjualanDetailController extends Controller
{
    private const IDEMPOTENCY_TTL = 10;
    private const AUTHORITATIVE_LOW_STOCK_THRESHOLD = 20;
    
    public function __construct()
    {
    }

    public function index()
    {
        $produk = Produk::orderBy('nama_produk')->get();
        $member = Member::orderBy('nama')->get();
        $diskon = Setting::first()->diskon ?? 0;
        $defaultTransactionWaktu = Carbon::now()->setTimezone(config('app.timezone'));

        if ($id_penjualan = session('id_penjualan')) {
            $penjualan = Penjualan::find($id_penjualan);
            $memberSelected = $penjualan->member ?? new Member();

            return view('penjualan_detail.detail', compact('produk', 'member', 'diskon', 'id_penjualan', 'penjualan', 'memberSelected', 'defaultTransactionWaktu'));
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
            ->join('produk', 'penjualan_detail.id_produk', '=', 'produk.id_produk')
            ->orderBy('produk.nama_produk', 'asc')
            ->select('penjualan_detail.*')
            ->get();

        $draftQtyByProduct = $detail
            ->groupBy('id_produk')
            ->map(function ($items) {
                return intval($items->sum('jumlah'));
            });
        $authoritativeSnapshots = app(StockRuntimeIntegrityService::class)
            ->previewAuthoritativeDraftAwareSnapshots($draftQtyByProduct->keys()->all());

        $data = array();
        $total = 0;
        $total_item = 0;

        foreach ($detail as $item) {
            $draftStockPreview = $this->buildDraftStockPreviewFromSnapshot(
                $authoritativeSnapshots[intval($item->id_produk)] ?? null,
                intval($item->produk->stok ?? 0),
                intval($draftQtyByProduct->get($item->id_produk, $item->jumlah))
            );

            $row = array();
            $kodeProduk = trim((string) ($item->produk['kode_produk'] ?? ''));
            $row['kode_produk'] = $kodeProduk !== ''
                ? '<span class="label label-success">' . e($kodeProduk) . '</span>'
                : '<span class="text-muted">-</span>';
            $row['nama_produk'] = $item->produk['nama_produk'];
            $row['harga_jual']  = 'Rp. '. format_uang($item->harga_jual);
            $row['jumlah']      = '<input type="number" class="form-control input-sm quantity" data-id="'. $item->id_penjualan_detail .'" value="'. $item->jumlah .'">';
            $row['diskon']      = $item->diskon . '%';
            $row['subtotal']    = 'Rp. '. format_uang($item->subtotal);
            $row['aksi']        = $this->buildDraftActionColumn(
                intval($item->id_penjualan_detail),
                intval($item->id_produk),
                $draftStockPreview
            );
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

    private function buildDraftActionColumn(int $detailId, int $productId, array $draftStockPreview): string
    {
        return '<div class="draft-action-wrap">'
            . '<div class="btn-group">'
            . '<button onclick="deleteData(`' . route('transaksi.destroy', $detailId) . '`)" class="btn btn-xs btn-danger btn-flat"><i class="fa fa-trash"></i></button>'
            . '</div>'
            . '<div class="draft-stock-summary" data-product-id="' . $productId . '">'
            . $this->renderDraftStockSummaryHtml($draftStockPreview)
            . '</div>'
            . '</div>';
    }

    private function buildDraftStockPreview(int $currentStock, int $draftQuantity): array
    {
        $resolvedDraftQuantity = max(0, intval($draftQuantity));
        $resolvedCurrentStock = intval($currentStock);

        return [
            'stock_before_transaction' => $resolvedCurrentStock + $resolvedDraftQuantity,
            'draft_quantity' => $resolvedDraftQuantity,
            'stock_after_transaction' => $resolvedCurrentStock,
        ];
    }

    private function buildDraftStockPreviewFromSnapshot(?array $snapshot, int $fallbackCurrentStock, int $draftQuantity): array
    {
        $resolvedDraftQuantity = max(0, intval($draftQuantity));

        if (!$snapshot) {
            return $this->buildDraftStockPreview($fallbackCurrentStock, $resolvedDraftQuantity);
        }

        return $this->buildDraftStockPreview(
            intval($snapshot['expected_stock'] ?? $fallbackCurrentStock),
            $resolvedDraftQuantity
        );
    }

    private function resolveAuthoritativeCurrentStock(int $productId, int $fallbackStock, bool $clampNegativeToZero = false): int
    {
        $stockMap = app(StockRuntimeIntegrityService::class)
            ->previewCurrentSellableStockMap([$productId], null, $clampNegativeToZero);

        if (!isset($stockMap[$productId])) {
            return intval($fallbackStock);
        }

        return intval($clampNegativeToZero
            ? ($stockMap[$productId]['display_stock'] ?? $fallbackStock)
            : ($stockMap[$productId]['raw_current_stock'] ?? $fallbackStock));
    }

    private function resolveAuthoritativeDraftPreview(int $productId, int $fallbackCurrentStock, int $draftQuantity): array
    {
        $snapshots = app(StockRuntimeIntegrityService::class)
            ->previewAuthoritativeDraftAwareSnapshots([$productId]);

        return $this->buildDraftStockPreviewFromSnapshot(
            $snapshots[$productId] ?? null,
            $fallbackCurrentStock,
            $draftQuantity
        );
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

    private function renderDraftStockSummaryHtml(array $draftStockPreview): string
    {
        $stockBeforeValue = intval($draftStockPreview['stock_before_transaction'] ?? 0);
        $draftQuantityValue = intval($draftStockPreview['draft_quantity'] ?? 0);
        $stockAfterValue = intval($draftStockPreview['stock_after_transaction'] ?? 0);

        $stockBefore = $this->formatStockUnit($stockBeforeValue);
        $draftQuantity = $this->formatStockUnit($draftQuantityValue);
        $stockAfter = $this->formatStockUnit($stockAfterValue);
        $warningClass = $stockAfterValue <= 0 ? ' draft-stock-card--warning' : '';
        $afterRowClass = $stockAfterValue <= 0 ? ' draft-stock-card__row--warning' : '';
        $warningNotice = $stockAfterValue <= 0
            ? '<div class="draft-stock-card__notice"><i class="fa fa-exclamation-triangle"></i> Stok akhir perlu perhatian</div>'
            : '';

        return '<div class="draft-stock-card' . $warningClass . '">'
            . '<div class="draft-stock-card__title"><i class="fa fa-line-chart"></i> Kartu stok draft</div>'
            . $warningNotice
            . '<div class="draft-stock-card__equation">'
            . '<div class="draft-stock-card__equation-main">' . $stockBefore . ' - ' . $draftQuantity . ' = ' . $stockAfter . '</div>'
            . '<div class="draft-stock-card__equation-note">Stok awal realtime - qty draft = stok akhir</div>'
            . '</div>'
            . '<div class="draft-stock-card__rows">'
            . '<div class="draft-stock-card__row">'
            . '<span class="draft-stock-card__label">Stok awal</span>'
            . '<strong class="draft-stock-card__value">' . $stockBefore . ' unit</strong>'
            . '</div>'
            . '<div class="draft-stock-card__row">'
            . '<span class="draft-stock-card__label">Di draft ini</span>'
            . '<strong class="draft-stock-card__value">' . $draftQuantity . ' unit</strong>'
            . '</div>'
            . '<div class="draft-stock-card__row draft-stock-card__row--after' . $afterRowClass . '">'
            . '<span class="draft-stock-card__label">Stok akhir</span>'
            . '<strong class="draft-stock-card__value">' . $stockAfter . ' unit</strong>'
            . '</div>'
            . '</div>'
            . '</div>';
    }

    private function formatStockUnit($quantity): string
    {
        return number_format(intval($quantity), 0, ',', '.');
    }

    public function store(Request $request)
    {
        $idempotencyKey = 'penjualan_store_' . ($request->id_penjualan ?? session('id_penjualan')) . '_' . $request->id_produk . '_' . auth()->id();
        
        if (Cache::has($idempotencyKey)) {
            return response()->json(['error' => true, 'message' => 'Request sedang diproses, mohon tunggu...'], 429);
        }
        
        Cache::put($idempotencyKey, true, self::IDEMPOTENCY_TTL);
        
        DB::beginTransaction();
        
        try {
            $idProduk = $request->id_produk;
            if (empty($idProduk)) {
                DB::rollBack();
                Cache::forget($idempotencyKey);
                return response()->json(['error' => true, 'message' => 'ID Produk tidak valid'], 400);
            }

            $produk = Produk::where('id_produk', $idProduk)->lockForUpdate()->first();
            if (!$produk) {
                DB::rollBack();
                Cache::forget($idempotencyKey);
                return response()->json(['error' => true, 'message' => 'Data produk tidak ditemukan'], 400);
            }

            $stokSaatIni = $this->resolveAuthoritativeCurrentStock(intval($produk->id_produk), intval($produk->stok));
            if ($stokSaatIni <= 0) {
                DB::rollBack();
                Cache::forget($idempotencyKey);
                return response()->json(['error' => true, 'message' => 'Stok habis! Produk tidak dapat dijual karena stok saat ini: ' . $stokSaatIni], 400);
            }

            $id_penjualan = $request->id_penjualan;
            
            if (empty($id_penjualan)) {
                $id_penjualan = session('id_penjualan');
            }
            
            $jumlah_tambahan = 1;

            if ($stokSaatIni < $jumlah_tambahan) {
                DB::rollBack();
                Cache::forget($idempotencyKey);
                return response()->json(['error' => true, 'message' => 'Tidak dapat menambah produk! Stok tersedia: ' . $stokSaatIni], 400);
            }
            
            $penjualan = null;
            if (empty($id_penjualan)) {
                $penjualan = new Penjualan();
                $penjualan->id_member = null;
                $penjualan->total_item = 0;
                $penjualan->total_harga = 0;
                $penjualan->diskon = 0;
                $penjualan->bayar = 0;
                $penjualan->diterima = 0;
                $penjualan->waktu = Carbon::now()->setTimezone(config('app.timezone'));
                $penjualan->id_user = auth()->id();
                $penjualan->save();

                session(['id_penjualan' => $penjualan->id_penjualan]);
                $id_penjualan = $penjualan->id_penjualan;
            } else {
                $penjualan = Penjualan::find($id_penjualan);
                if (!$penjualan) {
                    DB::rollBack();
                    Cache::forget($idempotencyKey);
                    return response()->json(['error' => true, 'message' => 'Transaksi tidak ditemukan'], 400);
                }
                $this->ensurePenjualanHasWaktu($penjualan);
            }

            $detail = new PenjualanDetail();
            $detail->id_penjualan = $id_penjualan;
            $detail->id_produk = $produk->id_produk;
            $detail->harga_jual = $produk->harga_jual ?? 0;
            $detail->jumlah = $jumlah_tambahan;
            $detail->diskon = $produk->diskon ?? 0;
            $detail->subtotal = ($produk->harga_jual ?? 0) - (($produk->diskon ?? 0) / 100 * ($produk->harga_jual ?? 0));
            $detail->save();

            $existingRekaman = DB::table('rekaman_stoks')
                ->where('id_penjualan', $id_penjualan)
                ->where('id_produk', $produk->id_produk)
                ->lockForUpdate()
                ->first();
            
            if ($existingRekaman) {
                $newStokKeluar = intval($existingRekaman->stok_keluar) + $jumlah_tambahan;
                $originalStokAwal = intval($existingRekaman->stok_awal);
                $newStokSisa = $originalStokAwal - $newStokKeluar;
                
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $existingRekaman->id_rekaman_stok)
                    ->update([
                        'stok_keluar' => $newStokKeluar,
                        'stok_sisa' => $newStokSisa,
                        'updated_at' => now()
                    ]);
                    
                $stok_baru = $stokSaatIni - $jumlah_tambahan;
            } else {
                $stok_sebelum = $stokSaatIni;
                $stok_baru = $stok_sebelum - $jumlah_tambahan;
                $rekamanWaktu = $penjualan && $penjualan->waktu
                    ? Carbon::parse($penjualan->waktu)->format('Y-m-d H:i:s')
                    : Carbon::now()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
                
                DB::table('rekaman_stoks')->insert([
                    'id_produk' => $produk->id_produk,
                    'id_penjualan' => $id_penjualan,
                    'waktu' => $rekamanWaktu,
                    'stok_masuk' => 0,
                    'stok_keluar' => $jumlah_tambahan,
                    'stok_awal' => $stok_sebelum,
                    'stok_sisa' => $stok_baru,
                    'keterangan' => 'Penjualan: Transaksi penjualan produk',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
            
            if ($stok_baru < 0) {
                DB::rollBack();
                Cache::forget($idempotencyKey);
                return response()->json(['error' => true, 'message' => 'FATAL: Stok tidak mencukupi! Stok tersedia: ' . $stokSaatIni . ', dibutuhkan: ' . $jumlah_tambahan], 400);
            }
            
            DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stok_baru]);

            $this->syncAffectedProdukHistory([$produk->id_produk ?? null]);

            $draftProductQuantity = intval(DB::table('penjualan_detail')
                ->where('id_penjualan', $detail->id_penjualan)
                ->where('id_produk', $produk->id_produk)
                ->sum('jumlah'));
            $currentProductStock = $this->resolveAuthoritativeCurrentStock(
                intval($produk->id_produk),
                intval(DB::table('produk')->where('id_produk', $produk->id_produk)->value('stok'))
            );
            $draftStockPreview = $this->resolveAuthoritativeDraftPreview(
                intval($produk->id_produk),
                $currentProductStock,
                $draftProductQuantity
            );
            
            DB::commit();
            
            Cache::forget($idempotencyKey);
            
            return response()->json([
                'success' => true,
                'message' => 'Produk berhasil ditambahkan ke keranjang. Stok tersisa: ' . $stok_baru,
                'id_penjualan' => $id_penjualan,
                'stok_tersisa' => $currentProductStock
            ], 200);
            
        } catch (UnsafeStockMutationException $e) {
            DB::rollBack();
            Cache::forget($idempotencyKey);
            Log::warning('PenjualanDetailController@store blocked by stock integrity guard', [
                'id_produk' => $request->id_produk ?? null,
                'id_penjualan' => $request->id_penjualan ?? session('id_penjualan'),
                'message' => $e->getMessage(),
            ]);
            return response()->json(['error' => true, 'message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            DB::rollBack();
            Cache::forget($idempotencyKey);
            Log::error('PenjualanDetailController@store error: ' . $e->getMessage(), [
                'id_produk' => $request->id_produk ?? null,
                'id_penjualan' => $request->id_penjualan ?? session('id_penjualan'),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => true, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $idempotencyKey = 'penjualan_update_' . $id . '_' . auth()->id();
        
        if (Cache::has($idempotencyKey)) {
            return response()->json(['message' => 'Request sedang diproses, mohon tunggu...'], 429);
        }
        
        Cache::put($idempotencyKey, true, self::IDEMPOTENCY_TTL);
        
        DB::beginTransaction();
        
        try {
            $detail = PenjualanDetail::find($id);
            
            if (!$detail) {
                DB::rollBack();
                Cache::forget($idempotencyKey);
                return response()->json(['message' => 'Detail transaksi tidak ditemukan'], 404);
            }
            
            $produk = Produk::where('id_produk', $detail->id_produk)->lockForUpdate()->first();
            
            if (!$produk) {
                DB::rollBack();
                Cache::forget($idempotencyKey);
                return response()->json(['message' => 'Produk tidak ditemukan'], 404);
            }
            
            $new_jumlah_row = (int) $request->jumlah;
            if ($new_jumlah_row < 1) {
                DB::rollBack();
                Cache::forget($idempotencyKey);
                return response()->json(['message' => 'Jumlah harus minimal 1'], 400);
            }
            
            $old_jumlah_row = intval($detail->jumlah);
            $selisih_row = $new_jumlah_row - $old_jumlah_row;
            
            $stok_sekarang = $this->resolveAuthoritativeCurrentStock(intval($produk->id_produk), intval($produk->stok));
            
            if ($selisih_row > 0 && $stok_sekarang < $selisih_row) {
                DB::rollBack();
                Cache::forget($idempotencyKey);
                return response()->json([
                    'message' => 'Tidak dapat mengubah jumlah! Stok tersedia: ' . $stok_sekarang . ', dibutuhkan: ' . $selisih_row
                ], 400);
            }
            
            $stok_baru = $stok_sekarang - $selisih_row;
            if ($stok_baru < 0) {
                DB::rollBack();
                Cache::forget($idempotencyKey);
                return response()->json(['message' => 'FATAL: Stok tidak mencukupi! Hasil akhir akan minus. Stok tersedia: ' . $stok_sekarang . ', akan dikurangi: ' . $selisih_row], 400);
            }
            DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stok_baru]);
            
            $hargaJual = floatval($detail->harga_jual);
            $diskon = floatval($detail->diskon);
            $subtotal = $hargaJual * $new_jumlah_row - (($diskon * $new_jumlah_row) / 100 * $hargaJual);
            
            DB::table('penjualan_detail')
                ->where('id_penjualan_detail', $detail->id_penjualan_detail)
                ->update([
                    'jumlah' => $new_jumlah_row,
                    'subtotal' => $subtotal,
                    'updated_at' => now()
                ]);
            
            $totalQtyInTransaction = DB::table('penjualan_detail')
                ->where('id_penjualan', $detail->id_penjualan)
                ->where('id_produk', $produk->id_produk)
                ->sum('jumlah');

            $penjualan = Penjualan::find($detail->id_penjualan);
            $waktu_transaksi = $penjualan && $penjualan->waktu
                ? $penjualan->waktu
                : Carbon::now()->setTimezone(config('app.timezone'));
            
            $existingRekaman = DB::table('rekaman_stoks')
                                 ->where('id_penjualan', $detail->id_penjualan)
                                 ->where('id_produk', $detail->id_produk)
                                 ->lockForUpdate()
                                 ->first();
            
            if ($existingRekaman) {
                $originalStokAwal = intval($existingRekaman->stok_awal);
                $newStokSisa = $originalStokAwal - $totalQtyInTransaction;
                
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $existingRekaman->id_rekaman_stok)
                    ->update([
                        'stok_keluar' => $totalQtyInTransaction,
                        'stok_sisa' => $newStokSisa,
                        'updated_at' => now()
                    ]);
            } else {
                $stokAwalRekaman = $stok_baru + $totalQtyInTransaction;
                $rekamanWaktu = Carbon::parse($waktu_transaksi)->format('Y-m-d H:i:s');
                
                DB::table('rekaman_stoks')->insert([
                    'id_produk' => $produk->id_produk,
                    'id_penjualan' => $detail->id_penjualan,
                    'waktu' => $rekamanWaktu,
                    'stok_masuk' => 0,
                    'stok_keluar' => $totalQtyInTransaction,
                    'stok_awal' => $stokAwalRekaman,
                    'stok_sisa' => $stok_baru,
                    'keterangan' => 'Penjualan: Transaksi penjualan produk',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
            
            $this->syncAffectedProdukHistory([$produk->id_produk ?? null]);

            $draftProductQuantity = intval(DB::table('penjualan_detail')
                ->where('id_penjualan', $detail->id_penjualan)
                ->where('id_produk', $produk->id_produk)
                ->sum('jumlah'));
            $currentProductStock = $this->resolveAuthoritativeCurrentStock(
                intval($produk->id_produk),
                intval(DB::table('produk')->where('id_produk', $produk->id_produk)->value('stok'))
            );
            $draftStockPreview = $this->resolveAuthoritativeDraftPreview(
                intval($produk->id_produk),
                $currentProductStock,
                $draftProductQuantity
            );

            DB::commit();
            
            Cache::forget($idempotencyKey);
            
            return response()->json([
                'message' => 'Jumlah berhasil diperbarui. Stok tersisa: ' . $stok_baru,
                'data' => [
                    'jumlah' => $new_jumlah_row,
                    'subtotal' => $subtotal,
                    'stok_tersisa' => $currentProductStock,
                    'id_produk' => intval($produk->id_produk),
                    'draft_quantity_for_product' => $draftProductQuantity,
                    'stock_summary_html' => $this->renderDraftStockSummaryHtml($draftStockPreview),
                ]
            ], 200);
            
        } catch (UnsafeStockMutationException $e) {
            DB::rollBack();
            Cache::forget($idempotencyKey);
            Log::warning('PenjualanDetailController@update blocked by stock integrity guard', [
                'id_penjualan_detail' => $id,
                'message' => $e->getMessage(),
            ]);
            return response()->json(['message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            DB::rollBack();
            Cache::forget($idempotencyKey);
            Log::error('Error updating penjualan detail: ' . $e->getMessage());
            return response()->json(['message' => 'Terjadi kesalahan: ' . $e->getMessage()], 500);
        }
    }

    public function updateEdit(Request $request, $id)
    {
         return $this->update($request, $id);
    }

    public function destroy($id)
    {
        $idempotencyKey = 'penjualan_destroy_' . $id . '_' . auth()->id();
        
        if (Cache::has($idempotencyKey)) {
            return response()->json(['message' => 'Request sedang diproses...'], 429);
        }
        
        Cache::put($idempotencyKey, true, self::IDEMPOTENCY_TTL);
        
        DB::beginTransaction();
        $deleteResponse = [
            'deleted' => true,
        ];
        
        try {
            $detail = DB::table('penjualan_detail')->where('id_penjualan_detail', $id)->first();
            
            if ($detail) {
                $produk = Produk::query()
                    ->where('id_produk', $detail->id_produk)
                    ->lockForUpdate()
                    ->first();
                $produkId = $detail->id_produk;
                $idPenjualan = $detail->id_penjualan;
                $jumlahDeleted = intval($detail->jumlah);
                
                if ($produk) {
                    $stokSebelum = intval($produk->stok);
                    $stokBaru = $stokSebelum + $jumlahDeleted;
                    
                    DB::table('produk')->where('id_produk', $produkId)->update(['stok' => $stokBaru]);
                    
                    DB::table('penjualan_detail')->where('id_penjualan_detail', $id)->delete();
                    
                    $remainingQty = DB::table('penjualan_detail')
                        ->where('id_penjualan', $idPenjualan)
                        ->where('id_produk', $produkId)
                        ->sum('jumlah');
                    
                    if ($remainingQty > 0) {
                        $existingRekaman = DB::table('rekaman_stoks')
                            ->where('id_penjualan', $idPenjualan)
                            ->where('id_produk', $produkId)
                            ->lockForUpdate()
                            ->first();

                        if ($existingRekaman) {
                             $originalStokAwal = intval($existingRekaman->stok_awal);
                             $newStokSisa = $originalStokAwal - $remainingQty;
                             
                             DB::table('rekaman_stoks')
                                ->where('id_rekaman_stok', $existingRekaman->id_rekaman_stok)
                                ->update([
                                    'stok_keluar' => $remainingQty,
                                    'stok_sisa' => $newStokSisa,
                                    'updated_at' => now()
                                ]);
                        } else {
                            $stokAwalRekaman = $stokBaru + $remainingQty;

                            DB::table('rekaman_stoks')->insert([
                                'id_produk' => $produkId,
                                'id_penjualan' => $idPenjualan,
                                'waktu' => Carbon::now()->format('Y-m-d H:i:s.u'),
                                'stok_masuk' => 0,
                                'stok_keluar' => $remainingQty,
                                'stok_awal' => $stokAwalRekaman,
                                'stok_sisa' => $stokBaru,
                                'keterangan' => 'Penjualan: Rekaman dipulihkan setelah hapus detail',
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);
                        }
                    } else {
                        DB::table('rekaman_stoks')
                            ->where('id_penjualan', $idPenjualan)
                            ->where('id_produk', $produkId)
                            ->delete();
                    }
                } else {
                     DB::table('penjualan_detail')->where('id_penjualan_detail', $id)->delete();
                }
                
                $this->syncAffectedProdukHistory([$produkId ?? null]);

                if (!empty($produkId)) {
                    $currentProductStock = $this->resolveAuthoritativeCurrentStock(
                        intval($produkId),
                        intval(DB::table('produk')->where('id_produk', $produkId)->value('stok')),
                        true
                    );
                    $deleteResponse['id_produk'] = intval($produkId);
                    $deleteResponse['stok_tersisa'] = $currentProductStock;
                    $deleteResponse['remaining_qty'] = intval($remainingQty ?? 0);

                    if (intval($remainingQty ?? 0) > 0) {
                        $deleteResponse['stock_summary_html'] = $this->renderDraftStockSummaryHtml(
                            $this->resolveAuthoritativeDraftPreview(
                                intval($produkId),
                                $currentProductStock,
                                intval($remainingQty)
                            )
                        );
                    }
                }

                DB::commit();
                
                Cache::forget($idempotencyKey);
            } else {
                DB::commit();
                Cache::forget($idempotencyKey);
            }
        } catch (UnsafeStockMutationException $e) {
            DB::rollBack();
            Cache::forget($idempotencyKey);
            Log::warning('PenjualanDetailController@destroy blocked by stock integrity guard', [
                'id_penjualan_detail' => $id,
                'message' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            DB::rollBack();
            Cache::forget($idempotencyKey);
            Log::error('Error in destroy: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json($deleteResponse);
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
            'kembali' => $kembali,
            'kembalirp' => format_uang($kembali),
            'kembali_terbilang' => ucwords(terbilang($kembali). ' Rupiah'),
        ];

        return response()->json($data);
    }

    public function getProdukData()
    {
        $produk = $this->applyAuthoritativeDisplayStockOverlay(Produk::orderBy('nama_produk')->get());
        
        $data = [];
        foreach ($produk as $key => $item) {
            $data[] = [
                'id' => $item->id_produk,
                'no' => $key + 1,
                'kode_produk' => $item->kode_produk,
                'nama_produk' => $item->nama_produk,
                'stok' => intval($item->stok),
                'harga_jual' => $item->harga_jual,
                'stok_badge_class' => intval($item->stok) == 0 ? 'bg-red' : (intval($item->stok) <= 5 ? 'bg-yellow' : 'bg-green'),
                'stok_text' => intval($item->stok) == 0 ? 'Stok habis valid setelah baseline' : (intval($item->stok) <= 5 ? 'Stok menipis' : ''),
                'stok_icon' => intval($item->stok) == 0 ? 'fa-exclamation-triangle' : (intval($item->stok) <= 5 ? 'fa-warning' : ''),
                'stok_text_class' => intval($item->stok) == 0 ? 'text-danger' : (intval($item->stok) <= 5 ? 'text-warning' : '')
            ];
        }
        
        return response()->json($data)
               ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
               ->header('Pragma', 'no-cache')
               ->header('Expires', '0');
    }

    public function recalculateStock()
    {
        $produk_list = Produk::all();
        
        foreach ($produk_list as $produk) {
            $total_keluar = RekamanStok::where('id_produk', $produk->id_produk)
                                      ->sum('stok_keluar');
            
            echo "Produk: {$produk->nama_produk}, Stok saat ini: {$produk->stok}, Total keluar: {$total_keluar}<br>";
        }
        
        return response()->json('Recalculate completed');
    }

    private function ensurePenjualanHasWaktu($penjualan)
    {
        if (!$penjualan->waktu) {
            $penjualan->waktu = $penjualan->created_at ?? Carbon::now()->setTimezone(config('app.timezone'));
            $penjualan->save();
        }
    }

    private function syncAffectedProdukHistory(array $produkIds): void
    {
        $normalizedIds = array_values(array_unique(array_filter(array_map('intval', $produkIds), function ($id) {
            return $id > 0;
        })));

        if (empty($normalizedIds)) {
            return;
        }

        // Keep draft stock aligned with baseline-backed committed truth, but limit any reflow
        // strictly to the affected products so unrelated history is untouched.
        app(StockRuntimeIntegrityService::class)->synchronizeDraftStockAgainstCommittedTruth(
            $normalizedIds,
            'sinkronisasi draft penjualan'
        );

        Log::debug('Draft stock sync aligned against committed truth', [
            'id_produk' => $normalizedIds,
        ]);
    }
    
    private function atomicRecalculateAndSync($produkId)
    {
        try {
            $lockKey = 'stock_recalc_' . $produkId;
            $lock = Cache::lock($lockKey, 30);
            
            if ($lock->get()) {
                try {
                    // IMPORTANT: Sort by waktu ASC, created_at ASC, id ASC for deterministic ordering
                    $stokRecords = DB::table('rekaman_stoks')
                        ->where('id_produk', $produkId)
                        ->orderBy('waktu', 'asc')
                        ->orderBy('created_at', 'asc')
                        ->orderBy('id_rekaman_stok', 'asc')
                        ->get();

                    if ($stokRecords->isEmpty()) {
                        $lock->release();
                        return;
                    }

                    // Start from the FIRST record's stok_awal (which should be the initial stock)
                    // The first record establishes the baseline
                    $firstRecord = $stokRecords->first();
                    $runningStock = intval($firstRecord->stok_awal);
                    
                    $updates = [];

                    foreach ($stokRecords as $index => $record) {
                        $needsUpdate = false;
                        $updateData = [];

                        // For all records: validate that stok_awal matches running stock
                        if (intval($record->stok_awal) != $runningStock) {
                            $updateData['stok_awal'] = $runningStock;
                            $needsUpdate = true;
                        }

                        // Calculate expected stok_sisa
                        $calculatedSisa = $runningStock + intval($record->stok_masuk) - intval($record->stok_keluar);

                        if (intval($record->stok_sisa) != $calculatedSisa) {
                            $updateData['stok_sisa'] = $calculatedSisa;
                            $needsUpdate = true;
                        }

                        if ($needsUpdate) {
                            $updates[$record->id_rekaman_stok] = $updateData;
                        }

                        // Move to next record - running stock becomes this record's sisa
                        $runningStock = $calculatedSisa;
                    }

                    // Apply all updates
                    foreach ($updates as $recordId => $updateData) {
                        DB::table('rekaman_stoks')
                            ->where('id_rekaman_stok', $recordId)
                            ->update($updateData);
                    }
                    
                    // Update master stock - DO NOT cap at 0 for kartu stok integrity
                    // Business logic should prevent negative stock at transaction time
                    DB::table('produk')
                        ->where('id_produk', $produkId)
                        ->update(['stok' => $runningStock]);
                        
                } finally {
                    $lock->release();
                }
            }
        } catch (\Exception $e) {
            Log::error('atomicRecalculateAndSync error: ' . $e->getMessage(), [
                'produk_id' => $produkId
            ]);
        }
    }
}
