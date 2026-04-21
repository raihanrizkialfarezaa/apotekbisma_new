<?php

namespace App\Services;

use App\Models\Pembelian;
use App\Models\Penjualan;
use App\Services\BaselineStockReflowService;
use App\Services\StockRuntimeIntegrityService;
use App\Services\TransactionLogicalClockService;
use App\Exceptions\UnsafeStockMutationException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionDateMutationService
{
    private BaselineStockReflowService $baselineStockReflowService;
    private StockRuntimeIntegrityService $stockRuntimeIntegrityService;
    private TransactionLogicalClockService $transactionLogicalClockService;

    public function __construct(
        BaselineStockReflowService $baselineStockReflowService,
        StockRuntimeIntegrityService $stockRuntimeIntegrityService,
        TransactionLogicalClockService $transactionLogicalClockService
    )
    {
        $this->baselineStockReflowService = $baselineStockReflowService;
        $this->stockRuntimeIntegrityService = $stockRuntimeIntegrityService;
        $this->transactionLogicalClockService = $transactionLogicalClockService;
    }

    public function handlePembelianFinalDateChange(Pembelian $pembelian, $oldWaktu, $newWaktu): array
    {
        $productIds = $this->getPembelianProductIds($pembelian);

        return $this->handleFinalDateChange(
            'pembelian',
            intval($pembelian->id_pembelian),
            (string) ($pembelian->no_faktur ?? ('pembelian#' . $pembelian->id_pembelian)),
            $oldWaktu,
            $newWaktu,
            $productIds
        );
    }

    public function handlePenjualanFinalDateChange(Penjualan $penjualan, $oldWaktu, $newWaktu): array
    {
        $productIds = $this->getPenjualanProductIds($penjualan);

        return $this->handleFinalDateChange(
            'penjualan',
            intval($penjualan->id_penjualan),
            'penjualan#' . $penjualan->id_penjualan,
            $oldWaktu,
            $newWaktu,
            $productIds
        );
    }

    public function synchronizeFinalizedPembelian(Pembelian $pembelian): array
    {
        $this->assertTransactionFinalWaktuAllowed($this->resolvePembelianStockWaktu($pembelian));

        return $this->synchronizeFinalizedTransaction(
            $this->getPembelianProductIds($pembelian),
            'sinkronisasi pembelian final ' . (string) ($pembelian->no_faktur ?? ('#' . $pembelian->id_pembelian)),
            'pembelian',
            intval($pembelian->id_pembelian)
        );
    }

    public function synchronizeFinalizedPenjualan(Penjualan $penjualan): array
    {
        $this->assertTransactionFinalWaktuAllowed($penjualan->waktu ?? $penjualan->created_at);

        return $this->synchronizeFinalizedTransaction(
            $this->getPenjualanProductIds($penjualan),
            'finalisasi penjualan #' . $penjualan->id_penjualan,
            'penjualan',
            intval($penjualan->id_penjualan)
        );
    }

    private function synchronizeFinalizedTransaction(
        array $productIds,
        string $contextLabel,
        string $transactionType,
        int $transactionId
    ): array {
        $resolvedUntil = $this->transactionLogicalClockService->now()->format('Y-m-d H:i:s');
        $existingNegativeSummary = $this->baselineStockReflowService->previewRebuildSummary(
            $productIds,
            $resolvedUntil,
            $transactionType,
            $transactionId
        );

        $reflowSummary = $this->stockRuntimeIntegrityService->rebuildAndValidate(
            $productIds,
            $contextLabel,
            false,
            $resolvedUntil
        );

        $this->assertNoAdditionalNegativeHistoricalStock(
            $existingNegativeSummary,
            $reflowSummary,
            $contextLabel
        );

        return $reflowSummary;
    }

    private function handleFinalDateChange(string $transactionType, int $transactionId, string $referenceLabel, $oldWaktu, $newWaktu, array $productIds): array
    {
        $resolvedOldWaktu = $this->normalizeWaktu($oldWaktu);
        $resolvedNewWaktu = $this->normalizeWaktu($newWaktu);
        $cutoff = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');

        if ($resolvedOldWaktu === $resolvedNewWaktu) {
            return [
                'changed' => false,
                'transaction_type' => $transactionType,
                'transaction_id' => $transactionId,
            ];
        }

        $this->assertTransactionNotFuture($resolvedNewWaktu);

        if ($resolvedOldWaktu <= $cutoff || $resolvedNewWaktu <= $cutoff) {
            throw new \RuntimeException('Perubahan tanggal final diblokir karena transaksi menyentuh periode baseline yang dilindungi. Gunakan proses baseline rebuild terkontrol bila histori sebelum cutoff memang harus diubah.');
        }

        $reflowSummary = $this->stockRuntimeIntegrityService->rebuildAndValidate(
            $productIds,
            'perubahan waktu ' . $referenceLabel,
            false,
            $this->transactionLogicalClockService->now()->format('Y-m-d H:i:s')
        );
        $this->assertNoNegativeHistoricalStock(
            $transactionType,
            $transactionId,
            $referenceLabel,
            $productIds,
            $resolvedOldWaktu,
            $resolvedNewWaktu,
            $reflowSummary
        );

        $actor = auth()->user();

        $auditId = DB::table('transaction_date_change_audits')->insertGetId([
            'transaction_type' => $transactionType,
            'transaction_id' => $transactionId,
            'user_id' => $actor ? $actor->id : null,
            'user_name_snapshot' => $actor ? (string) $actor->name : null,
            'old_waktu' => $resolvedOldWaktu,
            'new_waktu' => $resolvedNewWaktu,
            'reference_label' => $referenceLabel,
            'affected_product_ids' => json_encode(array_values(array_unique(array_map('intval', $productIds))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'affected_product_count' => count(array_unique(array_map('intval', $productIds))),
            'reflow_strategy' => 'baseline_rebuild',
            'reflow_status' => 'applied',
            'negative_event_products' => intval($reflowSummary['products_with_negative_event'] ?? 0),
            'negative_event_count' => intval($reflowSummary['negative_event_count'] ?? 0),
            'metadata' => json_encode([
                'cutoff' => $cutoff,
                'until' => $reflowSummary['until'] ?? null,
                'csv_delimiter' => $reflowSummary['csv_delimiter'] ?? null,
                'products_rebuilt' => intval($reflowSummary['products_rebuilt'] ?? 0),
                'negative_event_product_ids' => array_values(array_map('intval', $reflowSummary['negative_event_product_ids'] ?? [])),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        Log::info('Final transaction date change applied with stock reflow', [
            'audit_id' => $auditId,
            'transaction_type' => $transactionType,
            'transaction_id' => $transactionId,
            'reference_label' => $referenceLabel,
            'old_waktu' => $resolvedOldWaktu,
            'new_waktu' => $resolvedNewWaktu,
            'affected_product_count' => count(array_unique(array_map('intval', $productIds))),
            'negative_event_products' => intval($reflowSummary['products_with_negative_event'] ?? 0),
            'negative_event_count' => intval($reflowSummary['negative_event_count'] ?? 0),
            'negative_event_product_ids' => array_values(array_map('intval', $reflowSummary['negative_event_product_ids'] ?? [])),
        ]);

        return [
            'changed' => true,
            'audit_id' => $auditId,
            'transaction_type' => $transactionType,
            'transaction_id' => $transactionId,
            'reflow' => $reflowSummary,
        ];
    }

    private function assertNoNegativeHistoricalStock(
        string $transactionType,
        int $transactionId,
        string $referenceLabel,
        array $productIds,
        string $oldWaktu,
        string $newWaktu,
        array $reflowSummary
    ): void {
        $negativeEventCount = intval($reflowSummary['negative_event_count'] ?? 0);
        if ($negativeEventCount <= 0) {
            return;
        }

        $negativeProductIds = array_values(array_unique(array_filter(array_map('intval', $reflowSummary['negative_event_product_ids'] ?? $productIds), function ($productId) {
            return $productId > 0;
        })));

        $productSummary = $this->summarizeProductLabels($negativeProductIds);

        Log::warning('Final transaction date change blocked because it introduces negative historical stock', [
            'transaction_type' => $transactionType,
            'transaction_id' => $transactionId,
            'reference_label' => $referenceLabel,
            'old_waktu' => $oldWaktu,
            'new_waktu' => $newWaktu,
            'negative_event_products' => intval($reflowSummary['products_with_negative_event'] ?? 0),
            'negative_event_count' => $negativeEventCount,
            'negative_event_product_ids' => $negativeProductIds,
        ]);

        throw new UnsafeStockMutationException(
            'Perubahan waktu transaksi diblokir karena akan menimbulkan stok minus historis pada ' . $productSummary . '. Perbaiki urutan waktu transaksi atau lakukan penyesuaian stok yang terkontrol terlebih dahulu.'
        );
    }

    private function assertNoAdditionalNegativeHistoricalStock(
        array $existingNegativeSummary,
        array $reflowSummary,
        string $contextLabel
    ): void {
        $existingNegativeCount = intval($existingNegativeSummary['negative_event_count'] ?? 0);
        $reflowNegativeCount = intval($reflowSummary['negative_event_count'] ?? 0);

        if ($reflowNegativeCount <= 0) {
            return;
        }

        $existingNegativeProductIds = $this->normalizeNegativeProductIds(
            $existingNegativeSummary['negative_event_product_ids'] ?? []
        );
        $reflowNegativeProductIds = $this->normalizeNegativeProductIds(
            $reflowSummary['negative_event_product_ids'] ?? []
        );
        $newNegativeProductIds = array_values(array_diff($reflowNegativeProductIds, $existingNegativeProductIds));

        if ($reflowNegativeCount <= $existingNegativeCount && empty($newNegativeProductIds)) {
            return;
        }

        $productIdsToReport = !empty($newNegativeProductIds)
            ? $newNegativeProductIds
            : $reflowNegativeProductIds;

        throw new UnsafeStockMutationException(
            'Mutasi stok diblokir karena ' . $contextLabel . ' akan menimbulkan stok minus historis pada ' . $this->summarizeProductLabels($productIdsToReport) . '.'
        );
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
            $name = trim((string) ($labelsById[$productId] ?? 'Produk'));
            $labels[] = $name . ' (#' . $productId . ')';
        }

        $visibleLabels = array_slice($labels, 0, 5);
        $remaining = count($labels) - count($visibleLabels);

        if ($remaining > 0) {
            $visibleLabels[] = 'dan ' . $remaining . ' produk lain';
        }

        return implode(', ', $visibleLabels);
    }

    private function normalizeNegativeProductIds(array $productIds): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $productIds), function ($productId) {
            return $productId > 0;
        })));
    }

    private function normalizeWaktu($value): string
    {
        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    private function assertTransactionFinalWaktuAllowed($waktu): void
    {
        $resolvedWaktu = $this->normalizeWaktu($waktu);
        $cutoff = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');

        if ($resolvedWaktu <= $cutoff) {
            throw new \RuntimeException('Transaksi final tidak boleh disimpan pada atau sebelum cutoff baseline. Gunakan proses forensik terkontrol jika histori sebelum cutoff memang harus diubah.');
        }

        $this->assertTransactionNotFuture($resolvedWaktu);
    }

    private function assertTransactionNotFuture($waktu): void
    {
        $resolvedWaktu = $this->normalizeWaktu($waktu);
        $maxFutureMinutes = max(0, (int) config('stock.max_future_transaction_minutes', 5));
        $latestAllowed = $this->transactionLogicalClockService
            ->now()
            ->addMinutes($maxFutureMinutes)
            ->format('Y-m-d H:i:s');

        if ($resolvedWaktu > $latestAllowed) {
            throw new \RuntimeException('Transaksi final tidak boleh bertanggal di masa depan. Periksa jam perangkat yang dipakai input.');
        }
    }

    private function getPembelianProductIds(Pembelian $pembelian): array
    {
        return DB::table('pembelian_detail')
            ->where('id_pembelian', $pembelian->id_pembelian)
            ->pluck('id_produk')
            ->map(function ($productId) {
                return intval($productId);
            })
            ->all();
    }

    private function resolvePembelianStockWaktu(Pembelian $pembelian): string
    {
        $candidate = $pembelian->waktu_datang
            ?? $pembelian->waktu
            ?? $pembelian->created_at
            ?? Carbon::now();

        return Carbon::parse($candidate)->format('Y-m-d H:i:s');
    }

    private function getPenjualanProductIds(Penjualan $penjualan): array
    {
        return DB::table('penjualan_detail')
            ->where('id_penjualan', $penjualan->id_penjualan)
            ->pluck('id_produk')
            ->map(function ($productId) {
                return intval($productId);
            })
            ->all();
    }
}