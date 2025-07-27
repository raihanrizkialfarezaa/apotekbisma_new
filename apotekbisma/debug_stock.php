<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\PembelianDetail;
use App\Models\RekamanStok;

echo "=== Debugging Stock Update Issue ===\n";

// Check a specific product (like ACETHYL or ACIFAR CREAM)
$produk = Produk::where('nama_produk', 'like', '%acethyl%')
    ->orWhere('nama_produk', 'like', '%acifar%')
    ->first();

if (!$produk) {
    echo "No matching product found. Using first product...\n";
    $produk = Produk::first();
}

if ($produk) {
    echo "Product: " . $produk->nama_produk . "\n";
    echo "Current Stock: " . $produk->stok . "\n";
    
    // Check recent purchase details for this product
    $recent_purchases = PembelianDetail::where('id_produk', $produk->id_produk)
        ->whereHas('pembelian', function($query) {
            $query->whereDate('created_at', '>=', date('Y-m-d', strtotime('-7 days')));
        })
        ->with('pembelian')
        ->orderBy('created_at', 'desc')
        ->limit(5)
        ->get();
    
    echo "\nRecent Purchase Details (last 7 days):\n";
    foreach ($recent_purchases as $detail) {
        echo "- ID: " . $detail->id_pembelian_detail . " | Qty: " . $detail->jumlah . " | Date: " . $detail->created_at . "\n";
    }
    
    // Check recent stock records
    $recent_records = RekamanStok::where('id_produk', $produk->id_produk)
        ->whereDate('created_at', '>=', date('Y-m-d', strtotime('-7 days')))
        ->orderBy('created_at', 'desc')
        ->limit(10)
        ->get();
    
    echo "\nRecent Stock Records (last 7 days):\n";
    foreach ($recent_records as $record) {
        echo "- " . $record->created_at . " | In: " . ($record->stok_masuk ?? 0) . " | Out: " . ($record->stok_keluar ?? 0) . " | Final: " . $record->stok_sisa . "\n";
    }
    
    // Test stock update manually
    echo "\n=== Testing Manual Stock Update ===\n";
    $original_stock = $produk->stok;
    echo "Original stock: " . $original_stock . "\n";
    
    $produk->stok += 1;
    $produk->save();
    
    // Reload from database
    $produk->refresh();
    echo "After +1: " . $produk->stok . "\n";
    
    // Restore original
    $produk->stok = $original_stock;
    $produk->save();
    $produk->refresh();
    echo "Restored to: " . $produk->stok . "\n";
    
    echo "Manual stock update test: " . ($produk->stok == $original_stock ? "PASSED" : "FAILED") . "\n";
    
} else {
    echo "No products found in database\n";
}

echo "\n=== Debug Complete ===\n";
