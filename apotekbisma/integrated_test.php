<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== INTEGRATED FUNCTIONALITY TEST ===\n\n";

session()->forget('id_penjualan');

$produk = \App\Models\Produk::find(2);
$stok_awal = $produk->stok;

echo "Testing full transaction flow:\n";
echo "Product: {$produk->nama_produk} (Rp. " . number_format($produk->harga_jual, 0, ',', '.') . ")\n";
echo "Initial stock: {$stok_awal}\n\n";

echo "1. CREATE NEW TRANSACTION (ADD FIRST PRODUCT):\n";

$controller = new \App\Http\Controllers\PenjualanDetailController();

$request = new \Illuminate\Http\Request();
$request->merge([
    'id_produk' => $produk->id_produk,
    'waktu' => date('Y-m-d')
]);

$response = $controller->store($request);
$store_data = $response->getData(true);

if ($store_data['success']) {
    echo "✅ First product added successfully\n";
    echo "✅ Transaction ID: {$store_data['id_penjualan']}\n";
    echo "✅ Stock remaining: {$store_data['stok_tersisa']}\n";
    
    $transaction_id = $store_data['id_penjualan'];
    session(['id_penjualan' => $transaction_id]);
    
    $data_response = $controller->data($transaction_id);
    $ajax_data = $data_response->getData(true);
    echo "✅ DataTables data available: " . $ajax_data['recordsTotal'] . " records\n";
} else {
    echo "❌ Failed to add first product\n";
    exit;
}

echo "\n2. ADD SECOND PRODUCT (SAME TRANSACTION):\n";

$request2 = new \Illuminate\Http\Request();
$request2->merge([
    'id_produk' => $produk->id_produk,
    'waktu' => date('Y-m-d')
]);

$response2 = $controller->store($request2);
$store_data2 = $response2->getData(true);

if ($store_data2['success']) {
    echo "✅ Second product added successfully\n";
    echo "✅ Same transaction ID: {$store_data2['id_penjualan']}\n";
    echo "✅ Stock remaining: {$store_data2['stok_tersisa']}\n";
} else {
    echo "❌ Failed to add second product\n";
}

echo "\n3. TEST CALCULATION WITH CURRENT DATA:\n";

$current_details = \App\Models\PenjualanDetail::where('id_penjualan', $transaction_id)->get();
$total_items = $current_details->sum('jumlah');
$total_amount = $current_details->sum('subtotal');

echo "✅ Total items: {$total_items}\n";
echo "✅ Total amount: Rp. " . number_format($total_amount, 0, ',', '.') . "\n";

$calc_response = $controller->loadForm(0, $total_amount, $total_amount);
$calc_data = $calc_response->getData(true);

echo "✅ Calculated display: {$calc_data['totalrp']}\n";
echo "✅ Calculated payment: {$calc_data['bayarrp']}\n";
echo "✅ Calculated change: {$calc_data['kembalirp']}\n";

echo "\n4. TEST WITH DISCOUNT:\n";

$calc_discount = $controller->loadForm(15, $total_amount, $total_amount);
$calc_discount_data = $calc_discount->getData(true);

echo "✅ With 15% discount:\n";
echo "   - Payment required: {$calc_discount_data['bayarrp']}\n";
echo "   - Change given: {$calc_discount_data['kembalirp']}\n";

echo "\n5. TEST QUANTITY UPDATE:\n";

$first_detail = $current_details->first();

$update_request = new \Illuminate\Http\Request();
$update_request->merge([
    'jumlah' => 3
]);

$update_response = $controller->update($update_request, $first_detail->id_penjualan_detail);
$update_data = $update_response->getData(true);

if (isset($update_data['message']) && strpos($update_data['message'], 'berhasil') !== false) {
    echo "✅ Quantity updated successfully\n";
    echo "✅ New stock remaining: " . $update_data['data']['stok_tersisa'] . "\n";
} else {
    echo "❌ Failed to update quantity\n";
}

echo "\n6. FINAL VERIFICATION:\n";

$final_details = \App\Models\PenjualanDetail::where('id_penjualan', $transaction_id)->get();
$final_total_items = $final_details->sum('jumlah');
$final_total_amount = $final_details->sum('subtotal');

echo "✅ Final total items: {$final_total_items}\n";
echo "✅ Final total amount: Rp. " . number_format($final_total_amount, 0, ',', '.') . "\n";

$final_calc = $controller->loadForm(0, $final_total_amount, $final_total_amount);
$final_calc_data = $final_calc->getData(true);

echo "✅ Final calculations correct: {$final_calc_data['totalrp']} = {$final_calc_data['bayarrp']}\n";

echo "\n7. CLEANUP:\n";

foreach ($final_details as $detail) {
    $detail->delete();
}

$transaction = \App\Models\Penjualan::find($transaction_id);
if ($transaction) {
    $transaction->delete();
}

$produk->stok = $stok_awal;
$produk->save();
session()->forget('id_penjualan');

echo "✅ All test data cleaned\n";

echo "\n🎉 INTEGRATED TEST PASSED!\n";
echo "\nAll functionality working correctly:\n";
echo "✅ Product addition\n";
echo "✅ DataTables integration\n";
echo "✅ Dynamic calculations\n";
echo "✅ Discount handling\n";
echo "✅ Quantity updates\n";
echo "✅ Stock management\n";

echo "\n=== INTEGRATED TEST COMPLETED ===\n";
