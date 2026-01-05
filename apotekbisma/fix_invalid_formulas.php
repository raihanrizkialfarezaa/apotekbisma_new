<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Checking invalid formula records...\n";

$invalid = DB::select("
    SELECT rs.*, p.nama_produk,
           (stok_awal + stok_masuk - stok_keluar) as calculated_sisa
    FROM rekaman_stoks rs
    JOIN produk p ON rs.id_produk = p.id_produk
    WHERE stok_sisa != (stok_awal + stok_masuk - stok_keluar)
");

echo "Found: " . count($invalid) . " invalid records\n\n";

foreach ($invalid as $i) {
    echo "ID: {$i->id_rekaman_stok}\n";
    echo "  Produk: {$i->nama_produk}\n";
    echo "  stok_awal: {$i->stok_awal}\n";
    echo "  stok_masuk: {$i->stok_masuk}\n";
    echo "  stok_keluar: {$i->stok_keluar}\n";
    echo "  stok_sisa (current): {$i->stok_sisa}\n";
    echo "  stok_sisa (calculated): {$i->calculated_sisa}\n";
    
    $correct = max(0, $i->calculated_sisa);
    
    echo "  Fixing to: {$correct}\n\n";
    
    DB::table('rekaman_stoks')
        ->where('id_rekaman_stok', $i->id_rekaman_stok)
        ->update(['stok_sisa' => $correct]);
}

echo "All records fixed!\n";

$remainingCount = DB::select("
    SELECT COUNT(*) as cnt 
    FROM rekaman_stoks 
    WHERE stok_sisa != (stok_awal + stok_masuk - stok_keluar)
")[0]->cnt;

echo "Remaining invalid: {$remainingCount}\n";
