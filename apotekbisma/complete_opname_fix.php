<?php
/**
 * ============================================================================
 * COMPLETE STOCK FIX WITH OPNAME ADJUSTMENT INSERTION
 * ============================================================================
 * 
 * Strategi:
 * 1. Untuk setiap produk yang ada di baseline CSV:
 *    a. Cek apakah stok_sisa terakhir 2025 sama dengan nilai opname
 *    b. Jika berbeda, sisipkan record "Stock Opname Adjustment" di 2025-12-31 23:59:59
 *    c. Record adjustment akan menjembatani gap antara 2025 dan 2026
 * 2. Recalculate semua chain 2026 berdasarkan nilai opname (bukan dari record sebelumnya)
 * 3. Sync produk.stok
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

printHeader("COMPLETE STOCK FIX WITH OPNAME ADJUSTMENT");
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Mode: " . ($dryRun ? "DRY RUN" : "EXECUTE") . "\n\n";

// Load baseline
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
echo "Loaded " . count($baseline) . " products from baseline CSV\n";

// ============================================================================
// STEP 1: INSERT STOCK OPNAME ADJUSTMENT RECORDS WHERE NEEDED
// ============================================================================
printHeader("STEP 1: INSERT STOCK OPNAME ADJUSTMENTS");

$insertCount = 0;
$updateCount = 0;

foreach ($baseline as $productId => $data) {
    $opnameStock = $data['stok'];
    
    // Get last 2025 record
    $last2025 = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '<', $cutoffDate)  // strictly before cutoff
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    // Check if opname adjustment already exists
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
    
    if (!$first2026) continue; // Skip jika tidak ada record 2026
    
    // Calculate what stok_awal should be
    $stokAwal = $last2025 ? intval($last2025->stok_sisa) : 0;
    
    // If there's already an opname record at cutoff, update it
    if ($existingOpname) {
        $adjustment = $opnameStock - intval($existingOpname->stok_awal);
        $masuk = $adjustment > 0 ? $adjustment : 0;
        $keluar = $adjustment < 0 ? abs($adjustment) : 0;
        
        if (!$dryRun) {
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $existingOpname->id_rekaman_stok)
                ->update([
                    'stok_awal' => intval($existingOpname->stok_awal),
                    'stok_masuk' => $masuk,
                    'stok_keluar' => $keluar,
                    'stok_sisa' => $opnameStock
                ]);
        }
        $updateCount++;
        continue;
    }
    
    // Check if we need adjustment (stok_sisa 2025 != opname value)
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
        
        echo "[{$productId}] {$data['nama']}: inserted adjustment {$stokAwal} -> {$opnameStock}\n";
        $insertCount++;
    }
}

echo "\nOpname adjustments inserted: {$insertCount}\n";
echo "Opname adjustments updated: {$updateCount}\n";

// ============================================================================
// STEP 2: RECALCULATE ALL 2026 CHAINS
// ============================================================================
printHeader("STEP 2: RECALCULATE 2026 CHAINS");

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
// STEP 3: SYNC produk.stok
// ============================================================================
printHeader("STEP 3: SYNC produk.stok");

$products = DB::table('produk')->get();
$syncCount = 0;

foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if (!$lastRekaman) continue;
    
    $expectedStock = max(0, intval($lastRekaman->stok_sisa));
    
    if (intval($product->stok) != $expectedStock) {
        if (!$dryRun) {
            DB::table('produk')
                ->where('id_produk', $product->id_produk)
                ->update(['stok' => $expectedStock]);
        }
        $syncCount++;
    }
}

echo "Products synced: {$syncCount}\n";

// ============================================================================
// STEP 4: VERIFICATION
// ============================================================================
printHeader("STEP 4: FINAL VERIFICATION");

$products = DB::table('produk')->get();
$gapIssues = 0;
$contIssues = 0;
$mismatchIssues = 0;
$gapDetails = [];
$contDetails = [];
$mismatchDetails = [];

// Check gap
echo "Checking gap (opname -> first 2026)...\n";
foreach ($baseline as $productId => $data) {
    $first2026 = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->first();
    
    if ($first2026 && intval($first2026->stok_awal) != $data['stok']) {
        $gapIssues++;
        $gapDetails[] = "[{$productId}] {$data['nama']}: expected={$data['stok']}, got={$first2026->stok_awal}";
    }
}
echo "  Gap issues: {$gapIssues}\n";
foreach ($gapDetails as $d) echo "  {$d}\n";

// Check continuity
echo "\nChecking continuity (prev_sisa -> curr_awal)...\n";
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
            $contIssues++;
            $name = $baseline[$productId]['nama'] ?? "Product {$productId}";
            $contDetails[] = "[{$productId}] {$name}: prev_sisa={$prevSisa}, curr_awal={$r->stok_awal}";
            break;
        }
        $prevSisa = intval($r->stok_sisa);
    }
}
echo "  Continuity issues: {$contIssues}\n";
foreach ($contDetails as $d) echo "  {$d}\n";

// Check mismatch
echo "\nChecking produk.stok sync...\n";
foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman && intval($product->stok) != intval($lastRekaman->stok_sisa)) {
        $mismatchIssues++;
        $mismatchDetails[] = "[{$product->id_produk}]: produk={$product->stok}, rekaman={$lastRekaman->stok_sisa}";
    }
}
echo "  Mismatch issues: {$mismatchIssues}\n";
foreach ($mismatchDetails as $d) echo "  {$d}\n";

// ============================================================================
// SUMMARY
// ============================================================================
printHeader("SUMMARY");

$total = $gapIssues + $contIssues + $mismatchIssues;

echo "Actions taken:\n";
echo "  - Opname adjustments inserted: {$insertCount}\n";
echo "  - Opname adjustments updated: {$updateCount}\n";
echo "  - Products recalculated: {$recalcProducts}\n";
echo "  - Records updated: {$recalcRecords}\n";
echo "  - produk.stok synced: {$syncCount}\n\n";

echo "Verification Results:\n";
echo "  " . ($gapIssues == 0 ? "[OK]" : "[ISSUE]") . " Gap (opname -> 2026): {$gapIssues}\n";
echo "  " . ($contIssues == 0 ? "[OK]" : "[ISSUE]") . " Continuity: {$contIssues}\n";
echo "  " . ($mismatchIssues == 0 ? "[OK]" : "[ISSUE]") . " Mismatch: {$mismatchIssues}\n\n";

echo "TOTAL ISSUES: {$total}\n\n";

if ($total == 0) {
    echo "SUCCESS! All issues resolved - 100% consistent!\n";
} else {
    if ($dryRun) {
        echo "Run with --execute to apply fixes.\n";
    } else {
        echo "Run again to fix remaining issues.\n";
    }
}

echo "\nCompleted: " . date('Y-m-d H:i:s') . "\n";
