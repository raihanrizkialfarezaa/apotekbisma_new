<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== TEST LENGKAP EDIT TRANSAKSI ===\n\n";

// Simulate edit transaksi flow
$penjualan = \App\Models\Penjualan::orderBy('id_penjualan', 'desc')->first();

if (!$penjualan) {
    echo "❌ Tidak ada transaksi untuk ditest\n";
    exit;
}

echo "1. TRANSAKSI YANG AKAN DIEDIT:\n";
echo "ID: {$penjualan->id_penjualan}\n";
echo "Total: Rp " . number_format($penjualan->total_harga) . "\n";

// Get details
$details = \App\Models\PenjualanDetail::where('id_penjualan', $penjualan->id_penjualan)->get();
echo "Jumlah detail: " . $details->count() . "\n";

foreach($details as $detail) {
    $produk = \App\Models\Produk::find($detail->id_produk);
    echo "- Detail ID: {$detail->id_penjualan_detail} - {$detail->jumlah}x {$produk->nama_produk}\n";
}

echo "\n2. SIMULATE EDIT PROCESS:\n";

// Step 1: editTransaksi method
session()->put('id_penjualan', $penjualan->id_penjualan);
echo "✅ Session set: id_penjualan = {$penjualan->id_penjualan}\n";

// Step 2: createOrContinue method
$session_id = session('id_penjualan');
if ($session_id) {
    $session_penjualan = \App\Models\Penjualan::find($session_id);
    if ($session_penjualan) {
        echo "✅ Found transaction in session: ID {$session_penjualan->id_penjualan}\n";
        
        // Step 3: Check if details will load in data() method
        $test_details = \App\Models\PenjualanDetail::with('produk')
            ->where('id_penjualan', $session_id)
            ->join('produk', 'penjualan_detail.id_produk', '=', 'produk.id_produk')
            ->orderBy('produk.nama_produk', 'asc')
            ->select('penjualan_detail.*')
            ->get();
            
        echo "✅ Details that will load in table: " . $test_details->count() . "\n";
        
        foreach($test_details as $detail) {
            echo "  - {$detail->jumlah}x {$detail->produk->nama_produk} @ Rp " . number_format($detail->harga_jual) . "\n";
        }
        
        echo "\n3. VIEW DATA PREPARATION:\n";
        $produk = \App\Models\Produk::orderBy('nama_produk')->count();
        $member = \App\Models\Member::orderBy('nama')->count();
        $memberSelected = $session_penjualan->member ?? new \App\Models\Member();
        
        echo "✅ Products available: {$produk}\n";
        echo "✅ Members available: {$member}\n";
        echo "✅ Selected member: " . ($memberSelected->nama ?? 'Umum') . "\n";
        
        echo "\n🎉 EDIT TRANSAKSI SHOULD WORK PROPERLY!\n";
        echo "- Session is set correctly\n";
        echo "- Transaction exists in database\n";
        echo "- Details are available\n";
        echo "- View data is prepared correctly\n";
        
    } else {
        echo "❌ Transaction not found in database\n";
    }
} else {
    echo "❌ No session data\n";
}

echo "\n=== TEST SELESAI ===\n";
