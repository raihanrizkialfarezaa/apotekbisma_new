<?php

use App\Http\Controllers\PenjualanController;
use App\Models\Penjualan;
use App\Services\BaselineStockReflowService;
use App\Services\StockRuntimeIntegrityService;
use App\Services\TransactionDateMutationService;
use App\Services\TransactionLogicalClockService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$penjualanId = intval($argv[1] ?? 2512);
$selectedDate = (string) ($argv[2] ?? '2026-04-19');
$browserNowIso = (string) ($argv[3] ?? '2026-04-21T11:30:14+07:00');
$timezoneOffsetMinutes = intval($argv[4] ?? -420);

$controller = app(PenjualanController::class);
$resolveSubmittedMethod = new ReflectionMethod(PenjualanController::class, 'resolveSubmittedPenjualanWaktu');
$resolveSubmittedMethod->setAccessible(true);

$baselineReflowService = app(BaselineStockReflowService::class);
$dateMutationService = app(TransactionDateMutationService::class);
$logicalClockService = app(TransactionLogicalClockService::class);
$integrityService = app(StockRuntimeIntegrityService::class);

$request = Request::create('/transaksi/simpan', 'POST', [
    'waktu_tanggal' => $selectedDate,
    'browser_now_iso' => $browserNowIso,
    'browser_timezone_offset_minutes' => $timezoneOffsetMinutes,
]);

$penjualan = Penjualan::query()->findOrFail($penjualanId);
$originalWaktu = (string) ($penjualan->waktu ?? $penjualan->created_at);
$resolvedWaktu = $resolveSubmittedMethod->invoke($controller, $request);
$productIds = DB::table('penjualan_detail')
    ->where('id_penjualan', $penjualanId)
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

$resolvedUntil = $logicalClockService->now()->format('Y-m-d H:i:s');

DB::beginTransaction();

try {
    DB::table('penjualan')
        ->where('id_penjualan', $penjualanId)
        ->update([
            'waktu' => $resolvedWaktu,
            'updated_at' => now(),
        ]);

    $projectedSummary = $baselineReflowService->previewRebuildSummary(
        $productIds,
        $resolvedUntil
    );

    $refreshedPenjualan = Penjualan::query()->findOrFail($penjualanId);

    $syncStatus = 'LOLOS';
    $syncMessage = 'Sinkronisasi finalisasi penjualan berhasil.';

    try {
        $dateMutationService->synchronizeFinalizedPenjualan($refreshedPenjualan);
    } catch (Throwable $throwable) {
        $syncStatus = 'GAGAL';
        $syncMessage = $throwable->getMessage();
    }

    fwrite(STDOUT, "Analisis backdate penjualan\n");
    fwrite(STDOUT, str_repeat('=', 72) . "\n");
    fwrite(STDOUT, "ID penjualan              : {$penjualanId}\n");
    fwrite(STDOUT, "Waktu asli                : {$originalWaktu}\n");
    fwrite(STDOUT, "Tanggal dipilih           : {$selectedDate}\n");
    fwrite(STDOUT, "Browser now               : {$browserNowIso}\n");
    fwrite(STDOUT, "Waktu hasil submit        : {$resolvedWaktu}\n");
    fwrite(STDOUT, "Produk terdampak          : " . implode(', ', $productIds) . "\n");
    fwrite(STDOUT, "Until logical clock       : {$resolvedUntil}\n");
    fwrite(STDOUT, "Negatif projected count   : " . intval($projectedSummary['negative_event_count'] ?? 0) . "\n");
    fwrite(STDOUT, "Negatif projected produk  : " . implode(', ', array_map('intval', $projectedSummary['negative_event_product_ids'] ?? [])) . "\n");
    fwrite(STDOUT, "Final <= 0 count          : " . intval($projectedSummary['products_with_non_positive_final_stock'] ?? 0) . "\n");
    fwrite(STDOUT, "Final <= 0 produk         : " . implode(', ', array_map('intval', $projectedSummary['non_positive_final_stock_product_ids'] ?? [])) . "\n");

    $projectedCurrentStockRows = $integrityService->buildProjectedCurrentStockRows($projectedSummary['final_stock_by_product'] ?? []);
    $negativeCurrentProductIds = [];
    $projectedCurrentStockLines = [];
    foreach ($projectedCurrentStockRows as $row) {
        $productId = intval($row['id_produk'] ?? 0);
        if ($productId <= 0) {
            continue;
        }

        $projectedCurrentStock = intval($row['projected_current_stock'] ?? 0);
        $projectedCurrentStockLines[] = '#' . $productId . '=' . $projectedCurrentStock;

        if ($projectedCurrentStock < 0) {
            $negativeCurrentProductIds[] = $productId;
        }
    }

    fwrite(STDOUT, "Current < 0 count         : " . count($negativeCurrentProductIds) . "\n");
    fwrite(STDOUT, "Current < 0 produk        : " . implode(', ', $negativeCurrentProductIds) . "\n");
    fwrite(STDOUT, "Current stock map         : " . implode(', ', $projectedCurrentStockLines) . "\n");

    $finalStockLines = [];
    foreach (($projectedSummary['final_stock_by_product'] ?? []) as $row) {
        $productId = intval($row['id_produk'] ?? 0);
        if ($productId <= 0) {
            continue;
        }

        $finalStockLines[] = '#' . $productId . '=' . intval($row['final_stock'] ?? 0);
    }

    fwrite(STDOUT, "Final stock map           : " . implode(', ', $finalStockLines) . "\n");
    fwrite(STDOUT, "Status finalisasi         : {$syncStatus}\n");
    fwrite(STDOUT, "Pesan                     : {$syncMessage}\n");
} finally {
    DB::rollBack();
}