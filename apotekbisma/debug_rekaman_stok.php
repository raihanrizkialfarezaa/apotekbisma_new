<?php

require_once 'vendor/autoload.php';

use Illuminate\Support\Facades\DB;

// Setup Laravel
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== ANALISIS REKAMAN STOK PRODUK 2 ===\n";

$rekaman = DB::table('rekaman_stoks')
    ->where('id_produk', 2)
    ->orderBy('id_rekaman_stok', 'desc')
    ->take(10)
    ->get();

foreach($rekaman as $r) {
    echo "ID: {$r->id_rekaman_stok} | Waktu: {$r->waktu}\n";
    echo "  Masuk: {$r->stok_masuk} | Keluar: {$r->stok_keluar} | Sisa: {$r->stok_sisa}\n";
    echo "  Keterangan: {$r->keterangan}\n";
    echo "  Penjualan ID: {$r->id_penjualan} | Pembelian ID: {$r->id_pembelian}\n";
    
    if($r->id_penjualan) {
        $penjualan = DB::table('penjualan')->where('id_penjualan', $r->id_penjualan)->first();
        if($penjualan) {
            echo "  -> Penjualan waktu: {$penjualan->waktu}\n";
        }
    }
    
    if($r->id_pembelian) {
        $pembelian = DB::table('pembelian')->where('id_pembelian', $r->id_pembelian)->first();
        if($pembelian) {
            echo "  -> Pembelian waktu: {$pembelian->waktu}\n";
        }
    }
    
    echo "---\n";
}

echo "\n=== CEK STOK PRODUK ===\n";
$produk = DB::table('produk')->where('id_produk', 2)->first();
echo "Stok Produk: {$produk->stok}\n";

echo "\n=== CEK YANG BERMASALAH ===\n";
$bermasalah = DB::table('rekaman_stoks')
    ->where('id_produk', 2)
    ->where('keterangan', 'Perubahan Stok Manual')
    ->orderBy('id_rekaman_stok', 'desc')
    ->get();

echo "Total Perubahan Stok Manual: " . count($bermasalah) . "\n";
foreach($bermasalah as $r) {
    echo "ID: {$r->id_rekaman_stok} | Waktu: {$r->waktu} | Masuk: {$r->stok_masuk} | Keluar: {$r->stok_keluar}\n";
}
