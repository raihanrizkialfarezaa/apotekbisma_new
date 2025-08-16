<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Checking for inconsistent data...\n";

$count = DB::table('rekaman_stoks as rs')
    ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
    ->where(function($query) {
        $query->whereRaw('rs.stok_awal != p.stok')
              ->orWhereRaw('rs.stok_sisa != p.stok');
    })
    ->whereIn('rs.id_rekaman_stok', function($query) {
        $query->select(DB::raw('MAX(id_rekaman_stok)'))
              ->from('rekaman_stoks')
              ->groupBy('id_produk');
    })
    ->count();

echo "Found $count inconsistent records\n";

// Get a few examples
$examples = DB::table('rekaman_stoks as rs')
    ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
    ->select('p.nama_produk', 'p.stok as current_stok', 'rs.stok_awal', 'rs.stok_sisa')
    ->where(function($query) {
        $query->whereRaw('rs.stok_awal != p.stok')
              ->orWhereRaw('rs.stok_sisa != p.stok');
    })
    ->whereIn('rs.id_rekaman_stok', function($query) {
        $query->select(DB::raw('MAX(id_rekaman_stok)'))
              ->from('rekaman_stoks')
              ->groupBy('id_produk');
    })
    ->limit(5)
    ->get();

if ($examples->count() > 0) {
    echo "\nExample inconsistent records:\n";
    foreach ($examples as $example) {
        echo "- {$example->nama_produk}: Stock={$example->current_stok}, StokAwal={$example->stok_awal}, StokSisa={$example->stok_sisa}\n";
    }
}
