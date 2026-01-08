<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

ini_set('memory_limit', '512M');

echo "=================================================================\n";
echo "COMPREHENSIVE STOCK VERIFICATION AFTER RECALCULATION\n";
echo "=================================================================\n";
echo "Executed: " . date('Y-m-d H:i:s') . "\n\n";

$results = [
    'total_products' => 0,
    'products_checked' => 0,
    'products_with_issues' => 0,
    'issues' => [],
    'summary' => []
];

$products = DB::table('produk')->select('id_produk', 'nama_produk', 'stok')->get();
$results['total_products'] = count($products);

echo "Step 1: Checking Stock Card Integrity...\n";
$integrityIssues = 0;

foreach ($products as $product) {
    $productId = $product->id_produk;
    $productStock = intval($product->stok);
    
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();

    if ($records->isEmpty()) {
        continue;
    }

    $firstRecord = $records->first();
    $runningStock = intval($firstRecord->stok_awal);
    $issuesForProduct = [];

    foreach ($records as $index => $record) {
        $expectedStokAwal = $runningStock;
        $stokMasuk = intval($record->stok_masuk);
        $stokKeluar = intval($record->stok_keluar);
        $calculatedSisa = $expectedStokAwal + $stokMasuk - $stokKeluar;
        
        if ($index > 0 && intval($record->stok_awal) !== $expectedStokAwal) {
            $issuesForProduct[] = "Record {$record->id_rekaman_stok}: stok_awal mismatch (expected {$expectedStokAwal}, got {$record->stok_awal})";
        }

        if (intval($record->stok_sisa) !== $calculatedSisa) {
            $issuesForProduct[] = "Record {$record->id_rekaman_stok}: stok_sisa mismatch (expected {$calculatedSisa}, got {$record->stok_sisa})";
        }

        $runningStock = intval($record->stok_sisa);
    }

    $lastRecord = $records->last();
    $lastStokSisa = intval($lastRecord->stok_sisa);
    
    if ($productStock !== $lastStokSisa) {
        $issuesForProduct[] = "Product stock ({$productStock}) != last rekaman stok_sisa ({$lastStokSisa})";
    }

    if (count($issuesForProduct) > 0) {
        $integrityIssues++;
        $results['issues'][$productId] = $issuesForProduct;
    }

    $results['products_checked']++;
}

echo "   - Products with stock card issues: {$integrityIssues}\n";

echo "\nStep 2: Checking for Negative Stock Values...\n";
$negativeStock = DB::table('produk')->where('stok', '<', 0)->count();
echo "   - Products with negative stock: {$negativeStock}\n";

if ($negativeStock > 0) {
    $negativeProducts = DB::table('produk')->where('stok', '<', 0)->get(['id_produk', 'nama_produk', 'stok']);
    foreach ($negativeProducts as $np) {
        $results['issues'][$np->id_produk][] = "Negative stock: {$np->stok}";
    }
}

echo "\nStep 3: Checking for Orphaned Stock Records...\n";
$orphanedSales = DB::table('rekaman_stoks')
    ->whereNotNull('id_penjualan')
    ->whereNotIn('id_penjualan', function($q) {
        $q->select('id_penjualan')->from('penjualan');
    })
    ->count();

$orphanedPurchases = DB::table('rekaman_stoks')
    ->whereNotNull('id_pembelian')
    ->whereNotIn('id_pembelian', function($q) {
        $q->select('id_pembelian')->from('pembelian');
    })
    ->count();

echo "   - Orphaned sales records: {$orphanedSales}\n";
echo "   - Orphaned purchase records: {$orphanedPurchases}\n";

echo "\nStep 4: Checking for Duplicate Stock Records...\n";
$duplicateSales = DB::table('rekaman_stoks')
    ->select('id_produk', 'id_penjualan', DB::raw('COUNT(*) as cnt'))
    ->whereNotNull('id_penjualan')
    ->groupBy('id_produk', 'id_penjualan')
    ->having('cnt', '>', 1)
    ->count();

$duplicatePurchases = DB::table('rekaman_stoks')
    ->select('id_produk', 'id_pembelian', DB::raw('COUNT(*) as cnt'))
    ->whereNotNull('id_pembelian')
    ->groupBy('id_produk', 'id_pembelian')
    ->having('cnt', '>', 1)
    ->count();

echo "   - Duplicate sale records: {$duplicateSales}\n";
echo "   - Duplicate purchase records: {$duplicatePurchases}\n";

echo "\nStep 5: Sampling Random Products for Manual Verification...\n";
$sampleProducts = DB::table('produk')
    ->inRandomOrder()
    ->limit(5)
    ->get(['id_produk', 'nama_produk', 'stok']);

echo "   Sample Products:\n";
foreach ($sampleProducts as $sp) {
    $lastRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $sp->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $lastSisa = $lastRecord ? $lastRecord->stok_sisa : 'N/A';
    $match = ($lastSisa === 'N/A' || intval($lastSisa) === intval($sp->stok)) ? 'OK' : 'MISMATCH';
    
    echo "   - Product {$sp->id_produk} ({$sp->nama_produk}): Stock = {$sp->stok}, Last Record = {$lastSisa} [{$match}]\n";
}

echo "\n=================================================================\n";
echo "VERIFICATION SUMMARY\n";
echo "=================================================================\n";
echo "Total Products            : {$results['total_products']}\n";
echo "Products Checked          : {$results['products_checked']}\n";
echo "Products with Issues      : " . count($results['issues']) . "\n";
echo "Negative Stock Products   : {$negativeStock}\n";
echo "Orphaned Sales Records    : {$orphanedSales}\n";
echo "Orphaned Purchase Records : {$orphanedPurchases}\n";
echo "Duplicate Sale Records    : {$duplicateSales}\n";
echo "Duplicate Purchase Records: {$duplicatePurchases}\n";

$allClear = count($results['issues']) === 0 && 
            $negativeStock === 0 && 
            $orphanedSales === 0 && 
            $orphanedPurchases === 0 && 
            $duplicateSales === 0 && 
            $duplicatePurchases === 0;

if ($allClear) {
    echo "\n[SUCCESS] All checks passed! Stock data is 100% consistent.\n";
} else {
    echo "\n[WARNING] Some issues were found. See details above.\n";
    
    if (count($results['issues']) > 0 && count($results['issues']) <= 20) {
        echo "\nDetailed Issues:\n";
        foreach ($results['issues'] as $productId => $issues) {
            echo "  Product {$productId}:\n";
            foreach ($issues as $issue) {
                echo "    - {$issue}\n";
            }
        }
    }
}

$resultFile = __DIR__ . '/verification_result_' . date('Y-m-d_His') . '.txt';
$content = "STOCK VERIFICATION RESULTS\n";
$content .= "==========================\n";
$content .= "Executed: " . date('Y-m-d H:i:s') . "\n\n";
$content .= "Total Products: {$results['total_products']}\n";
$content .= "Products with Issues: " . count($results['issues']) . "\n";
$content .= "Negative Stock: {$negativeStock}\n";
$content .= "Orphaned Sales: {$orphanedSales}\n";
$content .= "Orphaned Purchases: {$orphanedPurchases}\n";
$content .= "Duplicate Sales: {$duplicateSales}\n";
$content .= "Duplicate Purchases: {$duplicatePurchases}\n";
$content .= "\nStatus: " . ($allClear ? "ALL CHECKS PASSED" : "ISSUES FOUND") . "\n";

if (count($results['issues']) > 0) {
    $content .= "\nDETAILED ISSUES:\n";
    foreach ($results['issues'] as $productId => $issues) {
        $content .= "Product {$productId}:\n";
        foreach ($issues as $issue) {
            $content .= "  - {$issue}\n";
        }
    }
}

file_put_contents($resultFile, $content);
echo "\nResults saved to: {$resultFile}\n";
