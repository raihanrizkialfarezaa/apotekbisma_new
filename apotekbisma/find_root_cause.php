<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\PenjualanDetail;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

echo "=== SIMULASI TRANSAKSI UNTUK MENCARI ROOT CAUSE ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n\n";

$produk = Produk::find(2);
echo "📦 Stok saat ini: {$produk->stok}\n\n";

echo "🧪 SIMULASI SKENARIO YANG MENYEBABKAN MASALAH:\n";
echo "==============================================\n";

echo "Reset stok ke 130 untuk test...\n";
$produk->stok = 130;
$produk->save();

echo "📦 Stok direset ke: {$produk->stok}\n\n";

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
    echo "   ✅ Transaksi ID: {$id_penjualan}\n";
    
    $stok_sebelum = $produk->stok;
    
    $detail = new PenjualanDetail();
    $detail->id_penjualan = $id_penjualan;
    $detail->id_produk = $produk->id_produk;
    $detail->harga_jual = $produk->harga_jual;
    $detail->jumlah = 1;
    $detail->diskon = 0;
    $detail->subtotal = $produk->harga_jual;
    $detail->save();
    
    $produk->stok = $stok_sebelum - 1;
    $produk->save();
    
    RekamanStok::create([
        'id_produk' => $produk->id_produk,
        'id_penjualan' => $id_penjualan,
        'waktu' => now(),
        'stok_keluar' => 1,
        'stok_awal' => $stok_sebelum,
        'stok_sisa' => $produk->stok,
        'keterangan' => 'Test: Penjualan 1 unit'
    ]);
    
    echo "   ✅ 1 produk ditambahkan, stok: {$stok_sebelum} → {$produk->stok}\n";
    
    echo "\n2. Update jumlah dari 1 menjadi 10 (simulasi user edit)...\n";
    
    $old_jumlah = $detail->jumlah;
    $new_jumlah = 10;
    $selisih = $new_jumlah - $old_jumlah;
    
    echo "   - Old jumlah: {$old_jumlah}\n";
    echo "   - New jumlah: {$new_jumlah}\n";
    echo "   - Selisih: {$selisih}\n";
    
    $stok_sebelum_update = $produk->stok;
    echo "   - Stok sebelum update: {$stok_sebelum_update}\n";
    
    $produk->stok = $stok_sebelum_update - $selisih;
    $produk->save();
    
    echo "   - Stok setelah update: {$produk->stok}\n";
    
    $detail->jumlah = $new_jumlah;
    $detail->subtotal = $detail->harga_jual * $new_jumlah;
    $detail->update();
    
    $rekaman_stok = RekamanStok::where('id_penjualan', $detail->id_penjualan)
                               ->where('id_produk', $detail->id_produk)
                               ->orderBy('id_rekaman_stok', 'desc')
                               ->first();
    
    if ($rekaman_stok) {
        echo "\n   📋 UPDATE REKAMAN STOK:\n";
        echo "      - Stok awal SEBELUM update: {$rekaman_stok->stok_awal}\n";
        
        $new_stok_awal = $stok_sebelum_update + $old_jumlah;
        
        echo "      - Perhitungan stok_awal: {$stok_sebelum_update} + {$old_jumlah} = {$new_stok_awal}\n";
        
        $rekaman_stok->update([
            'waktu' => now(),
            'stok_keluar' => $new_jumlah,
            'stok_awal' => $new_stok_awal,
            'stok_sisa' => $produk->stok,
            'keterangan' => 'Test: Update jumlah dari 1 ke 10'
        ]);
        
        echo "      - Stok awal SETELAH update: {$rekaman_stok->stok_awal}\n";
        echo "      - Stok sisa: {$rekaman_stok->stok_sisa}\n";
        
        echo "\n   🔍 VERIFIKASI:\n";
        echo "      - Stok awal yang benar seharusnya: 130 (stok awal sebelum transaksi)\n";
        echo "      - Stok awal yang tercatat: {$rekaman_stok->stok_awal}\n";
        
        if ($rekaman_stok->stok_awal == 130) {
            echo "      ✅ BENAR: Stok awal sudah sesuai\n";
        } else {
            echo "      ❌ SALAH: Ada selisih " . (130 - $rekaman_stok->stok_awal) . "\n";
            echo "      🔧 ROOT CAUSE: Logic perhitungan stok_awal dalam update salah\n";
        }
    }
    
    DB::rollback();
    echo "\n🔄 Transaksi di-rollback\n";
    
} catch (Exception $e) {
    DB::rollback();
    echo "❌ Error: " . $e->getMessage() . "\n";
}

$produk->stok = 120;
$produk->save();
echo "\n📦 Stok dikembalikan ke: {$produk->stok}\n";

echo "\n=== ANALISIS SELESAI ===\n";
