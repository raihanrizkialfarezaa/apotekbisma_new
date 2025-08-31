<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== CHECKING STOCK CONDITIONS ===\n\n";

// 1. Check products with negative stock
echo "1. PRODUCTS WITH NEGATIVE STOCK:\n";
$negative_products = \App\Models\Produk::where('stok', '<', 0)->get();
echo "Found: " . $negative_products->count() . " products with negative stock\n";
foreach($negative_products as $p) {
    echo "ID: {$p->id_produk} - {$p->nama_produk} - Stok: {$p->stok}\n";
}
echo "\n";

// 2. Check rekaman_stok with negative values
echo "2. REKAMAN STOK WITH NEGATIVE VALUES:\n";
echo "- Negative stok_awal:\n";
$negative_awal = \App\Models\RekamanStok::where('stok_awal', '<', 0)->count();
echo "Found: {$negative_awal} records\n";

echo "- Negative stok_sisa:\n";
$negative_sisa = \App\Models\RekamanStok::where('stok_sisa', '<', 0)->count();
echo "Found: {$negative_sisa} records\n";

echo "- Negative stok_masuk:\n";
$negative_masuk = \App\Models\RekamanStok::where('stok_masuk', '<', 0)->count();
echo "Found: {$negative_masuk} records\n";

echo "- Negative stok_keluar:\n";
$negative_keluar = \App\Models\RekamanStok::where('stok_keluar', '<', 0)->count();
echo "Found: {$negative_keluar} records\n";

echo "\n";

// 3. Show summary statistics
echo "3. STOCK SUMMARY:\n";
$total_products = \App\Models\Produk::count();
$zero_stock = \App\Models\Produk::where('stok', '=', 0)->count();
$positive_stock = \App\Models\Produk::where('stok', '>', 0)->count();

echo "Total products: {$total_products}\n";
echo "Products with zero stock: {$zero_stock}\n";
echo "Products with positive stock: {$positive_stock}\n";
echo "Products with negative stock: {$negative_products->count()}\n";

echo "\n";

// 4. Show recent stock records that might be problematic
echo "4. RECENT PROBLEMATIC STOCK RECORDS (last 10):\n";
$problematic = \App\Models\RekamanStok::where(function($query) {
    $query->where('stok_awal', '<', 0)
          ->orWhere('stok_sisa', '<', 0)
          ->orWhere('stok_masuk', '<', 0)
          ->orWhere('stok_keluar', '<', 0);
})->orderBy('waktu', 'desc')->limit(10)->get();

foreach($problematic as $record) {
    echo "ID: {$record->id_rekaman_stok} - Produk ID: {$record->id_produk} - ";
    echo "Awal: {$record->stok_awal} - Masuk: {$record->stok_masuk} - ";
    echo "Keluar: {$record->stok_keluar} - Sisa: {$record->stok_sisa} - ";
    echo "Waktu: {$record->waktu}\n";
}

echo "\n=== CHECK COMPLETED ===\n";
