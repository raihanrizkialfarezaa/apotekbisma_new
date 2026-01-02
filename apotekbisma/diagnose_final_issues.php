<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;

$cutoffDate = '2026-01-01 23:59:59';

echo "=== DIAGNOSIS MASALAH FINAL ===\n\n";

echo "1. ANALISIS 60 PRODUK TIDAK SINKRON:\n";
echo "------------------------------------\n";

$allProducts = Produk::all();
$count = 0;

foreach ($allProducts as $produk) {
    // Ambil stok terakhir sebelum/pada cutoff
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->where('waktu', '<=', $cutoffDate)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $stokCutoff = $lastBefore ? intval($lastBefore->stok_sisa) : 0;
    
    // Hitung mutasi setelah cutoff
    $keluar = DB::table('penjualan_detail')
        ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
        ->where('penjualan.waktu', '>', $cutoffDate)
        ->where('penjualan_detail.id_produk', $produk->id_produk)
        ->sum('penjualan_detail.jumlah');
    
    $masuk = DB::table('pembelian_detail')
        ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
        ->where('pembelian.waktu', '>', $cutoffDate)
        ->where('pembelian_detail.id_produk', $produk->id_produk)
        ->sum('pembelian_detail.jumlah');
    
    $expected = $stokCutoff + intval($masuk) - intval($keluar);
    if ($expected < 0) $expected = 0;
    
    if (intval($produk->stok) != $expected) {
        $count++;
        if ($count <= 10) {
            echo "{$count}. {$produk->nama_produk} (ID: {$produk->id_produk})\n";
            echo "   Stok 1 Jan: {$stokCutoff}\n";
            echo "   + Masuk: {$masuk}\n";
            echo "   - Keluar: {$keluar}\n";
            echo "   = Seharusnya: {$expected}\n";
            echo "   ! Aktual di Tabel Produk: {$produk->stok}\n";
            echo "   Selisih: " . ($produk->stok - $expected) . "\n\n";
        }
    }
}
echo "Total produk tidak sinkron: {$count}\n\n";


echo "2. ANALISIS REKAMAN STOK NEGATIF (58 REKAMAN):\n";
echo "----------------------------------------------\n";

$negativeRecords = DB::table('rekaman_stoks')
    ->join('produk', 'rekaman_stoks.id_produk', '=', 'produk.id_produk')
    ->where('rekaman_stoks.stok_sisa', '<', 0)
    ->select('rekaman_stoks.*', 'produk.nama_produk')
    ->orderBy('rekaman_stoks.waktu', 'asc')
    ->limit(10)
    ->get();

foreach ($negativeRecords as $r) {
    echo "ID Rekaman: {$r->id_rekaman_stok} | Produk: {$r->nama_produk}\n";
    echo "   Waktu: {$r->waktu}\n";
    echo "   Awal: {$r->stok_awal} + Masuk: {$r->stok_masuk} - Keluar: {$r->stok_keluar} = Sisa: {$r->stok_sisa}\n";
    echo "   Keterangan: {$r->keterangan}\n\n";
}

$countNeg = DB::table('rekaman_stoks')->where('stok_sisa', '<', 0)->count();
echo "Total rekaman negatif: {$countNeg}\n";

echo "\n";
