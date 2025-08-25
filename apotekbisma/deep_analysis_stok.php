<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\PenjualanDetail;
use App\Models\Penjualan;
use Illuminate\Support\Facades\DB;

echo "=== ANALISIS MENDALAM SELISIH STOK AWAL ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n\n";

$produk = Produk::find(2);
echo "📦 Produk: {$produk->nama_produk}\n";
echo "📦 Stok saat ini: {$produk->stok}\n\n";

$rekaman_terbaru = RekamanStok::where('id_produk', 2)
                             ->orderBy('waktu', 'desc')
                             ->limit(10)
                             ->get();

echo "📋 10 REKAMAN STOK TERBARU:\n";
echo "==========================\n";
foreach ($rekaman_terbaru as $i => $rekaman) {
    echo ($i + 1) . ". [{$rekaman->waktu}]\n";
    echo "   Stok Awal: {$rekaman->stok_awal}\n";
    echo "   Stok Masuk: " . ($rekaman->stok_masuk ?? 0) . "\n";
    echo "   Stok Keluar: " . ($rekaman->stok_keluar ?? 0) . "\n";
    echo "   Stok Sisa: {$rekaman->stok_sisa}\n";
    
    if ($rekaman->id_penjualan) {
        $penjualan = Penjualan::find($rekaman->id_penjualan);
        $detail = PenjualanDetail::where('id_penjualan', $rekaman->id_penjualan)
                                ->where('id_produk', 2)
                                ->get();
        echo "   ID Penjualan: {$rekaman->id_penjualan}\n";
        echo "   Detail Penjualan:\n";
        foreach ($detail as $d) {
            echo "     - Jumlah: {$d->jumlah}, Harga: {$d->harga_jual}\n";
        }
    }
    
    if ($rekaman->keterangan) {
        echo "   Keterangan: {$rekaman->keterangan}\n";
    }
    echo "\n";
}

echo "🔍 ANALISIS POLA INKONSISTENSI:\n";
echo "==============================\n";

$inconsistent_records = [];
foreach ($rekaman_terbaru as $i => $rekaman) {
    $next_rekaman = $rekaman_terbaru->get($i + 1);
    
    if ($next_rekaman) {
        $expected_stok_awal = $next_rekaman->stok_sisa;
        $actual_stok_awal = $rekaman->stok_awal;
        
        if ($expected_stok_awal != $actual_stok_awal) {
            $inconsistent_records[] = [
                'rekaman' => $rekaman,
                'expected' => $expected_stok_awal,
                'actual' => $actual_stok_awal,
                'selisih' => $actual_stok_awal - $expected_stok_awal
            ];
        }
    }
}

if (!empty($inconsistent_records)) {
    echo "❌ DITEMUKAN INKONSISTENSI:\n";
    foreach ($inconsistent_records as $inc) {
        echo "   - Waktu: {$inc['rekaman']->waktu}\n";
        echo "     Expected stok_awal: {$inc['expected']}\n";
        echo "     Actual stok_awal: {$inc['actual']}\n";
        echo "     Selisih: {$inc['selisih']}\n";
        echo "     ID Penjualan: " . ($inc['rekaman']->id_penjualan ?? 'NULL') . "\n\n";
    }
} else {
    echo "✅ Tidak ada inkonsistensi dalam 10 rekaman terakhir\n";
}

echo "🔎 ANALISIS DETAIL TRANSAKSI TERBARU:\n";
echo "====================================\n";

$penjualan_terakhir = Penjualan::orderBy('id_penjualan', 'desc')->first();
if ($penjualan_terakhir) {
    echo "Transaksi terakhir: ID {$penjualan_terakhir->id_penjualan}\n";
    echo "Waktu: {$penjualan_terakhir->waktu}\n";
    
    $detail_terakhir = PenjualanDetail::where('id_penjualan', $penjualan_terakhir->id_penjualan)
                                     ->where('id_produk', 2)
                                     ->get();
    
    if ($detail_terakhir->count() > 0) {
        echo "Detail produk ACETHYLESISTEIN:\n";
        foreach ($detail_terakhir as $detail) {
            echo "  - Jumlah: {$detail->jumlah}\n";
            echo "  - Harga Jual: {$detail->harga_jual}\n";
            echo "  - Subtotal: {$detail->subtotal}\n";
        }
        
        $rekaman_terkait = RekamanStok::where('id_penjualan', $penjualan_terakhir->id_penjualan)
                                     ->where('id_produk', 2)
                                     ->get();
        
        echo "Rekaman stok terkait:\n";
        foreach ($rekaman_terkait as $rekaman) {
            echo "  - Stok Awal: {$rekaman->stok_awal}\n";
            echo "  - Stok Keluar: {$rekaman->stok_keluar}\n";
            echo "  - Stok Sisa: {$rekaman->stok_sisa}\n";
            echo "  - Waktu: {$rekaman->waktu}\n";
        }
    } else {
        echo "Tidak ada detail untuk produk ACETHYLESISTEIN dalam transaksi terakhir\n";
    }
}

echo "\n=== SELESAI ANALISIS ===\n";
