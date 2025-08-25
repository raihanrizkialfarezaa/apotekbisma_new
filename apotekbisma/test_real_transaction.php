<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;
use App\Models\RekamanStok;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

echo "=== TEST REAL TRANSACTION ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n\n";

$produk = Produk::find(2);
echo "📦 Stok awal: {$produk->stok}\n";

// Simulasi transaksi real seperti yang dilakukan sistem
DB::beginTransaction();

try {
    // 1. Buat penjualan baru
    $penjualan = new Penjualan();
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
    echo "✅ ID Penjualan: {$id_penjualan}\n";
    
    // 2. Tambah produk ke keranjang (simulasi PenjualanDetailController::store)
    $jumlah = 10;
    $stok_sebelum = $produk->stok;
    
    // Buat detail
    $detail = new PenjualanDetail();
    $detail->id_penjualan = $id_penjualan;
    $detail->id_produk = $produk->id_produk;
    $detail->harga_jual = $produk->harga_jual;
    $detail->jumlah = $jumlah;
    $detail->diskon = 0;
    $detail->subtotal = $produk->harga_jual * $jumlah;
    $detail->save();
    
    // Update stok
    $produk->stok = $stok_sebelum - $jumlah;
    $produk->save();
    
    // Buat rekaman stok
    RekamanStok::create([
        'id_produk' => $produk->id_produk,
        'id_penjualan' => $id_penjualan,
        'waktu' => Carbon::now(),
        'stok_keluar' => $jumlah,
        'stok_awal' => $stok_sebelum,
        'stok_sisa' => $produk->stok,
        'keterangan' => 'Penjualan: Transaksi penjualan produk'
    ]);
    
    echo "✅ Produk ditambahkan ke keranjang\n";
    echo "📊 Stok sebelum: {$stok_sebelum}\n";
    echo "📊 Jumlah jual: {$jumlah}\n";
    echo "📊 Stok setelah: {$produk->stok}\n";
    
    // 3. Finalisasi transaksi (simulasi PenjualanController::store)
    $total = $detail->subtotal;
    
    $penjualan->total_item = 1;
    $penjualan->total_harga = $total;
    $penjualan->diskon = 0;
    $penjualan->bayar = $total;
    $penjualan->diterima = $total;
    $penjualan->waktu = date('Y-m-d');
    $penjualan->update();
    
    echo "✅ Transaksi diselesaikan\n";
    
    // Cek konsistensi rekaman stok
    $rekaman = RekamanStok::where('id_penjualan', $id_penjualan)
                          ->where('id_produk', $produk->id_produk)
                          ->first();
    
    if ($rekaman) {
        echo "\n📋 Rekaman Stok:\n";
        echo "   - Stok Awal: {$rekaman->stok_awal}\n";
        echo "   - Stok Keluar: {$rekaman->stok_keluar}\n";
        echo "   - Stok Sisa: {$rekaman->stok_sisa}\n";
        
        $konsisten = true;
        
        if ($rekaman->stok_awal != $stok_sebelum) {
            echo "❌ stok_awal tidak sesuai\n";
            $konsisten = false;
        }
        
        if ($rekaman->stok_sisa != $produk->stok) {
            echo "❌ stok_sisa tidak sesuai dengan stok produk\n";
            $konsisten = false;
        }
        
        if ($rekaman->stok_awal - $rekaman->stok_keluar != $rekaman->stok_sisa) {
            echo "❌ perhitungan stok salah\n";
            $konsisten = false;
        }
        
        if ($konsisten) {
            echo "✅ Semua data konsisten!\n";
        }
    }
    
    DB::commit();
    echo "\n✅ Transaksi berhasil disimpan\n";
    echo "📦 Stok final: " . Produk::find(2)->stok . "\n";
    
} catch (Exception $e) {
    DB::rollback();
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n=== SELESAI ===\n";
