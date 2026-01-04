<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$broken = DB::select("
    SELECT id_rekaman_stok, stok_awal, stok_masuk, stok_keluar, stok_sisa
    FROM rekaman_stoks 
    WHERE CAST(stok_sisa AS SIGNED) != (CAST(stok_awal AS SIGNED) + CAST(stok_masuk AS SIGNED) - CAST(stok_keluar AS SIGNED))
    LIMIT 30
");

echo "Broken records: " . count($broken) . "\n\n";

foreach ($broken as $b) {
    $calc = intval($b->stok_awal) + intval($b->stok_masuk) - intval($b->stok_keluar);
    echo "ID:{$b->id_rekaman_stok} | awal:{$b->stok_awal} masuk:{$b->stok_masuk} keluar:{$b->stok_keluar} sisa:{$b->stok_sisa} | calc:{$calc}\n";
    
    $newSisa = max(0, $calc);
    DB::table('rekaman_stoks')
        ->where('id_rekaman_stok', $b->id_rekaman_stok)
        ->update(['stok_sisa' => $newSisa]);
}

echo "\nVerifying...\n";
$stillBroken = DB::select("
    SELECT COUNT(*) as cnt FROM rekaman_stoks 
    WHERE CAST(stok_sisa AS SIGNED) != (CAST(stok_awal AS SIGNED) + CAST(stok_masuk AS SIGNED) - CAST(stok_keluar AS SIGNED))
");
echo "Still broken: " . $stillBroken[0]->cnt . "\n";
