<?php

namespace App\Services;

use App\Exceptions\UnsafeStockMutationException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockRuntimeIntegrityService
{
    private BaselineStockReflowService $baselineStockReflowService;

    public function __construct(BaselineStockReflowService $baselineStockReflowService)
    {
        $this->baselineStockReflowService = $baselineStockReflowService;
    }

    public function rebuildAndValidate(
        array $productIds,
        string $contextLabel,
        bool $blockOnNegativeHistoricalStock = false,
        ?string $until = null,
        bool $validateDraftAwareStock = false
    ): array {
        $normalizedIds = $this->normalizeProductIds($productIds);

        if (empty($normalizedIds)) {
            return [
                'products_rebuilt' => 0,
                'products_with_negative_event' => 0,
                'negative_event_count' => 0,
                'negative_event_product_ids' => [],
                'until' => $until,
            ];
        }

        $resolvedUntil = $until ?? Carbon::now()->format('Y-m-d H:i:s');
        $summary = $this->baselineStockReflowService->rebuildProducts($normalizedIds, $resolvedUntil);

        if ($blockOnNegativeHistoricalStock) {
            $this->assertNoNegativeHistoricalStock($normalizedIds, $summary, $contextLabel);
        }

        if ($validateDraftAwareStock) {
            $this->reconcileDraftStockConsistency($normalizedIds);
            $this->assertDraftStockConsistency($normalizedIds, $contextLabel);

            return $summary;
        }

        $this->assertLatestStockConsistency($normalizedIds, $contextLabel);

        return $summary;
    }

    public function buildProjectedCurrentStockRows(array $finalStockRows): array
    {
        $rows = [];

        foreach ($finalStockRows as $row) {
            $productId = intval($row['id_produk'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $committedFinalStock = intval($row['final_stock'] ?? $row['applied_stock'] ?? 0);
            $draftPembelianQty = $this->getOpenDraftPembelianQty($productId);
            $draftPenjualanQty = $this->getOpenDraftPenjualanQty($productId);

            $rows[] = [
                'id_produk' => $productId,
                'committed_final_stock' => $committedFinalStock,
                'draft_pembelian_qty' => $draftPembelianQty,
                'draft_penjualan_qty' => $draftPenjualanQty,
                'projected_current_stock' => $committedFinalStock + $draftPembelianQty - $draftPenjualanQty,
            ];
        }

        return $rows;
    }

    public function previewAuthoritativeDraftAwareSnapshots(array $productIds, ?string $until = null): array
    {
        $normalizedIds = $this->normalizeProductIds($productIds);
        if (empty($normalizedIds)) {
            return [];
        }

        $resolvedUntil = $until ?? Carbon::now()->format('Y-m-d H:i:s');
        sort($normalizedIds);
        $cacheKey = 'stock_preview_draft_' . md5(implode(',', $normalizedIds) . '|' . $resolvedUntil);

        return Cache::remember($cacheKey, 60, function () use ($normalizedIds, $resolvedUntil) {
            $committedStockOverrides = $this->buildCommittedStockMapFromReflow($normalizedIds, $resolvedUntil);
            $snapshots = [];

            foreach ($normalizedIds as $productId) {
                $snapshot = $this->buildDraftAwareStockSnapshot($productId, $committedStockOverrides);
                if ($snapshot === null) {
                    continue;
                }

                $snapshots[$productId] = $snapshot;
            }

            return $snapshots;
        });
    }

    public function previewCurrentSellableStockMap(array $productIds, ?string $until = null, bool $clampNegativeToZero = false): array
    {
        $snapshots = $this->previewAuthoritativeDraftAwareSnapshots($productIds, $until);
        $stockMap = [];

        foreach ($snapshots as $productId => $snapshot) {
            $rawCurrentStock = intval($snapshot['expected_stock'] ?? 0);
            $stockMap[intval($productId)] = [
                'id_produk' => intval($productId),
                'committed_stock' => intval($snapshot['committed_stock'] ?? 0),
                'draft_pembelian_qty' => intval($snapshot['draft_pembelian_qty'] ?? 0),
                'draft_penjualan_qty' => intval($snapshot['draft_penjualan_qty'] ?? 0),
                'raw_current_stock' => $rawCurrentStock,
                'display_stock' => $clampNegativeToZero ? max(0, $rawCurrentStock) : $rawCurrentStock,
                'has_negative_projection' => $rawCurrentStock < 0,
            ];
        }

        return $stockMap;
    }

    public function synchronizeDraftStockAgainstCommittedTruth(array $productIds, string $contextLabel): array
    {
        $normalizedIds = $this->normalizeProductIds($productIds);
        if (empty($normalizedIds)) {
            return [
                'drifted_product_ids' => [],
                'reconciled' => [],
                'until' => null,
            ];
        }

        $resolvedUntil = Carbon::now()->format('Y-m-d H:i:s');
        $committedStockOverrides = $this->buildCommittedStockMapFromReflow($normalizedIds, $resolvedUntil);
        $driftedProducts = $this->detectCommittedStockDrift($normalizedIds, $committedStockOverrides);

        if (!empty($driftedProducts)) {
            Log::warning('Draft stock sync detected committed-stock drift against baseline reflow', [
                'context_label' => $contextLabel,
                'drifted_products' => array_values($driftedProducts),
                'until' => $resolvedUntil,
            ]);

            $this->baselineStockReflowService->rebuildProducts(array_keys($driftedProducts), $resolvedUntil);
        }

        $this->assertProjectedDraftCurrentStockRemainsNonNegative(
            $normalizedIds,
            $contextLabel,
            $committedStockOverrides
        );

        $reconciled = $this->reconcileDraftStockConsistency($normalizedIds, $committedStockOverrides);
        $this->assertDraftStockConsistency($normalizedIds, $contextLabel, $committedStockOverrides);

        return [
            'drifted_product_ids' => array_values(array_map('intval', array_keys($driftedProducts))),
            'reconciled' => $reconciled,
            'until' => $resolvedUntil,
        ];
    }

    public function assertLatestStockConsistency(array $productIds, string $contextLabel): void
    {
        $normalizedIds = $this->normalizeProductIds($productIds);
        if (empty($normalizedIds)) {
            return;
        }

        $mismatches = [];

        foreach ($normalizedIds as $productId) {
            $product = DB::table('produk')
                ->where('id_produk', $productId)
                ->select('id_produk', 'nama_produk', 'stok')
                ->first();

            if (!$product) {
                continue;
            }

            $latestRecord = $this->resolveLatestStockRecord($productId);

            $expectedStock = $latestRecord ? intval($latestRecord->stok_sisa ?? 0) : 0;
            $actualStock = intval($product->stok ?? 0);

            if ($expectedStock !== $actualStock) {
                $mismatches[] = [
                    'id_produk' => intval($product->id_produk),
                    'nama_produk' => (string) ($product->nama_produk ?? ''),
                    'produk_stok' => $actualStock,
                    'latest_stok_sisa' => $expectedStock,
                    'latest_waktu' => $latestRecord ? (string) $latestRecord->waktu : null,
                ];
            }
        }

        if (empty($mismatches)) {
            return;
        }

        throw new UnsafeStockMutationException(
            'Sinkronisasi stok tidak konsisten setelah ' . $contextLabel . ' pada ' . $this->summarizeMismatches($mismatches) . '.'
        );
    }

    public function assertDraftStockConsistency(array $productIds, string $contextLabel, ?array $committedStockOverrides = null): void
    {
        $normalizedIds = $this->normalizeProductIds($productIds);
        if (empty($normalizedIds)) {
            return;
        }

        $mismatches = [];

        foreach ($normalizedIds as $productId) {
            $snapshot = $this->buildDraftAwareStockSnapshot($productId, $committedStockOverrides);
            if ($snapshot === null) {
                continue;
            }

            if (intval($snapshot['expected_stock']) !== intval($snapshot['actual_stock'])) {
                $mismatches[] = $snapshot;
            }
        }

        if (empty($mismatches)) {
            return;
        }

        throw new UnsafeStockMutationException(
            'Sinkronisasi stok tidak konsisten setelah ' . $contextLabel . ' pada ' . $this->summarizeDraftMismatches($mismatches) . '.'
        );
    }

    public function reconcileDraftStockConsistency(array $productIds, ?array $committedStockOverrides = null): array
    {
        $normalizedIds = $this->normalizeProductIds($productIds);
        if (empty($normalizedIds)) {
            return [];
        }

        $reconciled = [];

        foreach ($normalizedIds as $productId) {
            $snapshot = $this->buildDraftAwareStockSnapshot($productId, $committedStockOverrides);
            if ($snapshot === null) {
                continue;
            }

            $expectedStock = intval($snapshot['expected_stock'] ?? 0);
            $actualStock = intval($snapshot['actual_stock'] ?? 0);

            if ($expectedStock === $actualStock) {
                continue;
            }

            DB::table('produk')
                ->where('id_produk', $productId)
                ->update([
                    'stok' => $expectedStock,
                    'updated_at' => now(),
                ]);

            $snapshot['previous_stock'] = $actualStock;
            $snapshot['actual_stock'] = $expectedStock;
            $reconciled[] = $snapshot;
        }

        return $reconciled;
    }

    public function assertNoNegativeHistoricalStock(array $productIds, array $reflowSummary, string $contextLabel): void
    {
        $negativeEventCount = intval($reflowSummary['negative_event_count'] ?? 0);
        if ($negativeEventCount <= 0) {
            return;
        }

        $negativeProductIds = array_values(array_unique(array_filter(array_map('intval', $reflowSummary['negative_event_product_ids'] ?? $productIds), function ($productId) {
            return $productId > 0;
        })));

        throw new UnsafeStockMutationException(
            'Mutasi stok diblokir karena ' . $contextLabel . ' akan menimbulkan stok minus historis pada ' . $this->summarizeProductLabels($negativeProductIds) . '.'
        );
    }

    private function normalizeProductIds(array $productIds): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $productIds), function ($productId) {
            return $productId > 0;
        })));
    }

    private function summarizeProductLabels(array $productIds): string
    {
        if (empty($productIds)) {
            return 'produk terkait';
        }

        $labelsById = DB::table('produk')
            ->whereIn('id_produk', $productIds)
            ->orderBy('nama_produk')
            ->pluck('nama_produk', 'id_produk');

        $labels = [];
        foreach ($productIds as $productId) {
            $labels[] = trim((string) ($labelsById[$productId] ?? 'Produk')) . ' (#' . $productId . ')';
        }

        return $this->summarizeLabels($labels);
    }

    private function summarizeMismatches(array $mismatches): string
    {
        $labels = array_map(function (array $mismatch) {
            return trim((string) ($mismatch['nama_produk'] ?? 'Produk'))
                . ' (#' . intval($mismatch['id_produk']) . ', master ' . intval($mismatch['produk_stok'])
                . ', kartu ' . intval($mismatch['latest_stok_sisa']) . ')';
        }, $mismatches);

        return $this->summarizeLabels($labels);
    }

    private function summarizeDraftMismatches(array $mismatches): string
    {
        $labels = array_map(function (array $mismatch) {
            return trim((string) ($mismatch['nama_produk'] ?? 'Produk'))
                . ' (#' . intval($mismatch['id_produk'])
                . ', master ' . intval($mismatch['actual_stock'])
                . ', histori ' . intval($mismatch['committed_stock'])
                . ', draft beli ' . intval($mismatch['draft_pembelian_qty'])
                . ', draft jual ' . intval($mismatch['draft_penjualan_qty'])
                . ', expected ' . intval($mismatch['expected_stock']) . ')';
        }, $mismatches);

        return $this->summarizeLabels($labels);
    }

    private function summarizeProjectedCurrentStockLabels(array $productIds, array $projectedCurrentStockMap): string
    {
        if (empty($productIds)) {
            return 'produk terkait';
        }

        $labelsById = DB::table('produk')
            ->whereIn('id_produk', $productIds)
            ->orderBy('nama_produk')
            ->pluck('nama_produk', 'id_produk');

        $labels = [];
        foreach ($productIds as $productId) {
            $labels[] = trim((string) ($labelsById[$productId] ?? 'Produk'))
                . ' (#' . intval($productId)
                . ', stok akhir ' . intval($projectedCurrentStockMap[$productId] ?? 0) . ')';
        }

        return $this->summarizeLabels($labels);
    }

    private function buildDraftAwareStockSnapshot(int $productId, ?array $committedStockOverrides = null): ?array
    {
        $product = DB::table('produk')
            ->where('id_produk', $productId)
            ->select('id_produk', 'nama_produk', 'stok')
            ->first();

        if (!$product) {
            return null;
        }

        $hasCommittedOverride = is_array($committedStockOverrides) && array_key_exists($productId, $committedStockOverrides);
        $latestCommittedRecord = $hasCommittedOverride ? null : $this->resolveLatestStockRecord($productId, true);
        $committedStock = $hasCommittedOverride
            ? intval($committedStockOverrides[$productId] ?? 0)
            : ($latestCommittedRecord ? intval($latestCommittedRecord->stok_sisa ?? 0) : 0);
        $draftPembelianQty = $this->getOpenDraftPembelianQty($productId);
        $draftPenjualanQty = $this->getOpenDraftPenjualanQty($productId);

        return [
            'id_produk' => intval($product->id_produk),
            'nama_produk' => (string) ($product->nama_produk ?? ''),
            'actual_stock' => intval($product->stok ?? 0),
            'committed_stock' => $committedStock,
            'draft_pembelian_qty' => $draftPembelianQty,
            'draft_penjualan_qty' => $draftPenjualanQty,
            'expected_stock' => $committedStock + $draftPembelianQty - $draftPenjualanQty,
            'latest_committed_waktu' => $latestCommittedRecord ? (string) $latestCommittedRecord->waktu : null,
        ];
    }

    private function buildCommittedStockMapFromReflow(array $productIds, ?string $until = null): array
    {
        $normalizedIds = $this->normalizeProductIds($productIds);
        if (empty($normalizedIds)) {
            return [];
        }

        $summary = $this->baselineStockReflowService->previewRebuildSummary($normalizedIds, $until);
        $map = [];

        foreach (($summary['final_stock_by_product'] ?? []) as $row) {
            $productId = intval($row['id_produk'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $map[$productId] = intval($row['final_stock'] ?? $row['applied_stock'] ?? 0);
        }

        foreach ($normalizedIds as $productId) {
            if (!array_key_exists($productId, $map)) {
                $map[$productId] = 0;
            }
        }

        return $map;
    }

    private function detectCommittedStockDrift(array $productIds, array $committedStockOverrides): array
    {
        $drifted = [];

        foreach ($productIds as $productId) {
            $latestCommittedRecord = $this->resolveLatestStockRecord($productId, true);
            $latestCommittedStock = $latestCommittedRecord ? intval($latestCommittedRecord->stok_sisa ?? 0) : 0;
            $reflowCommittedStock = intval($committedStockOverrides[$productId] ?? 0);

            if ($latestCommittedStock === $reflowCommittedStock) {
                continue;
            }

            $product = DB::table('produk')
                ->where('id_produk', $productId)
                ->select('id_produk', 'nama_produk', 'stok')
                ->first();

            $drifted[$productId] = [
                'id_produk' => $productId,
                'nama_produk' => (string) ($product->nama_produk ?? ''),
                'master_stock' => intval($product->stok ?? 0),
                'latest_committed_stock' => $latestCommittedStock,
                'reflow_committed_stock' => $reflowCommittedStock,
                'latest_committed_waktu' => $latestCommittedRecord ? (string) $latestCommittedRecord->waktu : null,
            ];
        }

        return $drifted;
    }

    private function assertProjectedDraftCurrentStockRemainsNonNegative(
        array $productIds,
        string $contextLabel,
        array $committedStockOverrides
    ): void {
        $projectedRows = $this->buildProjectedCurrentStockRows(
            $this->buildFinalStockRowsFromCommittedStockMap($productIds, $committedStockOverrides)
        );

        $negativeProductIds = [];
        $projectedCurrentStockMap = [];

        foreach ($projectedRows as $row) {
            $productId = intval($row['id_produk'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $projectedCurrentStock = intval($row['projected_current_stock'] ?? 0);
            $projectedCurrentStockMap[$productId] = $projectedCurrentStock;

            if ($projectedCurrentStock < 0) {
                $negativeProductIds[] = $productId;
            }
        }

        $negativeProductIds = array_values(array_unique($negativeProductIds));
        if (empty($negativeProductIds)) {
            return;
        }

        throw new UnsafeStockMutationException(
            'Mutasi stok draft diblokir karena ' . $contextLabel . ' akan membuat stok saat ini menjadi minus pada '
            . $this->summarizeProjectedCurrentStockLabels($negativeProductIds, $projectedCurrentStockMap) . '.'
        );
    }

    private function buildFinalStockRowsFromCommittedStockMap(array $productIds, array $committedStockOverrides): array
    {
        $rows = [];

        foreach ($productIds as $productId) {
            $rows[] = [
                'id_produk' => intval($productId),
                'final_stock' => intval($committedStockOverrides[$productId] ?? 0),
            ];
        }

        return $rows;
    }

    private function resolveLatestStockRecord(int $productId, bool $committedOnly = false)
    {
        $query = DB::table('rekaman_stoks as rs')
            ->leftJoin('penjualan as pj', 'pj.id_penjualan', '=', 'rs.id_penjualan')
            ->leftJoin('pembelian as pb', 'pb.id_pembelian', '=', 'rs.id_pembelian')
            ->where('rs.id_produk', $productId);

        if ($committedOnly) {
            $query->where(function ($builder) {
                $builder->where(function ($nested) {
                    $nested->whereNull('rs.id_penjualan')
                        ->whereNull('rs.id_pembelian');
                })->orWhere(function ($nested) {
                    $nested->whereNotNull('rs.id_penjualan');
                    $this->applyFinalizedPenjualanConstraints($nested, 'pj');
                })->orWhere(function ($nested) {
                    $nested->whereNotNull('rs.id_pembelian');
                    $this->applyFinalizedPembelianConstraints($nested, 'pb');
                });
            });
        }

        $this->applyLatestRecordOrder($query, 'rs');

        return $query->first([
            'rs.id_rekaman_stok as id_rekaman_stok',
            'rs.id_penjualan as id_penjualan',
            'rs.id_pembelian as id_pembelian',
            'rs.waktu as waktu',
            'rs.stok_sisa as stok_sisa',
            'rs.keterangan as keterangan',
            'rs.created_at as created_at',
        ]);
    }

    private function getOpenDraftPenjualanQty(int $productId): int
    {
        $cutoff = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');

        return intval(DB::table('penjualan_detail as pd')
            ->join('penjualan as p', 'p.id_penjualan', '=', 'pd.id_penjualan')
            ->where('pd.id_produk', $productId)
            ->where('pd.jumlah', '>', 0)
            ->whereRaw('COALESCE(p.waktu, p.created_at) > ?', [$cutoff])
            ->where(function ($query) {
                $this->applyIncompletePenjualanConstraints($query, 'p');
            })
            ->sum('pd.jumlah'));
    }

    private function getOpenDraftPembelianQty(int $productId): int
    {
        $cutoff = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');

        return intval(DB::table('pembelian_detail as pd')
            ->join('pembelian as p', 'p.id_pembelian', '=', 'pd.id_pembelian')
            ->where('pd.id_produk', $productId)
            ->where('pd.jumlah', '>', 0)
            ->whereRaw('COALESCE(p.waktu_datang, p.waktu, p.created_at) > ?', [$cutoff])
            ->where(function ($query) {
                $this->applyIncompletePembelianConstraints($query, 'p');
            })
            ->sum('pd.jumlah'));
    }

    private function applyFinalizedPenjualanConstraints($query, string $alias): void
    {
        $query
            ->whereRaw('COALESCE(' . $this->qualifyColumn($alias, 'total_item') . ', 0) > 0')
            ->whereRaw('COALESCE(' . $this->qualifyColumn($alias, 'total_harga') . ', 0) > 0')
            ->whereRaw('COALESCE(' . $this->qualifyColumn($alias, 'bayar') . ', 0) > 0')
            ->whereRaw('COALESCE(' . $this->qualifyColumn($alias, 'diterima') . ', 0) > 0');
    }

    private function applyFinalizedPembelianConstraints($query, string $alias): void
    {
        $noFakturColumn = $this->qualifyColumn($alias, 'no_faktur');

        $query
            ->whereNotNull($noFakturColumn)
            ->whereRaw('TRIM(' . $noFakturColumn . ') != ?', [''])
            ->whereRaw('LOWER(TRIM(' . $noFakturColumn . ')) != ?', ['o'])
            ->whereRaw('COALESCE(' . $this->qualifyColumn($alias, 'total_harga') . ', 0) > 0')
            ->whereRaw('COALESCE(' . $this->qualifyColumn($alias, 'bayar') . ', 0) > 0');
    }

    private function applyIncompletePenjualanConstraints($query, string $alias): void
    {
        $query->where(function ($nested) use ($alias) {
            $nested->whereRaw('COALESCE(' . $this->qualifyColumn($alias, 'total_item') . ', 0) <= 0')
                ->orWhereRaw('COALESCE(' . $this->qualifyColumn($alias, 'total_harga') . ', 0) <= 0')
                ->orWhereRaw('COALESCE(' . $this->qualifyColumn($alias, 'bayar') . ', 0) <= 0')
                ->orWhereRaw('COALESCE(' . $this->qualifyColumn($alias, 'diterima') . ', 0) <= 0');
        });
    }

    private function applyIncompletePembelianConstraints($query, string $alias): void
    {
        $noFakturColumn = $this->qualifyColumn($alias, 'no_faktur');

        $query->where(function ($nested) use ($alias, $noFakturColumn) {
            $nested->whereNull($noFakturColumn)
                ->orWhereRaw('TRIM(COALESCE(' . $noFakturColumn . ', ?)) = ?', ['', ''])
                ->orWhereRaw('LOWER(TRIM(COALESCE(' . $noFakturColumn . ', ?))) = ?', ['', 'o'])
                ->orWhereRaw('COALESCE(' . $this->qualifyColumn($alias, 'total_harga') . ', 0) <= 0')
                ->orWhereRaw('COALESCE(' . $this->qualifyColumn($alias, 'bayar') . ', 0) <= 0');
        });
    }

    private function applyLatestRecordOrder($query, string $alias): void
    {
        $waktuColumn = $this->qualifyColumn($alias, 'waktu');
        $createdAtColumn = $this->qualifyColumn($alias, 'created_at');
        $rekamanIdColumn = $this->qualifyColumn($alias, 'id_rekaman_stok');
        $idPembelianColumn = $this->qualifyColumn($alias, 'id_pembelian');
        $idPenjualanColumn = $this->qualifyColumn($alias, 'id_penjualan');
        $keteranganColumn = $this->qualifyColumn($alias, 'keterangan');

        $query
            ->orderBy($waktuColumn, 'desc')
            ->orderByRaw("CASE
                WHEN {$idPembelianColumn} IS NOT NULL THEN 0
                WHEN {$idPenjualanColumn} IS NOT NULL THEN 1
                WHEN LOWER(COALESCE({$keteranganColumn}, '')) LIKE '%stock opname%' THEN 2
                WHEN LOWER(COALESCE({$keteranganColumn}, '')) LIKE '%perubahan stok manual%' THEN 2
                WHEN LOWER(COALESCE({$keteranganColumn}, '')) LIKE '%penyesuaian stok%' THEN 2
                WHEN LOWER(COALESCE({$keteranganColumn}, '')) LIKE '%saldo awal stok%' THEN 2
                ELSE 3
            END DESC")
            ->orderBy($createdAtColumn, 'desc')
            ->orderBy($rekamanIdColumn, 'desc');
    }

    private function qualifyColumn(string $alias, string $column): string
    {
        return $alias === '' ? $column : $alias . '.' . $column;
    }

    private function summarizeLabels(array $labels): string
    {
        $visible = array_slice($labels, 0, 5);
        $remaining = count($labels) - count($visible);

        if ($remaining > 0) {
            $visible[] = 'dan ' . $remaining . ' produk lain';
        }

        return implode(', ', $visible);
    }
}