<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;

// Test satu produk untuk memastikan sinkronisasi
$produk = Produk::first();
echo "Produk: " . $produk->nama_produk . "\n";
echo "Stok produk: " . $produk->stok . "\n";

$rekaman = RekamanStok::where('id_produk', $produk->id_produk)
    ->orderBy('waktu', 'desc')
    ->first();

if ($rekaman) {
    echo "Stok sisa rekaman: " . $rekaman->stok_sisa . "\n";
    echo "Sinkron: " . ($produk->stok == $rekaman->stok_sisa ? "Ya" : "Tidak") . "\n";
} else {
    echo "Tidak ada rekaman stok\n";
}

// Ubah stok produk untuk test
echo "\n--- Test mengubah stok produk ---\n";
$stokLama = $produk->stok;
$produk->stok = $stokLama + 5;
$produk->save();

echo "Stok produk setelah diubah: " . $produk->stok . "\n";
$rekamanBaru = RekamanStok::where('id_produk', $produk->id_produk)
    ->orderBy('waktu', 'desc')
    ->first();
echo "Stok sisa rekaman terbaru: " . $rekamanBaru->stok_sisa . "\n";
echo "Sinkron: " . ($produk->stok == $rekamanBaru->stok_sisa ? "Ya" : "Tidak") . "\n";
?>
