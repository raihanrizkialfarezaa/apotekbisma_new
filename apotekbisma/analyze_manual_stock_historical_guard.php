<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\BaselineStockReflowService;
use App\Services\StockRuntimeIntegrityService;
use Illuminate\Support\Facades\DB;

$productId = isset($argv[1]) ? max(0, intval($argv[1])) : 862;
$cutoff = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');

function out(string $text = ''): void
{
    echo $text . PHP_EOL;
}

$product = DB::table('produk')
    ->where('id_produk', $productId)
    ->first(['id_produk', 'nama_produk', 'stok']);

if (!$product) {
    fwrite(STDERR, 'Produk tidak ditemukan untuk id ' . $productId . PHP_EOL);
    exit(1);
}

$blockingDrafts = DB::table('penjualan_detail as pd')
    ->join('penjualan as p', 'pd.id_penjualan', '=', 'p.id_penjualan')
    ->where('pd.id_produk', $productId)
    ->where('pd.jumlah', '>', 0)
    ->whereRaw('COALESCE(p.waktu, p.created_at) > ?', [$cutoff])
    ->where(function ($query) {
        $query->where('p.total_item', '<=', 0)
            ->orWhere('p.total_harga', '<=', 0)
            ->orWhere('p.bayar', '<=', 0)
            ->orWhere('p.diterima', '<=', 0);
    })
    ->orderBy('p.id_penjualan')
    ->distinct()
    ->pluck('p.id_penjualan')
    ->map(function ($draftId) {
        return 'penjualan#' . intval($draftId);
    })
    ->all();

$reflowSummary = app(BaselineStockReflowService::class)->previewRebuildSummary([$productId]);
$stockMap = app(StockRuntimeIntegrityService::class)->previewCurrentSellableStockMap([$productId], null, false);
$snapshot = $stockMap[$productId] ?? [];

$negativeEventCount = intval($reflowSummary['negative_event_count'] ?? 0);
$legacyBlocked = !empty($blockingDrafts) || $negativeEventCount > 0;
$newBlocked = !empty($blockingDrafts);

out('Database: ' . DB::connection()->getDatabaseName());
out('Produk: #' . intval($product->id_produk) . ' | ' . (string) $product->nama_produk . ' | stok=' . intval($product->stok));
out('Cutoff: ' . $cutoff);
out('Committed stock: ' . intval($snapshot['committed_stock'] ?? 0));
out('Raw current stock: ' . intval($snapshot['raw_current_stock'] ?? 0));
out('Negative historical event count: ' . $negativeEventCount);
out('Blocking drafts after cutoff: ' . json_encode($blockingDrafts));
out('Legacy manual stock rule blocked: ' . ($legacyBlocked ? 'YES' : 'NO'));
out('Current manual stock rule blocked: ' . ($newBlocked ? 'YES' : 'NO'));