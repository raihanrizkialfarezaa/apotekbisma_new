<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\PenjualanDetail;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

echo "=== TEST SKENARIO USER: STOK 130 → JUAL 10 ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n\n";

$produk = Produk::find(2);

echo "🎯 SKENARIO: Stok awal 130, user jual 10 unit\n";
echo "Expected: stok_awal=130, stok_keluar=10, stok_sisa=120\n\n";

echo "🔧 Reset stok ke 130...\n";
$produk->stok = 130;
$produk->save();
echo "📦 Stok direset ke: {$produk->stok}\n\n";

DB::beginTransaction();

try {
    echo "STEP 1: User buat transaksi baru, tambah 1 produk\n";
    echo "================================================\n";
    
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
    
    $stok_awal_transaksi = $produk->stok;
    
    $detail = new PenjualanDetail();
    $detail->id_penjualan = $id_penjualan;
    $detail->id_produk = $produk->id_produk;
    $detail->harga_jual = $produk->harga_jual;
    $detail->jumlah = 1;
    $detail->diskon = 0;
    $detail->subtotal = $produk->harga_jual;
    $detail->save();
    
    $produk->stok = $stok_awal_transaksi - 1;
    $produk->save();
    
    RekamanStok::create([
        'id_produk' => $produk->id_produk,
        'id_penjualan' => $id_penjualan,
        'waktu' => now(),
        'stok_keluar' => 1,
        'stok_awal' => $stok_awal_transaksi,
        'stok_sisa' => $produk->stok,
        'keterangan' => 'Skenario Test: Penjualan produk'
    ]);
    
    echo "✅ Transaksi ID: {$id_penjualan}\n";
    echo "✅ Stok: {$stok_awal_transaksi} → {$produk->stok}\n\n";
    
    echo "STEP 2: User edit quantity dari 1 ke 10\n";
    echo "=======================================\n";
    
    $old_jumlah = $detail->jumlah;
    $new_jumlah = 10;
    $selisih = $new_jumlah - $old_jumlah;
    
    $stok_sebelum_edit = $produk->stok;
    
    echo "- Old quantity: {$old_jumlah}\n";
    echo "- New quantity: {$new_jumlah}\n";
    echo "- Selisih: {$selisih}\n";
    echo "- Stok sebelum edit: {$stok_sebelum_edit}\n";
    
    $produk->stok = $stok_sebelum_edit - $selisih;
    $produk->save();
    
    $detail->jumlah = $new_jumlah;
    $detail->subtotal = $detail->harga_jual * $new_jumlah;
    $detail->update();
    
    $rekaman_stok = RekamanStok::where('id_penjualan', $detail->id_penjualan)
                               ->where('id_produk', $detail->id_produk)
                               ->orderBy('id_rekaman_stok', 'desc')
                               ->first();
    
    if ($rekaman_stok) {
        $correct_stok_awal = $stok_sebelum_edit + $old_jumlah;
        
        $rekaman_stok->update([
            'waktu' => now(),
            'stok_keluar' => $new_jumlah,
            'stok_awal' => $correct_stok_awal,
            'stok_sisa' => $produk->stok,
            'keterangan' => 'Skenario Test: Update quantity'
        ]);
        
        echo "- Stok setelah edit: {$produk->stok}\n\n";
        
        echo "📋 HASIL REKAMAN STOK:\n";
        echo "======================\n";
        echo "Stok Awal: {$rekaman_stok->stok_awal}\n";
        echo "Stok Keluar: {$rekaman_stok->stok_keluar}\n";
        echo "Stok Sisa: {$rekaman_stok->stok_sisa}\n\n";
        
        echo "🎯 VERIFIKASI SKENARIO USER:\n";
        echo "============================\n";
        echo "Expected - Stok Awal: 130\n";
        echo "Actual   - Stok Awal: {$rekaman_stok->stok_awal}\n";
        
        echo "Expected - Stok Keluar: 10\n";
        echo "Actual   - Stok Keluar: {$rekaman_stok->stok_keluar}\n";
        
        echo "Expected - Stok Sisa: 120\n";
        echo "Actual   - Stok Sisa: {$rekaman_stok->stok_sisa}\n\n";
        
        $all_correct = true;
        
        if ($rekaman_stok->stok_awal != 130) {
            echo "❌ STOK AWAL SALAH!\n";
            $all_correct = false;
        } else {
            echo "✅ Stok awal benar\n";
        }
        
        if ($rekaman_stok->stok_keluar != 10) {
            echo "❌ STOK KELUAR SALAH!\n";
            $all_correct = false;
        } else {
            echo "✅ Stok keluar benar\n";
        }
        
        if ($rekaman_stok->stok_sisa != 120) {
            echo "❌ STOK SISA SALAH!\n";
            $all_correct = false;
        } else {
            echo "✅ Stok sisa benar\n";
        }
        
        if ($all_correct) {
            echo "\n🎉 SEMPURNA! Semua data sesuai ekspektasi user\n";
        } else {
            echo "\n❌ Masih ada masalah\n";
        }
    }
    
    DB::commit();
    echo "\n✅ Skenario test berhasil disimpan\n";
    
} catch (Exception $e) {
    DB::rollback();
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n=== SKENARIO SELESAI ===\n";
