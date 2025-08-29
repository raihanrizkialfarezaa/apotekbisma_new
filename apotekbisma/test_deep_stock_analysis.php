<?php

require_once 'vendor/autoload.php';

use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\PenjualanDetail;
use App\Models\PembelianDetail;
use App\Models\Penjualan;
use App\Models\Pembelian;
use Illuminate\Support\Facades\DB;

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== DEEP ANALYSIS STOK SYSTEM ===\n\n";

// Pilih produk "ACETHYLESISTEIN 200mg"
$produk = Produk::where('nama_produk', 'ACETHYLESISTEIN 200mg')->first();

if (!$produk) {
    echo "❌ Produk ACETHYLESISTEIN 200mg tidak ditemukan\n";
    exit;
}

echo "📦 PRODUK: {$produk->nama_produk} (ID: {$produk->id_produk})\n";
echo "📊 STOK SAAT INI: {$produk->stok}\n\n";

// Analisis timeline transaksi
echo "🔍 ANALISIS TIMELINE TRANSAKSI:\n";
echo "=" . str_repeat("=", 50) . "\n";

// Ambil semua rekaman stok untuk produk ini
$rekamanStoks = RekamanStok::where('id_produk', $produk->id_produk)
    ->orderBy('waktu', 'desc')
    ->get();

if ($rekamanStoks->count() == 0) {
    echo "⚠️ Tidak ada rekaman stok ditemukan\n\n";
} else {
    echo "📋 Total rekaman stok: {$rekamanStoks->count()}\n\n";
    
    foreach ($rekamanStoks->take(10) as $index => $rekaman) {
        echo "#{" . ($index + 1) . "} Waktu: {$rekaman->waktu}\n";
        echo "    Masuk: {$rekaman->stok_masuk} | Keluar: {$rekaman->stok_keluar}\n";
        echo "    Awal: {$rekaman->stok_awal} | Sisa: {$rekaman->stok_sisa}\n";
        echo "    Keterangan: {$rekaman->keterangan}\n";
        
        // Validasi konsistensi perhitungan
        $expected_sisa = $rekaman->stok_awal + $rekaman->stok_masuk - $rekaman->stok_keluar;
        if ($expected_sisa != $rekaman->stok_sisa) {
            echo "    ❌ ERROR: Perhitungan salah! Expected: {$expected_sisa}, Actual: {$rekaman->stok_sisa}\n";
        }
        echo "\n";
    }
}

// Analisis transaksi pembelian yang belum selesai
echo "🛒 ANALISIS PEMBELIAN:\n";
echo "=" . str_repeat("=", 50) . "\n";

$pembelianBelumSelesai = DB::table('pembelian_detail')
    ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
    ->where('pembelian_detail.id_produk', $produk->id_produk)
    ->where(function($query) {
        $query->where('pembelian.no_faktur', 'o')
              ->orWhereNull('pembelian.no_faktur');
    })
    ->select('pembelian_detail.*', 'pembelian.no_faktur', 'pembelian.bayar')
    ->get();

echo "📊 Pembelian belum selesai: {$pembelianBelumSelesai->count()}\n";
if ($pembelianBelumSelesai->count() > 0) {
    $totalBelumSelesai = $pembelianBelumSelesai->sum('jumlah');
    echo "📦 Total quantity belum selesai: {$totalBelumSelesai}\n";
    
    foreach ($pembelianBelumSelesai as $item) {
        echo "  - ID Pembelian: {$item->id_pembelian}, Qty: {$item->jumlah}, Faktur: '{$item->no_faktur}'\n";
    }
}

$pembelianSelesai = DB::table('pembelian_detail')
    ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
    ->where('pembelian_detail.id_produk', $produk->id_produk)
    ->where('pembelian.no_faktur', '!=', 'o')
    ->whereNotNull('pembelian.no_faktur')
    ->where('pembelian.bayar', '>', 0)
    ->sum('pembelian_detail.jumlah');

echo "✅ Pembelian selesai (total): {$pembelianSelesai}\n\n";

// Analisis transaksi penjualan yang belum dibayar
echo "🛍️ ANALISIS PENJUALAN:\n";
echo "=" . str_repeat("=", 50) . "\n";

$penjualanBelumBayar = DB::table('penjualan_detail')
    ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
    ->where('penjualan_detail.id_produk', $produk->id_produk)
    ->where('penjualan.bayar', '<=', 0)
    ->select('penjualan_detail.*', 'penjualan.bayar', 'penjualan.total_harga', 'penjualan.waktu')
    ->get();

echo "📊 Penjualan belum bayar: {$penjualanBelumBayar->count()}\n";
if ($penjualanBelumBayar->count() > 0) {
    $totalBelumBayar = $penjualanBelumBayar->sum('jumlah');
    echo "📦 Total quantity belum bayar: {$totalBelumBayar}\n";
    
    foreach ($penjualanBelumBayar as $item) {
        echo "  - ID Penjualan: {$item->id_penjualan}, Qty: {$item->jumlah}, Bayar: {$item->bayar}\n";
    }
}

$penjualanSelesai = DB::table('penjualan_detail')
    ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
    ->where('penjualan_detail.id_produk', $produk->id_produk)
    ->where('penjualan.bayar', '>', 0)
    ->sum('penjualan_detail.jumlah');

echo "✅ Penjualan selesai (total): {$penjualanSelesai}\n\n";

// Hitung stok berdasarkan transaksi aktual
echo "🧮 KALKULASI STOK AKTUAL:\n";
echo "=" . str_repeat("=", 50) . "\n";

$stokSeharusnya = $pembelianSelesai - $penjualanSelesai;
echo "📈 Pembelian selesai: +{$pembelianSelesai}\n";
echo "📉 Penjualan selesai: -{$penjualanSelesai}\n";
echo "🎯 Stok seharusnya: {$stokSeharusnya}\n";
echo "📊 Stok aktual: {$produk->stok}\n";

$selisih = $produk->stok - $stokSeharusnya;
if ($selisih != 0) {
    echo "❌ SELISIH DITEMUKAN: {$selisih}\n";
    
    // Cari perubahan manual
    $perubahanManual = RekamanStok::where('id_produk', $produk->id_produk)
        ->whereNull('id_pembelian')
        ->whereNull('id_penjualan')
        ->get()
        ->sum(function($item) {
            return $item->stok_masuk - $item->stok_keluar;
        });
    
    echo "🔧 Perubahan manual total: {$perubahanManual}\n";
    
    $stokDenganManual = $stokSeharusnya + $perubahanManual;
    echo "🎯 Stok dengan manual: {$stokDenganManual}\n";
    
    if ($stokDenganManual != $produk->stok) {
        echo "❌ MASIH ADA SELISIH: " . ($produk->stok - $stokDenganManual) . "\n";
        echo "🔍 KEMUNGKINAN PENYEBAB:\n";
        echo "  1. Race condition saat transaksi bersamaan\n";
        echo "  2. Update stok tanpa rekaman\n";
        echo "  3. Rollback yang tidak sempurna\n";
        echo "  4. Mutator/accessor yang mengubah nilai\n";
    } else {
        echo "✅ Selisih dijelaskan oleh perubahan manual\n";
    }
} else {
    echo "✅ Stok konsisten dengan transaksi\n";
}

// Analisis potential race condition
echo "\n🏁 ANALISIS RACE CONDITION:\n";
echo "=" . str_repeat("=", 50) . "\n";

$duplicateTimestamps = DB::table('rekaman_stoks')
    ->where('id_produk', $produk->id_produk)
    ->select('waktu', DB::raw('COUNT(*) as count'))
    ->groupBy('waktu')
    ->having('count', '>', 1)
    ->get();

if ($duplicateTimestamps->count() > 0) {
    echo "⚠️ Ditemukan timestamp duplikat (potensi race condition):\n";
    foreach ($duplicateTimestamps as $duplicate) {
        echo "  - Waktu: {$duplicate->waktu}, Count: {$duplicate->count}\n";
    }
} else {
    echo "✅ Tidak ada timestamp duplikat\n";
}

// Analisis consistency rekaman stok dengan actual
echo "\n📝 ANALISIS CONSISTENCY REKAMAN:\n";
echo "=" . str_repeat("=", 50) . "\n";

$rekamanTerbaru = RekamanStok::where('id_produk', $produk->id_produk)
    ->orderBy('waktu', 'desc')
    ->first();

if ($rekamanTerbaru) {
    echo "📅 Rekaman terbaru: {$rekamanTerbaru->waktu}\n";
    echo "📊 Stok sisa di rekaman: {$rekamanTerbaru->stok_sisa}\n";
    echo "📊 Stok aktual produk: {$produk->stok}\n";
    
    if ($rekamanTerbaru->stok_sisa != $produk->stok) {
        echo "❌ TIDAK SINKRON! Selisih: " . ($produk->stok - $rekamanTerbaru->stok_sisa) . "\n";
    } else {
        echo "✅ Sinkron dengan rekaman terbaru\n";
    }
} else {
    echo "⚠️ Tidak ada rekaman stok\n";
}

echo "\n🎯 REKOMENDASI PERBAIKAN:\n";
echo "=" . str_repeat("=", 50) . "\n";
echo "1. Implementasi database transaction locking\n";
echo "2. Perbaiki race condition di concurrent updates\n";
echo "3. Validasi consistency setelah setiap operasi\n";
echo "4. Implementasi stock reconciliation job\n";
echo "5. Fix mutator/accessor yang bermasalah\n";

?>
