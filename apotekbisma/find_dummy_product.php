<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;

echo "Mencari produk Dummy Product2...\n";

$produk = Produk::where('nama_produk', 'LIKE', '%Dummy Product2%')->first();

if ($produk) {
    echo "ID: " . $produk->id_produk . "\n";
    echo "Nama: " . $produk->nama_produk . "\n";
} else {
    echo "Tidak ditemukan, mencari semua produk dummy...\n";
    $dummyProducts = Produk::where('nama_produk', 'LIKE', '%dummy%')->get();
    
    if ($dummyProducts->count() > 0) {
        foreach ($dummyProducts as $prod) {
            echo "ID: {$prod->id_produk}, Nama: {$prod->nama_produk}\n";
        }
    } else {
        echo "Tidak ada produk dummy, menggunakan produk ID 117\n";
    }
}
