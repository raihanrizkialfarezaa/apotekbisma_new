<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$broken = DB::select("
    SELECT id_rekaman_stok, stok_awal, stok_masuk, stok_keluar, stok_sisa, 
           (stok_awal + stok_masuk - stok_keluar) as calculated 
    FROM rekaman_stoks 
    WHERE stok_sisa != (stok_awal + stok_masuk - stok_keluar)
");

echo "Broken records: " . count($broken) . "\n\n";

foreach ($broken as $b) {
    $newSisa = max(0, $b->calculated);
    DB::table('rekaman_stoks')
        ->where('id_rekaman_stok', $b->id_rekaman_stok)
        ->update(['stok_sisa' => $newSisa]);
    echo "Fixed ID:{$b->id_rekaman_stok}: {$b->stok_sisa} -> {$newSisa}\n";
}

echo "\nDone.\n";
