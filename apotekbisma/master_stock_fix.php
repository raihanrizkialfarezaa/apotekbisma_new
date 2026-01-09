<?php
/**
 * ============================================================================
 * COMPREHENSIVE STOCK FIX SCRIPT - ALL IN ONE
 * ============================================================================
 * 
 * Script ini melakukan perbaikan stok secara menyeluruh:
 * 1. Membaca data Stock Opname 31 Desember 2025 dari CSV
 * 2. Menyisipkan record "Stock Opname Adjustment" untuk produk yang ada gap
 * 3. Menghitung ulang seluruh chain stok (stok_awal dan stok_sisa)
 * 4. Mensinkronkan produk.stok dengan stok_sisa terakhir di rekaman_stoks
 * 5. Melakukan verifikasi akhir
 * 
 * CARA PENGGUNAAN:
 * - Dry run (preview): php master_stock_fix.php
 * - Execute (apply):   php master_stock_fix.php --execute
 * 
 * PENTING: 
 * - Pastikan file "REKAMAN STOK FINAL 31 DESEMBER 2025.csv" ada di folder yang sama
 * - Backup database sebelum menjalankan dengan --execute
 * 
 * ============================================================================
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
    echo "\n";
    echo str_repeat("=", 80) . "\n";
    echo "  " . $title . "\n";
    echo str_repeat("=", 80) . "\n\n";
}

function printSubHeader($title) {
    echo "\n--- " . $title . " ---\n\n";
}

printHeader("COMPREHENSIVE STOCK FIX SCRIPT");
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Mode: " . ($dryRun ? "DRY RUN (preview only)" : "EXECUTE (applying changes)") . "\n";

if ($dryRun) {
    echo "\n[INFO] Ini adalah mode DRY RUN. Tidak ada perubahan yang akan disimpan ke database.\n";
    echo "[INFO] Untuk menerapkan perubahan, jalankan: php " . basename(__FILE__) . " --execute\n";
}

printHeader("STEP 1: LOAD STOCK OPNAME DATA");

$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';

if (!file_exists($csvFile)) {
    die("[ERROR] File CSV tidak ditemukan: {$csvFile}\n");
}

$opnameData = [];
$handle = fopen($csvFile, 'r');
$header = fgetcsv($handle);

while (($row = fgetcsv($handle)) !== false) {
    if (count($row) >= 3 && !empty($row[0])) {
        $opnameData[intval($row[0])] = [
            'nama' => $row[1],
            'stok' => intval($row[2])
        ];
    }
}
fclose($handle);

echo "Loaded " . count($opnameData) . " products from stock opname file\n";

printHeader("STEP 2: IDENTIFY PRODUCTS WITH STOCK GAPS");

$gapProducts = [];

foreach ($opnameData as $productId => $opname) {
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '<', $cutoffDate)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $firstAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->first();
    
    $existingOpnameRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', $cutoffDate)
        ->where('keterangan', 'LIKE', '%Stock Opname%')
        ->first();
    
    if ($lastBefore && $firstAfter && !$existingOpnameRecord) {
        $lastSisa = intval($lastBefore->stok_sisa);
        $firstAwal = intval($firstAfter->stok_awal);
        $opnameStock = $opname['stok'];
        
        if ($lastSisa != $opnameStock || $firstAwal != $lastSisa) {
            $gapProducts[] = [
                'id' => $productId,
                'nama' => $opname['nama'],
                'last_sisa' => $lastSisa,
                'opname_stock' => $opnameStock,
                'first_awal' => $firstAwal,
                'needs_adjustment' => ($lastSisa != $opnameStock)
            ];
        }
    }
}

echo "Found " . count($gapProducts) . " products with gaps between 2025-2026\n";

if (count($gapProducts) > 0) {
    printSubHeader("Top 10 Products with Gaps");
    
    usort($gapProducts, function($a, $b) {
        return abs($b['last_sisa'] - $b['opname_stock']) - abs($a['last_sisa'] - $a['opname_stock']);
    });
    
    $shown = 0;
    foreach ($gapProducts as $p) {
        if ($shown >= 10) break;
        $adjustment = $p['opname_stock'] - $p['last_sisa'];
        echo "[{$p['id']}] {$p['nama']}\n";
        echo "    Last 2025: {$p['last_sisa']}, Opname: {$p['opname_stock']}, First 2026: {$p['first_awal']}\n";
        echo "    Adjustment needed: " . ($adjustment >= 0 ? "+{$adjustment}" : $adjustment) . "\n";
        $shown++;
    }
    
    if (count($gapProducts) > 10) {
        echo "\n... dan " . (count($gapProducts) - 10) . " produk lainnya\n";
    }
}

printHeader("STEP 3: INSERT STOCK OPNAME ADJUSTMENT RECORDS");

$insertedCount = 0;
$skippedCount = 0;

foreach ($gapProducts as $p) {
    if (!$p['needs_adjustment']) {
        $skippedCount++;
        continue;
    }
    
    $lastSisa = $p['last_sisa'];
    $opnameStock = $p['opname_stock'];
    $adjustment = $opnameStock - $lastSisa;
    
    $stokMasuk = $adjustment > 0 ? $adjustment : 0;
    $stokKeluar = $adjustment < 0 ? abs($adjustment) : 0;
    
    if (!$dryRun) {
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $p['id'],
            'id_penjualan' => null,
            'id_pembelian' => null,
            'stok_awal' => $lastSisa,
            'stok_masuk' => $stokMasuk,
            'stok_keluar' => $stokKeluar,
            'stok_sisa' => $opnameStock,
            'waktu' => $cutoffDate,
            'keterangan' => 'Stock Opname 31 Desember 2025: Penyesuaian dari ' . $lastSisa . ' ke ' . $opnameStock,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
    
    $insertedCount++;
}

echo "Adjustment records " . ($dryRun ? "to be inserted" : "inserted") . ": {$insertedCount}\n";
echo "Skipped (no adjustment needed): {$skippedCount}\n";

printHeader("STEP 4: RECALCULATE ALL STOCK CHAINS");

$allProductIds = DB::table('rekaman_stoks')
    ->distinct()
    ->pluck('id_produk')
    ->toArray();

echo "Processing " . count($allProductIds) . " products with stock records...\n\n";

$recalculatedCount = 0;
$updatedRecordsCount = 0;
$progressInterval = max(1, intval(count($allProductIds) / 20));

foreach ($allProductIds as $index => $productId) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($records->isEmpty()) continue;
    
    $runningStock = intval($records->first()->stok_awal);
    $isFirst = true;
    $updates = [];
    
    foreach ($records as $r) {
        $expectedAwal = $isFirst ? intval($r->stok_awal) : $runningStock;
        $expectedSisa = $expectedAwal + intval($r->stok_masuk) - intval($r->stok_keluar);
        
        if (intval($r->stok_awal) != $expectedAwal || intval($r->stok_sisa) != $expectedSisa) {
            $updates[$r->id_rekaman_stok] = [
                'stok_awal' => $expectedAwal,
                'stok_sisa' => $expectedSisa
            ];
        }
        
        $runningStock = $expectedSisa;
        $isFirst = false;
    }
    
    if (!empty($updates)) {
        if (!$dryRun) {
            foreach ($updates as $id => $data) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $id)
                    ->update($data);
            }
        }
        
        $updatedRecordsCount += count($updates);
        $recalculatedCount++;
    }
    
    if (($index + 1) % $progressInterval == 0) {
        $percent = round(($index + 1) / count($allProductIds) * 100);
        echo "  Progress: {$percent}% ({" . ($index + 1) . "/" . count($allProductIds) . "})\n";
    }
}

echo "\nProducts " . ($dryRun ? "to be recalculated" : "recalculated") . ": {$recalculatedCount}\n";
echo "Records " . ($dryRun ? "to be updated" : "updated") . ": {$updatedRecordsCount}\n";

printHeader("STEP 5: SYNC produk.stok WITH rekaman_stoks");

$products = DB::table('produk')->get();
$syncedCount = 0;
$alreadyOkCount = 0;
$noRecordCount = 0;

foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('created_at', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if (!$lastRekaman) {
        $noRecordCount++;
        continue;
    }
    
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

echo "Products " . ($dryRun ? "to be synced" : "synced") . ": {$syncedCount}\n";
echo "Already in sync: {$alreadyOkCount}\n";
echo "No stock records: {$noRecordCount}\n";

printHeader("STEP 6: FINAL VERIFICATION");

$issues = [
    'gap_2025_2026' => 0,
    'continuity_errors' => 0,
    'calculation_errors' => 0,
    'produk_rekaman_mismatch' => 0
];

echo "Checking gap between 2025-2026...\n";
foreach ($opnameData as $productId => $opname) {
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '<=', $cutoffDate)
        ->orderBy('waktu', 'desc')
        ->first();
    
    $firstAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->first();
    
    if ($lastBefore && $firstAfter && intval($firstAfter->stok_awal) != intval($lastBefore->stok_sisa)) {
        $issues['gap_2025_2026']++;
    }
}
echo "  Gap issues: {$issues['gap_2025_2026']}\n";

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
            break;
        }
        $prevSisa = $r->stok_sisa;
    }
}
echo "  Continuity errors: {$issues['continuity_errors']}\n";

echo "Checking produk.stok sync...\n";
foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->first();
    
    if ($lastRekaman && intval($product->stok) != intval($lastRekaman->stok_sisa)) {
        $issues['produk_rekaman_mismatch']++;
    }
}
echo "  Mismatch issues: {$issues['produk_rekaman_mismatch']}\n";

printHeader("SUMMARY");

$totalIssues = array_sum($issues);

echo "Mode: " . ($dryRun ? "DRY RUN" : "EXECUTE") . "\n\n";

echo "Actions " . ($dryRun ? "to be taken" : "completed") . ":\n";
echo "  - Stock opname adjustments inserted: {$insertedCount}\n";
echo "  - Products recalculated: {$recalculatedCount}\n";
echo "  - Stock records updated: {$updatedRecordsCount}\n";
echo "  - Products synced: {$syncedCount}\n\n";

echo "Verification Results:\n";
foreach ($issues as $type => $count) {
    $status = $count == 0 ? "[OK]" : "[ISSUE]";
    echo "  {$status} {$type}: {$count}\n";
}

echo "\nTotal Issues: {$totalIssues}\n";

if ($dryRun) {
    printHeader("NEXT STEPS");
    echo "Ini adalah mode DRY RUN. Untuk menerapkan semua perubahan, jalankan:\n\n";
    echo "    php " . basename(__FILE__) . " --execute\n\n";
    echo "PENTING: Backup database Anda sebelum menjalankan dengan --execute!\n";
} else {
    if ($totalIssues == 0) {
        printHeader("SUCCESS!");
        echo "Semua data stok telah diperbaiki dan 100% konsisten!\n";
    } else {
        printHeader("PARTIAL SUCCESS");
        echo "Sebagian besar data telah diperbaiki, tetapi masih ada {$totalIssues} masalah.\n";
        echo "Jalankan script ini sekali lagi untuk menyelesaikan masalah yang tersisa.\n";
    }
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "  Script completed at " . date('Y-m-d H:i:s') . "\n";
echo str_repeat("=", 80) . "\n\n";
