<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== FULL REPAIR FOR ALL REMAINING ISSUES ===\n\n";

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

$issueProducts = [];
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
        $issueProducts[] = $pid;
    }
}

echo "Found " . count($issueProducts) . " products with issues\n\n";

foreach ($issueProducts as $productId) {
    $product = DB::table('produk')->where('id_produk', $productId)->first();
    $opnameStock = $opnameData[$productId];
    
    echo "Fixing [{$productId}] " . ($product ? $product->nama_produk : 'Unknown') . "\n";
    
    $existingOpname = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '2025-12-31 23:59:59')
        ->where('keterangan', 'LIKE', '%Stock Opname%')
        ->first();
    
    if (!$existingOpname) {
        $lastBefore = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('waktu', '<', '2025-12-31 23:59:59')
            ->orderBy('waktu', 'desc')
            ->first();
        
        if ($lastBefore) {
            $lastSisa = intval($lastBefore->stok_sisa);
            $adjustment = $opnameStock - $lastSisa;
            
            $stokMasuk = $adjustment > 0 ? $adjustment : 0;
            $stokKeluar = $adjustment < 0 ? abs($adjustment) : 0;
            
            DB::table('rekaman_stoks')->insert([
                'id_produk' => $productId,
                'id_penjualan' => null,
                'id_pembelian' => null,
                'stok_awal' => $lastSisa,
                'stok_masuk' => $stokMasuk,
                'stok_keluar' => $stokKeluar,
                'stok_sisa' => $opnameStock,
                'waktu' => '2025-12-31 23:59:59',
                'keterangan' => 'Stock Opname 31 Desember 2025: Penyesuaian dari ' . $lastSisa . ' ke ' . $opnameStock,
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            echo "  Inserted opname adjustment: {$lastSisa} -> {$opnameStock}\n";
        }
    }
    
    $allRecords = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($allRecords->isEmpty()) continue;
    
    $runningStock = intval($allRecords->first()->stok_awal);
    $isFirst = true;
    
    foreach ($allRecords as $r) {
        $expectedAwal = $isFirst ? intval($r->stok_awal) : $runningStock;
        $expectedSisa = $expectedAwal + intval($r->stok_masuk) - intval($r->stok_keluar);
        
        if (intval($r->stok_awal) != $expectedAwal || intval($r->stok_sisa) != $expectedSisa) {
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $r->id_rekaman_stok)
                ->update([
                    'stok_awal' => $expectedAwal,
                    'stok_sisa' => $expectedSisa
                ]);
        }
        
        $runningStock = $expectedSisa;
        $isFirst = false;
    }
    
    DB::table('produk')
        ->where('id_produk', $productId)
        ->update(['stok' => max(0, $runningStock)]);
    
    echo "  Final stock: {$runningStock}\n";
}

echo "\n=== FINAL VERIFICATION ===\n";

$remainingIssues = 0;
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
        $remainingIssues++;
        $prod = DB::table('produk')->where('id_produk', $pid)->first();
        echo "Still issue: [{$pid}] " . ($prod ? $prod->nama_produk : '') . "\n";
        echo "  Last: {$lastBefore->stok_sisa}, First: {$firstAfter->stok_awal}, Gap: " . (intval($firstAfter->stok_awal) - intval($lastBefore->stok_sisa)) . "\n";
    }
}

echo "\nRemaining issues: {$remainingIssues}\n";

if ($remainingIssues == 0) {
    echo "\nSUCCESS: All stock gaps have been fixed!\n";
}
