<?php
/**
 * ============================================================================
 * ALL-IN-ONE STOCK FIX SCRIPT v2.0
 * ============================================================================
 * 
 * Script komprehensif untuk memperbaiki semua masalah stok dalam satu eksekusi.
 * Dapat dijalankan berulang kali dengan aman (idempotent).
 * 
 * FITUR:
 * 1. Fix timestamp collisions (spread out records dengan waktu yang sama)
 * 2. Load dan sync data dari CSV Stock Opname 31 Desember 2025
 * 3. Insert Stock Opname adjustment records untuk menjembatani gap 2025-2026
 * 4. Cleanup duplicate "Perubahan Stok Manual: SO" records
 * 5. Recalculate semua stock chains
 * 6. Sync produk.stok dengan stok_sisa terakhir di rekaman_stoks
 * 7. Verifikasi akhir (harus 0 issues)
 * 
 * CARA PENGGUNAAN:
 * - Dry run (preview):  php all_in_one_stock_fix.php
 * - Execute (apply):    php all_in_one_stock_fix.php --execute
 * - Via browser:        http://domain/all_in_one_stock_fix.php?execute=1
 * 
 * PRASYARAT:
 * - File "REKAMAN STOK FINAL 31 DESEMBER 2025.csv" harus ada di folder yang sama
 * - Format CSV: id_produk,nama_produk,stok (dengan header)
 * 
 * CATATAN KEAMANAN:
 * - Script ini aman dijalankan berulang kali
 * - Gunakan mode DRY RUN terlebih dahulu untuk preview
 * - Backup database sebelum menjalankan dengan --execute
 * - Log disimpan otomatis di folder yang sama
 * 
 * ============================================================================
 */

// Untuk eksekusi via browser
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// ============================================================================
// KONFIGURASI
// ============================================================================
set_time_limit(1800); // 30 menit
ini_set('memory_limit', '1024M');

$cutoffDate = '2025-12-31 23:59:59';
$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';

// Determine mode
$dryRun = true;
if (php_sapi_name() === 'cli') {
    $dryRun = !isset($argv[1]) || $argv[1] !== '--execute';
} else {
    $dryRun = !isset($_GET['execute']) || $_GET['execute'] !== '1';
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================
function printHeader($title) {
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "  " . $title . "\n";
    echo str_repeat("=", 80) . "\n\n";
}

function printSubHeader($title) {
    echo "\n--- " . $title . " ---\n\n";
}

function logMessage($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] [{$type}] {$message}\n";
}

// ============================================================================
// MAIN SCRIPT
// ============================================================================
$startTime = microtime(true);
$stats = [
    'timestamp_fixes' => 0,
    'opname_adjustments_inserted' => 0,
    'opname_adjustments_updated' => 0,
    'duplicate_records_deleted' => 0,
    'products_recalculated' => 0,
    'records_updated' => 0,
    'products_synced' => 0,
];

printHeader("ALL-IN-ONE STOCK FIX SCRIPT");
echo "Tanggal Eksekusi : " . date('Y-m-d H:i:s') . "\n";
echo "Mode             : " . ($dryRun ? "DRY RUN (preview only)" : "EXECUTE (applying changes)") . "\n";
echo "Cutoff Date      : " . $cutoffDate . "\n";
echo "CSV File         : " . basename($csvFile) . "\n";

if ($dryRun) {
    echo "\n[INFO] Ini adalah mode DRY RUN. Tidak ada perubahan yang akan disimpan.\n";
    echo "[INFO] Untuk menerapkan perubahan:\n";
    echo "       - CLI: php " . basename(__FILE__) . " --execute\n";
    echo "       - Browser: " . basename(__FILE__) . "?execute=1\n";
}

// ============================================================================
// STEP 0: VALIDASI FILE CSV
// ============================================================================
printHeader("STEP 0: VALIDASI FILE CSV");

if (!file_exists($csvFile)) {
    die("[ERROR] File CSV tidak ditemukan: {$csvFile}\n");
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

logMessage("Loaded " . count($baseline) . " produk dari CSV");

if (count($baseline) < 1) {
    die("[ERROR] Tidak ada data produk di file CSV!\n");
}

// ============================================================================
// STEP 1: FIX TIMESTAMP COLLISIONS
// ============================================================================
printHeader("STEP 1: FIX TIMESTAMP COLLISIONS");

logMessage("Scanning for timestamp collisions...");

// Find timestamps with multiple records for same product
$collisions = DB::table('rekaman_stoks')
    ->select('id_produk', 'waktu', DB::raw('COUNT(*) as cnt'), DB::raw('MIN(id_rekaman_stok) as min_id'))
    ->groupBy('id_produk', 'waktu')
    ->having('cnt', '>', 1)
    ->get();

$uniqueTimestamps = [];
foreach ($collisions as $c) {
    $key = $c->waktu;
    if (!isset($uniqueTimestamps[$key])) {
        $uniqueTimestamps[$key] = [];
    }
    $uniqueTimestamps[$key][] = $c->id_produk;
}

logMessage("Found " . count($uniqueTimestamps) . " unique timestamps with collisions");

if (!$dryRun && count($uniqueTimestamps) > 0) {
    foreach ($uniqueTimestamps as $waktu => $productIds) {
        // Get all records at this timestamp
        $records = DB::table('rekaman_stoks')
            ->where('waktu', $waktu)
            ->orderBy('id_rekaman_stok', 'asc')
            ->get();
        
        $offset = 0;
        foreach ($records as $record) {
            if ($offset > 0) {
                // Add milliseconds to spread out the timestamps
                $newWaktu = date('Y-m-d H:i:s', strtotime($waktu) + $offset);
                
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $record->id_rekaman_stok)
                    ->update(['waktu' => $newWaktu]);
                
                $stats['timestamp_fixes']++;
            }
            $offset++;
        }
    }
}

logMessage("Timestamp fixes applied: " . ($dryRun ? "(dry run)" : $stats['timestamp_fixes']));

// ============================================================================
// STEP 2: INSERT STOCK OPNAME ADJUSTMENTS
// ============================================================================
printHeader("STEP 2: INSERT STOCK OPNAME ADJUSTMENTS");

logMessage("Processing stock opname adjustments...");

foreach ($baseline as $productId => $data) {
    $opnameStock = $data['stok'];
    
    // Get last 2025 record (before cutoff)
    $last2025 = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '<', $cutoffDate)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    // Check if opname adjustment already exists at cutoff
    $existingOpname = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', $cutoffDate)
        ->where('keterangan', 'LIKE', '%Stock Opname%')
        ->first();
    
    // Get first 2026 record
    $first2026 = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->first();
    
    // Skip if no 2026 records
    if (!$first2026) continue;
    
    $stokAwal = $last2025 ? intval($last2025->stok_sisa) : 0;
    
    // If existing opname record, update it
    if ($existingOpname) {
        $adjustment = $opnameStock - intval($existingOpname->stok_awal);
        $masuk = $adjustment > 0 ? $adjustment : 0;
        $keluar = $adjustment < 0 ? abs($adjustment) : 0;
        
        if (!$dryRun) {
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $existingOpname->id_rekaman_stok)
                ->update([
                    'stok_awal' => $stokAwal,
                    'stok_masuk' => $masuk,
                    'stok_keluar' => $keluar,
                    'stok_sisa' => $opnameStock,
                    'keterangan' => 'Stock Opname 31 Desember 2025: Penyesuaian dari ' . $stokAwal . ' ke ' . $opnameStock
                ]);
        }
        $stats['opname_adjustments_updated']++;
        continue;
    }
    
    // Insert new adjustment if needed
    if ($stokAwal != $opnameStock) {
        $adjustment = $opnameStock - $stokAwal;
        $masuk = $adjustment > 0 ? $adjustment : 0;
        $keluar = $adjustment < 0 ? abs($adjustment) : 0;
        
        if (!$dryRun) {
            DB::table('rekaman_stoks')->insert([
                'id_produk' => $productId,
                'id_penjualan' => null,
                'id_pembelian' => null,
                'stok_awal' => $stokAwal,
                'stok_masuk' => $masuk,
                'stok_keluar' => $keluar,
                'stok_sisa' => $opnameStock,
                'waktu' => $cutoffDate,
                'keterangan' => 'Stock Opname 31 Desember 2025: Penyesuaian dari ' . $stokAwal . ' ke ' . $opnameStock,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
        $stats['opname_adjustments_inserted']++;
    }
}

logMessage("Opname adjustments inserted: " . $stats['opname_adjustments_inserted']);
logMessage("Opname adjustments updated: " . $stats['opname_adjustments_updated']);

// ============================================================================
// STEP 3: CLEANUP DUPLICATE "Perubahan Stok Manual: SO" RECORDS
// ============================================================================
printHeader("STEP 3: CLEANUP DUPLICATE SO RECORDS");

logMessage("Finding duplicate 'Perubahan Stok Manual: SO' records...");

$invalidRecords = DB::table('rekaman_stoks')
    ->where('keterangan', 'LIKE', '%Perubahan Stok Manual: SO%')
    ->where('waktu', '>', $cutoffDate)
    ->get();

logMessage("Found " . count($invalidRecords) . " 'Perubahan Stok Manual: SO' records after cutoff");

$affectedProducts = [];

foreach ($invalidRecords as $r) {
    // Check if there's already a Stock Opname adjustment at cutoff
    $hasOpnameAdjustment = DB::table('rekaman_stoks')
        ->where('id_produk', $r->id_produk)
        ->where('waktu', $cutoffDate)
        ->where('keterangan', 'LIKE', '%Stock Opname%')
        ->exists();
    
    if ($hasOpnameAdjustment) {
        if (!$dryRun) {
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $r->id_rekaman_stok)
                ->delete();
        }
        $stats['duplicate_records_deleted']++;
        $affectedProducts[$r->id_produk] = true;
        logMessage("Deleted duplicate record ID={$r->id_rekaman_stok} for product {$r->id_produk}");
    }
}

logMessage("Duplicate records deleted: " . $stats['duplicate_records_deleted']);

// ============================================================================
// STEP 4: RECALCULATE ALL 2026 STOCK CHAINS
// ============================================================================
printHeader("STEP 4: RECALCULATE 2026 STOCK CHAINS");

logMessage("Recalculating stock chains for products with baseline...");

$progressCount = 0;
$totalBaseline = count($baseline);

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
        $stats['products_recalculated']++;
        $stats['records_updated'] += count($updates);
        
        if (!$dryRun) {
            foreach ($updates as $id => $upd) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $id)
                    ->update($upd);
            }
        }
    }
    
    $progressCount++;
    if ($progressCount % 100 == 0) {
        logMessage("Progress: {$progressCount}/{$totalBaseline} products processed");
    }
}

logMessage("Products recalculated: " . $stats['products_recalculated']);
logMessage("Records updated: " . $stats['records_updated']);

// ============================================================================
// STEP 5: RECALCULATE NON-BASELINE PRODUCTS
// ============================================================================
printHeader("STEP 5: RECALCULATE NON-BASELINE PRODUCTS");

$nonBaselineProducts = DB::table('rekaman_stoks')
    ->distinct()
    ->whereNotIn('id_produk', array_keys($baseline))
    ->pluck('id_produk')
    ->toArray();

logMessage("Found " . count($nonBaselineProducts) . " products without baseline");

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

logMessage("Non-baseline products fixed: " . $nbFixCount);
logMessage("Non-baseline records updated: " . $nbRecordCount);

// ============================================================================
// STEP 6: SYNC produk.stok WITH rekaman_stoks
// ============================================================================
printHeader("STEP 6: SYNC produk.stok");

logMessage("Syncing produk.stok with latest rekaman_stoks...");

$products = DB::table('produk')->get();

foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('created_at', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if (!$lastRekaman) continue;
    
    $correctStock = max(0, intval($lastRekaman->stok_sisa));
    
    if (intval($product->stok) != $correctStock) {
        if (!$dryRun) {
            DB::table('produk')
                ->where('id_produk', $product->id_produk)
                ->update(['stok' => $correctStock]);
        }
        $stats['products_synced']++;
    }
}

logMessage("Products synced: " . $stats['products_synced']);

// ============================================================================
// STEP 7: FINAL VERIFICATION
// ============================================================================
printHeader("STEP 7: FINAL VERIFICATION");

$issues = [
    'gap_2025_2026' => 0,
    'continuity_errors' => 0,
    'produk_rekaman_mismatch' => 0
];

$issueDetails = [
    'gap' => [],
    'continuity' => [],
    'mismatch' => []
];

// Refresh data
$products = DB::table('produk')->get();

// Check gap (opname -> first 2026)
logMessage("Checking gap between 2025-2026...");
foreach ($baseline as $productId => $data) {
    $first2026 = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->first();
    
    if ($first2026 && intval($first2026->stok_awal) != $data['stok']) {
        $issues['gap_2025_2026']++;
        $issueDetails['gap'][] = "[{$productId}] {$data['nama']}: expected={$data['stok']}, got={$first2026->stok_awal}";
    }
}

// Check continuity
logMessage("Checking stock continuity...");
$allProductIds = DB::table('rekaman_stoks')->distinct()->pluck('id_produk')->toArray();

foreach ($allProductIds as $productId) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    $prevSisa = null;
    foreach ($records as $r) {
        if ($prevSisa !== null && intval($r->stok_awal) != $prevSisa) {
            $issues['continuity_errors']++;
            $name = $baseline[$productId]['nama'] ?? "Product {$productId}";
            $issueDetails['continuity'][] = "[{$productId}] {$name}: prev_sisa={$prevSisa}, curr_awal={$r->stok_awal}";
            break;
        }
        $prevSisa = intval($r->stok_sisa);
    }
}

// Check produk.stok sync
logMessage("Checking produk.stok sync...");
foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('created_at', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman && intval($product->stok) != intval($lastRekaman->stok_sisa)) {
        $issues['produk_rekaman_mismatch']++;
        $issueDetails['mismatch'][] = "[{$product->id_produk}] {$product->nama_produk}: produk={$product->stok}, rekaman={$lastRekaman->stok_sisa}";
    }
}

// ============================================================================
// SUMMARY
// ============================================================================
printHeader("SUMMARY");

$endTime = microtime(true);
$duration = round($endTime - $startTime, 2);
$totalIssues = array_sum($issues);

echo "Mode: " . ($dryRun ? "DRY RUN" : "EXECUTE") . "\n";
echo "Duration: {$duration} seconds\n\n";

echo "Actions " . ($dryRun ? "to be taken" : "completed") . ":\n";
echo "  - Timestamp collisions fixed: " . $stats['timestamp_fixes'] . "\n";
echo "  - Stock opname adjustments inserted: " . $stats['opname_adjustments_inserted'] . "\n";
echo "  - Stock opname adjustments updated: " . $stats['opname_adjustments_updated'] . "\n";
echo "  - Duplicate SO records deleted: " . $stats['duplicate_records_deleted'] . "\n";
echo "  - Products recalculated: " . $stats['products_recalculated'] . "\n";
echo "  - Stock records updated: " . $stats['records_updated'] . "\n";
echo "  - produk.stok synced: " . $stats['products_synced'] . "\n\n";

echo "Verification Results:\n";
echo "  " . ($issues['gap_2025_2026'] == 0 ? "[OK]" : "[ISSUE]") . " Gap 2025-2026: " . $issues['gap_2025_2026'] . "\n";
echo "  " . ($issues['continuity_errors'] == 0 ? "[OK]" : "[ISSUE]") . " Continuity Errors: " . $issues['continuity_errors'] . "\n";
echo "  " . ($issues['produk_rekaman_mismatch'] == 0 ? "[OK]" : "[ISSUE]") . " produk-rekaman Mismatch: " . $issues['produk_rekaman_mismatch'] . "\n";
echo "\nTOTAL ISSUES: {$totalIssues}\n";

// Show issue details if any
if ($totalIssues > 0) {
    printSubHeader("Issue Details");
    
    if (!empty($issueDetails['gap'])) {
        echo "Gap Issues:\n";
        foreach (array_slice($issueDetails['gap'], 0, 10) as $d) echo "  {$d}\n";
        if (count($issueDetails['gap']) > 10) echo "  ... and " . (count($issueDetails['gap']) - 10) . " more\n";
        echo "\n";
    }
    
    if (!empty($issueDetails['continuity'])) {
        echo "Continuity Issues:\n";
        foreach (array_slice($issueDetails['continuity'], 0, 10) as $d) echo "  {$d}\n";
        if (count($issueDetails['continuity']) > 10) echo "  ... and " . (count($issueDetails['continuity']) - 10) . " more\n";
        echo "\n";
    }
    
    if (!empty($issueDetails['mismatch'])) {
        echo "Mismatch Issues:\n";
        foreach (array_slice($issueDetails['mismatch'], 0, 10) as $d) echo "  {$d}\n";
        if (count($issueDetails['mismatch']) > 10) echo "  ... and " . (count($issueDetails['mismatch']) - 10) . " more\n";
    }
}

// Final status
if ($totalIssues == 0) {
    printHeader("SUCCESS!");
    echo "Semua data stok telah diperbaiki dan 100% konsisten!\n";
    echo "Tidak ada masalah yang ditemukan.\n";
} else {
    if ($dryRun) {
        printHeader("PREVIEW COMPLETE");
        echo "Ini adalah mode DRY RUN.\n";
        echo "Untuk menerapkan perubahan:\n";
        echo "  - CLI: php " . basename(__FILE__) . " --execute\n";
        echo "  - Browser: " . basename(__FILE__) . "?execute=1\n";
    } else {
        printHeader("PARTIAL SUCCESS");
        echo "Sebagian besar data telah diperbaiki.\n";
        echo "Jalankan script ini sekali lagi untuk menyelesaikan masalah yang tersisa.\n";
    }
}

// Log file
$logContent = [
    'timestamp' => date('Y-m-d H:i:s'),
    'mode' => $dryRun ? 'DRY RUN' : 'EXECUTE',
    'duration' => $duration,
    'stats' => $stats,
    'issues' => $issues,
    'total_issues' => $totalIssues
];

$logFileName = __DIR__ . '/stock_fix_log_' . date('Ymd_His') . '.json';
file_put_contents($logFileName, json_encode($logContent, JSON_PRETTY_PRINT));

echo "\n" . str_repeat("=", 80) . "\n";
echo "  Log saved to: " . basename($logFileName) . "\n";
echo "  Script completed at " . date('Y-m-d H:i:s') . "\n";
echo str_repeat("=", 80) . "\n\n";
