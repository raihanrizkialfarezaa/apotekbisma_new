<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
use Illuminate\Support\Facades\DB;

$problematic = [602, 524, 335, 125, 808, 674, 795, 510, 512, 178, 357, 561];

foreach ($problematic as $pid) {
    echo "\n=== Product {$pid} ===\n";
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->where('waktu', '>', '2025-12-25 00:00:00')
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->take(10)
        ->get();
    foreach ($records as $r) {
        echo "ID={$r->id_rekaman_stok}, waktu={$r->waktu}, awal={$r->stok_awal}, +{$r->stok_masuk}, -{$r->stok_keluar}, sisa={$r->stok_sisa}\n";
        echo "  ket: " . substr($r->keterangan ?? '', 0, 60) . "\n";
    }
}
