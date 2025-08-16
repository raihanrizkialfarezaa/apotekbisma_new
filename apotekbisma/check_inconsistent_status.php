<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

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

// Get details if any
if ($count > 0) {
    $details = DB::table('rekaman_stoks as rs')
        ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
        ->select('p.nama_produk', 'p.stok as current_stok', 'rs.stok_awal', 'rs.stok_sisa', 'p.id_produk')
        ->where(function($query) {
            $query->whereRaw('rs.stok_awal != p.stok')
                  ->orWhereRaw('rs.stok_sisa != p.stok');
        })
        ->whereIn('rs.id_rekaman_stok', function($query) {
            $query->select(DB::raw('MAX(id_rekaman_stok)'))
                  ->from('rekaman_stoks')
                  ->groupBy('id_produk');
        })
        ->get();

    echo "\nInconsistent records:\n";
    foreach ($details as $detail) {
        echo "- {$detail->nama_produk} (ID: {$detail->id_produk}): Stock={$detail->current_stok}, StokAwal={$detail->stok_awal}, StokSisa={$detail->stok_sisa}\n";
    }
}
