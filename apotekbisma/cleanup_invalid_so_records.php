<?php
/**
 * ============================================================================
 * CLEANUP DUPLICATE/INVALID STOCK OPNAME RECORDS
 * ============================================================================
 * 
 * Beberapa produk memiliki record "Perubahan Stok Manual: SO" yang duplikat
 * dengan Stock Opname adjustment. Ini menyebabkan stok menjadi negatif.
 * 
 * Script ini akan:
 * 1. Identifikasi record "Perubahan Stok Manual: SO" yang ada di 2026
 * 2. Hapus record tersebut jika sudah ada Stock Opname adjustment di cutoff
 * 3. Recalculate chain
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

echo "============================================================\n";
echo "  CLEANUP DUPLICATE STOCK OPNAME RECORDS\n";
echo "============================================================\n\n";
echo "Mode: " . ($dryRun ? "DRY RUN" : "EXECUTE") . "\n\n";

// Load baseline
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

// Find and delete invalid "Perubahan Stok Manual: SO" records
$invalidRecords = DB::table('rekaman_stoks')
    ->where('keterangan', 'LIKE', '%Perubahan Stok Manual: SO%')
    ->where('waktu', '>', $cutoffDate)
    ->get();

echo "Found " . count($invalidRecords) . " 'Perubahan Stok Manual: SO' records after cutoff\n\n";

$deleteCount = 0;
$affectedProducts = [];

foreach ($invalidRecords as $r) {
    // Check if there's already a Stock Opname adjustment at cutoff
    $hasOpnameAdjustment = DB::table('rekaman_stoks')
        ->where('id_produk', $r->id_produk)
        ->where('waktu', $cutoffDate)
        ->where('keterangan', 'LIKE', '%Stock Opname%')
        ->exists();
    
    if ($hasOpnameAdjustment) {
        echo "[DELETE] ID={$r->id_rekaman_stok}, produk={$r->id_produk}: {$r->keterangan}\n";
        echo "  (Already has Stock Opname adjustment at cutoff)\n\n";
        
        if (!$dryRun) {
            DB::table('rekaman_stoks')->where('id_rekaman_stok', $r->id_rekaman_stok)->delete();
        }
        
        $deleteCount++;
        $affectedProducts[$r->id_produk] = true;
    }
}

echo "Deleted {$deleteCount} invalid records\n\n";

// Recalculate affected products
echo "============================================================\n";
echo "  RECALCULATING AFFECTED PRODUCTS\n";
echo "============================================================\n\n";

$recalcCount = 0;
$updateCount = 0;

foreach (array_keys($affectedProducts) as $productId) {
    $opnameStock = $baseline[$productId] ?? null;
    if (!$opnameStock) continue;
    
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
        $recalcCount++;
        $updateCount += count($updates);
        
        echo "[RECALC] Product {$productId}: " . count($updates) . " records updated\n";
        
        if (!$dryRun) {
            foreach ($updates as $id => $upd) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $id)
                    ->update($upd);
            }
        }
    }
}

echo "\nRecalculated: {$recalcCount} products, {$updateCount} records\n\n";

// Sync produk.stok
echo "============================================================\n";
echo "  SYNC produk.stok\n";
echo "============================================================\n\n";

$syncCount = 0;

foreach (array_keys($affectedProducts) as $productId) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if (!$lastRekaman) continue;
    
    $expectedStock = max(0, intval($lastRekaman->stok_sisa));
    $currentStock = DB::table('produk')->where('id_produk', $productId)->value('stok');
    
    if (intval($currentStock) != $expectedStock) {
        echo "[SYNC] Product {$productId}: {$currentStock} -> {$expectedStock}\n";
        
        if (!$dryRun) {
            DB::table('produk')
                ->where('id_produk', $productId)
                ->update(['stok' => $expectedStock]);
        }
        
        $syncCount++;
    }
}

echo "\nSynced: {$syncCount} products\n\n";

// Final verification
echo "============================================================\n";
echo "  FINAL VERIFICATION\n";
echo "============================================================\n\n";

$issues = 0;

// Check produk.stok sync for all products
$products = DB::table('produk')->get();
foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman && intval($product->stok) != intval($lastRekaman->stok_sisa)) {
        $issues++;
        echo "[MISMATCH] Product {$product->id_produk}: produk={$product->stok}, rekaman={$lastRekaman->stok_sisa}\n";
    }
}

echo "\nTotal mismatches: {$issues}\n\n";

if ($dryRun) {
    echo "Run with --execute to apply changes.\n";
} else {
    echo ($issues == 0 ? "SUCCESS! All clean." : "Run again if issues remain.") . "\n";
}
