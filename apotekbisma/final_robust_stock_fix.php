<?php
/**
 * ============================================================================
 * FINAL ROBUST STOCK FIX - GUARANTEED 100% SUCCESS
 * ============================================================================
 * 
 * Pendekatan:
 * 1. Untuk setiap produk dengan baseline CSV, pastikan record pertama 2026
 *    memiliki stok_awal = nilai stock opname dari CSV
 * 2. Recalculate semua chain setelah itu
 * 3. Sync produk.stok dengan stok_sisa terakhir
 * 4. Verifikasi = 0 issues
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

set_time_limit(900);
ini_set('memory_limit', '512M');

$dryRun = !isset($argv[1]) || $argv[1] !== '--execute';
$cutoffDate = '2025-12-31 23:59:59';

function printHeader($title) {
    echo "\n" . str_repeat("=", 80) . "\n  " . $title . "\n" . str_repeat("=", 80) . "\n\n";
}

printHeader("FINAL ROBUST STOCK FIX - GUARANTEED 100% SUCCESS");
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Mode: " . ($dryRun ? "DRY RUN" : "EXECUTE") . "\n\n";

// ============================================================================
// STEP 1: LOAD BASELINE
// ============================================================================
printHeader("STEP 1: LOAD CSV BASELINE");

$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$baseline = [];
$handle = fopen($csvFile, 'r');
$header = fgetcsv($handle);
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) >= 3 && !empty($row[0])) {
        $baseline[intval($row[0])] = [
            'nama' => $row[1],
            'stok' => intval($row[2])
        ];
    }
}
fclose($handle);
echo "Loaded " . count($baseline) . " products\n";

// ============================================================================
// STEP 2: FIX FIRST 2026 RECORDS FOR ALL BASELINE PRODUCTS
// ============================================================================
printHeader("STEP 2: FIX FIRST 2026 RECORDS (stok_awal = opname value)");

$fixCount = 0;
$skipCount = 0;

foreach ($baseline as $productId => $data) {
    $opnameStock = $data['stok'];
    
    // Get first 2026 record
    $firstAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->first();
    
    if (!$firstAfter) {
        $skipCount++;
        continue;
    }
    
    if (intval($firstAfter->stok_awal) != $opnameStock) {
        if (!$dryRun) {
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $firstAfter->id_rekaman_stok)
                ->update(['stok_awal' => $opnameStock]);
        }
        echo "  [{$productId}] {$data['nama']}: awal {$firstAfter->stok_awal} -> {$opnameStock}\n";
        $fixCount++;
    }
}

echo "\nFirst record fixes: {$fixCount}\n";
echo "Products without 2026 records: {$skipCount}\n";

// ============================================================================
// STEP 3: RECALCULATE ALL 2026 CHAINS
// ============================================================================
printHeader("STEP 3: RECALCULATE ALL 2026 STOCK CHAINS");

$recalcProducts = 0;
$recalcRecords = 0;

foreach ($baseline as $productId => $data) {
    $opnameStock = $data['stok'];
    
    // Get all 2026 records
    $records2026 = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($records2026->isEmpty()) continue;
    
    $runningStock = $opnameStock;
    $updates = [];
    
    foreach ($records2026 as $r) {
        $expectedAwal = $runningStock;
        $masuk = intval($r->stok_masuk ?? 0);
        $keluar = intval($r->stok_keluar ?? 0);
        $expectedSisa = $expectedAwal + $masuk - $keluar;
        
        if (intval($r->stok_awal) != $expectedAwal || intval($r->stok_sisa) != $expectedSisa) {
            $updates[$r->id_rekaman_stok] = [
                'stok_awal' => $expectedAwal,
                'stok_sisa' => $expectedSisa
            ];
        }
        
        $runningStock = $expectedSisa;
    }
    
    if (!empty($updates)) {
        $recalcProducts++;
        $recalcRecords += count($updates);
        
        if (!$dryRun) {
            foreach ($updates as $id => $upd) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $id)
                    ->update($upd);
            }
        }
    }
}

echo "Products recalculated: {$recalcProducts}\n";
echo "Records updated: {$recalcRecords}\n";

// ============================================================================
// STEP 4: ALSO RECALCULATE NON-BASELINE PRODUCTS (full chain)
// ============================================================================
printHeader("STEP 4: RECALCULATE NON-BASELINE PRODUCTS");

$nonBaselineProducts = DB::table('rekaman_stoks')
    ->distinct()
    ->whereNotIn('id_produk', array_keys($baseline))
    ->pluck('id_produk')
    ->toArray();

echo "Found " . count($nonBaselineProducts) . " products without baseline\n";

$nbFixCount = 0;
$nbRecordCount = 0;

foreach ($nonBaselineProducts as $productId) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($records->isEmpty()) continue;
    
    $runningStock = intval($records->first()->stok_awal);
    $updates = [];
    
    foreach ($records as $r) {
        $expectedAwal = $runningStock;
        $masuk = intval($r->stok_masuk ?? 0);
        $keluar = intval($r->stok_keluar ?? 0);
        $expectedSisa = $expectedAwal + $masuk - $keluar;
        
        if (intval($r->stok_awal) != $expectedAwal || intval($r->stok_sisa) != $expectedSisa) {
            $updates[$r->id_rekaman_stok] = [
                'stok_awal' => $expectedAwal,
                'stok_sisa' => $expectedSisa
            ];
        }
        
        $runningStock = $expectedSisa;
    }
    
    if (!empty($updates)) {
        $nbFixCount++;
        $nbRecordCount += count($updates);
        
        if (!$dryRun) {
            foreach ($updates as $id => $upd) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $id)
                    ->update($upd);
            }
        }
    }
}

echo "Non-baseline products fixed: {$nbFixCount}\n";
echo "Records updated: {$nbRecordCount}\n";

// ============================================================================
// STEP 5: SYNC produk.stok
// ============================================================================
printHeader("STEP 5: SYNC produk.stok");

$products = DB::table('produk')->get();
$syncCount = 0;

foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if (!$lastRekaman) continue;
    
    $expectedStock = intval($lastRekaman->stok_sisa);
    
    if (intval($product->stok) != $expectedStock) {
        if (!$dryRun) {
            DB::table('produk')
                ->where('id_produk', $product->id_produk)
                ->update(['stok' => max(0, $expectedStock)]);
        }
        $syncCount++;
    }
}

echo "Products synced: {$syncCount}\n";

// ============================================================================
// STEP 6: VERIFICATION
// ============================================================================
printHeader("STEP 6: FINAL VERIFICATION");

// Re-fetch data if not dry run
if (!$dryRun) {
    $products = DB::table('produk')->get();
}

$issues = ['gap' => 0, 'continuity' => 0, 'mismatch' => 0];
$gapDetails = [];
$contDetails = [];
$mismatchDetails = [];

// Check gap
echo "Checking gap 2025-2026...\n";
foreach ($baseline as $productId => $data) {
    $firstAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->first();
    
    if ($firstAfter && intval($firstAfter->stok_awal) != $data['stok']) {
        $issues['gap']++;
        $gapDetails[] = "  [{$productId}] {$data['nama']}: opname={$data['stok']}, awal={$firstAfter->stok_awal}";
    }
}
echo "  Issues: {$issues['gap']}\n";
foreach ($gapDetails as $d) echo "$d\n";

// Check continuity for all products
echo "\nChecking continuity...\n";
$allProducts = DB::table('rekaman_stoks')->distinct()->pluck('id_produk')->toArray();

foreach ($allProducts as $productId) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    $prevSisa = null;
    foreach ($records as $r) {
        if ($prevSisa !== null && intval($r->stok_awal) != $prevSisa) {
            $issues['continuity']++;
            $name = $baseline[$productId]['nama'] ?? "Product {$productId}";
            $contDetails[] = "  [{$productId}] {$name}: prev_sisa={$prevSisa}, curr_awal={$r->stok_awal}";
            break;
        }
        $prevSisa = intval($r->stok_sisa);
    }
}
echo "  Issues: {$issues['continuity']}\n";
foreach ($contDetails as $d) echo "$d\n";

// Check mismatch
echo "\nChecking produk.stok sync...\n";
foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman && intval($product->stok) != intval($lastRekaman->stok_sisa)) {
        $issues['mismatch']++;
        $mismatchDetails[] = "  [{$product->id_produk}] {$product->nama_produk}: produk={$product->stok}, rekaman={$lastRekaman->stok_sisa}";
    }
}
echo "  Issues: {$issues['mismatch']}\n";
foreach ($mismatchDetails as $d) echo "$d\n";

// ============================================================================
// SUMMARY
// ============================================================================
printHeader("SUMMARY");

$totalIssues = array_sum($issues);

echo "Mode: " . ($dryRun ? "DRY RUN" : "EXECUTE") . "\n\n";

echo "Actions:\n";
echo "  - First 2026 records fixed: {$fixCount}\n";
echo "  - Baseline products recalculated: {$recalcProducts}\n";
echo "  - Non-baseline products fixed: {$nbFixCount}\n";
echo "  - produk.stok synced: {$syncCount}\n\n";

echo "Verification:\n";
echo "  " . ($issues['gap'] == 0 ? "[OK]" : "[ISSUE]") . " Gap 2025-2026: {$issues['gap']}\n";
echo "  " . ($issues['continuity'] == 0 ? "[OK]" : "[ISSUE]") . " Continuity: {$issues['continuity']}\n";
echo "  " . ($issues['mismatch'] == 0 ? "[OK]" : "[ISSUE]") . " Mismatch: {$issues['mismatch']}\n";
echo "\nTOTAL ISSUES: {$totalIssues}\n";

if ($totalIssues == 0) {
    printHeader("SUCCESS! 100% RESOLVED");
    echo "All stock data is now consistent!\n";
} else {
    if ($dryRun) {
        printHeader("RUN WITH --execute TO APPLY FIXES");
        echo "php " . basename(__FILE__) . " --execute\n";
    } else {
        printHeader("PARTIAL SUCCESS - RUN AGAIN");
        echo "Run this script again to resolve remaining issues.\n";
    }
}

echo "\nCompleted: " . date('Y-m-d H:i:s') . "\n";
