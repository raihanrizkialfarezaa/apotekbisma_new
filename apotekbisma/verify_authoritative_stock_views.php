<?php

use App\Http\Controllers\KartuStokController;
use App\Http\Controllers\PenjualanDetailController;
use App\Http\Controllers\ProdukController;
use App\Models\Produk;
use App\Services\StockRuntimeIntegrityService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$productId = intval($argv[1] ?? 0);
$transactionId = intval($argv[2] ?? 0);

if ($productId <= 0 || $transactionId <= 0) {
    fwrite(STDERR, "Usage: php verify_authoritative_stock_views.php <id_produk> <id_penjualan>\n");
    exit(1);
}

$stockMap = app(StockRuntimeIntegrityService::class)
    ->previewCurrentSellableStockMap([$productId], null, true);

$produk = Produk::where('id_produk', $productId)->first();
$produkCollection = new Collection([$produk]);

$produkController = app(ProdukController::class);
$produkOverlayMethod = new ReflectionMethod(ProdukController::class, 'applyAuthoritativeDisplayStockOverlay');
$produkOverlayMethod->setAccessible(true);
$overlayed = $produkOverlayMethod->invoke($produkController, $produkCollection);

$kartuController = app(KartuStokController::class);
$rowsMethod = new ReflectionMethod(KartuStokController::class, 'buildStockCardRows');
$rowsMethod->setAccessible(true);
$stockCardRows = $rowsMethod->invoke($kartuController, $productId, new Request(['date_filter' => 'all']), true);

$draftController = app(PenjualanDetailController::class);
$draftResponse = $draftController->data($transactionId);
$draftContent = $draftResponse->getContent();

fwrite(STDOUT, "Verifikasi view stok otoritatif produk #{$productId}, transaksi #{$transactionId}\n");
fwrite(STDOUT, str_repeat('=', 90) . "\n");
fwrite(STDOUT, 'Service raw current stock        : ' . intval($stockMap[$productId]['raw_current_stock'] ?? 0) . "\n");
fwrite(STDOUT, 'Service clamped display stock    : ' . intval($stockMap[$productId]['display_stock'] ?? 0) . "\n");
fwrite(STDOUT, 'ProdukController display stock   : ' . intval($overlayed->first()->stok ?? 0) . "\n");

$draftRow = collect($stockCardRows)->first(function (array $row) use ($transactionId) {
    return str_contains((string) ($row['keterangan'] ?? ''), 'ID Transaksi: ' . $transactionId);
});

if ($draftRow) {
    fwrite(STDOUT, 'Kartu stok row tx target         : ' . trim(strip_tags((string) ($draftRow['stok_sisa'] ?? ''))) . "\n");
    fwrite(STDOUT, 'Kartu stok label tx target       : ' . trim(preg_replace('/\s+/', ' ', strip_tags((string) ($draftRow['keterangan'] ?? '')))) . "\n");
}

$summaryRow = collect($stockCardRows)->first(function (array $row) {
    return ($row['id'] ?? null) === PHP_INT_MAX;
});
if ($summaryRow) {
    fwrite(STDOUT, 'Kartu stok summary current stock : ' . trim(strip_tags((string) ($summaryRow['stok_sisa'] ?? ''))) . "\n");
}

fwrite(STDOUT, 'Draft HTML contains -1?          : ' . (str_contains($draftContent, '= -1') || str_contains($draftContent, '> -1 <') || str_contains($draftContent, '1 = -1') ? 'YA' : 'TIDAK') . "\n");
fwrite(STDOUT, 'Draft HTML contains warning?     : ' . (str_contains($draftContent, 'Stok akhir perlu perhatian') ? 'YA' : 'TIDAK') . "\n");