<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$r = Illuminate\Support\Facades\DB::table('rekaman_stoks')
    ->where('id_produk', 63)
    ->where('waktu', 'like', '2026-01-01%')
    ->get();
    
echo "Jan 1 records: " . count($r) . "\n";
foreach($r as $x) {
    echo $x->id_rekaman_stok . " | " . $x->waktu . "\n";
}
