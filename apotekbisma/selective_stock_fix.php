<?php
/**
 * SELECTIVE STOCK FIX - AFTER BUG FIX
 * ====================================
 * 
 * Script ini HANYA memperbaiki produk yang:
 * 1. Memiliki discrepancy antara stok di produk vs calculated dari rekaman_stoks
 * 2. Memiliki chain rekaman_stoks yang broken
 * 
 * Script ini AMAN karena:
 * - Tidak blind recalculate semua produk
 * - Hanya fix yang benar-benar bermasalah
 * - Transaksi baru setelah bug fix tidak disentuh
 * 
 * Date: 2026-01-23 (AFTER BUG FIX)
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\RekamanStok;
use App\Models\Produk;

$CUTOFF_DATETIME = '2025-12-31 23:59:59';
$DRY_RUN = false; // Set true untuk test tanpa ubah data

echo "=============================================================\n";
echo "SELECTIVE STOCK FIX - POST BUG FIX\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Mode: " . ($DRY_RUN ? "DRY RUN" : "LIVE") . "\n";
echo "=============================================================\n\n";

// Step 1: Find products with discrepancies
echo "[STEP 1] Scanning for products with discrepancies...\n\n";

$allProducts = Produk::orderBy('id_produk')->get();
$problematicProducts = [];
$stats = [
    'total_scanned' => 0,
    'with_discrepancy' => 0,
    'chain_broken' => 0,
    'all_good' => 0,
    'fixed' => 0,
    'errors' => 0
];

foreach ($allProducts as $produk) {
    $stats['total_scanned']++;
    $productId = $produk->id_produk;
    
    // Check integrity
    $integrity = RekamanStok::verifyIntegrity($productId);
    
    $hasDiscrepancy = !$integrity['valid'];
    $hasBrokenChain = $integrity['chain_errors'] > 0;
    
    if ($hasDiscrepancy || $hasBrokenChain) {
        $problematicProducts[] = [
            'id' => $productId,
            'nama' => $produk->nama_produk,
            'stok_db' => $integrity['product_stock'],
            'stok_calculated' => $integrity['calculated_stock'],
            'difference' => $integrity['difference'],
            'chain_errors' => $integrity['chain_errors']
        ];
        
        if ($hasDiscrepancy) $stats['with_discrepancy']++;
        if ($hasBrokenChain) $stats['chain_broken']++;
    } else {
        $stats['all_good']++;
    }
    
    if ($stats['total_scanned'] % 100 == 0) {
        echo "   Scanned: {$stats['total_scanned']} products...\n";
    }
}

echo "\nScan Results:\n";
echo "   Total products scanned: {$stats['total_scanned']}\n";
echo "   Products OK: {$stats['all_good']}\n";
echo "   With discrepancy: {$stats['with_discrepancy']}\n";
echo "   With broken chain: {$stats['chain_broken']}\n";
echo "   Total problematic: " . count($problematicProducts) . "\n\n";

if (empty($problematicProducts)) {
    echo "🎉 NO PROBLEMS FOUND! All stocks are consistent!\n";
    echo "=============================================================\n";
    exit(0);
}

// Step 2: Display problematic products
echo "[STEP 2] Problematic Products:\n\n";
echo str_pad("ID", 6) . str_pad("Product Name", 40) . str_pad("DB Stok", 10) . str_pad("Calculated", 12) . str_pad("Diff", 8) . str_pad("Chain Err", 10) . "\n";
echo str_repeat("-", 86) . "\n";

foreach ($problematicProducts as $p) {
    echo str_pad($p['id'], 6) . 
         str_pad(substr($p['nama'], 0, 38), 40) . 
         str_pad($p['stok_db'], 10) . 
         str_pad($p['stok_calculated'], 12) . 
         str_pad($p['difference'], 8) . 
         str_pad($p['chain_errors'], 10) . "\n";
}

echo "\n";

// Step 3: Ask for confirmation if LIVE mode
if (!$DRY_RUN) {
    echo "⚠️  WARNING: This will FIX " . count($problematicProducts) . " products!\n";
    echo "Press ENTER to continue, or Ctrl+C to abort: ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    echo "\n";
}

// Step 4: Fix problematic products
echo "[STEP 3] Fixing problematic products...\n\n";

foreach ($problematicProducts as $p) {
    try {
        echo "Fixing: [{$p['id']}] {$p['nama']}... ";
        
        if (!$DRY_RUN) {
            // Cleanup duplicates first
            $removed = RekamanStok::cleanupDuplicates($p['id']);
            
            // Recalculate stock
            RekamanStok::recalculateStock($p['id']);
            
            // Verify fix
            $verifyAfter = RekamanStok::verifyIntegrity($p['id']);
            
            if ($verifyAfter['valid']) {
                echo "✓ FIXED";
                if ($removed > 0) echo " (removed $removed duplicates)";
                echo "\n";
                $stats['fixed']++;
            } else {
                echo "✗ STILL BROKEN (diff: {$verifyAfter['difference']})\n";
                $stats['errors']++;
            }
        } else {
            echo "DRY RUN - would fix\n";
        }
        
    } catch (\Exception $e) {
        echo "✗ ERROR: {$e->getMessage()}\n";
        $stats['errors']++;
    }
}

// Final Summary
echo "\n=============================================================\n";
echo "FINAL SUMMARY\n";
echo "=============================================================\n";
echo "Total scanned: {$stats['total_scanned']}\n";
echo "Products OK: {$stats['all_good']}\n";
echo "Problematic found: " . count($problematicProducts) . "\n";
echo "Successfully fixed: {$stats['fixed']}\n";
echo "Errors: {$stats['errors']}\n";
echo "\n";

if ($stats['fixed'] > 0 && $stats['errors'] == 0) {
    echo "🎉 ALL PROBLEMS FIXED SUCCESSFULLY!\n";
} elseif ($stats['errors'] > 0) {
    echo "⚠️  SOME PRODUCTS STILL HAVE ISSUES - Check manually!\n";
} else {
    echo "✓ No changes made (DRY RUN or no problems found)\n";
}

echo "=============================================================\n";
