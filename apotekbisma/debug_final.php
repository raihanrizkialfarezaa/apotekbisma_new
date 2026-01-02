<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;

$cutoffDate = '2026-01-01 23:59:59';

echo "=== DEBUG FINAL ===\n\n";

echo "1. Duplikat:\n";
$dupP = DB::table('rekaman_stoks')
    ->select(DB::raw('COUNT(*) as cnt'))
    ->whereNotNull('id_penjualan')
    ->groupBy('id_produk', 'id_penjualan')
    ->having('cnt', '>', 1)
    ->count();
echo "   Penjualan: {$dupP}\n";

$dupB = DB::table('rekaman_stoks')
    ->select(DB::raw('COUNT(*) as cnt'))
    ->whereNotNull('id_pembelian')
    ->groupBy('id_produk', 'id_pembelian')
    ->having('cnt', '>', 1)
    ->count();
echo "   Pembelian: {$dupB}\n\n";

echo "2. Stok negatif:\n";
$negP = Produk::where('stok', '<', 0)->count();
echo "   Produk: {$negP}\n";
$negR = DB::table('rekaman_stoks')->where('stok_sisa', '<', 0)->count();
echo "   Rekaman: {$negR}\n\n";

echo "3. Formula salah (sample 500):\n";
$formulaErr = 0;
$samples = DB::table('rekaman_stoks')->limit(500)->get();
foreach ($samples as $r) {
    $calc = intval($r->stok_awal) + intval($r->stok_masuk) - intval($r->stok_keluar);
    if ($calc != intval($r->stok_sisa)) {
        $formulaErr++;
    }
}
echo "   Errors: {$formulaErr}\n\n";

echo "4. Produk tidak sinkron (max 5):\n";
$count = 0;
$allProducts = Produk::all();
foreach ($allProducts as $produk) {
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->where('waktu', '<=', $cutoffDate)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $stokCutoff = $lastBefore ? intval($lastBefore->stok_sisa) : 0;
    
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
    
    if (intval($produk->stok) != $expected && $count < 5) {
        echo "   {$produk->nama_produk}: cutoff={$stokCutoff}, +{$masuk}, -{$keluar}, exp={$expected}, act={$produk->stok}\n";
        $count++;
    }
}

if ($count == 0) {
    echo "   Semua sinkron!\n";
}

echo "\n=== END ===\n";
