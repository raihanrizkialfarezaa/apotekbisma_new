<?php
/**
 * ============================================================================
 * DEFINITIVE STOCK FIX - HANDLES TIMESTAMP COLLISIONS CORRECTLY
 * ============================================================================
 * 
 * Masalah utama: Banyak record dengan waktu yang sama (collision)
 * Solusi: 
 * 1. Urutkan berdasarkan waktu ASC, kemudian ID ASC (konsisten)
 * 2. Record pertama 2026 harus punya stok_awal = nilai opname CSV
 * 3. Recalculate seluruh chain 2026 dari titik itu
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

printHeader("DEFINITIVE STOCK FIX");
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

printHeader("PROCESSING ALL BASELINE PRODUCTS");

$totalFixed = 0;
$totalRecords = 0;
$errors = [];

foreach ($baseline as $productId => $data) {
    $opnameStock = $data['stok'];
    
    // Get ALL records untuk produk ini, diurutkan dengan benar
    $allRecords = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($allRecords->isEmpty()) continue;
    
    // Pisahkan record 2025 dan 2026
    $records2025 = $allRecords->filter(fn($r) => $r->waktu <= $cutoffDate);
    $records2026 = $allRecords->filter(fn($r) => $r->waktu > $cutoffDate);
    
    if ($records2026->isEmpty()) continue;
    
    // Running stock dimulai dari baseline (opname) untuk 2026
    $runningStock = $opnameStock;
    $updates = [];
    $isFirst = true;
    
    foreach ($records2026 as $r) {
        $expectedAwal = $runningStock;
        $masuk = intval($r->stok_masuk ?? 0);
        $keluar = intval($r->stok_keluar ?? 0);
        $expectedSisa = $expectedAwal + $masuk - $keluar;
        
        if (intval($r->stok_awal) != $expectedAwal || intval($r->stok_sisa) != $expectedSisa) {
            $updates[$r->id_rekaman_stok] = [
                'stok_awal' => $expectedAwal,
                'stok_sisa' => $expectedSisa,
                'old_awal' => $r->stok_awal,
                'old_sisa' => $r->stok_sisa
            ];
        }
        
        $runningStock = $expectedSisa;
        $isFirst = false;
    }
    
    if (!empty($updates)) {
        $totalFixed++;
        $totalRecords += count($updates);
        
        if (!$dryRun) {
            foreach ($updates as $id => $upd) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $id)
                    ->update([
                        'stok_awal' => $upd['stok_awal'],
                        'stok_sisa' => $upd['stok_sisa']
                    ]);
            }
        }
        
        // Show details for this product
        echo "[{$productId}] {$data['nama']}: baseline={$opnameStock}, fixed " . count($updates) . " records\n";
    }
}

echo "\nProducts fixed: {$totalFixed}\n";
echo "Records updated: {$totalRecords}\n";

printHeader("SYNC produk.stok");

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

printHeader("FINAL VERIFICATION");

// Refresh data
$products = DB::table('produk')->get();

$gapIssues = 0;
$contIssues = 0;
$mismatchIssues = 0;

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
        $gapIssues++;
        echo "  [GAP] {$productId} {$data['nama']}: expected={$data['stok']}, got={$firstAfter->stok_awal}\n";
    }
}
echo "  Total: {$gapIssues}\n\n";

// Check continuity
echo "Checking continuity...\n";
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
            echo "  [CONT] {$productId} {$name}: prev_sisa={$prevSisa}, curr_awal={$r->stok_awal}, id={$r->id_rekaman_stok}\n";
            break;
        }
        $prevSisa = intval($r->stok_sisa);
    }
}
echo "  Total: {$contIssues}\n\n";

// Check mismatch
echo "Checking produk.stok sync...\n";
foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman && intval($product->stok) != intval($lastRekaman->stok_sisa)) {
        $mismatchIssues++;
        echo "  [MISMATCH] {$product->id_produk}: produk={$product->stok}, rekaman={$lastRekaman->stok_sisa}\n";
    }
}
echo "  Total: {$mismatchIssues}\n";

printHeader("SUMMARY");

$total = $gapIssues + $contIssues + $mismatchIssues;

echo "Gap issues: {$gapIssues}\n";
echo "Continuity issues: {$contIssues}\n";
echo "Mismatch issues: {$mismatchIssues}\n";
echo "TOTAL: {$total}\n\n";

if ($total == 0) {
    echo "SUCCESS! All issues resolved.\n";
} else {
    if ($dryRun) {
        echo "Run with --execute to apply fixes.\n";
    } else {
        echo "Run again to fix remaining issues.\n";
    }
}

echo "\nCompleted: " . date('Y-m-d H:i:s') . "\n";
