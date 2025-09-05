<?php
// Test file to debug pembelian_detail issue

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Pembelian;
use App\Models\Supplier;

echo "=== DEBUGGING PEMBELIAN_DETAIL ISSUE ===\n\n";

echo "1. Checking if Pembelian table has data:\n";
$pembelianCount = DB::table('pembelian')->count();
echo "Total pembelian records: " . $pembelianCount . "\n\n";

if ($pembelianCount > 0) {
    echo "Latest 3 pembelian records:\n";
    $latestPembelian = DB::table('pembelian')
        ->orderBy('id_pembelian', 'desc')
        ->take(3)
        ->get(['id_pembelian', 'id_supplier', 'total_harga', 'created_at']);
    
    foreach ($latestPembelian as $p) {
        echo "ID: {$p->id_pembelian}, Supplier: {$p->id_supplier}, Total: {$p->total_harga}, Created: {$p->created_at}\n";
    }
    echo "\n";
}

echo "2. Checking suppliers:\n";
$supplierCount = DB::table('supplier')->count();
echo "Total supplier records: " . $supplierCount . "\n";

if ($supplierCount > 0) {
    $suppliers = DB::table('supplier')->take(3)->get(['id_supplier', 'nama']);
    foreach ($suppliers as $s) {
        echo "ID: {$s->id_supplier}, Name: {$s->nama}\n";
    }
}

echo "\n3. Current session data (simulation):\n";
echo "Session data can only be checked in web context\n";

echo "\n=== END DEBUG ===\n";
