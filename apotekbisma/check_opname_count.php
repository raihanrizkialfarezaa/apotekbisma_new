<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$count = DB::table('rekaman_stoks')
    ->where('waktu', '2025-12-31 23:59:59')
    ->count();

echo "Total Stock Opname records at cutoff (2025-12-31 23:59:59): {$count}\n";

$products = DB::table('rekaman_stoks')
    ->where('waktu', '2025-12-31 23:59:59')
    ->select('id_produk')
    ->distinct()
    ->pluck('id_produk')
    ->toArray();

echo "Products with opname record: " . count($products) . "\n";
echo "Product IDs: " . implode(', ', array_slice($products, 0, 30)) . (count($products) > 30 ? '...' : '') . "\n";
