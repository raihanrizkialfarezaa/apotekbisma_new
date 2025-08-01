<?php

require_once 'vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;

// Setup database connection
$capsule = new Capsule;
$capsule->addConnection([
    'driver' => 'mysql',
    'host' => 'localhost',
    'database' => 'apotekbisma',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8',
    'collation' => 'utf8_unicode_ci',
]);

$capsule->setAsGlobal();
$capsule->bootEloquent();

// Test pembelian workflow
echo "=== Testing Pembelian Stock System ===\n";

// Test product
$produk_id = 4; // ACIFAR CREAM
$produk = Capsule::table('produk')->where('id_produk', $produk_id)->first();

if (!$produk) {
    echo "Product not found!\n";
    exit;
}

echo "Product: {$produk->nama_produk}\n";
echo "Current Stock: {$produk->stok}\n";
echo "Product ID: {$produk->id_produk}\n\n";

// Check if there's an active pembelian
$pembelian = Capsule::table('pembelian')->orderBy('id_pembelian', 'desc')->first();

if (!$pembelian) {
    echo "No pembelian found. Creating test pembelian...\n";
    
    $pembelian_id = Capsule::table('pembelian')->insertGetId([
        'tanggal' => date('Y-m-d'),
        'id_supplier' => 1,
        'total' => 0,
        'created_at' => now(),
        'updated_at' => now()
    ]);
    
    echo "Created pembelian ID: {$pembelian_id}\n";
} else {
    $pembelian_id = $pembelian->id_pembelian;
    echo "Using existing pembelian ID: {$pembelian_id}\n";
}

// Check existing pembelian_detail for this product
$existing_detail = Capsule::table('pembelian_detail')
    ->where('id_pembelian', $pembelian_id)
    ->where('id_produk', $produk_id)
    ->first();

if ($existing_detail) {
    echo "Found existing pembelian_detail:\n";
    echo "  - Detail ID: {$existing_detail->id_pembelian_detail}\n";
    echo "  - Quantity: {$existing_detail->jumlah}\n";
    echo "  - Price: {$existing_detail->harga_beli}\n\n";
} else {
    echo "No existing pembelian_detail found for this product.\n\n";
}

// Show recent stock records for this product
echo "Recent Stock Records for this Product:\n";
$records = Capsule::table('rekaman_stoks')
    ->where('id_produk', $produk_id)
    ->orderBy('waktu', 'desc')
    ->limit(10)
    ->get();

foreach ($records as $record) {
    $type = '';
    if ($record->stok_masuk > 0) $type = "Purchase (In: {$record->stok_masuk})";
    if ($record->stok_keluar > 0) $type = "Sale (Out: {$record->stok_keluar})";
    
    echo "- {$record->waktu} | {$type} | Final Stock: {$record->stok_sisa}\n";
}

echo "\n=== Test Complete ===\n";

function now() {
    return date('Y-m-d H:i:s');
}
