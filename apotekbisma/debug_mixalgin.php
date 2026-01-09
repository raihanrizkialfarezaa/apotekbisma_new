<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
use Illuminate\Support\Facades\DB;

echo "=== Product 524 (MIXALGIN) Full History ===\n\n";

$records = DB::table('rekaman_stoks')
    ->where('id_produk', 524)
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

foreach ($records as $r) {
    echo "ID={$r->id_rekaman_stok}, waktu={$r->waktu}, awal={$r->stok_awal}, +{$r->stok_masuk}, -{$r->stok_keluar}, sisa={$r->stok_sisa}\n";
    echo "  ket: " . substr($r->keterangan ?? '', 0, 70) . "\n";
}

echo "\n\nLast Record Info:\n";
$last = DB::table('rekaman_stoks')
    ->where('id_produk', 524)
    ->orderBy('waktu', 'desc')
    ->orderBy('id_rekaman_stok', 'desc')
    ->first();
echo "ID={$last->id_rekaman_stok}, waktu={$last->waktu}, stok_sisa={$last->stok_sisa}\n";

echo "\n\nProduk.stok:\n";
$produk = DB::table('produk')->where('id_produk', 524)->first();
echo "stok={$produk->stok}\n";
