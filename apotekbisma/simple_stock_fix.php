<?php
/**
 * SIMPLE STOCK FIX - FINAL VERSION
 * 
 * Logika:
 * 1. CSV = baseline (kebenaran)
 * 2. Hitung transaksi setelah 31 Des 2025 dari tabel penjualan_detail & pembelian_detail
 * 3. Stok akhir = CSV + pembelian - penjualan
 * 4. Abaikan semua rekaman sebelum cutoff
 * 5. Abaikan produk tidak ada di CSV
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$cutoff = '2025-12-31 23:59:59';

echo "╔═══════════════════════════════════════════════════════════════════════════╗\n";
echo "║                    SIMPLE STOCK FIX - FINAL VERSION                      ║\n";
echo "║                         " . date('Y-m-d H:i:s') . "                           ║\n";
echo "╚═══════════════════════════════════════════════════════════════════════════╝\n\n";

// Load CSV (row[0]=id, row[1]=nama, row[2]=stok)
$csvProducts = [];
if (($handle = fopen('REKAMAN STOK FINAL 31 DESEMBER 2025.csv', 'r')) !== FALSE) {
    $header = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== FALSE) {
        if (count($row) >= 3 && is_numeric($row[0])) {
            $csvProducts[(int)$row[0]] = (int)$row[2];
        }
    }
    fclose($handle);
}

echo "Loaded " . count($csvProducts) . " products from CSV\n";
echo "Sample: #835 VENTOLIN SPRAY = {$csvProducts[835]}, #524 MIXALGIN = {$csvProducts[524]}\n\n";

$fixed = 0;

foreach ($csvProducts as $prodId => $csvStock) {
    // 1. Hitung total PENJUALAN setelah cutoff
    $totalPenjualan = DB::table('penjualan_detail as pd')
        ->join('penjualan as p', 'p.id_penjualan', '=', 'pd.id_penjualan')
        ->where('pd.id_produk', $prodId)
        ->where('p.waktu', '>', $cutoff)
        ->sum('pd.jumlah');
    
    // 2. Hitung total PEMBELIAN setelah cutoff
    $totalPembelian = DB::table('pembelian_detail as pd')
        ->join('pembelian as p', 'p.id_pembelian', '=', 'pd.id_pembelian')
        ->where('pd.id_produk', $prodId)
        ->where('p.waktu', '>', $cutoff)
        ->sum('pd.jumlah');
    
    // 3. Hitung stok akhir
    $stokAkhir = $csvStock + (int)$totalPembelian - (int)$totalPenjualan;
    
    // 4. Update master stock
    DB::table('produk')
        ->where('id_produk', $prodId)
        ->update(['stok' => $stokAkhir]);
    
    $fixed++;
    
    if ($fixed % 100 == 0) {
        echo "Progress: $fixed / " . count($csvProducts) . "\n";
    }
}

echo "\n═══════════════════════════════════════════════════════════════════════════\n";
echo "Fixed: $fixed products\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

// Verify beberapa sample
echo "VERIFICATION SAMPLES:\n";
$samples = [835, 524, 421, 97, 125];
foreach ($samples as $sid) {
    if (!isset($csvProducts[$sid])) continue;
    
    $prod = DB::table('produk')->where('id_produk', $sid)->first();
    
    $penjualan = DB::table('penjualan_detail as pd')
        ->join('penjualan as p', 'p.id_penjualan', '=', 'pd.id_penjualan')
        ->where('pd.id_produk', $sid)
        ->where('p.waktu', '>', $cutoff)
        ->sum('pd.jumlah');
    
    $pembelian = DB::table('pembelian_detail as pd')
        ->join('pembelian as p', 'p.id_pembelian', '=', 'pd.id_pembelian')
        ->where('pd.id_produk', $sid)
        ->where('p.waktu', '>', $cutoff)
        ->sum('pd.jumlah');
    
    $expected = $csvProducts[$sid] + (int)$pembelian - (int)$penjualan;
    $match = ($prod->stok == $expected) ? "✅" : "❌";
    
    echo "  #{$sid} {$prod->nama_produk}: CSV: {$csvProducts[$sid]} + Beli: {$pembelian} - Jual: {$penjualan} = {$expected}, Master: {$prod->stok} {$match}\n";
}

echo "\nDone!\n";
