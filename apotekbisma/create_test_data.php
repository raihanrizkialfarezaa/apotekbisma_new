<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Produk;

$produk = Produk::first();
$produk->stok = $produk->stok + 10;
$produk->save();

echo "Test: Mengubah stok produk {$produk->nama_produk} menjadi {$produk->stok}\n";
?>
