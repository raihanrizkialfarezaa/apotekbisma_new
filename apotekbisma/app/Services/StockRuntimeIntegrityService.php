<?php

namespace App\Services;

use App\Exceptions\UnsafeStockMutationException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
        ?string $until = null
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

        $this->assertLatestStockConsistency($normalizedIds, $contextLabel);

        return $summary;
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

    public function assertDraftStockConsistency(array $productIds, string $contextLabel): void
    {
        $normalizedIds = $this->normalizeProductIds($productIds);
        if (empty($normalizedIds)) {
            return;
        }

        $mismatches = [];

        foreach ($normalizedIds as $productId) {
            $snapshot = $this->buildDraftAwareStockSnapshot($productId);
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

    public function reconcileDraftStockConsistency(array $productIds): array
    {
        $normalizedIds = $this->normalizeProductIds($productIds);
        if (empty($normalizedIds)) {
            return [];
        }

        $reconciled = [];

        foreach ($normalizedIds as $productId) {
            $snapshot = $this->buildDraftAwareStockSnapshot($productId);
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

    private function buildDraftAwareStockSnapshot(int $productId): ?array
    {
        $product = DB::table('produk')
            ->where('id_produk', $productId)
            ->select('id_produk', 'nama_produk', 'stok')
            ->first();

        if (!$product) {
            return null;
        }

        $latestCommittedRecord = $this->resolveLatestStockRecord($productId, true);
        $committedStock = $latestCommittedRecord ? intval($latestCommittedRecord->stok_sisa ?? 0) : 0;
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