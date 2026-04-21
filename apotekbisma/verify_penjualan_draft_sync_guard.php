<?php

use App\Exceptions\UnsafeStockMutationException;
use App\Services\StockRuntimeIntegrityService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$transactionId = intval($argv[1] ?? 0);

if ($transactionId <= 0) {
    fwrite(STDERR, "Usage: php verify_penjualan_draft_sync_guard.php <id_penjualan>\n");
    exit(1);
}

$productIds = DB::table('penjualan_detail')
    ->where('id_penjualan', $transactionId)
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

if (empty($productIds)) {
    fwrite(STDOUT, "Transaksi #{$transactionId} tidak memiliki detail produk.\n");
    exit(0);
}

fwrite(STDOUT, "Verifikasi read-only draft sync guard transaksi #{$transactionId}\n");
fwrite(STDOUT, str_repeat('=', 72) . "\n");

try {
    DB::beginTransaction();

    $result = app(StockRuntimeIntegrityService::class)
        ->synchronizeDraftStockAgainstCommittedTruth($productIds, 'verifikasi draft penjualan #' . $transactionId);

    DB::rollBack();

    fwrite(STDOUT, "Guard LULUS. Tidak ada blokir.\n");
    fwrite(STDOUT, 'Drifted product IDs: ' . json_encode($result['drifted_product_ids'] ?? []) . "\n");
    fwrite(STDOUT, 'Reconciled rows    : ' . count($result['reconciled'] ?? []) . "\n");
    exit(0);
} catch (UnsafeStockMutationException $exception) {
    DB::rollBack();
    fwrite(STDOUT, "Guard MEMBLOKIR transaksi.\n");
    fwrite(STDOUT, $exception->getMessage() . "\n");
    exit(2);
} catch (Throwable $exception) {
    DB::rollBack();
    fwrite(STDERR, "Verifikasi gagal: {$exception->getMessage()}\n");
    exit(1);
}