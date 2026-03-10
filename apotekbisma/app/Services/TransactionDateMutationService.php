<?php

namespace App\Services;

use App\Models\Pembelian;
use App\Models\Penjualan;
use App\Services\BaselineStockReflowService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionDateMutationService
{
    private BaselineStockReflowService $baselineStockReflowService;

    public function __construct(BaselineStockReflowService $baselineStockReflowService)
    {
        $this->baselineStockReflowService = $baselineStockReflowService;
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
        $this->assertTransactionIsPostCutoff($this->resolvePembelianStockWaktu($pembelian));

        return $this->baselineStockReflowService->rebuildProducts(
            $this->getPembelianProductIds($pembelian),
            Carbon::now()->format('Y-m-d H:i:s')
        );
    }

    public function synchronizeFinalizedPenjualan(Penjualan $penjualan): array
    {
        $this->assertTransactionIsPostCutoff($penjualan->waktu ?? $penjualan->created_at);

        return $this->baselineStockReflowService->rebuildProducts(
            $this->getPenjualanProductIds($penjualan),
            Carbon::now()->format('Y-m-d H:i:s')
        );
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

        if ($resolvedOldWaktu <= $cutoff || $resolvedNewWaktu <= $cutoff) {
            throw new \RuntimeException('Perubahan tanggal final diblokir karena transaksi menyentuh periode baseline yang dilindungi. Gunakan proses baseline rebuild terkontrol bila histori sebelum cutoff memang harus diubah.');
        }

        $reflowSummary = $this->baselineStockReflowService->rebuildProducts($productIds, Carbon::now()->format('Y-m-d H:i:s'));
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
        ]);

        return [
            'changed' => true,
            'audit_id' => $auditId,
            'transaction_type' => $transactionType,
            'transaction_id' => $transactionId,
            'reflow' => $reflowSummary,
        ];
    }

    private function normalizeWaktu($value): string
    {
        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    private function assertTransactionIsPostCutoff($waktu): void
    {
        $resolvedWaktu = $this->normalizeWaktu($waktu);
        $cutoff = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');

        if ($resolvedWaktu <= $cutoff) {
            throw new \RuntimeException('Transaksi final tidak boleh disimpan pada atau sebelum cutoff baseline. Gunakan proses forensik terkontrol jika histori sebelum cutoff memang harus diubah.');
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