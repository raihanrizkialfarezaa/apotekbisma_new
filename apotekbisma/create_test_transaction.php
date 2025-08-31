<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== TEST EDIT TRANSAKSI PENJUALAN ===\n\n";

// 1. Buat transaksi test
echo "1. MEMBUAT TRANSAKSI TEST:\n";

$penjualan = new \App\Models\Penjualan();
$penjualan->id_member = null;
$penjualan->total_item = 2;
$penjualan->total_harga = 20000;
$penjualan->diskon = 0;
$penjualan->bayar = 20000;
$penjualan->diterima = 20000;
$penjualan->waktu = date('Y-m-d');
$penjualan->id_user = 1; // Assuming admin user ID is 1
$penjualan->save();

echo "Transaksi dibuat dengan ID: {$penjualan->id_penjualan}\n";

// 2. Tambahkan detail penjualan
echo "2. MENAMBAHKAN DETAIL PENJUALAN:\n";

$produk = \App\Models\Produk::where('nama_produk', 'ACETHYLESISTEIN 200mg')->first();
if (!$produk) {
    echo "Produk ACETHYLESISTEIN 200mg tidak ditemukan!\n";
    exit;
}

echo "Menggunakan produk: {$produk->nama_produk} (ID: {$produk->id_produk})\n";
echo "Stok sebelum: {$produk->stok}\n";

// Buat detail penjualan
$detail1 = new \App\Models\PenjualanDetail();
$detail1->id_penjualan = $penjualan->id_penjualan;
$detail1->id_produk = $produk->id_produk;
$detail1->harga_jual = 10000;
$detail1->jumlah = 2;
$detail1->diskon = 0;
$detail1->subtotal = 20000;
$detail1->save();

echo "Detail penjualan dibuat: 2x {$produk->nama_produk} @ Rp 10,000 = Rp 20,000\n";

// Update stok produk
$produk->stok = $produk->stok - 2;
$produk->save();

echo "Stok setelah: {$produk->stok}\n";

// 3. Buat rekaman stok
$rekaman = new \App\Models\RekamanStok();
$rekaman->id_produk = $produk->id_produk;
$rekaman->id_penjualan = $penjualan->id_penjualan;
$rekaman->waktu = now();
$rekaman->stok_keluar = 2;
$rekaman->stok_awal = $produk->stok + 2;
$rekaman->stok_sisa = $produk->stok;
$rekaman->keterangan = 'Test penjualan untuk edit transaksi';
$rekaman->save();

echo "Rekaman stok dibuat\n";

echo "\n3. INFORMASI TRANSAKSI:\n";
echo "ID Penjualan: {$penjualan->id_penjualan}\n";
echo "Total Item: {$penjualan->total_item}\n";
echo "Total Harga: Rp " . number_format($penjualan->total_harga) . "\n";
echo "Status: " . ($penjualan->total_harga > 0 && $penjualan->diterima > 0 ? 'Selesai' : 'Belum Selesai') . "\n";

echo "\n=== TRANSAKSI TEST BERHASIL DIBUAT ===\n";
echo "Silakan test edit transaksi dengan ID: {$penjualan->id_penjualan}\n";
