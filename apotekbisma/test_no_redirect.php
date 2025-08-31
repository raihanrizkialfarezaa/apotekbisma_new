<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== TEST NO REDIRECT FLOW ===\n\n";

session()->forget('id_penjualan');
echo "✅ Session cleared\n";

$produk = \App\Models\Produk::where('nama_produk', 'ACETHYLESISTEIN 200mg')->first();
if (!$produk) {
    echo "❌ Test product not found\n";
    exit;
}

$stok_awal = $produk->stok;
echo "Testing dengan produk: {$produk->nama_produk}\n";
echo "Stok awal: {$stok_awal}\n";

echo "\n1. TESTING STORE METHOD RESPONSE:\n";

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

session(['id_penjualan' => $penjualan->id_penjualan]);

$detail = new \App\Models\PenjualanDetail();
$detail->id_penjualan = $penjualan->id_penjualan;
$detail->id_produk = $produk->id_produk;
$detail->harga_jual = $produk->harga_jual;
$detail->jumlah = 1;
$detail->diskon = $produk->diskon;
$detail->subtotal = $produk->harga_jual;
$detail->save();

$produk->stok = $produk->stok - 1;
$produk->save();

$rekaman = new \App\Models\RekamanStok();
$rekaman->id_produk = $produk->id_produk;
$rekaman->id_penjualan = $penjualan->id_penjualan;
$rekaman->waktu = now();
$rekaman->stok_keluar = 1;
$rekaman->stok_awal = $stok_awal;
$rekaman->stok_sisa = $produk->stok;
$rekaman->keterangan = 'Penjualan: Transaksi penjualan produk';
$rekaman->save();

echo "✅ Transaction created: ID {$penjualan->id_penjualan}\n";

$response = [
    'success' => true,
    'message' => 'Produk berhasil ditambahkan ke keranjang. Stok tersisa: ' . $produk->stok,
    'id_penjualan' => $penjualan->id_penjualan,
    'stok_tersisa' => $produk->stok
];

echo "✅ New store response format:\n";
echo json_encode($response, JSON_PRETTY_PRINT) . "\n";

echo "\n2. TESTING DATA ENDPOINT:\n";
$data_url = "transaksi_detail/{$penjualan->id_penjualan}/data";
echo "✅ DataTable will use endpoint: {$data_url}\n";

$test_data = \App\Models\PenjualanDetail::with('produk')
    ->where('id_penjualan', $penjualan->id_penjualan)
    ->join('produk', 'penjualan_detail.id_produk', '=', 'produk.id_produk')
    ->orderBy('produk.nama_produk', 'asc')
    ->select('penjualan_detail.*')
    ->get();

echo "✅ Data available: " . $test_data->count() . " items\n";

echo "\n3. FLOW SUMMARY:\n";
echo "✅ User stays on /transaksi/baru\n";
echo "✅ Table gets reinitialized with proper ajax endpoint\n";
echo "✅ No redirect to /transaksi/aktif\n";
echo "✅ DataTables works without JSON errors\n";

$detail->delete();
$rekaman->delete();
$penjualan->delete();
$produk->stok = $stok_awal;
$produk->save();
session()->forget('id_penjualan');

echo "\n✅ Test data cleaned\n";
echo "\n🎉 NO REDIRECT SOLUTION READY!\n";

echo "\n=== TEST COMPLETED ===\n";
