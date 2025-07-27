<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\PembelianDetail;
use App\Models\RekamanStok;

echo "=== Fixing Stock Inconsistencies ===\n";

// Find all purchase details from today that have quantity > 1
// but stock records showing only 1 unit added
$problematic_details = PembelianDetail::whereDate('created_at', '>=', date('Y-m-d'))
    ->where('jumlah', '>', 1)
    ->get();

echo "Found " . count($problematic_details) . " purchase details from today to check...\n";

$fixed_count = 0;

foreach ($problematic_details as $detail) {
    $stock_record = RekamanStok::where('id_pembelian', $detail->id_pembelian)
        ->where('id_produk', $detail->id_produk)
        ->first();
    
    if ($stock_record && $stock_record->stok_masuk != $detail->jumlah) {
        $produk = Produk::find($detail->id_produk);
        if ($produk) {
            echo "\nFixing: " . $produk->nama_produk . "\n";
            echo "- Purchase Detail Qty: " . $detail->jumlah . "\n";
            echo "- Stock Record Qty: " . $stock_record->stok_masuk . "\n";
            echo "- Current Stock: " . $produk->stok . "\n";
            
            // Calculate the missing stock
            $missing_stock = $detail->jumlah - $stock_record->stok_masuk;
            
            // Add the missing stock
            $new_stock = $produk->stok + $missing_stock;
            $produk->stok = $new_stock;
            $produk->save();
            
            // Update the stock record
            $stock_record->stok_masuk = $detail->jumlah;
            $stock_record->stok_sisa = $new_stock;
            $stock_record->save();
            
            echo "- Fixed Stock: " . $new_stock . " (added " . $missing_stock . " units)\n";
            $fixed_count++;
        }
    }
}

echo "\n=== Fixed " . $fixed_count . " stock inconsistencies ===\n";
