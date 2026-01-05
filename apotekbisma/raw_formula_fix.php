<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Direct formula fix with RAW SQL...\n";

DB::update("
    UPDATE rekaman_stoks 
    SET stok_sisa = CASE 
        WHEN (stok_awal + stok_masuk - stok_keluar) < 0 THEN 0 
        ELSE (stok_awal + stok_masuk - stok_keluar) 
    END
    WHERE ABS(stok_sisa - (stok_awal + stok_masuk - stok_keluar)) > 0.01
");

$invalid = DB::select("
    SELECT COUNT(*) as cnt 
    FROM rekaman_stoks 
    WHERE stok_sisa != (stok_awal + stok_masuk - stok_keluar)
");

echo "Remaining invalid: {$invalid[0]->cnt}\n";

$neg = DB::select("
    SELECT id_rekaman_stok, stok_sisa FROM rekaman_stoks WHERE stok_sisa < 0
");
echo "Negative stok_sisa: " . count($neg) . "\n";

if (count($neg) > 0) {
    DB::update("UPDATE rekaman_stoks SET stok_sisa = 0 WHERE stok_sisa < 0");
    echo "Fixed negative stok_sisa\n";
}

$finalCheck = DB::select("
    SELECT rs.id_rekaman_stok, rs.stok_awal, rs.stok_masuk, rs.stok_keluar, rs.stok_sisa,
           (rs.stok_awal + rs.stok_masuk - rs.stok_keluar) as calc
    FROM rekaman_stoks rs
    WHERE rs.stok_sisa != (rs.stok_awal + rs.stok_masuk - rs.stok_keluar)
    LIMIT 10
");

echo "\nRemaining records with formula issue:\n";
foreach ($finalCheck as $r) {
    echo "  ID: {$r->id_rekaman_stok} | awal:{$r->stok_awal} + masuk:{$r->stok_masuk} - keluar:{$r->stok_keluar} = calc:{$r->calc} vs sisa:{$r->stok_sisa}\n";
}
