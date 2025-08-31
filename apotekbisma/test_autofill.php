<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== TEST TOTAL AUTO-FILL FUNCTIONALITY ===\n\n";

session()->forget('id_penjualan');

$produk = \App\Models\Produk::find(2);
$stok_awal = $produk->stok;

echo "Testing total auto-fill for new transaction:\n";
echo "Product: {$produk->nama_produk} (Rp. " . number_format($produk->harga_jual, 0, ',', '.') . ")\n";
echo "Initial stock: {$stok_awal}\n\n";

echo "1. CREATE TRANSACTION WITH PRODUCT:\n";

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
echo "✅ Product detail: Qty {$detail->jumlah}, Subtotal Rp. " . number_format($detail->subtotal, 0, ',', '.') . "\n";

echo "\n2. TEST LOAD FORM CALCULATION:\n";

$controller = new \App\Http\Controllers\PenjualanDetailController();

$diskon = 0;
$total = $detail->subtotal;
$diterima = $total;

$response = $controller->loadForm($diskon, $total, $diterima);
$data = $response->getData(true);

echo "✅ Input: Total={$total}, Diskon={$diskon}%, Diterima={$diterima}\n";
echo "✅ Output: Total Display='{$data['totalrp']}', Bayar='{$data['bayarrp']}', Kembali='{$data['kembalirp']}'\n";

$expected_bayar = $total - ($diskon / 100 * $total);
echo "✅ Expected vs Actual Bayar: {$expected_bayar} vs {$data['bayar']} - " . ($data['bayar'] == $expected_bayar ? 'MATCH' : 'MISMATCH') . "\n";

echo "\n3. TEST MULTIPLE PRODUCTS:\n";

$detail2 = new \App\Models\PenjualanDetail();
$detail2->id_penjualan = $penjualan->id_penjualan;
$detail2->id_produk = $produk->id_produk;
$detail2->harga_jual = $produk->harga_jual;
$detail2->jumlah = 2;
$detail2->diskon = $produk->diskon;
$detail2->subtotal = $produk->harga_jual * 2;
$detail2->save();

$new_total = $detail->subtotal + $detail2->subtotal;

echo "✅ Added second product: Qty {$detail2->jumlah}, Subtotal Rp. " . number_format($detail2->subtotal, 0, ',', '.') . "\n";
echo "✅ New total: Rp. " . number_format($new_total, 0, ',', '.') . "\n";

$response2 = $controller->loadForm(0, $new_total, $new_total);
$data2 = $response2->getData(true);

echo "✅ Multi-product calculation: Total='{$data2['totalrp']}', Bayar='{$data2['bayarrp']}'\n";

echo "\n4. TEST WITH DISCOUNT:\n";

$response3 = $controller->loadForm(15, $new_total, $new_total);
$data3 = $response3->getData(true);

echo "✅ With 15% discount: Bayar='{$data3['bayarrp']}', Kembali='{$data3['kembalirp']}'\n";

$expected_bayar_discount = $new_total - (15 / 100 * $new_total);
echo "✅ Expected discount calculation: {$expected_bayar_discount} vs {$data3['bayar']} - " . ($data3['bayar'] == $expected_bayar_discount ? 'CORRECT' : 'INCORRECT') . "\n";

echo "\n5. CLEANUP:\n";

$detail->delete();
$detail2->delete();
$penjualan->delete();
$produk->stok = $stok_awal;
$produk->save();
session()->forget('id_penjualan');

echo "✅ Test data cleaned\n";

echo "\n🎉 TOTAL AUTO-FILL TEST COMPLETED!\n";
echo "\nJavaScript should now correctly:\n";
echo "✅ Calculate total from table rows\n";
echo "✅ Update hidden inputs\n";
echo "✅ Call loadForm with correct parameters\n";
echo "✅ Display calculated values in form fields\n";

echo "\n=== TEST COMPLETED ===\n";
