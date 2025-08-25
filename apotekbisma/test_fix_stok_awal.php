<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\PenjualanDetail;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

echo "=== TEST PERBAIKAN STOK AWAL ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n\n";

$produk = Produk::find(2);
echo "📦 Stok saat ini: {$produk->stok}\n";

echo "🔧 Reset stok ke 130 untuk test...\n";
$produk->stok = 130;
$produk->save();
echo "📦 Stok direset ke: {$produk->stok}\n\n";

echo "🧪 TEST SKENARIO YANG BERMASALAH:\n";
echo "=================================\n";

DB::beginTransaction();

try {
    echo "1. Buat transaksi baru dengan 1 produk...\n";
    
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
    
    $id_penjualan = $penjualan->id_penjualan;
    
    $stok_sebelum_transaksi = $produk->stok;
    
    $detail = new PenjualanDetail();
    $detail->id_penjualan = $id_penjualan;
    $detail->id_produk = $produk->id_produk;
    $detail->harga_jual = $produk->harga_jual;
    $detail->jumlah = 1;
    $detail->diskon = 0;
    $detail->subtotal = $produk->harga_jual;
    $detail->save();
    
    $produk->stok = $stok_sebelum_transaksi - 1;
    $produk->save();
    
    RekamanStok::create([
        'id_produk' => $produk->id_produk,
        'id_penjualan' => $id_penjualan,
        'waktu' => now(),
        'stok_keluar' => 1,
        'stok_awal' => $stok_sebelum_transaksi,
        'stok_sisa' => $produk->stok,
        'keterangan' => 'Test: Transaksi awal 1 unit'
    ]);
    
    echo "   ✅ Stok: {$stok_sebelum_transaksi} → {$produk->stok}\n";
    echo "   ✅ Rekaman stok_awal: {$stok_sebelum_transaksi}\n";
    
    echo "\n2. User update quantity dari 1 ke 10...\n";
    
    $old_jumlah = $detail->jumlah;
    $new_jumlah = 10;
    $selisih = $new_jumlah - $old_jumlah;
    
    $stok_sebelum_update = $produk->stok;
    
    echo "   - Stok sebelum update: {$stok_sebelum_update}\n";
    echo "   - Old jumlah: {$old_jumlah}\n";
    echo "   - New jumlah: {$new_jumlah}\n";
    echo "   - Selisih: {$selisih}\n";
    
    $produk->stok = $stok_sebelum_update - $selisih;
    $produk->save();
    
    $detail->jumlah = $new_jumlah;
    $detail->subtotal = $detail->harga_jual * $new_jumlah;
    $detail->update();
    
    $rekaman_stok = RekamanStok::where('id_penjualan', $detail->id_penjualan)
                               ->where('id_produk', $detail->id_produk)
                               ->orderBy('id_rekaman_stok', 'desc')
                               ->first();
    
    if ($rekaman_stok) {
        $correct_stok_awal = $stok_sebelum_update + $old_jumlah;
        
        echo "   - Stok awal yang benar: {$stok_sebelum_update} + {$old_jumlah} = {$correct_stok_awal}\n";
        
        $rekaman_stok->update([
            'waktu' => now(),
            'stok_keluar' => $new_jumlah,
            'stok_awal' => $correct_stok_awal,
            'stok_sisa' => $produk->stok,
            'keterangan' => 'Test: Update jumlah 1 → 10'
        ]);
        
        echo "   ✅ Stok setelah update: {$produk->stok}\n";
        echo "   ✅ Rekaman stok_awal diupdate ke: {$correct_stok_awal}\n";
        
        echo "\n📋 VERIFIKASI AKHIR:\n";
        echo "   - Stok awal yang diharapkan: 130\n";
        echo "   - Stok awal yang tercatat: {$rekaman_stok->stok_awal}\n";
        echo "   - Stok keluar: {$rekaman_stok->stok_keluar}\n";
        echo "   - Stok sisa: {$rekaman_stok->stok_sisa}\n";
        
        if ($rekaman_stok->stok_awal == 130) {
            echo "   ✅ PERBAIKAN BERHASIL: Stok awal sudah benar!\n";
        } else {
            echo "   ❌ MASIH SALAH: Selisih " . (130 - $rekaman_stok->stok_awal) . "\n";
        }
        
        if ($rekaman_stok->stok_awal - $rekaman_stok->stok_keluar == $rekaman_stok->stok_sisa) {
            echo "   ✅ KONSISTENSI: Perhitungan stok benar\n";
        } else {
            echo "   ❌ INKONSISTENSI: Perhitungan stok salah\n";
        }
    }
    
    DB::commit();
    echo "\n🎉 Test berhasil - perbaikan disimpan\n";
    
} catch (Exception $e) {
    DB::rollback();
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n=== SELESAI ===\n";
