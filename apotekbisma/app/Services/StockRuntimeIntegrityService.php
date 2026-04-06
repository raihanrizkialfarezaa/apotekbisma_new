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

            $latestRecord = DB::table('rekaman_stoks')
                ->where('id_produk', $productId)
                ->orderBy('waktu', 'desc')
                ->orderBy('id_rekaman_stok', 'desc')
                ->first(['id_rekaman_stok', 'waktu', 'stok_sisa']);

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