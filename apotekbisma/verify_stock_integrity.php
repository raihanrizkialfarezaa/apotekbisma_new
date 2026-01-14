<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

echo "==========================================================\n";
echo "  VERIFIKASI INTEGRITAS STOK - POST-FIX ROBUSTNESS CHECK  \n";
echo "==========================================================\n\n";

$issues = [];
$totalProducts = Produk::count();
$checkedProducts = 0;
$productsWithIssues = 0;

echo "Memeriksa {$totalProducts} produk...\n\n";

$produkList = Produk::all();

foreach ($produkList as $produk) {
    $checkedProducts++;
    
    $integrity = RekamanStok::verifyIntegrity($produk->id_produk);
    
    if (!$integrity['valid']) {
        $productsWithIssues++;
        $issues[] = [
            'id_produk' => $produk->id_produk,
            'nama' => $produk->nama_produk,
            'stok_produk' => $integrity['product_stock'],
            'stok_kalkulasi' => $integrity['calculated_stock'],
            'selisih' => $integrity['difference'],
            'chain_errors' => $integrity['chain_errors']
        ];
    }
    
    if ($checkedProducts % 100 === 0) {
        echo "Sudah periksa {$checkedProducts}/{$totalProducts} produk...\n";
    }
}

echo "\n==========================================================\n";
echo "  HASIL VERIFIKASI  \n";
echo "==========================================================\n\n";

echo "Total produk diperiksa: {$checkedProducts}\n";
echo "Produk dengan masalah: {$productsWithIssues}\n";
echo "Produk valid: " . ($checkedProducts - $productsWithIssues) . "\n\n";

if (count($issues) > 0) {
    echo "DAFTAR PRODUK BERMASALAH:\n";
    echo str_repeat("-", 80) . "\n";
    
    foreach($issues as $issue) {
        echo "ID: {$issue['id_produk']} | {$issue['nama']}\n";
        echo "  Stok Produk: {$issue['stok_produk']} | Kalkulasi: {$issue['stok_kalkulasi']} | Selisih: {$issue['selisih']} | Chain Errors: {$issue['chain_errors']}\n";
    }
    
    echo "\n";
    echo "REKOMENDASI:\n";
    echo "1. Jalankan: php complete_stock_opname_fix_v3.php --execute\n";
    echo "2. Atau jalankan fix untuk produk spesifik:\n";
    echo "   \$repair = RekamanStok::fullRepair(\$productId);\n";
} else {
    echo "SEMUA PRODUK DALAM KONDISI VALID!\n";
    echo "Tidak ada masalah integritas stok yang terdeteksi.\n";
}

echo "\n==========================================================\n";
echo "  CEK DUPLIKAT GLOBAL  \n";
echo "==========================================================\n\n";

$dupPenjualan = DB::table('rekaman_stoks')
    ->select('id_produk', 'id_penjualan', DB::raw('COUNT(*) as cnt'))
    ->whereNotNull('id_penjualan')
    ->groupBy('id_produk', 'id_penjualan')
    ->having('cnt', '>', 1)
    ->get();

$dupPembelian = DB::table('rekaman_stoks')
    ->select('id_produk', 'id_pembelian', DB::raw('COUNT(*) as cnt'))
    ->whereNotNull('id_pembelian')
    ->groupBy('id_produk', 'id_pembelian')
    ->having('cnt', '>', 1)
    ->get();

echo "Duplikat penjualan: " . count($dupPenjualan) . "\n";
echo "Duplikat pembelian: " . count($dupPembelian) . "\n";

if (count($dupPenjualan) > 0 || count($dupPembelian) > 0) {
    echo "\nWARNING: Ditemukan duplikat! Jalankan cleanup:\n";
    echo "foreach(Produk::all() as \$p) { RekamanStok::cleanupDuplicates(\$p->id_produk); }\n";
} else {
    echo "\nTidak ada duplikat ditemukan.\n";
}

echo "\n==========================================================\n";
echo "  VERIFIKASI SELESAI  \n";
echo "==========================================================\n";
