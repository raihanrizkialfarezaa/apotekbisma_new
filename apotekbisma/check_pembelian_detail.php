<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$pembelian = DB::table('pembelian')->where('id_pembelian', 436)->first();
echo "Pembelian 436:\n";
echo "  waktu: " . $pembelian->waktu . "\n";
echo "  created_at: " . $pembelian->created_at . "\n";
echo "  no_faktur: " . $pembelian->no_faktur . "\n";

// Check detail untuk product 994
$rs = DB::table('rekaman_stoks')
    ->where('id_produk', 994)
    ->where('id_pembelian', 436)
    ->first();

echo "\nRekaman Stok for Product 994 from Pembelian 436:\n";
if ($rs) {
    echo "  id_rekaman_stok: " . $rs->id_rekaman_stok . "\n";
    echo "  waktu: " . $rs->waktu . "\n";
    echo "  stok_masuk: " . $rs->stok_masuk . "\n";
    echo "  stok_sisa: " . $rs->stok_sisa . "\n";
} else {
    echo "  NOT FOUND\n";
}

// Get all rekaman stok for product 994 around cutoff
echo "\n\nAll rekaman_stoks for Product 994 around cutoff:\n";
$records = DB::table('rekaman_stoks')
    ->where('id_produk', 994)
    ->whereBetween('waktu', ['2025-12-30 00:00:00', '2026-01-03 12:00:00'])
    ->orderBy('waktu', 'desc')
    ->get();

foreach ($records as $r) {
    echo sprintf("ID: %d | waktu: %s | masuk: %s | keluar: %s | sisa: %d | id_pembelian: %s\n",
        $r->id_rekaman_stok,
        $r->waktu,
        $r->stok_masuk ?: '-',
        $r->stok_keluar ?: '-',
        $r->stok_sisa,
        $r->id_pembelian ?: 'NULL'
    );
}
