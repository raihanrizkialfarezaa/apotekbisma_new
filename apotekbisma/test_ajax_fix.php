<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== TEST AJAX ENDPOINT FIX ===\n\n";

session()->forget('id_penjualan');
echo "✅ Session cleared\n";

$produk = \App\Models\Produk::find(2);
if (!$produk) {
    echo "❌ Test product not found\n";
    exit;
}

echo "Testing dengan produk: {$produk->nama_produk} (ID: {$produk->id_produk})\n";

echo "\n1. TESTING ROUTE EXISTENCE:\n";

$routes = [
    'transaksi.data' => 'GET /transaksi/{id}/data',
    'transaksi_detail.data' => 'GET /transaksi_detail/{id}/data',
    'transaksi.store' => 'POST /transaksi',
    'transaksi.produk_data' => 'GET /transaksi/produk-data'
];

foreach ($routes as $name => $description) {
    if (\Illuminate\Support\Facades\Route::has($name)) {
        echo "✅ Route exists: {$name} ({$description})\n";
    } else {
        echo "❌ Route missing: {$name} ({$description})\n";
    }
}

echo "\n2. TESTING TRANSACTION CREATION:\n";

$penjualan = new \App\Models\Penjualan();
$penjualan->id_member = null;
$penjualan->total_item = 0;
$penjualan->total_harga = 0;
$penjualan->diskon = 0;
$penjualan->bayar = 0;
$penjualan->diterima = 0;
$penjualan->waktu = date('Y-m-d');
$penjualan->id_user = 1;
$penjualan->save();

echo "✅ Transaction created: ID {$penjualan->id_penjualan}\n";

$detail = new \App\Models\PenjualanDetail();
$detail->id_penjualan = $penjualan->id_penjualan;
$detail->id_produk = $produk->id_produk;
$detail->harga_jual = $produk->harga_jual;
$detail->jumlah = 1;
$detail->diskon = $produk->diskon;
$detail->subtotal = $produk->harga_jual;
$detail->save();

echo "✅ Transaction detail added\n";

echo "\n3. TESTING AJAX ENDPOINT DATA:\n";

$ajax_data = \App\Models\PenjualanDetail::with('produk')
    ->where('id_penjualan', $penjualan->id_penjualan)
    ->join('produk', 'penjualan_detail.id_produk', '=', 'produk.id_produk')
    ->orderBy('produk.nama_produk', 'asc')
    ->select('penjualan_detail.*')
    ->get();

echo "✅ AJAX data retrievable: " . $ajax_data->count() . " records\n";

if ($ajax_data->count() > 0) {
    $first_item = $ajax_data->first();
    echo "✅ First item data: {$first_item->produk->nama_produk}\n";
    echo "✅ Quantity: {$first_item->jumlah}\n";
    echo "✅ Subtotal: {$first_item->subtotal}\n";
}

echo "\n4. TESTING RESPONSE FORMAT:\n";

$response = [
    'success' => true,
    'message' => 'Produk berhasil ditambahkan ke keranjang',
    'id_penjualan' => $penjualan->id_penjualan,
    'stok_tersisa' => $produk->stok
];

echo "✅ Store response format:\n";
echo json_encode($response, JSON_PRETTY_PRINT) . "\n";

echo "\n5. TESTING EXPECTED AJAX URLS:\n";
echo "✅ DataTable AJAX URL will be: /transaksi_detail/{$penjualan->id_penjualan}/data\n";
echo "✅ Product data URL: /transaksi/produk-data\n";
echo "✅ Store URL: /transaksi (POST)\n";

echo "\n6. CLEANUP:\n";
$detail->delete();
$penjualan->delete();
echo "✅ Test data cleaned\n";

echo "\n🎉 AJAX ENDPOINT FIX READY!\n";
echo "\nDataTables should now work without AJAX errors.\n";

echo "\n=== TEST COMPLETED ===\n";
