<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

ini_set('memory_limit', '512M');

echo "=================================================================\n";
echo "FIXING INVALID REKAMAN FORMULAS\n";
echo "=================================================================\n\n";

$cutoffDateTime = '2025-12-31 23:59:59';

$invalidRecords = DB::table('rekaman_stoks')
    ->where('waktu', '>', $cutoffDateTime)
    ->whereRaw('stok_sisa != (stok_awal + stok_masuk - stok_keluar)')
    ->get();

echo "Found " . $invalidRecords->count() . " records with invalid formulas.\n\n";

$fixed = 0;

foreach ($invalidRecords as $record) {
    $expectedSisa = intval($record->stok_awal) + intval($record->stok_masuk) - intval($record->stok_keluar);
    if ($expectedSisa < 0) $expectedSisa = 0;

    echo "Fixing ID {$record->id_rekaman_stok} (Product {$record->id_produk}): ";
    echo "Current Sisa: {$record->stok_sisa}, Expected: {$expectedSisa}... ";

    DB::table('rekaman_stoks')
        ->where('id_rekaman_stok', $record->id_rekaman_stok)
        ->update([
            'stok_sisa' => $expectedSisa,
            'updated_at' => now()
        ]);
    
    echo "DONE\n";
    $fixed++;
}

echo "\nTotal fixed: {$fixed}\n";

// Re-verify
$remaining = DB::table('rekaman_stoks')
    ->where('waktu', '>', $cutoffDateTime)
    ->whereRaw('stok_sisa != (stok_awal + stok_masuk - stok_keluar)')
    ->count();

if ($remaining === 0) {
    echo "\n[SUCCESS] All invalid formulas fixed! System is now 100% Robust.\n";
} else {
    echo "\n[WARNING] Still {$remaining} invalid records remaining.\n";
}
