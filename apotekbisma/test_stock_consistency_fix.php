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

echo "=== TEST KONSISTENSI STOK SETELAH PERBAIKAN ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n\n";

// Test dengan produk ACETHYLESISTEIN 200mg (ID 2)
$produk = Produk::find(2);
if (!$produk) {
    echo "❌ Produk ACETHYLESISTEIN 200mg tidak ditemukan\n";
    exit;
}

echo "🧪 Testing dengan produk: {$produk->nama_produk}\n";
echo "📦 Stok awal: {$produk->stok}\n\n";

$stok_awal = $produk->stok;

// 1. Test Simulasi Transaksi Penjualan
echo "1. SIMULASI TRANSAKSI PENJUALAN\n";
echo "--------------------------------\n";

DB::beginTransaction();

try {
    // Buat transaksi penjualan
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
    echo "✅ Transaksi penjualan dibuat dengan ID: {$id_penjualan}\n";
    
    // Catat stok sebelum perubahan
    $stok_sebelum = $produk->stok;
    $jumlah_jual = 5;
    
    // Buat detail penjualan
    $detail = new PenjualanDetail();
    $detail->id_penjualan = $id_penjualan;
    $detail->id_produk = $produk->id_produk;
    $detail->harga_jual = $produk->harga_jual;
    $detail->jumlah = $jumlah_jual;
    $detail->diskon = 0;
    $detail->subtotal = $produk->harga_jual * $jumlah_jual;
    $detail->save();
    
    // Update stok produk
    $produk->stok = $stok_sebelum - $jumlah_jual;
    $produk->save();
    
    // Buat rekaman stok
    RekamanStok::create([
        'id_produk' => $produk->id_produk,
        'id_penjualan' => $id_penjualan,
        'waktu' => Carbon::now(),
        'stok_keluar' => $jumlah_jual,
        'stok_awal' => $stok_sebelum,
        'stok_sisa' => $produk->stok,
        'keterangan' => 'Test Penjualan'
    ]);
    
    echo "✅ Detail penjualan dibuat: {$jumlah_jual} unit\n";
    echo "📊 Stok sebelum transaksi: {$stok_sebelum}\n";
    echo "📊 Stok setelah transaksi: {$produk->stok}\n";
    
    // Verifikasi rekaman stok
    $rekaman = RekamanStok::where('id_penjualan', $id_penjualan)
                          ->where('id_produk', $produk->id_produk)
                          ->first();
    
    if ($rekaman) {
        echo "✅ Rekaman stok dibuat:\n";
        echo "   - Stok Awal: {$rekaman->stok_awal}\n";
        echo "   - Stok Keluar: {$rekaman->stok_keluar}\n";
        echo "   - Stok Sisa: {$rekaman->stok_sisa}\n";
        
        // Verifikasi konsistensi
        $konsisten = true;
        if ($rekaman->stok_awal != $stok_sebelum) {
            echo "❌ INKONSISTENSI: stok_awal di rekaman ({$rekaman->stok_awal}) != stok sebelum transaksi ({$stok_sebelum})\n";
            $konsisten = false;
        }
        
        if ($rekaman->stok_sisa != $produk->stok) {
            echo "❌ INKONSISTENSI: stok_sisa di rekaman ({$rekaman->stok_sisa}) != stok produk saat ini ({$produk->stok})\n";
            $konsisten = false;
        }
        
        if ($rekaman->stok_awal - $rekaman->stok_keluar != $rekaman->stok_sisa) {
            echo "❌ INKONSISTENSI: perhitungan stok tidak sesuai (awal - keluar != sisa)\n";
            $konsisten = false;
        }
        
        if ($konsisten) {
            echo "✅ KONSISTENSI: Semua data stok konsisten!\n";
        }
    } else {
        echo "❌ Rekaman stok tidak ditemukan\n";
    }
    
    DB::rollback();
    echo "🔄 Transaksi di-rollback untuk testing\n\n";
    
} catch (Exception $e) {
    DB::rollback();
    echo "❌ Error: " . $e->getMessage() . "\n\n";
}

// 2. Test Cross-Check Data Existing
echo "2. CROSS-CHECK DATA EXISTING\n";
echo "-----------------------------\n";

// Ambil rekaman stok terbaru untuk produk ini
$rekaman_terbaru = RekamanStok::where('id_produk', $produk->id_produk)
                              ->orderBy('waktu', 'desc')
                              ->first();

if ($rekaman_terbaru) {
    echo "📋 Rekaman stok terbaru:\n";
    echo "   - Waktu: {$rekaman_terbaru->waktu}\n";
    echo "   - Stok Awal: {$rekaman_terbaru->stok_awal}\n";
    echo "   - Stok Masuk: " . ($rekaman_terbaru->stok_masuk ?? 0) . "\n";
    echo "   - Stok Keluar: " . ($rekaman_terbaru->stok_keluar ?? 0) . "\n";
    echo "   - Stok Sisa: {$rekaman_terbaru->stok_sisa}\n";
    echo "   - Keterangan: " . ($rekaman_terbaru->keterangan ?? 'N/A') . "\n";
    
    if ($rekaman_terbaru->stok_sisa == $produk->stok) {
        echo "✅ Rekaman stok konsisten dengan stok produk\n";
    } else {
        echo "❌ INKONSISTENSI: Rekaman stok ({$rekaman_terbaru->stok_sisa}) != Stok produk ({$produk->stok})\n";
    }
} else {
    echo "⚠️  Tidak ada rekaman stok untuk produk ini\n";
}

echo "\n=== SELESAI ===\n";
