<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== FIX REMAINING 2 PRODUCTS ===\n\n";

$remainingProducts = [
    ['id' => 602, 'name' => 'OBH ITRASAL'],
    ['id' => 335, 'name' => 'GRATAZONE']
];

foreach ($remainingProducts as $prod) {
    $productId = $prod['id'];
    echo "Fixing [{$productId}] {$prod['name']}...\n";
    
    $allRecords = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($allRecords->isEmpty()) {
        echo "  No records found\n\n";
        continue;
    }
    
    $runningStock = intval($allRecords->first()->stok_awal);
    
    echo "  Records: {$allRecords->count()}\n";
    echo "  Initial stok_awal: {$runningStock}\n";
    
    $isFirst = true;
    $updates = [];
    
    foreach ($allRecords as $r) {
        $expectedAwal = $isFirst ? intval($r->stok_awal) : $runningStock;
        $expectedSisa = $expectedAwal + intval($r->stok_masuk) - intval($r->stok_keluar);
        
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
        echo "  Updates needed: " . count($updates) . "\n";
        
        foreach ($updates as $id => $data) {
            echo "    Record {$id}: awal {$data['old_awal']}->{$data['stok_awal']}, sisa {$data['old_sisa']}->{$data['stok_sisa']}\n";
            
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $id)
                ->update([
                    'stok_awal' => $data['stok_awal'],
                    'stok_sisa' => $data['stok_sisa']
                ]);
        }
        
        DB::table('produk')
            ->where('id_produk', $productId)
            ->update(['stok' => max(0, $runningStock)]);
        
        echo "  Final stock: {$runningStock}\n";
    } else {
        echo "  No updates needed\n";
    }
    
    echo "\n";
}

echo "=== VERIFICATION ===\n\n";

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

$problems = 0;
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
        $problems++;
        $prod = DB::table('produk')->where('id_produk', $pid)->first();
        echo "Still problem: [{$pid}] " . ($prod ? $prod->nama_produk : 'Unknown') . "\n";
        echo "  Last sisa: {$lastBefore->stok_sisa}, First awal: {$firstAfter->stok_awal}\n";
    }
}

echo "\nTotal remaining problems: {$problems}\n";
