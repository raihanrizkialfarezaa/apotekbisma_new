<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║   COMPLETE STOCK FIX - INSERT ADJUSTMENTS & RECALCULATE ALL                  ║\n";
echo "║   Date: " . date('Y-m-d H:i:s') . "                                               ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";

set_time_limit(600);
ini_set('memory_limit', '512M');

$dryRun = !isset($argv[1]) || $argv[1] !== '--execute';

if ($dryRun) {
    echo "[DRY RUN MODE] Tidak ada perubahan yang akan dibuat.\n";
    echo "Jalankan dengan --execute untuk menerapkan perubahan.\n\n";
} else {
    echo "[EXECUTE MODE] Perubahan AKAN diterapkan ke database!\n\n";
}

$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$opnameData = [];
$handle = fopen($csvFile, 'r');
fgetcsv($handle);
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) >= 3 && !empty($row[0])) {
        $opnameData[intval($row[0])] = intval($row[2]);
    }
}
fclose($handle);

echo "Loaded " . count($opnameData) . " products from stock opname file\n\n";

$problems = [];
foreach ($opnameData as $pid => $opnameStock) {
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->where('waktu', '<=', '2025-12-31 23:59:59')
        ->orderBy('waktu', 'desc')
        ->first();
    
    $firstAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->where('waktu', '>', '2025-12-31 23:59:59')
        ->orderBy('waktu', 'asc')
        ->first();
    
    if ($lastBefore && $firstAfter && intval($firstAfter->stok_awal) != intval($lastBefore->stok_sisa)) {
        $problems[] = [
            'id' => $pid,
            'opname' => $opnameStock,
            'last_sisa' => intval($lastBefore->stok_sisa)
        ];
    }
}

echo "STEP 1: Insert Stock Opname Adjustment Records\n";
echo "Ditemukan " . count($problems) . " produk dengan gap\n\n";

$insertedCount = 0;
$skippedCount = 0;

foreach ($problems as $p) {
    $existingAdj = DB::table('rekaman_stoks')
        ->where('id_produk', $p['id'])
        ->where('waktu', '2025-12-31 23:59:59')
        ->where('keterangan', 'LIKE', '%Stock Opname%')
        ->first();
    
    if ($existingAdj) {
        $skippedCount++;
        continue;
    }
    
    $adjustmentAmount = $p['opname'] - $p['last_sisa'];
    $stokMasuk = $adjustmentAmount > 0 ? $adjustmentAmount : 0;
    $stokKeluar = $adjustmentAmount < 0 ? abs($adjustmentAmount) : 0;
    
    if (!$dryRun) {
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $p['id'],
            'id_penjualan' => null,
            'id_pembelian' => null,
            'stok_awal' => $p['last_sisa'],
            'stok_masuk' => $stokMasuk,
            'stok_keluar' => $stokKeluar,
            'stok_sisa' => $p['opname'],
            'waktu' => '2025-12-31 23:59:59',
            'keterangan' => 'Stock Opname 31 Desember 2025: Penyesuaian dari ' . $p['last_sisa'] . ' ke ' . $p['opname'],
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
    $insertedCount++;
}

echo "  Inserted: {$insertedCount}, Skipped (already exists): {$skippedCount}\n\n";

echo "STEP 2: Recalculate ALL stock chains based on stock opname\n\n";

$recalcCount = 0;
$errorCount = 0;

foreach ($opnameData as $pid => $opnameStock) {
    $allRecords = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($allRecords->isEmpty()) {
        continue;
    }
    
    $runningStock = intval($allRecords->first()->stok_awal);
    $needsUpdate = false;
    $updates = [];
    
    $isFirst = true;
    foreach ($allRecords as $r) {
        $expectedAwal = $isFirst ? intval($r->stok_awal) : $runningStock;
        $expectedSisa = $expectedAwal + intval($r->stok_masuk) - intval($r->stok_keluar);
        
        if (intval($r->stok_awal) != $expectedAwal || intval($r->stok_sisa) != $expectedSisa) {
            $updates[$r->id_rekaman_stok] = [
                'stok_awal' => $expectedAwal,
                'stok_sisa' => $expectedSisa
            ];
            $needsUpdate = true;
        }
        
        $runningStock = $expectedSisa;
        $isFirst = false;
    }
    
    if ($needsUpdate && !$dryRun) {
        try {
            foreach ($updates as $id => $data) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $id)
                    ->update($data);
            }
            
            DB::table('produk')
                ->where('id_produk', $pid)
                ->update(['stok' => max(0, $runningStock)]);
            
            $recalcCount++;
        } catch (\Exception $e) {
            $errorCount++;
        }
    } elseif ($needsUpdate) {
        $recalcCount++;
    }
}

echo "  Recalculated: {$recalcCount} products\n";
echo "  Errors: {$errorCount}\n\n";

echo "STEP 3: Verify remaining issues\n\n";

$remainingProblems = 0;
foreach ($opnameData as $pid => $opnameStock) {
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->where('waktu', '<=', '2025-12-31 23:59:59')
        ->orderBy('waktu', 'desc')
        ->first();
    
    $firstAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->where('waktu', '>', '2025-12-31 23:59:59')
        ->orderBy('waktu', 'asc')
        ->first();
    
    if ($lastBefore && $firstAfter && intval($firstAfter->stok_awal) != intval($lastBefore->stok_sisa)) {
        $remainingProblems++;
    }
}

echo "  Remaining gap problems: {$remainingProblems}\n\n";

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "SUMMARY:\n";
echo "  Total products with gap: " . count($problems) . "\n";
echo "  Adjustment records inserted: {$insertedCount}\n";
echo "  Products recalculated: {$recalcCount}\n";
echo "  Remaining problems: {$remainingProblems}\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n";

if ($dryRun) {
    echo "\nUntuk menerapkan perbaikan, jalankan:\n";
    echo "  php " . basename(__FILE__) . " --execute\n";
} else {
    echo "\nSemua perbaikan telah diterapkan!\n";
}
