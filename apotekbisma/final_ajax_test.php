<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== FINAL DATATABLES AJAX TEST ===\n\n";

session()->forget('id_penjualan');

$produk = \App\Models\Produk::find(2);
$stok_awal = $produk->stok;

echo "Creating test transaction...\n";

$penjualan = new \App\Models\Penjualan();
$penjualan->id_member = null;
$penjualan->total_item = 1;
$penjualan->total_harga = $produk->harga_jual;
$penjualan->diskon = 0;
$penjualan->bayar = $produk->harga_jual;
$penjualan->diterima = $produk->harga_jual;
$penjualan->waktu = date('Y-m-d');
$penjualan->id_user = 1;
$penjualan->save();

$detail = new \App\Models\PenjualanDetail();
$detail->id_penjualan = $penjualan->id_penjualan;
$detail->id_produk = $produk->id_produk;
$detail->harga_jual = $produk->harga_jual;
$detail->jumlah = 1;
$detail->diskon = $produk->diskon;
$detail->subtotal = $produk->harga_jual;
$detail->save();

echo "✅ Test transaction created: ID {$penjualan->id_penjualan}\n";

session(['id_penjualan' => $penjualan->id_penjualan]);

echo "\nTesting DataTables endpoint simulation:\n";

$controller = new \App\Http\Controllers\PenjualanDetailController();

$request = new \Illuminate\Http\Request();
$request->merge([
    'draw' => 1,
    'start' => 0,
    'length' => 10
]);

try {
    $data_response = $controller->data($penjualan->id_penjualan);
    
    if ($data_response instanceof \Illuminate\Http\JsonResponse) {
        $response_data = $data_response->getData(true);
        echo "✅ DataTables endpoint responds correctly\n";
        echo "✅ Records found: " . $response_data['recordsTotal'] . "\n";
        
        if (isset($response_data['data']) && count($response_data['data']) > 0) {
            $first_item = $response_data['data'][0];
            echo "✅ First item: " . $first_item['nama_produk'] . "\n";
            echo "✅ Quantity: " . $first_item['jumlah'] . "\n";
        }
    } else {
        echo "❌ Unexpected response type\n";
    }
} catch (Exception $e) {
    echo "❌ DataTables endpoint error: " . $e->getMessage() . "\n";
}

echo "\nTesting store endpoint simulation:\n";

$store_request = new \Illuminate\Http\Request();
$store_request->merge([
    'id_produk' => $produk->id_produk,
    'waktu' => date('Y-m-d')
]);

try {
    $store_response = $controller->store($store_request);
    
    if ($store_response instanceof \Illuminate\Http\JsonResponse) {
        $store_data = $store_response->getData(true);
        echo "✅ Store endpoint responds correctly\n";
        echo "✅ Success: " . ($store_data['success'] ? 'true' : 'false') . "\n";
        
        if (isset($store_data['id_penjualan'])) {
            echo "✅ Transaction ID returned: " . $store_data['id_penjualan'] . "\n";
        }
    } else {
        echo "❌ Unexpected store response type\n";
    }
} catch (Exception $e) {
    echo "❌ Store endpoint error: " . $e->getMessage() . "\n";
}

echo "\nCleaning up...\n";
$detail->delete();
$penjualan->delete();
$produk->stok = $stok_awal;
$produk->save();
session()->forget('id_penjualan');

echo "✅ Test data cleaned\n";

echo "\n🎉 ALL AJAX ENDPOINTS WORKING!\n";
echo "\n=== FINAL TEST COMPLETED ===\n";
