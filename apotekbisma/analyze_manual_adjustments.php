<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=======================================================\n";
echo "  ANALYZE STOCK MANUAL ADJUSTMENTS - DETECT ANOMALIES\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

$manualAdjustments = DB::table('rekaman_stoks')
    ->whereNull('id_penjualan')
    ->whereNull('id_pembelian')
    ->where('stok_masuk', '>', 0)
    ->orderBy('waktu', 'desc')
    ->get();

echo "Found " . count($manualAdjustments) . " manual stock adjustments.\n\n";

$anomalies = [];

foreach ($manualAdjustments as $adj) {
    $nextTransaction = DB::table('rekaman_stoks')
        ->where('id_produk', $adj->id_produk)
        ->where('waktu', '>', $adj->waktu)
        ->orderBy('waktu', 'asc')
        ->first();
    
    $prevTransaction = DB::table('rekaman_stoks')
        ->where('id_produk', $adj->id_produk)
        ->where('waktu', '<', $adj->waktu)
        ->orderBy('waktu', 'desc')
        ->first();

    $isAnomaly = false;
    $reason = '';
    
    if ($prevTransaction && $prevTransaction->stok_sisa > $adj->stok_sisa) {
        $isAnomaly = true;
        $reason = "Stok turun dari {$prevTransaction->stok_sisa} ke {$adj->stok_sisa} padahal ada stok masuk {$adj->stok_masuk}";
    }
    
    if ($nextTransaction && $adj->stok_sisa + ($nextTransaction->stok_masuk ?? 0) - ($nextTransaction->stok_keluar ?? 0) != $nextTransaction->stok_sisa) {
        $product = DB::table('produk')->where('id_produk', $adj->id_produk)->first();
        
        $anomalies[] = [
            'product_id' => $adj->id_produk,
            'product_name' => $product->nama_produk ?? 'Unknown',
            'adjustment_id' => $adj->id_rekaman_stok,
            'adjustment_time' => $adj->waktu,
            'adjustment_masuk' => $adj->stok_masuk,
            'adjustment_stok_sisa' => $adj->stok_sisa,
            'prev_time' => $prevTransaction->waktu ?? 'N/A',
            'prev_stok_sisa' => $prevTransaction->stok_sisa ?? 'N/A',
            'next_time' => $nextTransaction->waktu ?? 'N/A',
            'next_expected' => $adj->stok_sisa + ($nextTransaction->stok_masuk ?? 0) - ($nextTransaction->stok_keluar ?? 0),
            'next_actual' => $nextTransaction->stok_sisa ?? 'N/A',
            'reason' => $reason ?: 'Calculation mismatch with next transaction'
        ];
    }
}

echo "=======================================================\n";
echo "  ANOMALIES FOUND: " . count($anomalies) . "\n";
echo "=======================================================\n\n";

if (!empty($anomalies)) {
    foreach ($anomalies as $a) {
        echo "Product ID: {$a['product_id']}\n";
        echo "Product: {$a['product_name']}\n";
        echo "Adjustment ID: {$a['adjustment_id']}\n";
        echo "Adjustment Time: {$a['adjustment_time']}\n";
        echo "Adjustment Masuk: {$a['adjustment_masuk']} -> Stok Sisa: {$a['adjustment_stok_sisa']}\n";
        echo "Previous: {$a['prev_time']} (Stok: {$a['prev_stok_sisa']})\n";
        echo "Next: {$a['next_time']} (Expected: {$a['next_expected']}, Actual: {$a['next_actual']})\n";
        echo "Issue: {$a['reason']}\n";
        echo "---\n\n";
    }
} else {
    echo "No anomalies detected in manual adjustments.\n";
}

echo "\n=======================================================\n";
echo "  SPECIFIC CHECK FOR PRODUCT 63\n";
echo "=======================================================\n\n";

$prod63Records = DB::table('rekaman_stoks')
    ->where('id_produk', 63)
    ->whereBetween('waktu', ['2025-12-29 00:00:00', '2026-01-03 00:00:00'])
    ->orderBy('waktu', 'asc')
    ->get();

echo "Records for Product 63 (29 Dec 2025 - 02 Jan 2026):\n\n";
echo sprintf("%-12s %-25s %-10s %-10s %-12s %-12s %s\n", 
    "ID", "Waktu", "Masuk", "Keluar", "Stok Awal", "Stok Sisa", "Type");
echo str_repeat("-", 100) . "\n";

foreach ($prod63Records as $r) {
    $type = 'Unknown';
    if ($r->id_penjualan) $type = 'Penjualan';
    elseif ($r->id_pembelian) $type = 'Pembelian';
    else $type = 'Manual';
    
    echo sprintf("%-12s %-25s %-10s %-10s %-12s %-12s %s\n",
        $r->id_rekaman_stok,
        $r->waktu,
        $r->stok_masuk ?: '-',
        $r->stok_keluar ?: '-',
        $r->stok_awal,
        $r->stok_sisa,
        $type
    );
}

echo "\nDone.\n";
