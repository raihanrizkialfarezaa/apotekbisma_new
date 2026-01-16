<?php

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$productId = 666;
$opnameStock = 6; // From CSV

ob_start();

echo "# Analysis for Product ID $productId (POSTINOR)\n\n";
echo "Baseline Stock (Opname 31 Dec 2025 from CSV): **$opnameStock**\n\n";

$product = Produk::find($productId);
if (!$product) {
    echo "Product not found in DB!\n";
    exit;
}
echo "Current Product Stock in DB (`produk.stok`): **{$product->stok}**\n\n";

// Check all records to see timeline
$allTransactions = RekamanStok::where('id_produk', $productId)
    ->orderBy('created_at', 'asc')
    ->get();

echo "## Transaction History Overview\n";
echo "Total Records: " . $allTransactions->count() . "\n";
if ($allTransactions->isNotEmpty()) {
    echo "First Record Date: " . $allTransactions->first()->created_at . "\n";
    echo "Last Record Date: " . $allTransactions->last()->created_at . "\n";
} else {
    echo "No transactions found.\n";
}

echo "\n## Transactions after cutoff (2025-12-31 23:59:59):\n";
echo "| ID | Date | Type | Masuk | Keluar | Awal (DB) | Sisa (DB) | Notes |\n";
echo "|---|---|---|---|---|---|---|---|\n";

$transactionsPostCutoff = $allTransactions->filter(function($tx) {
    return $tx->created_at > '2025-12-31 23:59:59';
});

$runningStock = $opnameStock;

foreach ($transactionsPostCutoff as $tx) {
    $prevStock = $runningStock;
    
    // Using correct columns from Model
    $masuk = intval($tx->stok_masuk);
    $keluar = intval($tx->stok_keluar);
    
    $runningStock = $runningStock + $masuk - $keluar;
    
    $note = "";
    if (intval($tx->stok_awal) != $prevStock) {
        $note .= "**Mismatch Start** (Exp: $prevStock, Act: {$tx->stok_awal}) ";
    }
    if (intval($tx->stok_sisa) != $runningStock) {
        $note .= "**Mismatch End** (Exp: $runningStock, Act: {$tx->stok_sisa}) ";
    }

    echo "| {$tx->id_rekaman_stok} | {$tx->created_at} | {$tx->jenis_transaksi} | {$masuk} | {$keluar} | {$tx->stok_awal} | {$tx->stok_sisa} | {$note} |\n";
}

echo "\n\n## Conclusion\n";
echo "Calculated Final Stock (from CSV baseline): **$runningStock**\n";
echo "Current DB Stock: **{$product->stok}**\n";

$output = ob_get_clean();
file_put_contents('debug_report_666.md', $output);
echo "Report written to debug_report_666.md\n";
