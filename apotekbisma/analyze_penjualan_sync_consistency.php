<?php

use App\Http\Controllers\PenjualanController;
use App\Models\Penjualan;
use App\Services\BaselineStockReflowService;
use App\Services\StockRuntimeIntegrityService;
use App\Services\TransactionLogicalClockService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$penjualanId = intval($argv[1] ?? 2512);
$selectedDate = (string) ($argv[2] ?? '2026-01-01');
$browserNowIso = (string) ($argv[3] ?? '2026-04-21T11:30:14+07:00');
$timezoneOffsetMinutes = intval($argv[4] ?? -420);

$controller = app(PenjualanController::class);
$resolveSubmittedMethod = new ReflectionMethod(PenjualanController::class, 'resolveSubmittedPenjualanWaktu');
$resolveSubmittedMethod->setAccessible(true);

$integrityService = app(StockRuntimeIntegrityService::class);
$baselineReflowService = app(BaselineStockReflowService::class);
$logicalClockService = app(TransactionLogicalClockService::class);

$buildDraftAwareSnapshotMethod = new ReflectionMethod(StockRuntimeIntegrityService::class, 'buildDraftAwareStockSnapshot');
$buildDraftAwareSnapshotMethod->setAccessible(true);

$resolveLatestRecordMethod = new ReflectionMethod(StockRuntimeIntegrityService::class, 'resolveLatestStockRecord');
$resolveLatestRecordMethod->setAccessible(true);

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

    $rebuildSummary = $baselineReflowService->rebuildProducts($productIds, $resolvedUntil);

    $latestConsistencyStatus = 'LOLOS';
    $latestConsistencyMessage = '-';
    try {
        $integrityService->assertLatestStockConsistency($productIds, 'analisis konsistensi latest committed');
    } catch (Throwable $throwable) {
        $latestConsistencyStatus = 'GAGAL';
        $latestConsistencyMessage = $throwable->getMessage();
    }

    $draftReconciled = $integrityService->reconcileDraftStockConsistency($productIds);

    $draftConsistencyStatus = 'LOLOS';
    $draftConsistencyMessage = '-';
    try {
        $integrityService->assertDraftStockConsistency($productIds, 'analisis konsistensi draft-aware');
    } catch (Throwable $throwable) {
        $draftConsistencyStatus = 'GAGAL';
        $draftConsistencyMessage = $throwable->getMessage();
    }

    fwrite(STDOUT, "Analisis konsistensi sync penjualan\n");
    fwrite(STDOUT, str_repeat('=', 72) . "\n");
    fwrite(STDOUT, "ID penjualan              : {$penjualanId}\n");
    fwrite(STDOUT, "Waktu asli                : {$originalWaktu}\n");
    fwrite(STDOUT, "Tanggal dipilih           : {$selectedDate}\n");
    fwrite(STDOUT, "Waktu hasil submit        : {$resolvedWaktu}\n");
    fwrite(STDOUT, "Until logical clock       : {$resolvedUntil}\n");
    fwrite(STDOUT, "Produk terdampak          : " . implode(', ', $productIds) . "\n");
    fwrite(STDOUT, "Rebuild produk            : " . intval($rebuildSummary['products_rebuilt'] ?? 0) . "\n");
    fwrite(STDOUT, "Latest consistency        : {$latestConsistencyStatus}\n");
    fwrite(STDOUT, "Latest message            : {$latestConsistencyMessage}\n");
    fwrite(STDOUT, "Draft-aware consistency   : {$draftConsistencyStatus}\n");
    fwrite(STDOUT, "Draft-aware message       : {$draftConsistencyMessage}\n");
    fwrite(STDOUT, "Draft reconciled rows     : " . count($draftReconciled) . "\n");
    fwrite(STDOUT, str_repeat('-', 72) . "\n");

    foreach ($productIds as $productId) {
        $product = DB::table('produk')
            ->where('id_produk', $productId)
            ->select('nama_produk', 'stok')
            ->first();

        $latestRecord = $resolveLatestRecordMethod->invoke($integrityService, intval($productId), false);
        $draftAware = $buildDraftAwareSnapshotMethod->invoke($integrityService, intval($productId));

        $latestStock = $latestRecord ? intval($latestRecord->stok_sisa ?? 0) : 0;
        $actualStock = intval($product->stok ?? 0);
        $draftExpected = intval($draftAware['expected_stock'] ?? 0);
        $draftPenjualanQty = intval($draftAware['draft_penjualan_qty'] ?? 0);
        $draftPembelianQty = intval($draftAware['draft_pembelian_qty'] ?? 0);
        $committedStock = intval($draftAware['committed_stock'] ?? 0);

        $latestFlag = $latestStock === $actualStock ? 'OK' : 'MISMATCH';
        $draftFlag = $draftExpected === $actualStock ? 'OK' : 'MISMATCH';

        fwrite(
            STDOUT,
            sprintf(
                "%s (#%d) | master=%d | latest=%d [%s] | committed=%d | draft_jual=%d | draft_beli=%d | expected=%d [%s]\n",
                (string) ($product->nama_produk ?? ('Produk #' . $productId)),
                intval($productId),
                $actualStock,
                $latestStock,
                $latestFlag,
                $committedStock,
                $draftPenjualanQty,
                $draftPembelianQty,
                $draftExpected,
                $draftFlag
            )
        );
    }
} finally {
    DB::rollBack();
}