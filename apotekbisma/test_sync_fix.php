<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== TESTING SYNC FIX ===\n\n";

echo "1. Before sync - Checking ACETHYLESISTEIN 200mg inconsistencies...\n";

$beforeRecords = DB::table('rekaman_stoks as rs')
    ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
    ->where('p.nama_produk', 'ACETHYLESISTEIN 200mg')
    ->select('rs.id_rekaman_stok', 'p.nama_produk', 'p.stok', 'rs.stok_awal', 'rs.stok_sisa', 'rs.created_at')
    ->orderBy('rs.id_rekaman_stok', 'desc')
    ->first();

if ($beforeRecords) {
    echo "Current record: Stok = {$beforeRecords->stok}, Awal = {$beforeRecords->stok_awal}, Sisa = {$beforeRecords->stok_sisa}\n";
    
    if ($beforeRecords->stok_awal != $beforeRecords->stok || $beforeRecords->stok_sisa != $beforeRecords->stok) {
        echo "INCONSISTENT - needs sync\n";
    } else {
        echo "CONSISTENT\n";
    }
} else {
    echo "No records found\n";
}

echo "\n2. Running sync command...\n";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$exitCode = $kernel->call('stock:sync', ['--force' => true]);

echo "Sync exit code: $exitCode\n";

echo "\n3. After sync - Checking same product...\n";

$afterRecords = DB::table('rekaman_stoks as rs')
    ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
    ->where('p.nama_produk', 'ACETHYLESISTEIN 200mg')
    ->select('rs.id_rekaman_stok', 'p.nama_produk', 'p.stok', 'rs.stok_awal', 'rs.stok_sisa', 'rs.created_at')
    ->orderBy('rs.id_rekaman_stok', 'desc')
    ->first();

if ($afterRecords) {
    echo "After sync: Stok = {$afterRecords->stok}, Awal = {$afterRecords->stok_awal}, Sisa = {$afterRecords->stok_sisa}\n";
    
    if ($afterRecords->stok_awal == $afterRecords->stok && $afterRecords->stok_sisa == $afterRecords->stok) {
        echo "SUCCESS - Now consistent!\n";
    } else {
        echo "FAILED - Still inconsistent\n";
    }
} else {
    echo "No records found after sync\n";
}

echo "\n4. Overall inconsistency check...\n";

$allInconsistent = DB::table('rekaman_stoks as rs')
    ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
    ->select('rs.id_rekaman_stok', 'rs.id_produk', 'p.nama_produk', 'p.stok', 'rs.stok_awal', 'rs.stok_sisa', 'rs.created_at')
    ->orderBy('rs.id_produk')
    ->orderBy('rs.id_rekaman_stok', 'desc')
    ->get();

$latestRecords = [];
$inconsistentCount = 0;

foreach ($allInconsistent as $record) {
    if (!isset($latestRecords[$record->id_produk])) {
        $latestRecords[$record->id_produk] = $record;
        
        if ($record->stok == 0 && $record->stok_awal == 0 && $record->stok_sisa == 0) {
            continue;
        }
        
        $isInconsistent = (
            $record->stok_awal != $record->stok ||
            $record->stok_sisa != $record->stok ||
            $record->stok_awal < 0 ||
            $record->stok_sisa < 0
        );
        
        if ($isInconsistent) {
            $inconsistentCount++;
            if ($inconsistentCount <= 5) {
                echo "- {$record->nama_produk}: Stok = {$record->stok}, Awal = {$record->stok_awal}, Sisa = {$record->stok_sisa}\n";
            }
        }
    }
}

echo "Total remaining inconsistencies: $inconsistentCount\n";

echo "\n=== TEST COMPLETED ===\n";
