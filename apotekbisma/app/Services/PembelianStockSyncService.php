<?php

namespace App\Services;

use App\Models\RekamanStok;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PembelianStockSyncService
{
    private BaselineStockReflowService $baselineStockReflowService;
    private StockRuntimeIntegrityService $stockRuntimeIntegrityService;

    public function __construct(
        BaselineStockReflowService $baselineStockReflowService,
        StockRuntimeIntegrityService $stockRuntimeIntegrityService
    )
    {
        $this->baselineStockReflowService = $baselineStockReflowService;
        $this->stockRuntimeIntegrityService = $stockRuntimeIntegrityService;
    }

    public function syncAffectedProducts(array $productIds, ?int $idPembelian = null, array $options = []): array
    {
        $normalizedIds = $this->normalizeProductIds($productIds);

        if (empty($normalizedIds)) {
            return [
                'strategy' => 'noop',
                'products_synced' => 0,
                'product_ids' => [],
            ];
        }

        $snapshot = isset($options['pembelian_snapshot']) && is_array($options['pembelian_snapshot'])
            ? $options['pembelian_snapshot']
            : null;

        $forceReflow = (bool) ($options['force_reflow'] ?? false);
        $useReflow = $forceReflow || $this->shouldUsePostCutoffReflow($idPembelian, $snapshot);
        $hasContext = $idPembelian && $idPembelian > 0;

        if ($hasContext && !$useReflow && !$forceReflow) {
            // Draft pembelian sudah mengubah produk.stok secara atomic di level transaksi.
            // Hindari fallback recalculate global agar histori lama yang korup tidak menimpa stok draft.
            $this->stockRuntimeIntegrityService->assertLatestStockConsistency(
                $normalizedIds,
                'sinkronisasi draft pembelian'
            );

            return [
                'strategy' => 'skip_draft_sync',
                'products_synced' => count($normalizedIds),
                'product_ids' => $normalizedIds,
            ];
        }

        if ($useReflow && $this->canExecuteReflow()) {
            try {
                $summary = $this->baselineStockReflowService->rebuildProducts(
                    $normalizedIds,
                    Carbon::now()->format('Y-m-d H:i:s')
                );
                $this->stockRuntimeIntegrityService->assertLatestStockConsistency(
                    $normalizedIds,
                    'sinkronisasi pembelian'
                );

                return [
                    'strategy' => 'baseline_reflow',
                    'products_synced' => count($normalizedIds),
                    'product_ids' => $normalizedIds,
                    'summary' => $summary,
                ];
            } catch (\Throwable $e) {
                Log::warning('Sinkronisasi pembelian via baseline reflow gagal, fallback ke recalculate', [
                    'id_pembelian' => $idPembelian,
                    'product_ids' => $normalizedIds,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $errors = [];

        foreach ($normalizedIds as $productId) {
            try {
                RekamanStok::recalculateStock($productId);
            } catch (\Throwable $e) {
                $errors[] = [
                    'id_produk' => $productId,
                    'message' => $e->getMessage(),
                ];

                Log::warning('Fallback recalculate stok pembelian gagal', [
                    'id_pembelian' => $idPembelian,
                    'id_produk' => $productId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($errors)) {
            throw new \RuntimeException(
                'Fallback recalculate stok pembelian gagal pada produk: '
                . implode(', ', array_map(function (array $error) {
                    return '#' . intval($error['id_produk'] ?? 0);
                }, $errors))
            );
        }

        $this->stockRuntimeIntegrityService->assertLatestStockConsistency(
            $normalizedIds,
            'fallback recalculate pembelian'
        );

        return [
            'strategy' => 'recalculate',
            'products_synced' => count($normalizedIds),
            'product_ids' => $normalizedIds,
            'errors' => $errors,
        ];
    }

    private function shouldUsePostCutoffReflow(?int $idPembelian, ?array $snapshot = null): bool
    {
        $context = $snapshot ?? $this->loadPembelianSnapshot($idPembelian);

        if (!$context) {
            return false;
        }

        if (!$this->isPembelianFinalized($context)) {
            return false;
        }

        $waktu = $this->resolvePembelianWaktu($context);
        if ($waktu === null) {
            return false;
        }

        return $waktu > $this->resolveCutoff();
    }

    private function loadPembelianSnapshot(?int $idPembelian): ?array
    {
        if (!$idPembelian || $idPembelian <= 0) {
            return null;
        }

        $row = DB::table('pembelian')
            ->select('id_pembelian', 'no_faktur', 'total_harga', 'bayar', 'waktu', 'waktu_datang', 'created_at')
            ->where('id_pembelian', $idPembelian)
            ->first();

        if (!$row) {
            return null;
        }

        return [
            'id_pembelian' => intval($row->id_pembelian),
            'no_faktur' => $row->no_faktur,
            'total_harga' => $row->total_harga,
            'bayar' => $row->bayar,
            'waktu' => $row->waktu,
            'waktu_datang' => $row->waktu_datang,
            'created_at' => $row->created_at,
        ];
    }

    private function normalizeProductIds(array $productIds): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $productIds), function ($id) {
            return $id > 0;
        })));
    }

    private function isPembelianFinalized(array $snapshot): bool
    {
        $noFaktur = trim((string) ($snapshot['no_faktur'] ?? ''));
        if ($noFaktur === '' || strtolower($noFaktur) === 'o') {
            return false;
        }

        return intval($snapshot['total_harga'] ?? 0) > 0
            && intval($snapshot['bayar'] ?? 0) > 0;
    }

    private function resolvePembelianWaktu(array $snapshot): ?string
    {
        $candidate = $snapshot['waktu_datang']
            ?? $snapshot['waktu']
            ?? $snapshot['created_at']
            ?? null;

        if (!$candidate) {
            return null;
        }

        try {
            return Carbon::parse($candidate)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveCutoff(): string
    {
        return (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');
    }

    private function canExecuteReflow(): bool
    {
        return Carbon::now()->format('Y-m-d H:i:s') > $this->resolveCutoff();
    }
}
