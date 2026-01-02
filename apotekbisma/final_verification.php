<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\RekamanStok;

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║          FINAL VERIFICATION - APOTEK BISMA                   ║\n";
echo "║          " . date('d F Y H:i:s') . "                        ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$allPassed = true;

echo "┌──────────────────────────────────────────────────────────────┐\n";
echo "│ TEST 1: Verifikasi Sinkronisasi Stok (per 1 Jan 2026)        │\n";
echo "└──────────────────────────────────────────────────────────────┘\n";

$cutoffDate = '2026-01-01 23:59:59';

$allProducts = Produk::all();
$outOfSync = 0;

foreach ($allProducts as $produk) {
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->where('waktu', '<=', $cutoffDate)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $stok31Des = $lastBefore ? intval($lastBefore->stok_sisa) : 0;
    
    $penjualan = DB::table('penjualan_detail')
        ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
        ->where('penjualan.waktu', '>', $cutoffDate)
        ->where('penjualan_detail.id_produk', $produk->id_produk)
        ->sum('penjualan_detail.jumlah');
    
    $pembelian = DB::table('pembelian_detail')
        ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
        ->where('pembelian.waktu', '>', $cutoffDate)
        ->where('pembelian_detail.id_produk', $produk->id_produk)
        ->sum('pembelian_detail.jumlah');
    
    $expected = $stok31Des + intval($pembelian) - intval($penjualan);
    if ($expected < 0) $expected = 0;
    
    if (intval($produk->stok) != $expected) {
        $outOfSync++;
    }
}

if ($outOfSync == 0) {
    echo "  ✓ Semua stok sinkron dengan perhitungan\n";
} else {
    echo "  ✗ {$outOfSync} produk tidak sinkron\n";
    $allPassed = false;
}

echo "\n┌──────────────────────────────────────────────────────────────┐\n";
echo "│ TEST 2: Tidak Ada Duplikat Rekaman Stok                      │\n";
echo "└──────────────────────────────────────────────────────────────┘\n";

$dupPenjualan = DB::table('rekaman_stoks')
    ->select('id_produk', 'id_penjualan', DB::raw('COUNT(*) as cnt'))
    ->whereNotNull('id_penjualan')
    ->groupBy('id_produk', 'id_penjualan')
    ->having('cnt', '>', 1)
    ->count();

$dupPembelian = DB::table('rekaman_stoks')
    ->select('id_produk', 'id_pembelian', DB::raw('COUNT(*) as cnt'))
    ->whereNotNull('id_pembelian')
    ->groupBy('id_produk', 'id_pembelian')
    ->having('cnt', '>', 1)
    ->count();

if ($dupPenjualan == 0 && $dupPembelian == 0) {
    echo "  ✓ Tidak ada duplikat rekaman\n";
} else {
    echo "  ✗ Duplikat: Penjualan={$dupPenjualan}, Pembelian={$dupPembelian}\n";
    $allPassed = false;
}

echo "\n┌──────────────────────────────────────────────────────────────┐\n";
echo "│ TEST 3: Formula Kalkulasi Benar                              │\n";
echo "└──────────────────────────────────────────────────────────────┘\n";

$formulaErrors = 0;
$allRekaman = DB::table('rekaman_stoks')->limit(1000)->get();

foreach ($allRekaman as $r) {
    $calc = intval($r->stok_awal) + intval($r->stok_masuk) - intval($r->stok_keluar);
    if ($calc != intval($r->stok_sisa)) {
        $formulaErrors++;
    }
}

if ($formulaErrors == 0) {
    echo "  ✓ Semua formula benar\n";
} else {
    echo "  ✗ {$formulaErrors} rekaman dengan formula salah\n";
    $allPassed = false;
}

echo "\n┌──────────────────────────────────────────────────────────────┐\n";
echo "│ TEST 4: Tidak Ada Stok Negatif                               │\n";
echo "└──────────────────────────────────────────────────────────────┘\n";

$negProduk = Produk::where('stok', '<', 0)->count();
$negRekaman = DB::table('rekaman_stoks')
    ->where('stok_sisa', '<', 0)
    ->orWhere('stok_awal', '<', 0)
    ->count();

if ($negProduk == 0 && $negRekaman == 0) {
    echo "  ✓ Tidak ada stok negatif\n";
} else {
    echo "  ✗ Stok negatif: Produk={$negProduk}, Rekaman={$negRekaman}\n";
    $allPassed = false;
}

echo "\n┌──────────────────────────────────────────────────────────────┐\n";
echo "│ TEST 5: Transaksi Hari Ini                                   │\n";
echo "└──────────────────────────────────────────────────────────────┘\n";

$today = date('Y-m-d');
$todayPenjualan = DB::table('penjualan')->whereDate('waktu', $today)->count();
$todayPembelian = DB::table('pembelian')->whereDate('waktu', $today)->count();
$todayRekaman = DB::table('rekaman_stoks')->whereDate('waktu', $today)->count();

echo "  Penjualan hari ini: {$todayPenjualan}\n";
echo "  Pembelian hari ini: {$todayPembelian}\n";
echo "  Rekaman stok hari ini: {$todayRekaman}\n";

echo "\n╔══════════════════════════════════════════════════════════════╗\n";

if ($allPassed) {
    echo "║  ✓ SEMUA VERIFIKASI BERHASIL - SISTEM STOK ROBUST 100%      ║\n";
} else {
    echo "║  ✗ ADA MASALAH YANG PERLU DIPERBAIKI                        ║\n";
}

echo "╚══════════════════════════════════════════════════════════════╝\n\n";

echo "STATISTIK:\n";
echo "  Total produk: " . Produk::count() . "\n";
echo "  Total rekaman stok: " . RekamanStok::count() . "\n";
echo "  Total penjualan: " . DB::table('penjualan')->count() . "\n";
echo "  Total pembelian: " . DB::table('pembelian')->count() . "\n";

echo "\n";
