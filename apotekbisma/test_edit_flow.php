<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== TEST SESSION DAN EDIT TRANSAKSI ===\n\n";

// Test 1: Cek transaksi yang baru dibuat
echo "1. CEK TRANSAKSI YANG DIBUAT:\n";
$penjualan = \App\Models\Penjualan::orderBy('id_penjualan', 'desc')->first();
if ($penjualan) {
    echo "Last transaction ID: {$penjualan->id_penjualan}\n";
    echo "Total harga: Rp " . number_format($penjualan->total_harga) . "\n";
    echo "Status: " . ($penjualan->total_harga > 0 && $penjualan->diterima > 0 ? 'Selesai' : 'Belum Selesai') . "\n";
    
    // Cek detail
    $details = \App\Models\PenjualanDetail::where('id_penjualan', $penjualan->id_penjualan)->get();
    echo "Jumlah detail: " . $details->count() . "\n";
    foreach($details as $detail) {
        $produk = \App\Models\Produk::find($detail->id_produk);
        echo "- {$detail->jumlah}x {$produk->nama_produk} @ Rp " . number_format($detail->harga_jual) . "\n";
    }
    
    // Test simulate edit
    echo "\n2. SIMULATE EDIT TRANSAKSI:\n";
    
    // Simulate what happens in editTransaksi method
    session()->put('id_penjualan', $penjualan->id_penjualan);
    echo "Session set: id_penjualan = {$penjualan->id_penjualan}\n";
    
    // Test createOrContinue method
    echo "\n3. TEST CREATEORCONTINUE METHOD:\n";
    $session_id = session('id_penjualan');
    echo "Session id_penjualan: {$session_id}\n";
    
    if ($session_id) {
        $session_penjualan = \App\Models\Penjualan::find($session_id);
        if ($session_penjualan) {
            echo "Found transaction in session: ID {$session_penjualan->id_penjualan}\n";
            echo "Member: " . ($session_penjualan->member->nama ?? 'Umum') . "\n";
            echo "Total: Rp " . number_format($session_penjualan->total_harga) . "\n";
            
            // Test if details are loaded
            $session_details = \App\Models\PenjualanDetail::where('id_penjualan', $session_id)->get();
            echo "Details count: " . $session_details->count() . "\n";
            
            if ($session_details->count() > 0) {
                echo "✅ DETAIL TRANSAKSI DITEMUKAN - EDIT AKAN BERFUNGSI\n";
            } else {
                echo "❌ DETAIL TRANSAKSI TIDAK DITEMUKAN - EDIT TIDAK AKAN BERFUNGSI\n";
            }
        } else {
            echo "❌ TRANSAKSI TIDAK DITEMUKAN DI DATABASE\n";
        }
    } else {
        echo "❌ TIDAK ADA SESSION id_penjualan\n";
    }
    
} else {
    echo "❌ TIDAK ADA TRANSAKSI DITEMUKAN\n";
}

echo "\n=== TEST SELESAI ===\n";
