<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

$id = 63; 

echo "DEBUGGING UI QUERY FOR PRODUCT ID: $id\n";
echo "--------------------------------------\n";

// Replicate Controller Logic
$query = RekamanStok::with(['produk', 'pembelian.supplier', 'penjualan'])
                ->where('id_produk', $id);

// Check count
$count = $query->count();
echo "Total Records via Eloquent: $count\n\n";

// Get Data (Mimic Controller sort)
$stok = $query->orderBy('rekaman_stoks.waktu', 'asc')
            ->orderBy('id_rekaman_stok', 'asc')
            ->get();

echo "Listing First 10 Records:\n";
foreach ($stok->take(10) as $index => $item) {
    echo "[$index] ID: " . $item->id_rekaman_stok . 
         " | Waktu: " . $item->waktu . 
         " | Type: " . ($item->id_pembelian ? 'Pembelian' : ($item->id_penjualan ? 'Penjualan' : 'Manual')) . 
         " | Masuk: " . $item->stok_masuk . 
         " | Sisa: " . $item->stok_sisa . "\n";
}


echo "\nChecking for ID 175721...\n";
$target = $stok->firstWhere('id_rekaman_stok', 175721);
if ($target) {
    echo "FOUND TARGET! ID: 175721\n";
    echo "Waktu: " . $target->waktu . "\n";
    echo "Masuk: " . $target->stok_masuk . "\n";
} else {
    echo "TARGET ID 175721 NOT FOUND IN ELOQUENT RESULT!\n";
}

echo "\nAll Large Purchases (>100):\n";
foreach ($stok as $item) {
    if ($item->stok_masuk > 100) {
        echo "ID: " . $item->id_rekaman_stok . " | Date: " . $item->waktu . " | Masuk: " . $item->stok_masuk . "\n";
    }
}

