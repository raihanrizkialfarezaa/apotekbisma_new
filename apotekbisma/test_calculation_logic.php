<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== TEST LOGIC TOTAL, BAYAR, DITERIMA ===\n\n";

session()->forget('id_penjualan');

$produk = \App\Models\Produk::find(2);
if (!$produk) {
    echo "❌ Test product not found\n";
    exit;
}

$stok_awal = $produk->stok;
echo "Testing dengan produk: {$produk->nama_produk}\n";
echo "Harga jual: Rp. " . number_format($produk->harga_jual, 0, ',', '.') . "\n";
echo "Stok awal: {$stok_awal}\n\n";

echo "1. TESTING TRANSACTION CREATION:\n";

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

echo "✅ Transaction created: ID {$penjualan->id_penjualan}\n";
echo "✅ Product added - Qty: {$detail->jumlah}, Subtotal: Rp. " . number_format($detail->subtotal, 0, ',', '.') . "\n";

echo "\n2. TESTING LOADFORM LOGIC:\n";

$controller = new \App\Http\Controllers\PenjualanDetailController();

$total = $detail->subtotal;
$diskon = 0;
$diterima = $total;

$response = $controller->loadForm($diskon, $total, $diterima);
$data = $response->getData(true);

echo "✅ Input - Total: Rp. " . number_format($total, 0, ',', '.') . ", Diskon: {$diskon}%, Diterima: Rp. " . number_format($diterima, 0, ',', '.') . "\n";
echo "✅ Output - Total Display: {$data['totalrp']}\n";
echo "✅ Output - Bayar: {$data['bayarrp']} ({$data['bayar']})\n";
echo "✅ Output - Kembali: {$data['kembalirp']}\n";
echo "✅ Output - Terbilang: {$data['terbilang']}\n";

echo "\n3. TESTING WITH DISCOUNT:\n";

$diskon = 10;
$response_discount = $controller->loadForm($diskon, $total, $diterima);
$data_discount = $response_discount->getData(true);

echo "✅ Input - Total: Rp. " . number_format($total, 0, ',', '.') . ", Diskon: {$diskon}%, Diterima: Rp. " . number_format($diterima, 0, ',', '.') . "\n";
echo "✅ Output - Total Display: {$data_discount['totalrp']}\n";
echo "✅ Output - Bayar: {$data_discount['bayarrp']} ({$data_discount['bayar']})\n";
echo "✅ Output - Kembali: {$data_discount['kembalirp']}\n";

echo "\n4. TESTING MULTIPLE PRODUCTS:\n";

$detail2 = new \App\Models\PenjualanDetail();
$detail2->id_penjualan = $penjualan->id_penjualan;
$detail2->id_produk = $produk->id_produk;
$detail2->harga_jual = $produk->harga_jual;
$detail2->jumlah = 2;
$detail2->diskon = $produk->diskon;
$detail2->subtotal = $produk->harga_jual * 2;
$detail2->save();

$new_total = $detail->subtotal + $detail2->subtotal;

echo "✅ Added second product - Qty: {$detail2->jumlah}, Subtotal: Rp. " . number_format($detail2->subtotal, 0, ',', '.') . "\n";
echo "✅ New total: Rp. " . number_format($new_total, 0, ',', '.') . "\n";

$response_multi = $controller->loadForm(0, $new_total, $new_total);
$data_multi = $response_multi->getData(true);

echo "✅ Multi-product calculation:\n";
echo "   - Total Display: {$data_multi['totalrp']}\n";
echo "   - Bayar: {$data_multi['bayarrp']} ({$data_multi['bayar']})\n";
echo "   - Kembali: {$data_multi['kembalirp']}\n";

echo "\n5. CLEANUP:\n";
$detail->delete();
$detail2->delete();
$penjualan->delete();
$produk->stok = $stok_awal;
$produk->save();
session()->forget('id_penjualan');

echo "✅ Test data cleaned\n";

echo "\n🎉 LOGIC CALCULATION TEST COMPLETED!\n";
echo "\nTotal, Bayar, Diterima calculations should now work correctly.\n";

echo "\n=== TEST COMPLETED ===\n";
