<?php
/**
 * ============================================================================
 * FINAL FIX - CORRECT ORDERING FOR TIMESTAMP COLLISIONS
 * ============================================================================
 * 
 * Masalah: Ada multiple records dengan waktu yang sama
 * Solusi: Gunakan ordering yang konsisten (waktu DESC, id_rekaman_stok DESC)
 *         untuk mendapatkan record yang benar-benar terakhir
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "============================================================\n";
echo "  FINAL FIX - CORRECT TIMESTAMP COLLISION ORDERING\n";
echo "============================================================\n\n";

$dryRun = !isset($argv[1]) || $argv[1] !== '--execute';
echo "Mode: " . ($dryRun ? "DRY RUN" : "EXECUTE") . "\n\n";

$products = DB::table('produk')->get();
$fixCount = 0;

foreach ($products as $product) {
    // Get truly last record dengan ordering yang benar
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')  // TIE-BREAKER: ID terbesar = record terakhir
        ->first();
    
    if (!$lastRekaman) continue;
    
    $correctStock = max(0, intval($lastRekaman->stok_sisa));
    $currentStock = intval($product->stok);
    
    if ($currentStock != $correctStock) {
        echo "[FIX] {$product->id_produk} {$product->nama_produk}: {$currentStock} -> {$correctStock}\n";
        
        if (!$dryRun) {
            DB::table('produk')
                ->where('id_produk', $product->id_produk)
                ->update(['stok' => $correctStock]);
        }
        
        $fixCount++;
    }
}

echo "\nFixed: {$fixCount} products\n\n";

// Verify using SAME ordering
echo "============================================================\n";
echo "  VERIFICATION (using correct ordering)\n";
echo "============================================================\n\n";

$products = DB::table('produk')->get();
$mismatches = 0;

foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman && intval($product->stok) != intval($lastRekaman->stok_sisa)) {
        $mismatches++;
        echo "[MISMATCH] {$product->id_produk}: stok={$product->stok}, rekaman={$lastRekaman->stok_sisa}\n";
    }
}

echo "\nMismatches: {$mismatches}\n";

if ($mismatches == 0) {
    echo "\nSUCCESS! All products synced correctly.\n";
}

// Also verify that continuity and gap are OK
echo "\n============================================================\n";
echo "  ADDITIONAL VERIFICATION\n";
echo "============================================================\n\n";

$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$baseline = [];
$handle = fopen($csvFile, 'r');
$header = fgetcsv($handle);
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) >= 3 && !empty($row[0])) {
        $baseline[intval($row[0])] = intval($row[2]);
    }
}
fclose($handle);

$cutoffDate = '2025-12-31 23:59:59';

// Check gap
$gapIssues = 0;
foreach ($baseline as $productId => $opnameStock) {
    $firstAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->first();
    
    if ($firstAfter && intval($firstAfter->stok_awal) != $opnameStock) {
        $gapIssues++;
    }
}
echo "Gap issues (opname -> first 2026): {$gapIssues}\n";

// Check continuity
$contIssues = 0;
$allProductIds = DB::table('rekaman_stoks')->distinct()->pluck('id_produk')->toArray();
foreach ($allProductIds as $productId) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    $prevSisa = null;
    foreach ($records as $r) {
        if ($prevSisa !== null && intval($r->stok_awal) != $prevSisa) {
            $contIssues++;
            break;
        }
        $prevSisa = intval($r->stok_sisa);
    }
}
echo "Continuity issues: {$contIssues}\n";

$totalIssues = $mismatches + $gapIssues + $contIssues;
echo "\nTOTAL ISSUES: {$totalIssues}\n";

if ($totalIssues == 0) {
    echo "\n============================================================\n";
    echo "  SUCCESS! ALL 0 ISSUES - 100% CONSISTENT!\n";
    echo "============================================================\n";
}
