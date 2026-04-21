<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$transactionId = intval($argv[1] ?? 0);

if ($transactionId <= 0) {
    fwrite(STDERR, "Usage: php analyze_penjualan_draft_stock_ui.php <id_penjualan>\n");
    exit(1);
}

$details = DB::table('penjualan_detail as pd')
    ->join('produk as p', 'p.id_produk', '=', 'pd.id_produk')
    ->where('pd.id_penjualan', $transactionId)
    ->select('pd.id_produk', 'p.nama_produk', 'p.stok', 'pd.jumlah')
    ->orderBy('p.nama_produk')
    ->get();

if ($details->isEmpty()) {
    fwrite(STDOUT, "Tidak ada detail untuk transaksi #{$transactionId}.\n");
    exit(0);
}

$draftQtyByProduct = $details
    ->groupBy('id_produk')
    ->map(function ($rows) {
        return intval($rows->sum('jumlah'));
    });

fwrite(STDOUT, "Analisis UI kartu stok draft transaksi #{$transactionId}\n");
fwrite(STDOUT, str_repeat('=', 72) . "\n");

foreach ($draftQtyByProduct as $productId => $draftQuantity) {
    $productRow = $details->firstWhere('id_produk', $productId);
    $currentStock = intval($productRow->stok ?? 0);
    $stockBeforeTransaction = $currentStock + intval($draftQuantity);
    $stockAfterTransaction = $currentStock;
    $status = $stockAfterTransaction <= 0 ? 'WARNING-KUNING' : 'NORMAL';

    fwrite(
        STDOUT,
        sprintf(
            "%-28s | sebelum: %6d | draft: %6d | akhir: %6d | %s\n",
            (string) ($productRow->nama_produk ?? ('Produk #' . $productId)),
            $stockBeforeTransaction,
            intval($draftQuantity),
            $stockAfterTransaction,
            $status
        )
    );
}