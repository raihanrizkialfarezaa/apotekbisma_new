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

foreach ($invalidRecords as $r) {
    echo "ID: {$r->id_rekaman_stok}\n";
    echo "Raw DB Values:\n";
    echo "  Stok Awal: {$r->stok_awal} (Type: " . gettype($r->stok_awal) . ")\n";
    echo "  Stok Masuk: {$r->stok_masuk} (Type: " . gettype($r->stok_masuk) . ")\n";
    echo "  Stok Keluar: {$r->stok_keluar} (Type: " . gettype($r->stok_keluar) . ")\n";
    echo "  Stok Sisa: {$r->stok_sisa} (Type: " . gettype($r->stok_sisa) . ")\n";
    
    $calc = intval($r->stok_awal) + intval($r->stok_masuk) - intval($r->stok_keluar);
    echo "  Calculated PHP: {$calc}\n";
    echo "--------------------------\n";
}
