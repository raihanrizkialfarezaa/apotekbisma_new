<?php
/**
 * COMPLETE STOCK OPNAME FIX v3.0
 * ===============================
 * 
 * Script ini memastikan SEMUA produk di CSV baseline memiliki:
 * 1. Record Stock Opname di cutoff date (2025-12-31 23:59:59)
 * 2. Chain yang benar dari 2025 ke 2026
 * 3. Recalculate semua record setelah cutoff
 * 
 * USAGE:
 *   DRY RUN:  php complete_stock_opname_fix_v3.php
 *   EXECUTE:  php complete_stock_opname_fix_v3.php --execute
 * 
 * @author AI Assistant
 * @version 3.0
 */

ini_set('memory_limit', '512M');
set_time_limit(0);

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

// Configuration
$cutoffDate = '2025-12-31 23:59:59';
$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';

// Check if we should execute or just simulate
$dryRun = true;
if (isset($argv[1]) && $argv[1] === '--execute') {
    $dryRun = false;
}
if (isset($_GET['execute']) && $_GET['execute'] == '1') {
    $dryRun = false;
}

$output = [];
$startTime = microtime(true);

function out($msg, &$output) {
    echo $msg . "\n";
    $output[] = $msg;
}

out("=======================================================", $output);
out("    COMPLETE STOCK OPNAME FIX v3.0", $output);
out("    " . ($dryRun ? "*** DRY RUN MODE ***" : "!!! EXECUTE MODE !!!"), $output);
out("=======================================================", $output);
out("Started at: " . date('Y-m-d H:i:s'), $output);
out("Cutoff Date: {$cutoffDate}", $output);
out("", $output);

// =====================================================
// STEP 0: Load CSV baseline
// =====================================================
out("STEP 0: Loading CSV baseline data...", $output);

$csvData = [];
if (($handle = fopen($csvFile, "r")) !== FALSE) {
    $header = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== FALSE) {
        if (count($row) >= 3) {
            $produkId = (int)$row[0];
            $csvData[$produkId] = [
                'nama' => $row[1],
                'stok' => (int)$row[2]
            ];
        }
    }
    fclose($handle);
}

if (empty($csvData)) {
    out("ERROR: CSV file is empty or not readable!", $output);
    exit(1);
}

out("  Loaded " . count($csvData) . " products from CSV", $output);
out("", $output);

// =====================================================
// STEP 1: Ensure ALL products have Stock Opname record at cutoff
// =====================================================
out("STEP 1: Ensuring Stock Opname records for ALL products...", $output);

$insertedOpname = 0;
$updatedOpname = 0;
$skippedOpname = 0;
$now = Carbon::now();

foreach ($csvData as $produkId => $baseline) {
    // Check if product exists
    $produk = DB::table('produk')->where('id_produk', $produkId)->first();
    if (!$produk) {
        out("  [SKIP] Product {$produkId} ({$baseline['nama']}) not found in database", $output);
        $skippedOpname++;
        continue;
    }
    
    // Check if opname record exists
    $opnameRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->where('waktu', $cutoffDate)
        ->first();
    
    // Get last record before cutoff to determine stok_awal
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->where('waktu', '<', $cutoffDate)
        ->orderBy('waktu', 'desc')
        ->orderBy('created_at', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $stokAwalBeforeOpname = $lastBefore ? $lastBefore->stok_sisa : 0;
    $baselineStok = $baseline['stok'];
    
    // Calculate adjustment
    $diff = $baselineStok - $stokAwalBeforeOpname;
    
    if ($opnameRecord) {
        // Check if needs update
        if ($opnameRecord->stok_sisa != $baselineStok) {
            // Update existing
            if (!$dryRun) {
                $stokKeluar = null;
                $stokMasuk = null;
                
                if ($diff < 0) {
                    $stokKeluar = abs($diff);
                } elseif ($diff > 0) {
                    $stokMasuk = $diff;
                }
                
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $opnameRecord->id_rekaman_stok)
                    ->update([
                        'stok_awal' => $stokAwalBeforeOpname,
                        'stok_masuk' => $stokMasuk,
                        'stok_keluar' => $stokKeluar,
                        'stok_sisa' => $baselineStok,
                        'keterangan' => "Stock Opname 31 Desember 2025: Penyesuaian dari {$stokAwalBeforeOpname} ke {$baselineStok}",
                        'updated_at' => $now
                    ]);
            }
            $updatedOpname++;
        } else {
            // Already correct
            // Just skip silently
        }
    } else {
        // Insert new opname record
        $stokKeluar = null;
        $stokMasuk = null;
        
        if ($diff < 0) {
            $stokKeluar = abs($diff);
        } elseif ($diff > 0) {
            $stokMasuk = $diff;
        }
        
        if (!$dryRun) {
            DB::table('rekaman_stoks')->insert([
                'id_produk' => $produkId,
                'waktu' => $cutoffDate,
                'stok_awal' => $stokAwalBeforeOpname,
                'stok_masuk' => $stokMasuk,
                'stok_keluar' => $stokKeluar,
                'stok_sisa' => $baselineStok,
                'keterangan' => "Stock Opname 31 Desember 2025: Penyesuaian dari {$stokAwalBeforeOpname} ke {$baselineStok}",
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }
        $insertedOpname++;
    }
}

out("  Inserted: {$insertedOpname} new Stock Opname records", $output);
out("  Updated: {$updatedOpname} existing Stock Opname records", $output);
out("  Skipped: {$skippedOpname} products (not in database)", $output);
out("", $output);

// =====================================================
// STEP 2: Clean up duplicate SO records (keep only one per product at cutoff)
// =====================================================
out("STEP 2: Cleaning up duplicate Stock Opname records...", $output);

$cleanedUp = 0;
foreach ($csvData as $produkId => $baseline) {
    // Check for duplicates at cutoff
    $duplicates = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->where('waktu', $cutoffDate)
        ->orderBy('id_rekaman_stok', 'desc')
        ->get();
    
    if (count($duplicates) > 1) {
        // Keep the first one (highest ID), delete the rest
        $keep = $duplicates->first();
        foreach ($duplicates->skip(1) as $dup) {
            if (!$dryRun) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $dup->id_rekaman_stok)
                    ->delete();
            }
            $cleanedUp++;
        }
    }
}

out("  Cleaned up {$cleanedUp} duplicate Stock Opname records", $output);
out("", $output);

// =====================================================
// STEP 3: Recalculate ALL records AFTER cutoff
// =====================================================
out("STEP 3: Recalculating stock chain for ALL products after cutoff...", $output);

$recalculated = 0;
$chainFixed = 0;

foreach ($csvData as $produkId => $baseline) {
    $baselineStok = $baseline['stok'];
    
    // Get all records after cutoff
    $recordsAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($recordsAfter->isEmpty()) {
        continue;
    }
    
    $prevSisa = $baselineStok; // Start from baseline
    $hasChanges = false;
    
    foreach ($recordsAfter as $record) {
        $newStokAwal = $prevSisa;
        $masuk = $record->stok_masuk ?? 0;
        $keluar = $record->stok_keluar ?? 0;
        $newStokSisa = $newStokAwal + $masuk - $keluar;
        
        if ($record->stok_awal != $newStokAwal || $record->stok_sisa != $newStokSisa) {
            $hasChanges = true;
            
            if (!$dryRun) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $record->id_rekaman_stok)
                    ->update([
                        'stok_awal' => $newStokAwal,
                        'stok_sisa' => $newStokSisa,
                        'updated_at' => $now
                    ]);
            }
            $chainFixed++;
        }
        
        $prevSisa = $newStokSisa;
    }
    
    if ($hasChanges) {
        $recalculated++;
    }
}

out("  Recalculated chain for {$recalculated} products", $output);
out("  Fixed {$chainFixed} individual records", $output);
out("", $output);

// =====================================================
// STEP 4: Sync produk.stok with latest rekaman_stoks
// =====================================================
out("STEP 4: Syncing produk.stok with latest rekaman_stoks...", $output);

$synced = 0;
$syncMismatches = 0;

foreach ($csvData as $produkId => $baseline) {
    // Get latest record
    $latestRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->orderBy('waktu', 'desc')
        ->orderBy('created_at', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if (!$latestRecord) {
        continue;
    }
    
    $produk = DB::table('produk')->where('id_produk', $produkId)->first();
    if (!$produk) {
        continue;
    }
    
    if ($produk->stok != $latestRecord->stok_sisa) {
        $syncMismatches++;
        if (!$dryRun) {
            DB::table('produk')
                ->where('id_produk', $produkId)
                ->update([
                    'stok' => $latestRecord->stok_sisa,
                    'updated_at' => $now
                ]);
        }
        $synced++;
    }
}

out("  Found {$syncMismatches} produk.stok mismatches", $output);
out("  Synced {$synced} products", $output);
out("", $output);

// =====================================================
// STEP 5: Final Verification
// =====================================================
out("STEP 5: Final Verification...", $output);

$verifyIssues = 0;

// Check 1: All products should have opname record
$productsWithOpname = DB::table('rekaman_stoks')
    ->where('waktu', $cutoffDate)
    ->select('id_produk')
    ->distinct()
    ->count();

out("  Products with Stock Opname at cutoff: {$productsWithOpname}", $output);

if ($productsWithOpname < count($csvData)) {
    $missing = count($csvData) - $productsWithOpname;
    out("  [WARNING] {$missing} products missing Stock Opname record", $output);
    $verifyIssues += $missing;
}

// Check 2: All opname records should have correct stok_sisa
$opnameMismatches = 0;
foreach ($csvData as $produkId => $baseline) {
    $opname = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->where('waktu', $cutoffDate)
        ->first();
    
    if ($opname && $opname->stok_sisa != $baseline['stok']) {
        $opnameMismatches++;
    }
}

if ($opnameMismatches > 0) {
    out("  [WARNING] {$opnameMismatches} Stock Opname records have wrong stok_sisa", $output);
    $verifyIssues += $opnameMismatches;
}

// Check 3: Gap 2025-2026 (first record after cutoff should have stok_awal = baseline)
$gapErrors = 0;
foreach ($csvData as $produkId => $baseline) {
    $firstAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->first();
    
    if ($firstAfter && $firstAfter->stok_awal != $baseline['stok']) {
        $gapErrors++;
    }
}

out("  Gap 2025-2026 errors: {$gapErrors}", $output);
$verifyIssues += $gapErrors;

// Check 4: produk.stok matches latest rekaman
$produkMismatches = 0;
foreach ($csvData as $produkId => $baseline) {
    $latestRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->orderBy('waktu', 'desc')
        ->orderBy('created_at', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if (!$latestRecord) continue;
    
    $produk = DB::table('produk')->where('id_produk', $produkId)->first();
    if (!$produk) continue;
    
    if ($produk->stok != $latestRecord->stok_sisa) {
        $produkMismatches++;
    }
}

out("  produk.stok mismatches: {$produkMismatches}", $output);
$verifyIssues += $produkMismatches;

out("", $output);
out("=======================================================", $output);
out("TOTAL ISSUES: {$verifyIssues}", $output);
out("=======================================================", $output);

$endTime = microtime(true);
$duration = round($endTime - $startTime, 2);

out("", $output);
out("Duration: {$duration} seconds", $output);
out("Completed at: " . date('Y-m-d H:i:s'), $output);

if ($dryRun) {
    out("", $output);
    out("*** DRY RUN - No changes were made ***", $output);
    out("Run with --execute to apply changes", $output);
}

// Save log
$logFile = __DIR__ . '/stock_opname_fix_log_' . date('Ymd_His') . '.json';
$logData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'mode' => $dryRun ? 'DRY_RUN' : 'EXECUTE',
    'csv_products' => count($csvData),
    'inserted_opname' => $insertedOpname,
    'updated_opname' => $updatedOpname,
    'cleaned_up' => $cleanedUp,
    'recalculated_products' => $recalculated,
    'chain_fixed' => $chainFixed,
    'synced_produk' => $synced,
    'verify_issues' => $verifyIssues,
    'duration_seconds' => $duration,
    'output' => $output
];

file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
out("", $output);
out("Log saved to: {$logFile}", $output);
