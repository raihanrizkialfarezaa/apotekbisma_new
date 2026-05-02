<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$invoiceNumber = 'JM1-2604-01939';

// 1. Check Purchases
$pembelian = DB::table('pembelian')
    ->leftJoin('supplier', 'pembelian.id_supplier', '=', 'supplier.id_supplier')
    ->where('pembelian.created_at', '>=', '2026-04-01 00:00:00')
    ->where(function($query) use ($invoiceNumber) {
        $query->where('pembelian.no_faktur', 'LIKE', '%' . $invoiceNumber . '%')
              ->orWhere('supplier.nama', 'LIKE', '%JA Makmur Abadi%')
              ->orWhere('pembelian.no_faktur', 'o');
    })
    ->select('pembelian.*', 'supplier.nama as supplier_name')
    ->get();

echo "=== PEMBELIAN (Including Incomplete 'o') ===\n";
foreach ($pembelian as $p) {
    $items = DB::table('pembelian_detail')->where('id_pembelian', $p->id_pembelian)->get();
    
    // Only show if it has items or is specifically the invoice we're looking for
    if (strpos($p->no_faktur, $invoiceNumber) !== false || $items->count() > 0) {
        echo "ID: {$p->id_pembelian} | Invoice: {$p->no_faktur} | Date: {$p->created_at} | Supplier: {$p->supplier_name}\n";
        foreach ($items as $i) {
            $product = DB::table('produk')->where('id_produk', $i->id_produk)->first();
            $productName = $product ? $product->nama_produk : 'UNKNOWN (ID: ' . $i->id_produk . ')';
            echo "  - Item: {$productName} (Product ID: {$i->id_produk}) | Qty: {$i->jumlah} | Price: {$i->harga_beli}\n";
        }
    }
}
echo "=================\n\n";

// 2. Look for any purchases around April 18 or April 28 with these items
$targetProducts = DB::table('produk')
    ->whereIn('nama_produk', ['Glimepirid 2mg', 'Glimepirid 3mg', 'Insto Eye Drops', 'Erlamycetin TM & TT', 'Nebacetin Powder', 'Counterpain Cream'])
    ->orWhere('nama_produk', 'LIKE', '%Glimepirid%')
    ->orWhere('nama_produk', 'LIKE', '%Insto%')
    ->orWhere('nama_produk', 'LIKE', '%Erlamycetin%')
    ->orWhere('nama_produk', 'LIKE', '%Nebacetin%')
    ->orWhere('nama_produk', 'LIKE', '%Counterpain%')
    ->pluck('id_produk', 'nama_produk');

echo "=== PRODUCTS ===\n";
foreach ($targetProducts as $name => $id) {
    echo "Found Product: {$name} (ID: {$id})\n";
}
echo "=================\n\n";

// 3. Search logs or general purchases in late April
$recentPurchases = DB::table('pembelian')
    ->leftJoin('supplier', 'pembelian.id_supplier', '=', 'supplier.id_supplier')
    ->whereBetween('pembelian.created_at', ['2026-04-15 00:00:00', '2026-04-30 23:59:59'])
    ->select('pembelian.*', 'supplier.nama as supplier_name')
    ->get();

echo "=== ALL PURCHASES IN LATE APRIL 2026 ===\n";
foreach ($recentPurchases as $p) {
    echo "ID: {$p->id_pembelian} | Invoice: {$p->no_faktur} | Date: {$p->created_at} | Supplier: {$p->supplier_name}\n";
}
echo "=================\n\n";

// 4. Check for any stock adjustment or related issue
$stockIssues = DB::table('produk')
    ->whereIn('id_produk', $targetProducts->values())
    ->get();
echo "=== CURRENT STOCK OF TARGET PRODUCTS ===\n";
foreach ($stockIssues as $s) {
    echo "Product ID: {$s->id_produk} | Stock: {$s->stok}\n";
}
echo "=================\n\n";
