<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== ANALISIS MASALAH STOK ===\n\n";

echo "1. REKAMAN STOK TERAKHIR UNTUK ACETHYLESISTEIN (ID: 2):\n";
$rekaman = \App\Models\RekamanStok::where('id_produk', 2)
    ->orderBy('id_rekaman_stok', 'desc')
    ->take(10)
    ->get();

foreach($rekaman as $r) {
    $penjualan_info = $r->id_penjualan ? " | Penjualan: {$r->id_penjualan}" : "";
    $pembelian_info = $r->id_pembelian ? " | Pembelian: {$r->id_pembelian}" : "";
    echo "ID: {$r->id_rekaman_stok} | Waktu: {$r->waktu} | Masuk: {$r->stok_masuk} | Keluar: {$r->stok_keluar} | Sisa: {$r->stok_sisa}{$penjualan_info}{$pembelian_info} | Keterangan: {$r->keterangan}\n";
}

echo "\n2. PENJUALAN TERKAIT:\n";
$penjualan = \App\Models\Penjualan::where('id_penjualan', 603)->first();
if($penjualan) {
    echo "ID: {$penjualan->id_penjualan} | Waktu: {$penjualan->waktu} | Total: {$penjualan->total_harga}\n";
    
    $details = \App\Models\PenjualanDetail::where('id_penjualan', 603)->get();
    foreach($details as $detail) {
        echo "  - Produk: {$detail->id_produk} | Jumlah: {$detail->jumlah}\n";
    }
}

echo "\n3. STOK PRODUK SAAT INI:\n";
$produk = \App\Models\Produk::find(2);
if($produk) {
    echo "Nama: {$produk->nama_produk} | Stok: {$produk->stok}\n";
}

echo "\n4. CONTROLLERS YANG MENGATUR STOK:\n";
echo "- PenjualanController::update() - untuk sinkronisasi waktu\n";
echo "- PenjualanDetailController::update() - untuk update detail transaksi\n";
echo "- ProdukController::update() - untuk update manual stok\n";
