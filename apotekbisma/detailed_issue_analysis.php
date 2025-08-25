<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\PenjualanDetail;
use App\Models\PembelianDetail;
use Illuminate\Support\Facades\DB;

echo "=== DETAILED ISSUE ANALYSIS ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n\n";

$produk = Produk::where('nama_produk', 'LIKE', '%acethylesistein%')->first();

echo "MASALAH UTAMA YANG TERIDENTIFIKASI:\n";
echo "=====================================\n\n";

echo "1. KETIDAKKONSISTENAN STOK BESAR:\n";
echo "   - Stok seharusnya: -90 (MINUS!)\n";
echo "   - Stok aktual: 40\n";
echo "   - Selisih: 130 unit\n\n";

echo "2. ANALISIS REKAMAN DETAIL:\n";
$rekaman = RekamanStok::where('id_produk', $produk->id_produk)
    ->orderBy('waktu', 'desc')
    ->get();

echo "   Total rekaman: " . $rekaman->count() . "\n";

// Analisis per jenis transaksi
$rekamanPembelian = $rekaman->whereNotNull('id_pembelian');
$rekamanPenjualan = $rekaman->whereNotNull('id_penjualan');
$rekamanManual = $rekaman->whereNull('id_pembelian')->whereNull('id_penjualan');

echo "   - Rekaman pembelian: " . $rekamanPembelian->count() . "\n";
echo "   - Rekaman penjualan: " . $rekamanPenjualan->count() . "\n";
echo "   - Rekaman manual: " . $rekamanManual->count() . "\n\n";

echo "3. PROBLEM DALAM REKAMAN STOK:\n";
foreach ($rekaman->take(10) as $r) {
    $masukKeluar = $r->stok_masuk - $r->stok_keluar;
    $awalSisa = $r->stok_awal - $r->stok_sisa;
    
    echo "   Rekaman ID: {$r->id_rekaman_stok}\n";
    echo "   Waktu: {$r->waktu}\n";
    echo "   Masuk-Keluar: {$masukKeluar}, Awal-Sisa: {$awalSisa}\n";
    
    if ($masukKeluar != -$awalSisa) {
        echo "   ❌ INKONSISTENSI: Perhitungan tidak sesuai!\n";
    }
    
    if ($r->stok_keluar > 0 && $r->stok_sisa == $r->stok_awal) {
        echo "   ❌ BUG: Stok keluar tapi stok_sisa tidak berkurang!\n";
    }
    
    echo "\n";
}

echo "4. ANALISIS TRANSAKSI AKTUAL:\n";
$transaksiPembelian = PembelianDetail::join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
    ->where('pembelian_detail.id_produk', $produk->id_produk)
    ->where('pembelian.no_faktur', '!=', 'o')
    ->whereNotNull('pembelian.no_faktur')
    ->select('pembelian_detail.*', 'pembelian.no_faktur', 'pembelian.created_at as tgl_pembelian')
    ->orderBy('pembelian.created_at', 'desc')
    ->get();

$transaksiPenjualan = PenjualanDetail::join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
    ->where('penjualan_detail.id_produk', $produk->id_produk)
    ->where('penjualan.bayar', '>', 0)
    ->select('penjualan_detail.*', 'penjualan.created_at as tgl_penjualan')
    ->orderBy('penjualan.created_at', 'desc')
    ->get();

echo "   Transaksi pembelian valid: " . $transaksiPembelian->count() . "\n";
echo "   Transaksi penjualan valid: " . $transaksiPenjualan->count() . "\n\n";

echo "5. DETAIL TRANSAKSI PENJUALAN (10 terbaru):\n";
foreach ($transaksiPenjualan->take(10) as $tp) {
    echo "   ID: {$tp->id_penjualan_detail}, Jumlah: {$tp->jumlah}, Tanggal: {$tp->tgl_penjualan}\n";
}

echo "\n6. KEMUNGKINAN PENYEBAB MASALAH:\n";
echo "   a) Race condition saat update stok dan rekaman\n";
echo "   b) Double deduction/addition dalam beberapa operasi\n";
echo "   c) Rekaman stok tidak sinkron dengan stok produk\n";
echo "   d) Bug dalam logic update stok_sisa\n\n";

echo "7. VERIFIKASI MANUAL CALCULATION:\n";
$stokAwal = 0; // Asumsi mulai dari 0
$currentStock = $stokAwal;

echo "   Simulasi perhitungan manual:\n";
echo "   Stok awal: {$currentStock}\n";

// Hitung berdasarkan transaksi aktual
foreach ($transaksiPembelian as $tp) {
    $currentStock += $tp->jumlah;
    echo "   + Beli {$tp->jumlah} = {$currentStock}\n";
}

foreach ($transaksiPenjualan as $tp) {
    $currentStock -= $tp->jumlah;
    echo "   - Jual {$tp->jumlah} = {$currentStock}\n";
}

echo "   Hasil manual: {$currentStock}\n";
echo "   Stok sistem: {$produk->stok}\n";
echo "   Selisih: " . ($produk->stok - $currentStock) . "\n\n";

echo "=== REKOMENDASI PERBAIKAN ===\n";
echo "1. Implementasi database transaction untuk atomicity\n";
echo "2. Perbaiki logic update stok_sisa dalam rekaman\n";
echo "3. Tambahkan validasi konsistensi sebelum commit\n";
echo "4. Implementasi locking untuk mencegah race condition\n\n";

echo "=== ANALISIS SELESAI ===\n";
