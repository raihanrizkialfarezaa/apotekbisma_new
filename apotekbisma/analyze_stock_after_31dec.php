<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;

echo "=======================================================\n";
echo "   SINKRONISASI STOK BERDASARKAN CUTOFF 1 JAN 2026\n";
echo "   Tanggal: " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

$cutoffDate = '2026-01-01 23:59:59';

echo "CUTOFF DATE: {$cutoffDate}\n\n";

echo "LANGKAH 1: Mengambil stok per 31 Desember 2025...\n";
echo "--------------------------------------------------\n";

$allProducts = Produk::orderBy('nama_produk')->get();
$stokPer31Des = [];

foreach ($allProducts as $produk) {
    $lastRecordBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->where('waktu', '<=', $cutoffDate)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRecordBefore) {
        $stokPer31Des[$produk->id_produk] = intval($lastRecordBefore->stok_sisa);
    } else {
        $stokPer31Des[$produk->id_produk] = 0;
    }
}

echo "   Produk dengan data stok per 31 Des: " . count(array_filter($stokPer31Des)) . "\n\n";

echo "LANGKAH 2: Menghitung transaksi SETELAH 31 Desember 2025...\n";
echo "------------------------------------------------------------\n";

$penjualanSetelah = DB::table('penjualan_detail')
    ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
    ->where('penjualan.waktu', '>', $cutoffDate)
    ->select('penjualan_detail.id_produk', DB::raw('SUM(penjualan_detail.jumlah) as total_keluar'))
    ->groupBy('penjualan_detail.id_produk')
    ->get()
    ->keyBy('id_produk');

$pembelianSetelah = DB::table('pembelian_detail')
    ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
    ->where('pembelian.waktu', '>', $cutoffDate)
    ->select('pembelian_detail.id_produk', DB::raw('SUM(pembelian_detail.jumlah) as total_masuk'))
    ->groupBy('pembelian_detail.id_produk')
    ->get()
    ->keyBy('id_produk');

echo "   Produk dengan penjualan setelah cutoff: " . count($penjualanSetelah) . "\n";
echo "   Produk dengan pembelian setelah cutoff: " . count($pembelianSetelah) . "\n\n";

echo "LANGKAH 3: Menghitung stok yang seharusnya...\n";
echo "-----------------------------------------------\n";

$perbaikan = [];
$sudahBenar = 0;

foreach ($allProducts as $produk) {
    $stok31Des = $stokPer31Des[$produk->id_produk] ?? 0;
    
    $totalMasuk = 0;
    if (isset($pembelianSetelah[$produk->id_produk])) {
        $totalMasuk = intval($pembelianSetelah[$produk->id_produk]->total_masuk);
    }
    
    $totalKeluar = 0;
    if (isset($penjualanSetelah[$produk->id_produk])) {
        $totalKeluar = intval($penjualanSetelah[$produk->id_produk]->total_keluar);
    }
    
    $stokSeharusnya = $stok31Des + $totalMasuk - $totalKeluar;
    if ($stokSeharusnya < 0) {
        $stokSeharusnya = 0;
    }
    
    $stokSekarang = intval($produk->stok);
    
    if ($stokSekarang != $stokSeharusnya) {
        $perbaikan[] = [
            'id_produk' => $produk->id_produk,
            'nama' => $produk->nama_produk,
            'stok_31_des' => $stok31Des,
            'masuk' => $totalMasuk,
            'keluar' => $totalKeluar,
            'seharusnya' => $stokSeharusnya,
            'sekarang' => $stokSekarang,
            'selisih' => $stokSekarang - $stokSeharusnya
        ];
    } else {
        $sudahBenar++;
    }
}

echo "   Produk sudah benar: {$sudahBenar}\n";
echo "   Produk perlu diperbaiki: " . count($perbaikan) . "\n\n";

if (count($perbaikan) > 0) {
    echo "DETAIL PRODUK YANG PERLU DIPERBAIKI:\n";
    echo "-------------------------------------\n";
    
    usort($perbaikan, function($a, $b) {
        return abs($b['selisih']) - abs($a['selisih']);
    });
    
    foreach (array_slice($perbaikan, 0, 30) as $p) {
        echo "\n{$p['nama']}:\n";
        echo "  Stok 31 Des 2025   : {$p['stok_31_des']}\n";
        echo "  + Pembelian setelah: {$p['masuk']}\n";
        echo "  - Penjualan setelah: {$p['keluar']}\n";
        echo "  = Seharusnya       : {$p['seharusnya']}\n";
        echo "  Stok saat ini      : {$p['sekarang']}\n";
        echo "  SELISIH            : {$p['selisih']} " . ($p['selisih'] < 0 ? "(KURANG)" : "(LEBIH)") . "\n";
    }
    
    if (count($perbaikan) > 30) {
        echo "\n... dan " . (count($perbaikan) - 30) . " produk lainnya\n";
    }
}

echo "\n=======================================================\n";
echo "   RINGKASAN\n";
echo "=======================================================\n";
echo "Total produk: " . count($allProducts) . "\n";
echo "Stok sudah benar: {$sudahBenar}\n";
echo "Perlu diperbaiki: " . count($perbaikan) . "\n\n";

if (count($perbaikan) > 0) {
    echo "Untuk MEMPERBAIKI stok, jalankan:\n";
    echo "   php fix_stock_after_31dec.php\n";
}

echo "\n";
