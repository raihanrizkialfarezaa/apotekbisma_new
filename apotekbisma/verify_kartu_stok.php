<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;

echo "=== VERIFIKASI KARTU STOK ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n\n";

$produk = Produk::find(2);
echo "📦 Produk: {$produk->nama_produk}\n";
echo "📦 Stok saat ini: {$produk->stok}\n\n";

// Ambil 5 rekaman stok terbaru
$rekaman_stoks = RekamanStok::where('id_produk', 2)
                           ->orderBy('waktu', 'desc')
                           ->limit(5)
                           ->get();

echo "📋 5 Rekaman Stok Terbaru:\n";
echo "----------------------------\n";
foreach ($rekaman_stoks as $i => $rekaman) {
    echo ($i + 1) . ". [{$rekaman->waktu}]\n";
    echo "   Stok Awal: {$rekaman->stok_awal}\n";
    echo "   Stok Masuk: " . ($rekaman->stok_masuk ?? 0) . "\n";
    echo "   Stok Keluar: " . ($rekaman->stok_keluar ?? 0) . "\n";
    echo "   Stok Sisa: {$rekaman->stok_sisa}\n";
    
    if ($rekaman->id_penjualan) {
        echo "   Transaksi Penjualan ID: {$rekaman->id_penjualan}\n";
    }
    if ($rekaman->id_pembelian) {
        echo "   Transaksi Pembelian ID: {$rekaman->id_pembelian}\n";
    }
    if ($rekaman->keterangan) {
        echo "   Keterangan: {$rekaman->keterangan}\n";
    }
    echo "\n";
}

// Verifikasi konsistensi
$rekaman_terakhir = $rekaman_stoks->first();
if ($rekaman_terakhir && $rekaman_terakhir->stok_sisa == $produk->stok) {
    echo "✅ KONSISTEN: Rekaman stok terakhir sesuai dengan stok produk\n";
    
    // Cek juga apakah stok_awal di rekaman terakhir sesuai dengan logic
    $transaksi_sebelumnya = RekamanStok::where('id_produk', 2)
                                     ->where('waktu', '<', $rekaman_terakhir->waktu)
                                     ->orderBy('waktu', 'desc')
                                     ->first();
    
    if ($transaksi_sebelumnya) {
        if ($rekaman_terakhir->stok_awal == $transaksi_sebelumnya->stok_sisa) {
            echo "✅ KONSISTEN: stok_awal rekaman sesuai dengan stok_sisa transaksi sebelumnya\n";
        } else {
            echo "❌ INKONSISTENSI: stok_awal ({$rekaman_terakhir->stok_awal}) != stok_sisa sebelumnya ({$transaksi_sebelumnya->stok_sisa})\n";
        }
    }
} else {
    echo "❌ INKONSISTENSI: Rekaman stok tidak sesuai dengan stok produk\n";
}

echo "\n=== SELESAI ===\n";
