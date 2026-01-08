<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Checking records and their transaction sources ===\n\n";

$rows = [435, 436, 437, 438, 439, 440, 441, 442];

$recs = DB::table('rekaman_stoks')
    ->where('id_produk', 63)
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

$counter = 1;
foreach ($recs as $r) {
    if (in_array($counter, $rows)) {
        echo "Row $counter | ID: $r->id_rekaman_stok\n";
        echo "  rekaman_stoks.waktu: $r->waktu\n";
        
        $displayDate = $r->waktu;
        
        if ($r->id_penjualan) {
            $penjualan = DB::table('penjualan')->where('id_penjualan', $r->id_penjualan)->first();
            if ($penjualan && $penjualan->waktu) {
                $displayDate = $penjualan->waktu;
                echo "  penjualan.waktu: $penjualan->waktu *** USED FOR DISPLAY\n";
            }
        } elseif ($r->id_pembelian) {
            $pembelian = DB::table('pembelian')->where('id_pembelian', $r->id_pembelian)->first();
            if ($pembelian && $pembelian->waktu) {
                $displayDate = $pembelian->waktu;
                echo "  pembelian.waktu: $pembelian->waktu *** USED FOR DISPLAY\n";
            }
        } else {
            echo "  No linked transaction - using rekaman_stoks.waktu\n";
        }
        
        echo "  DISPLAY DATE: $displayDate\n\n";
    }
    $counter++;
}
