<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$invalidRecords = DB::table('rekaman_stoks')
    ->where('waktu', '>', '2025-12-31 23:59:59')
    ->whereRaw('stok_sisa != (stok_awal + stok_masuk - stok_keluar)')
    ->get();

echo "Fixing " . $invalidRecords->count() . " records to allow negative values for consistency...\n";

foreach ($invalidRecords as $record) {
    $mathSisa = intval($record->stok_awal) + intval($record->stok_masuk) - intval($record->stok_keluar);
    
    // Update to exact mathematical result even if negative
    DB::table('rekaman_stoks')
        ->where('id_rekaman_stok', $record->id_rekaman_stok)
        ->update(['stok_sisa' => $mathSisa]);
        
    echo "Record {$record->id_rekaman_stok}: Updated to {$mathSisa}\n";
}

echo "Done.\n";
