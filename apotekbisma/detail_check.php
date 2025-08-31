<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== DETAILED ANALYSIS OF PROBLEMATIC RECORDS ===\n\n";

// Check all records with negative stok_keluar
echo "1. RECORDS WITH NEGATIVE STOK_KELUAR:\n";
$negative_keluar = \App\Models\RekamanStok::where('stok_keluar', '<', 0)->get();
foreach($negative_keluar as $record) {
    echo "ID: {$record->id_rekaman_stok} - ";
    echo "Produk ID: {$record->id_produk} - ";
    echo "Awal: {$record->stok_awal} - ";
    echo "Keluar: {$record->stok_keluar} - ";
    echo "Sisa: {$record->stok_sisa} - ";
    echo "Waktu: {$record->waktu} - ";
    echo "Keterangan: {$record->keterangan}\n";
}

echo "\n2. RECORDS WITH NEGATIVE STOK_AWAL (showing first 10):\n";
$negative_awal = \App\Models\RekamanStok::where('stok_awal', '<', 0)->limit(10)->get();
foreach($negative_awal as $record) {
    echo "ID: {$record->id_rekaman_stok} - ";
    echo "Produk ID: {$record->id_produk} - ";
    echo "Awal: {$record->stok_awal} - ";
    echo "Masuk: {$record->stok_masuk} - ";
    echo "Sisa: {$record->stok_sisa} - ";
    echo "Waktu: {$record->waktu} - ";
    echo "Keterangan: {$record->keterangan}\n";
}

echo "\n3. VERIFICATION: Current stock of products with historical negative records:\n";
$problematic_products = \App\Models\RekamanStok::where('stok_awal', '<', 0)
    ->distinct('id_produk')
    ->pluck('id_produk')
    ->take(10);

foreach($problematic_products as $produk_id) {
    $produk = \App\Models\Produk::find($produk_id);
    if($produk) {
        echo "Produk ID: {$produk_id} - {$produk->nama_produk} - Current Stock: {$produk->stok}\n";
    }
}

echo "\n=== ANALYSIS COMPLETED ===\n";
