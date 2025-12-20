<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\RekamanStok;
use App\Models\Produk;

$productId = 23;
$produk = Produk::find($productId);

if (!$produk) {
    echo "Product ID $productId not found.\n";
    // Try to find another product if 23 doesn't exist, maybe the one from the video (Acethylesistein ID 2?)
    $produk = Produk::first();
    if ($produk) {
        $productId = $produk->id_produk;
        echo "Using Product ID $productId (" . $produk->nama_produk . ") instead.\n";
    } else {
        exit;
    }
}

echo "Analyzing Stock for Product: " . $produk->nama_produk . " (ID: $productId)\n";
echo "Current Stock in Produk table: " . $produk->stok . "\n\n";

// Fetch data sorted by TIME (waktu) then CREATED_AT then ID
$stok = RekamanStok::where('id_produk', $productId)
    ->orderBy('waktu', 'asc')
    ->orderBy('created_at', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

echo str_pad("ID", 6) . " | " . 
     str_pad("Date (Waktu)", 20) . " | " . 
     str_pad("Created At", 20) . " | " . 
     str_pad("Awal", 8) . " | " . 
     str_pad("Masuk", 8) . " | " . 
     str_pad("Keluar", 8) . " | " . 
     str_pad("Sisa", 8) . " | " . 
     str_pad("Calc Sisa", 10) . " | " .
     "Keterangan\n";
echo str_repeat("-", 130) . "\n";

$calculatedRunningStock = 0;
$first = true;

foreach ($stok as $index => $item) {
    if ($first) {
        // Assume the first record's stok_awal is correct starting point? 
        // Or should we assume 0? 
        // Usually stok_awal of the first record is 0 if it's a new product, or some value if it's an adjustment.
        // Let's trust the first record's stok_awal for now as the base.
        $calculatedRunningStock = $item->stok_awal;
        $first = false;
    }
    
    // Calculate what the stock SHOULD be based on this transaction
    $expectedSisa = $calculatedRunningStock + $item->stok_masuk - $item->stok_keluar;
    
    $isConsistent = $expectedSisa == $item->stok_sisa;
    $marker = $isConsistent ? " " : "*";
    
    echo str_pad($item->id_rekaman_stok, 6) . " | " . 
         str_pad($item->waktu, 20) . " | " . 
         str_pad($item->created_at, 20) . " | " . 
         str_pad($item->stok_awal, 8) . " | " . 
         str_pad($item->stok_masuk, 8) . " | " . 
         str_pad($item->stok_keluar, 8) . " | " . 
         str_pad($item->stok_sisa, 8) . " | " . 
         str_pad($expectedSisa, 10) . " $marker| " .
         substr($item->keterangan, 0, 30) . "\n";
         
    $calculatedRunningStock = $expectedSisa;
}
