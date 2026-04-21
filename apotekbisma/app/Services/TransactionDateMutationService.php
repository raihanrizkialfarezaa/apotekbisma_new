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
        $projectedReflowSummary = $this->baselineStockReflowService->previewRebuildSummary(
            $productIds,
            $resolvedUntil
        );

        $this->assertProjectedCurrentStockRemainsPositive(
            $projectedReflowSummary,
            $contextLabel
        );

        $reflowSummary = $this->stockRuntimeIntegrityService->rebuildAndValidate(
            $productIds,
            $contextLabel,
            false,
            $resolvedUntil,
            true
        );

        return $reflowSummary;
    }

    private function handleFinalDateChange(string $transactionType, int $transactionId, string $referenceLabel, $oldWaktu, $newWaktu, array $productIds): array
    {
        $resolvedOldWaktu = $this->normalizeWaktu($oldWaktu);
        $resolvedNewWaktu = $this->normalizeWaktu($newWaktu);
        $minimumAllowedWaktu = $this->resolveMinimumAllowedFinalTransactionWaktu();

        if ($resolvedOldWaktu === $resolvedNewWaktu) {
            return [
                'changed' => false,
                'transaction_type' => $transactionType,
                'transaction_id' => $transactionId,
            ];
        }

        $this->assertTransactionNotFuture($resolvedNewWaktu);

        if ($resolvedOldWaktu < $minimumAllowedWaktu || $resolvedNewWaktu < $minimumAllowedWaktu) {
            throw new \RuntimeException('Perubahan tanggal final diblokir karena transaksi tidak boleh dimundurkan lebih lama dari ' . $minimumAllowedWaktu . ' (sehari setelah cutoff baseline).');
        }

        $resolvedUntil = $this->transactionLogicalClockService->now()->format('Y-m-d H:i:s');
        $projectedReflowSummary = $this->baselineStockReflowService->previewRebuildSummary(
            $productIds,
            $resolvedUntil
        );

        $this->assertProjectedCurrentStockRemainsPositive(
            $projectedReflowSummary,
            'perubahan waktu ' . $referenceLabel
        );

        $reflowSummary = $this->stockRuntimeIntegrityService->rebuildAndValidate(
            $productIds,
            'perubahan waktu ' . $referenceLabel,
            false,
            $resolvedUntil,
            true
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
                'cutoff' => (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59'),
                'minimum_allowed_waktu' => $minimumAllowedWaktu,
                'until' => $reflowSummary['until'] ?? null,
                'csv_delimiter' => $reflowSummary['csv_delimiter'] ?? null,
                'products_rebuilt' => intval($reflowSummary['products_rebuilt'] ?? 0),
                'negative_event_product_ids' => array_values(array_map('intval', $reflowSummary['negative_event_product_ids'] ?? [])),
                'non_positive_final_stock_product_ids' => array_values(array_map('intval', $reflowSummary['non_positive_final_stock_product_ids'] ?? [])),
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
            'non_positive_final_stock_product_ids' => array_values(array_map('intval', $reflowSummary['non_positive_final_stock_product_ids'] ?? [])),
        ]);

        return [
            'changed' => true,
            'audit_id' => $auditId,
            'transaction_type' => $transactionType,
            'transaction_id' => $transactionId,
            'reflow' => $reflowSummary,
        ];
    }

    private function assertProjectedCurrentStockRemainsPositive(array $reflowSummary, string $contextLabel): void
    {
        $projectedCurrentStockRows = $this->stockRuntimeIntegrityService->buildProjectedCurrentStockRows(
            $reflowSummary['final_stock_by_product'] ?? []
        );

        $negativeProductIds = [];
        foreach ($projectedCurrentStockRows as $row) {
            $productId = intval($row['id_produk'] ?? 0);
            $projectedCurrentStock = intval($row['projected_current_stock'] ?? 0);

            if ($productId > 0 && $projectedCurrentStock < 0) {
                $negativeProductIds[] = $productId;
            }
        }

        $negativeProductIds = array_values(array_unique($negativeProductIds));

        if (empty($negativeProductIds)) {
            return;
        }

        $currentStockMap = $this->buildProjectedCurrentStockMap($projectedCurrentStockRows);
        $productSummary = $this->summarizeProjectedCurrentStockLabels($negativeProductIds, $currentStockMap);

        Log::warning('Stock mutation blocked because projected current stock after draft reservations becomes negative', [
            'context_label' => $contextLabel,
            'negative_current_stock_product_ids' => $negativeProductIds,
            'final_stock_by_product' => $this->buildFinalStockMap($reflowSummary['final_stock_by_product'] ?? []),
            'projected_current_stock_rows' => $projectedCurrentStockRows,
        ]);

        throw new UnsafeStockMutationException(
            'Mutasi stok diblokir karena ' . $contextLabel . ' akan membuat stok akhir saat ini menjadi minus pada ' . $productSummary . '.'
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

    private function buildFinalStockMap(array $finalStockRows): array
    {
        $finalStockMap = [];

        foreach ($finalStockRows as $row) {
            $productId = intval($row['id_produk'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $finalStockMap[$productId] = intval($row['final_stock'] ?? 0);
        }

        return $finalStockMap;
    }

    private function buildProjectedCurrentStockMap(array $projectedCurrentStockRows): array
    {
        $currentStockMap = [];

        foreach ($projectedCurrentStockRows as $row) {
            $productId = intval($row['id_produk'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $currentStockMap[$productId] = intval($row['projected_current_stock'] ?? 0);
        }

        return $currentStockMap;
    }

    private function summarizeFinalStockLabels(array $productIds, array $finalStockMap): string
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
            $finalStock = intval($finalStockMap[$productId] ?? 0);
            $labels[] = $name . ' (#' . $productId . ', stok akhir ' . $finalStock . ')';
        }

        $visibleLabels = array_slice($labels, 0, 5);
        $remaining = count($labels) - count($visibleLabels);

        if ($remaining > 0) {
            $visibleLabels[] = 'dan ' . $remaining . ' produk lain';
        }

        return implode(', ', $visibleLabels);
    }

    private function summarizeProjectedCurrentStockLabels(array $productIds, array $currentStockMap): string
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
            $currentStock = intval($currentStockMap[$productId] ?? 0);
            $labels[] = $name . ' (#' . $productId . ', stok akhir ' . $currentStock . ')';
        }

        $visibleLabels = array_slice($labels, 0, 5);
        $remaining = count($labels) - count($visibleLabels);

        if ($remaining > 0) {
            $visibleLabels[] = 'dan ' . $remaining . ' produk lain';
        }

        return implode(', ', $visibleLabels);
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
        $minimumAllowedWaktu = $this->resolveMinimumAllowedFinalTransactionWaktu();

        if ($resolvedWaktu < $minimumAllowedWaktu) {
            throw new \RuntimeException('Transaksi final tidak boleh disimpan lebih lama dari ' . $minimumAllowedWaktu . ' (sehari setelah cutoff baseline).');
        }

        $this->assertTransactionNotFuture($resolvedWaktu);
    }

    private function resolveMinimumAllowedFinalTransactionWaktu(): string
    {
        return Carbon::parse((string) config('stock.cutoff_datetime', '2025-12-31 23:59:59'))
            ->addDay()
            ->startOfDay()
            ->format('Y-m-d H:i:s');
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