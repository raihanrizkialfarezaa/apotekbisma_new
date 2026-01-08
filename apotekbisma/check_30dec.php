<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Records for Product 63 on 30 Dec 2025:\n";
$r = DB::table('rekaman_stoks')->where('id_produk', 63)->where('waktu', 'like', '2025-12-30%')->get();
foreach($r as $x) {
    echo $x->id_rekaman_stok . ' | ' . $x->waktu . ' | Masuk: ' . $x->stok_masuk . ' | Keluar: ' . $x->stok_keluar . ' | Sisa: ' . $x->stok_sisa . "\n";
}
if ($r->isEmpty()) echo "No records found.\n";

echo "\nChecking id_pembelian 048659 (Faktur from screenshot):\n";
$pembelian = DB::table('pembelian')->where('id_pembelian', 48659)->first();
if ($pembelian) {
    echo "Pembelian ID: " . $pembelian->id_pembelian . "\n";
    echo "Waktu: " . $pembelian->waktu . "\n";
    echo "Supplier: " . ($pembelian->id_supplier ?? 'N/A') . "\n";
} else {
    echo "Pembelian not found.\n";
}

echo "\nAll rekaman_stoks linked to pembelian 48659:\n";
$linked = DB::table('rekaman_stoks')->where('id_pembelian', 48659)->get();
foreach ($linked as $l) {
    echo "ID: " . $l->id_rekaman_stok . " | Produk: " . $l->id_produk . " | Waktu: " . $l->waktu . " | Masuk: " . $l->stok_masuk . "\n";
}
