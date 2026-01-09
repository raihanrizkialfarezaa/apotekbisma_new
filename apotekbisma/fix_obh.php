<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== FIX OBH ITRASAL (ID: 602) ===\n\n";

$productId = 602;
$opnameStock = 8;

$existingOpname = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where('waktu', '2025-12-31 23:59:59')
    ->where('keterangan', 'LIKE', '%Stock Opname%')
    ->first();

if ($existingOpname) {
    echo "Stock opname record already exists: ID {$existingOpname->id_rekaman_stok}\n";
} else {
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '<=', '2025-12-31 23:59:59')
        ->orderBy('waktu', 'desc')
        ->first();
    
    if ($lastBefore) {
        $lastSisa = intval($lastBefore->stok_sisa);
        $adjustment = $opnameStock - $lastSisa;
        
        $stokMasuk = $adjustment > 0 ? $adjustment : 0;
        $stokKeluar = $adjustment < 0 ? abs($adjustment) : 0;
        
        echo "Inserting Stock Opname adjustment...\n";
        echo "  Last stok_sisa before cutoff: {$lastSisa}\n";
        echo "  Opname stock: {$opnameStock}\n";
        echo "  Adjustment: {$adjustment}\n";
        
        $insertId = DB::table('rekaman_stoks')->insertGetId([
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
        
        echo "  Inserted record ID: {$insertId}\n";
    }
}

echo "\nRecalculating stock chain...\n";

$allRecords = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->orderBy('waktu', 'asc')
    ->orderBy('created_at', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

$runningStock = intval($allRecords->first()->stok_awal);
$isFirst = true;
$updates = [];

foreach ($allRecords as $r) {
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
    echo "Updating " . count($updates) . " records...\n";
    foreach ($updates as $id => $data) {
        DB::table('rekaman_stoks')
            ->where('id_rekaman_stok', $id)
            ->update($data);
    }
}

DB::table('produk')
    ->where('id_produk', $productId)
    ->update(['stok' => max(0, $runningStock)]);

echo "Final stock: {$runningStock}\n\n";

echo "=== VERIFICATION ===\n";

$lastBefore = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where('waktu', '<=', '2025-12-31 23:59:59')
    ->orderBy('waktu', 'desc')
    ->first();

$firstAfter = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where('waktu', '>', '2025-12-31 23:59:59')
    ->orderBy('waktu', 'asc')
    ->first();

if ($lastBefore && $firstAfter) {
    echo "Last before cutoff: stok_sisa = {$lastBefore->stok_sisa}\n";
    echo "First after cutoff: stok_awal = {$firstAfter->stok_awal}\n";
    
    if (intval($firstAfter->stok_awal) == intval($lastBefore->stok_sisa)) {
        echo "SUCCESS: Stock chain is continuous!\n";
    } else {
        echo "STILL HAS GAP: " . (intval($firstAfter->stok_awal) - intval($lastBefore->stok_sisa)) . "\n";
    }
}
