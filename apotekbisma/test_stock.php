<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;

echo "=== Testing Stock Update System ===\n";

// Find a test product
$produk = Produk::where('nama_produk', 'like', '%cream%')
    ->orWhere('nama_produk', 'like', '%acifar%')
    ->first();

if (!$produk) {
    $produk = Produk::first();
}

if ($produk) {
    echo "Product: " . $produk->nama_produk . "\n";
    echo "Current Stock: " . $produk->stok . "\n";
    echo "Product ID: " . $produk->id_produk . "\n";
    
    // Check recent stock records
    $recent_records = RekamanStok::where('id_produk', $produk->id_produk)
        ->orderBy('created_at', 'desc')
        ->limit(5)
        ->get();
    
    echo "\nRecent Stock Records:\n";
    foreach ($recent_records as $record) {
        echo "- " . $record->waktu . " | In: " . ($record->stok_masuk ?? 0) . " | Out: " . ($record->stok_keluar ?? 0) . " | Final: " . $record->stok_sisa . "\n";
    }
} else {
    echo "No products found in database\n";
}

echo "\n=== Test Complete ===\n";
