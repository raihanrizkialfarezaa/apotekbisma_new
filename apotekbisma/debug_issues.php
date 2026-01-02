<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;

$cutoffDate = '2026-01-01 23:59:59';

echo "Checking for issues...\n\n";

$today = date('Y-m-d');

echo "1. Transaksi hari ini ({$today}):\n";
$todayTrans = DB::table('penjualan')->whereDate('waktu', $today)->get();
foreach ($todayTrans as $t) {
    $details = DB::table('penjualan_detail')->where('id_penjualan', $t->id_penjualan)->get();
    echo "  Penjualan ID: {$t->id_penjualan}\n";
    foreach ($details as $d) {
        $produk = Produk::find($d->id_produk);
        echo "    - " . ($produk ? $produk->nama_produk : "ID {$d->id_produk}") . ": {$d->jumlah}\n";
    }
}

echo "\n2. Produk yang tidak sinkron:\n";
$outOfSync = [];

$allProducts = Produk::all();
foreach ($allProducts as $produk) {
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->where('waktu', '<=', $cutoffDate)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $stokCutoff = $lastBefore ? intval($lastBefore->stok_sisa) : 0;
    
    $totalKeluar = DB::table('penjualan_detail')
        ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
        ->where('penjualan.waktu', '>', $cutoffDate)
        ->where('penjualan_detail.id_produk', $produk->id_produk)
        ->sum('penjualan_detail.jumlah');
    
    $totalMasuk = DB::table('pembelian_detail')
        ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
        ->where('pembelian.waktu', '>', $cutoffDate)
        ->where('pembelian_detail.id_produk', $produk->id_produk)
        ->sum('pembelian_detail.jumlah');
    
    $expected = $stokCutoff + intval($totalMasuk) - intval($totalKeluar);
    if ($expected < 0) $expected = 0;
    
    if (intval($produk->stok) != $expected) {
        echo "  - {$produk->nama_produk}\n";
        echo "    Stok cutoff: {$stokCutoff}\n";
        echo "    +Masuk: {$totalMasuk}, -Keluar: {$totalKeluar}\n";
        echo "    Expected: {$expected}, Actual: {$produk->stok}\n";
        echo "    Diff: " . (intval($produk->stok) - $expected) . "\n\n";
        $outOfSync[] = $produk->nama_produk;
    }
}

if (empty($outOfSync)) {
    echo "  Tidak ada produk yang tidak sinkron!\n";
}

echo "\n3. Duplikat rekaman:\n";
$dups = DB::table('rekaman_stoks')
    ->select('id_produk', 'id_penjualan', DB::raw('COUNT(*) as cnt'))
    ->whereNotNull('id_penjualan')
    ->groupBy('id_produk', 'id_penjualan')
    ->having('cnt', '>', 1)
    ->get();

if ($dups->isEmpty()) {
    echo "  Tidak ada duplikat!\n";
} else {
    foreach ($dups as $d) {
        $produk = Produk::find($d->id_produk);
        echo "  - " . ($produk ? $produk->nama_produk : "ID {$d->id_produk}") . " pada penjualan {$d->id_penjualan}: {$d->cnt}x\n";
    }
}
