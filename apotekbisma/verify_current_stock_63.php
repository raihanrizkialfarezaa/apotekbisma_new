<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$p = DB::table('produk')->where('id_produk', 63)->first();
$last = DB::table('rekaman_stoks')->where('id_produk', 63)->orderBy('waktu', 'desc')->orderBy('id_rekaman_stok', 'desc')->first();

echo "Product 63 Status:\n";
echo "Master Stock: " . $p->stok . "\n";
echo "Card Last Balance: " . ($last ? $last->stok_sisa : 0) . "\n";

echo "Card Last Balance: " . ($last ? $last->stok_sisa : 0) . "\n\n";

echo "Listing Last 10 Records (Sorted by Waktu DESC):\n";
$recs = DB::table('rekaman_stoks')
    ->where('id_produk', 63)
    ->orderBy('waktu', 'desc')
    ->limit(10)
    ->get();

foreach ($recs as $r) {
    echo "ID: $r->id_rekaman_stok | Time: $r->waktu | Masuk: $r->stok_masuk | Keluar: $r->stok_keluar\n";
}

