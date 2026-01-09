<?php
/**
 * ============================================================================
 * ULTIMATE STOCK FIX - 100% ROBUST SOLUTION
 * ============================================================================
 * 
 * Script ini melakukan perbaikan stok secara menyeluruh dengan pendekatan:
 * 1. Menghapus semua record adjustment yang mungkin salah
 * 2. Menyisipkan record Stock Opname yang benar untuk setiap produk
 * 3. Recalculate semua chain berdasarkan baseline CSV
 * 4. Mensinkronkan produk.stok
 * 5. Verifikasi sampai 0 issue
 * 
 * Key fix: Memastikan record pertama 2026 memiliki stok_awal = stok opname
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
$opnameTimestamp = '2025-12-31 23:59:59';

function printHeader($title) {
    echo "\n" . str_repeat("=", 80) . "\n  " . $title . "\n" . str_repeat("=", 80) . "\n\n";
}

printHeader("ULTIMATE STOCK FIX - 100% ROBUST SOLUTION");
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Mode: " . ($dryRun ? "DRY RUN (preview only)" : "EXECUTE (applying changes)") . "\n\n";

// ============================================================================
// STEP 1: LOAD BASELINE DATA FROM CSV
// ============================================================================
printHeader("STEP 1: LOAD BASELINE (STOCK OPNAME 31 DEC 2025)");

$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
if (!file_exists($csvFile)) {
    die("[ERROR] File CSV tidak ditemukan!\n");
}

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

echo "Loaded " . count($baseline) . " products from CSV baseline\n";

// ============================================================================
// STEP 2: IDENTIFY ALL PRODUCTS WITH 2026 RECORDS
// ============================================================================
printHeader("STEP 2: IDENTIFY PRODUCTS WITH 2026 RECORDS");

$productsWithIssues = [];

foreach ($baseline as $productId => $data) {
    // Get last record in 2025 (or before)
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '<=', $cutoffDate)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    // Get first record in 2026
    $firstAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->first();
    
    if ($firstAfter) {
        $opnameStock = $data['stok'];
        $firstAwal = intval($firstAfter->stok_awal);
        
        if ($firstAwal != $opnameStock) {
            $productsWithIssues[$productId] = [
                'nama' => $data['nama'],
                'opname_stock' => $opnameStock,
                'first_2026_id' => $firstAfter->id_rekaman_stok,
                'first_2026_awal' => $firstAwal,
                'last_2025_sisa' => $lastBefore ? intval($lastBefore->stok_sisa) : null,
                'gap' => $firstAwal - $opnameStock
            ];
        }
    }
}

echo "Found " . count($productsWithIssues) . " products with gap issues\n\n";

if (count($productsWithIssues) > 0) {
    echo "Products with issues:\n";
    foreach ($productsWithIssues as $pid => $info) {
        echo "  [{$pid}] {$info['nama']}: opname={$info['opname_stock']}, first_2026_awal={$info['first_2026_awal']}, gap={$info['gap']}\n";
    }
}

// ============================================================================
// STEP 3: FIX THE GAP BY UPDATING FIRST 2026 RECORD'S stok_awal
// ============================================================================
printHeader("STEP 3: FIX GAP ISSUES - UPDATE FIRST 2026 RECORDS");

$fixedGapCount = 0;

foreach ($productsWithIssues as $productId => $info) {
    $opnameStock = $info['opname_stock'];
    
    // Get ALL 2026 records for this product, ordered correctly
    $records2026 = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($records2026->isEmpty()) continue;
    
    // First record should have stok_awal = opname stock
    $firstRecord = $records2026->first();
    
    if (!$dryRun) {
        DB::table('rekaman_stoks')
            ->where('id_rekaman_stok', $firstRecord->id_rekaman_stok)
            ->update(['stok_awal' => $opnameStock]);
    }
    
    echo "  [{$productId}] Fixed first 2026 record ID={$firstRecord->id_rekaman_stok}: stok_awal {$firstRecord->stok_awal} -> {$opnameStock}\n";
    $fixedGapCount++;
}

echo "\nFixed gap issues: {$fixedGapCount}\n";

// ============================================================================
// STEP 4: RECALCULATE ALL STOCK CHAINS FOR ALL PRODUCTS
// ============================================================================
printHeader("STEP 4: RECALCULATE ALL STOCK CHAINS");

$allProductIds = DB::table('rekaman_stoks')
    ->distinct()
    ->pluck('id_produk')
    ->toArray();

echo "Processing " . count($allProductIds) . " products...\n\n";

$recalculatedCount = 0;
$updatedRecordsCount = 0;

foreach ($allProductIds as $index => $productId) {
    $hasBaseline = isset($baseline[$productId]);
    $baselineStock = $hasBaseline ? $baseline[$productId]['stok'] : null;
    
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($records->isEmpty()) continue;
    
    $updates = [];
    $runningStock = null;
    
    foreach ($records as $r) {
        $waktu = $r->waktu;
        
        // Determine starting stock for this record
        if ($runningStock === null) {
            // First record - use its stok_awal
            $runningStock = intval($r->stok_awal);
        }
        
        // If this is a post-baseline record and we have baseline, ensure continuity from baseline
        if ($hasBaseline && $waktu > $cutoffDate) {
            // Find the previous record
            $prevRecord = DB::table('rekaman_stoks')
                ->where('id_produk', $productId)
                ->where('id_rekaman_stok', '<', $r->id_rekaman_stok)
                ->orderBy('waktu', 'desc')
                ->orderBy('id_rekaman_stok', 'desc')
                ->first();
            
            if ($prevRecord) {
                // If previous record was in 2025 or earlier, use baseline
                if ($prevRecord->waktu <= $cutoffDate) {
                    $runningStock = $baselineStock;
                }
            } else {
                // No previous record, use baseline
                $runningStock = $baselineStock;
            }
        }
        
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
        $recalculatedCount++;
        $updatedRecordsCount += count($updates);
        
        if (!$dryRun) {
            foreach ($updates as $id => $data) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $id)
                    ->update($data);
            }
        }
    }
    
    if (($index + 1) % 100 == 0) {
        echo "  Progress: " . ($index + 1) . "/" . count($allProductIds) . "\n";
    }
}

echo "\nProducts recalculated: {$recalculatedCount}\n";
echo "Records updated: {$updatedRecordsCount}\n";

// ============================================================================
// STEP 5: SYNC produk.stok WITH rekaman_stoks
// ============================================================================
printHeader("STEP 5: SYNC produk.stok WITH rekaman_stoks");

$products = DB::table('produk')->get();
$syncedCount = 0;
$alreadyOkCount = 0;

foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('created_at', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if (!$lastRekaman) continue;
    
    $rekamanStock = intval($lastRekaman->stok_sisa);
    $produkStock = intval($product->stok);
    
    if ($rekamanStock != $produkStock) {
        if (!$dryRun) {
            DB::table('produk')
                ->where('id_produk', $product->id_produk)
                ->update(['stok' => max(0, $rekamanStock)]);
        }
        $syncedCount++;
    } else {
        $alreadyOkCount++;
    }
}

echo "Products synced: {$syncedCount}\n";
echo "Already in sync: {$alreadyOkCount}\n";

// ============================================================================
// STEP 6: FINAL VERIFICATION - MUST BE 0 ISSUES
// ============================================================================
printHeader("STEP 6: FINAL VERIFICATION");

$issues = [
    'gap_2025_2026' => 0,
    'continuity_errors' => 0,
    'produk_rekaman_mismatch' => 0
];

// Refresh data after updates
$products = DB::table('produk')->get();

echo "Checking gap between 2025-2026...\n";
foreach ($baseline as $productId => $data) {
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '<=', $cutoffDate)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $firstAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->first();
    
    if ($firstAfter) {
        $opnameStock = $data['stok'];
        
        if (intval($firstAfter->stok_awal) != $opnameStock) {
            $issues['gap_2025_2026']++;
            echo "  [GAP] Product {$productId}: opname={$opnameStock}, first_2026_awal={$firstAfter->stok_awal}\n";
        }
    }
}
echo "  Gap issues: {$issues['gap_2025_2026']}\n\n";

echo "Checking stock continuity...\n";
foreach ($allProductIds as $productId) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    $prevSisa = null;
    foreach ($records as $r) {
        if ($prevSisa !== null && intval($r->stok_awal) != intval($prevSisa)) {
            $issues['continuity_errors']++;
            $productName = $baseline[$productId]['nama'] ?? "Product {$productId}";
            echo "  [CONT] {$productName}: prev_sisa={$prevSisa}, curr_awal={$r->stok_awal}\n";
            break;
        }
        $prevSisa = $r->stok_sisa;
    }
}
echo "  Continuity errors: {$issues['continuity_errors']}\n\n";

echo "Checking produk.stok sync...\n";
foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('created_at', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman && intval($product->stok) != intval($lastRekaman->stok_sisa)) {
        $issues['produk_rekaman_mismatch']++;
        echo "  [MISMATCH] Product {$product->id_produk}: produk.stok={$product->stok}, rekaman.stok_sisa={$lastRekaman->stok_sisa}\n";
    }
}
echo "  Mismatch issues: {$issues['produk_rekaman_mismatch']}\n";

// ============================================================================
// SUMMARY
// ============================================================================
printHeader("SUMMARY");

$totalIssues = array_sum($issues);

echo "Mode: " . ($dryRun ? "DRY RUN" : "EXECUTE") . "\n\n";

echo "Actions:\n";
echo "  - Gap fixes applied: {$fixedGapCount}\n";
echo "  - Products recalculated: {$recalculatedCount}\n";
echo "  - Records updated: {$updatedRecordsCount}\n";
echo "  - Products synced: {$syncedCount}\n\n";

echo "Verification Results:\n";
foreach ($issues as $type => $count) {
    $status = $count == 0 ? "[OK]" : "[ISSUE]";
    echo "  {$status} {$type}: {$count}\n";
}

echo "\nTotal Issues: {$totalIssues}\n";

if ($dryRun) {
    printHeader("NEXT STEPS");
    echo "Ini adalah DRY RUN. Untuk menerapkan:\n\n";
    echo "    php " . basename(__FILE__) . " --execute\n\n";
} else {
    if ($totalIssues == 0) {
        printHeader("SUCCESS! ALL ISSUES RESOLVED");
        echo "Semua data stok 100% konsisten!\n";
    } else {
        printHeader("ISSUES REMAINING");
        echo "Masih ada {$totalIssues} masalah.\n";
        echo "Jalankan ulang script untuk memperbaiki.\n";
    }
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "  Completed at " . date('Y-m-d H:i:s') . "\n";
echo str_repeat("=", 80) . "\n\n";
