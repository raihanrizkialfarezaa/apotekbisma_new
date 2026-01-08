<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$rec = DB::table('rekaman_stoks')->where('id_rekaman_stok', 175533)->first();

echo "Record ID 175533 (Dec 31):\n";
echo "waktu: " . $rec->waktu . "\n";
echo "id_penjualan: " . ($rec->id_penjualan ?: 'NULL') . "\n";
echo "id_pembelian: " . ($rec->id_pembelian ?: 'NULL') . "\n";

$displayDate = $rec->waktu;

if ($rec->id_penjualan) {
    $p = DB::table('penjualan')->where('id_penjualan', $rec->id_penjualan)->first();
    if ($p) echo "penjualan.waktu: " . $p->waktu . "\n";
    $displayDate = $p->waktu ?? $displayDate;
} elseif ($rec->id_pembelian) {
    $p = DB::table('pembelian')->where('id_pembelian', $rec->id_pembelian)->first();
    if ($p) echo "pembelian.waktu: " . $p->waktu . "\n";
    $displayDate = $p->waktu ?? $displayDate;
}

echo "\nFINAL DISPLAY: " . $displayDate . "\n";
echo "This is what appears in UI for this record.\n";
