<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$productId = isset($argv[1]) ? max(0, intval($argv[1])) : 862;
$cutoff = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');

function output(string $text = ''): void
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

$pembelianDrafts = DB::table('pembelian_detail as pd')
    ->join('pembelian as p', 'pd.id_pembelian', '=', 'p.id_pembelian')
    ->where('pd.id_produk', $productId)
    ->where('pd.jumlah', '>', 0)
    ->whereRaw('COALESCE(p.waktu_datang, p.waktu, p.created_at) > ?', [$cutoff])
    ->where(function ($query) {
        $query->where('p.no_faktur', 'o')
            ->orWhere('p.no_faktur', '')
            ->orWhereNull('p.no_faktur')
            ->orWhere('p.total_harga', '<=', 0)
            ->orWhere('p.bayar', '<=', 0);
    })
    ->orderBy('p.id_pembelian')
    ->distinct()
    ->pluck('p.id_pembelian')
    ->map(function ($draftId) {
        return 'pembelian#' . intval($draftId);
    })
    ->all();

$penjualanDrafts = DB::table('penjualan_detail as pd')
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

output('Database: ' . DB::connection()->getDatabaseName());
output('Produk: #' . intval($product->id_produk) . ' | ' . (string) $product->nama_produk . ' | stok=' . intval($product->stok));
output('Cutoff: ' . $cutoff);
output('Blocking pembelian drafts after cutoff: ' . json_encode($pembelianDrafts));
output('Blocking penjualan drafts after cutoff: ' . json_encode($penjualanDrafts));
output('Blocked now: ' . (empty($pembelianDrafts) && empty($penjualanDrafts) ? 'NO' : 'YES'));