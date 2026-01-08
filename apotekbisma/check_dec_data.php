<?php
require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Checking Product 63 transactions in December 2025 ===\n\n";

$recs = DB::table('rekaman_stoks')
    ->where('id_produk', 63)
    ->where('waktu', '>=', '2025-12-01')
    ->where('waktu', '<=', '2025-12-31 23:59:59')
    ->orderBy('waktu', 'asc')
    ->get();

echo "Found " . count($recs) . " records in December 2025\n\n";

foreach ($recs as $r) {
    $date = date('d M Y H:i:s', strtotime($r->waktu));
    echo "ID: $r->id_rekaman_stok | Date: $date | In: $r->stok_masuk | Out: $r->stok_keluar\n";
}

echo "\n=== Checking for Dec 31, 2025 specifically ===\n";
$dec31 = DB::table('rekaman_stoks')
    ->where('id_produk', 63)
    ->where('waktu', 'like', '2025-12-31%')
    ->get();
    
echo "Found " . count($dec31) . " records on Dec 31, 2025\n";
foreach ($dec31 as $r) {
    echo "ID: $r->id_rekaman_stok | Time: $r->waktu | In: $r->stok_masuk | Out: $r->stok_keluar\n";
}
