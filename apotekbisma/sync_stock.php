<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\PembelianDetail;
use App\Models\RekamanStok;

echo "=== Comprehensive Stock Fix ===\n";

// Get all products and recalculate their stock based on purchase and sales history
$products = Produk::all();

foreach ($products as $produk) {
    echo "\nChecking: " . $produk->nama_produk . " (Current: " . $produk->stok . ")\n";
    
    // Calculate expected stock from all purchases
    $total_purchases = PembelianDetail::where('id_produk', $produk->id_produk)
        ->sum('jumlah');
    
    // Calculate total sales (if any)
    $total_sales = 0;
    if (class_exists('App\Models\PenjualanDetail')) {
        $total_sales = \App\Models\PenjualanDetail::where('id_produk', $produk->id_produk)
            ->sum('jumlah');
    }
    
    $expected_stock = $total_purchases - $total_sales;
    
    if ($expected_stock != $produk->stok) {
        echo "- Expected: " . $expected_stock . " (Purchases: " . $total_purchases . " - Sales: " . $total_sales . ")";
        echo " | Actual: " . $produk->stok . " | Difference: " . ($expected_stock - $produk->stok) . "\n";
        
        // Update to correct stock
        $produk->stok = max(0, $expected_stock); // Ensure non-negative
        $produk->save();
        
        echo "- Fixed to: " . $produk->stok . "\n";
    } else {
        echo "- OK\n";
    }
}

echo "\n=== Stock synchronization complete ===\n";
