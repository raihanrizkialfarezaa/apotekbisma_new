<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
use Illuminate\Support\Facades\DB;

echo "Product 602 (OBH ITRASAL):\n";
$records = DB::table('rekaman_stoks')
    ->where('id_produk', 602)
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();
foreach ($records as $r) {
    echo "ID={$r->id_rekaman_stok}, waktu={$r->waktu}, awal={$r->stok_awal}, masuk={$r->stok_masuk}, keluar={$r->stok_keluar}, sisa={$r->stok_sisa}\n";
    echo "  ket: " . substr($r->keterangan ?? '', 0, 80) . "\n";
}

echo "\n\nProduct 335 (GRATAZONE):\n";
$records = DB::table('rekaman_stoks')
    ->where('id_produk', 335)
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();
foreach ($records as $r) {
    echo "ID={$r->id_rekaman_stok}, waktu={$r->waktu}, awal={$r->stok_awal}, masuk={$r->stok_masuk}, keluar={$r->stok_keluar}, sisa={$r->stok_sisa}\n";
    echo "  ket: " . substr($r->keterangan ?? '', 0, 80) . "\n";
}
