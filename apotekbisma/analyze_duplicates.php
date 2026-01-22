<?php
/**
 * FIX DUPLICATE PRODUCTS
 * Produk yang sama tapi beda ID/nama di database
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=============================================================\n";
echo "ANALYZE & FIX DUPLICATE PRODUCTS\n";
echo "=============================================================\n\n";

// Known duplicates: DB product that should match CSV product
$duplicates = [
    // [DB ID tanpa CSV, CSV ID yang benar, Nama]
    ['db_id' => 8, 'csv_id' => 283, 'name' => 'ACTIFED'],
    ['db_id' => 364, 'csv_id' => 1000, 'name' => 'HYPAFIX'],
];

echo "Checking duplicate products...\n\n";

foreach ($duplicates as $dup) {
    $dbProduct = DB::table('produk')->where('id_produk', $dup['db_id'])->first();
    $csvProduct = DB::table('produk')->where('id_produk', $dup['csv_id'])->first();
    
    echo "--- {$dup['name']} ---\n";
    
    if ($dbProduct) {
        echo "DB ID {$dup['db_id']}: {$dbProduct->nama_produk}, Stok: {$dbProduct->stok}\n";
    } else {
        echo "DB ID {$dup['db_id']}: NOT FOUND\n";
    }
    
    if ($csvProduct) {
        echo "CSV ID {$dup['csv_id']}: {$csvProduct->nama_produk}, Stok: {$csvProduct->stok}\n";
    } else {
        echo "CSV ID {$dup['csv_id']}: NOT FOUND\n";
    }
    
    // Check transactions for the non-CSV product
    $penjualan = DB::table('penjualan_detail')
        ->where('id_produk', $dup['db_id'])
        ->sum('jumlah');
    
    $pembelian = DB::table('pembelian_detail')
        ->where('id_produk', $dup['db_id'])
        ->sum('jumlah');
    
    echo "Transactions for ID {$dup['db_id']}: Sold=$penjualan, Purchased=$pembelian\n";
    
    // Check rekaman_stoks
    $rekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $dup['db_id'])
        ->count();
    
    echo "Rekaman stoks for ID {$dup['db_id']}: $rekaman records\n\n";
}

// Find more potential duplicates
echo "=============================================================\n";
echo "SEARCHING FOR MORE POTENTIAL DUPLICATES\n";
echo "=============================================================\n\n";

// Products in DB but NOT in CSV (993 - 790 = 203 products)
$csvPath = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$csvIds = [];
$handle = fopen($csvPath, 'r');
fgetcsv($handle); // skip header
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) >= 3) {
        $csvIds[(int)trim($row[0])] = trim($row[1]);
    }
}
fclose($handle);

$dbProducts = DB::table('produk')->get();
$notInCsv = [];

foreach ($dbProducts as $p) {
    if (!isset($csvIds[$p->id_produk])) {
        $notInCsv[] = $p;
    }
}

echo "Products in DB but NOT in CSV: " . count($notInCsv) . "\n\n";

// Show first 30
echo "First 30 products not in CSV:\n";
$count = 0;
foreach ($notInCsv as $p) {
    if ($count >= 30) break;
    echo sprintf("  ID %4d: %-40s | Stok: %d\n", $p->id_produk, $p->nama_produk, $p->stok);
    $count++;
}

echo "\n=============================================================\n";
echo "RECOMMENDATION\n";
echo "=============================================================\n\n";

echo "For ACTIFED (ID 8 = ID 283):\n";
echo "  Option 1: Update ID 8 stok to match ID 283\n";
echo "  Option 2: Merge all transactions from ID 8 to ID 283, then delete ID 8\n";
echo "  Option 3: Keep both but sync stock (if they track different sizes)\n";
