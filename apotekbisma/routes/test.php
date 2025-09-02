<?php

use Illuminate\Support\Facades\Route;
use App\Models\Penjualan;
use App\Models\RekamanStok;

Route::get('/test-sync', function() {
    $produk_id = 2;
    
    echo "=== Test Sinkronisasi Kartu Stok ===\n";
    
    $penjualan = Penjualan::whereHas('detail', function($q) use ($produk_id) {
        $q->where('id_produk', $produk_id);
    })->first();
    
    if (!$penjualan) {
        echo "Tidak ada transaksi penjualan untuk produk id=2\n";
        return;
    }
    
    echo "Penjualan ID: {$penjualan->id_penjualan}\n";
    echo "Waktu penjualan saat ini: {$penjualan->waktu}\n";
    
    $rekaman = RekamanStok::where('id_produk', $produk_id)
                          ->where('id_penjualan', $penjualan->id_penjualan)
                          ->first();
    
    if ($rekaman) {
        echo "RekamanStok waktu saat ini: {$rekaman->waktu}\n";
        echo "Apakah waktu sama? " . ($penjualan->waktu == $rekaman->waktu ? 'YA' : 'TIDAK') . "\n";
    } else {
        echo "Tidak ada RekamanStok untuk penjualan ini\n";
    }
    
    return response('Test selesai', 200)->header('Content-Type', 'text/plain');
});
