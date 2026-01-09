<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$productId = 602;

echo "=== DETAILED INVESTIGATION: OBH ITRASAL (ID: 602) ===\n\n";

$allRecords = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->orderBy('waktu', 'asc')
    ->orderBy('created_at', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

echo "Total records: {$allRecords->count()}\n\n";

$prevSisa = null;
foreach ($allRecords as $r) {
    $gap = "";
    if ($prevSisa !== null && intval($r->stok_awal) != intval($prevSisa)) {
        $gap = " [GAP: " . (intval($r->stok_awal) - intval($prevSisa)) . "]";
    }
    
    echo "[{$r->id_rekaman_stok}] {$r->waktu}\n";
    echo "  Awal:{$r->stok_awal} +{$r->stok_masuk} -{$r->stok_keluar} = Sisa:{$r->stok_sisa}{$gap}\n";
    echo "  Created: {$r->created_at}\n";
    echo "  Keterangan: {$r->keterangan}\n\n";
    
    $prevSisa = $r->stok_sisa;
}

$existingOpname = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where('waktu', '2025-12-31 23:59:59')
    ->first();

echo "=== Stock Opname Record Check ===\n";
if ($existingOpname) {
    echo "Found: ID {$existingOpname->id_rekaman_stok}\n";
    echo "  {$existingOpname->stok_awal} -> {$existingOpname->stok_sisa}\n";
    echo "  {$existingOpname->keterangan}\n";
} else {
    echo "No stock opname record found for 2025-12-31 23:59:59\n";
    echo "Need to insert one.\n";
    
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '<=', '2025-12-31 23:59:59')
        ->orderBy('waktu', 'desc')
        ->first();
    
    $opnameStock = 8;
    
    if ($lastBefore) {
        echo "\nLast record before cutoff: stok_sisa = {$lastBefore->stok_sisa}\n";
        echo "Opname stock should be: {$opnameStock}\n";
        
        $adjustment = $opnameStock - intval($lastBefore->stok_sisa);
        echo "Adjustment needed: {$adjustment}\n";
    }
}
