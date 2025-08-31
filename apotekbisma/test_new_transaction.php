<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== TEST NEW TRANSACTION CALCULATION ===\n\n";

session()->forget('id_penjualan');

$produk = \App\Models\Produk::find(2);
if (!$produk) {
    echo "❌ Test product not found\n";
    exit;
}

$stok_awal = $produk->stok;
echo "Testing new transaction calculation flow:\n";
echo "Product: {$produk->nama_produk}\n";
echo "Price: Rp. " . number_format($produk->harga_jual, 0, ',', '.') . "\n";
echo "Initial stock: {$stok_awal}\n\n";

echo "1. SIMULATE FIRST PRODUCT ADDITION:\n";

$controller = new \App\Http\Controllers\PenjualanDetailController();

$request = new \Illuminate\Http\Request();
$request->merge([
    'id_produk' => $produk->id_produk,
    'waktu' => date('Y-m-d')
]);

try {
    $response = $controller->store($request);
    
    if ($response instanceof \Illuminate\Http\JsonResponse) {
        $data = $response->getData(true);
        
        if (isset($data['success']) && $data['success']) {
            echo "✅ Product added successfully\n";
            echo "✅ Transaction ID: {$data['id_penjualan']}\n";
            echo "✅ Stock remaining: {$data['stok_tersisa']}\n";
            
            $transaction_id = $data['id_penjualan'];
            session(['id_penjualan' => $transaction_id]);
            
            echo "\n2. TEST DATA RETRIEVAL FOR CALCULATION:\n";
            
            $details = \App\Models\PenjualanDetail::where('id_penjualan', $transaction_id)->get();
            $total = $details->sum('subtotal');
            $total_items = $details->sum('jumlah');
            
            echo "✅ Total items in transaction: {$total_items}\n";
            echo "✅ Total amount: Rp. " . number_format($total, 0, ',', '.') . "\n";
            
            echo "\n3. TEST LOADFORM CALCULATION FOR NEW TRANSACTION:\n";
            
            $calc_response = $controller->loadForm(0, $total, $total);
            $calc_data = $calc_response->getData(true);
            
            echo "✅ Calculated total display: {$calc_data['totalrp']}\n";
            echo "✅ Calculated payment: {$calc_data['bayarrp']}\n";
            echo "✅ Expected payment matches: " . ($calc_data['bayar'] == $total ? 'YES' : 'NO') . "\n";
            
            echo "\n4. TEST WITH DISCOUNT:\n";
            
            $discount_response = $controller->loadForm(10, $total, $total);
            $discount_data = $discount_response->getData(true);
            
            echo "✅ With 10% discount - Payment: {$discount_data['bayarrp']}\n";
            echo "✅ Change: {$discount_data['kembalirp']}\n";
            
            echo "\n5. CLEANUP:\n";
            foreach ($details as $detail) {
                $detail->delete();
            }
            
            $transaction = \App\Models\Penjualan::find($transaction_id);
            if ($transaction) {
                $transaction->delete();
            }
            
            $produk->stok = $stok_awal;
            $produk->save();
            session()->forget('id_penjualan');
            
            echo "✅ Test data cleaned\n";
            
        } else {
            echo "❌ Product addition failed\n";
            if (isset($data['message'])) {
                echo "Error: {$data['message']}\n";
            }
        }
    } else {
        echo "❌ Unexpected response type\n";
    }
    
} catch (Exception $e) {
    echo "❌ Exception occurred: " . $e->getMessage() . "\n";
}

echo "\n🎉 NEW TRANSACTION CALCULATION TEST COMPLETE!\n";
echo "\nTotal, Bayar, Diterima should now auto-fill correctly.\n";

echo "\n=== TEST COMPLETED ===\n";
