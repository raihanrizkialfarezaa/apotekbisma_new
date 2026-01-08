<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Checking Specific IDs for Product 63:\n";
$ids = [175721, 175745, 175721]; // Purchase and Sale
$recs = DB::table('rekaman_stoks')->whereIn('id_rekaman_stok', $ids)->get();

foreach ($recs as $r) {
    echo "ID: $r->id_rekaman_stok | Time: $r->waktu | Type: " . ($r->stok_masuk > 0 ? "Masuk" : "Keluar") . "\n";
}


echo "\nChecking Large Purchase (>500):\n";
$purch = DB::table('rekaman_stoks')->where('id_produk', 63)->where('stok_masuk', '>', 500)->first();

if ($purch) {
    echo "Found Purchase ID: $purch->id_rekaman_stok | Time: $purch->waktu | IN: $purch->stok_masuk\n";
    
    // Check neighbors by ID
    echo "Neighbors by ID:\n";
    $neighbors = DB::table('rekaman_stoks')
        ->where('id_produk', 63)
        ->whereBetween('id_rekaman_stok', [$purch->id_rekaman_stok - 2, $purch->id_rekaman_stok + 5])
        ->get();
        
    foreach ($neighbors as $n) {
        echo "ID: $n->id_rekaman_stok | Time: $n->waktu | " . ($n->id_rekaman_stok == $purch->id_rekaman_stok ? "*Target*" : "") . "\n";
    }
} else {
    echo "No >500 purchase found for Product 63.\n";
}

