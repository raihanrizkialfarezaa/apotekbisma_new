<?php
require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Checking Dec 31 record source dates ===\n\n";

$rec = DB::table('rekaman_stoks')->where('id_rekaman_stok', 175533)->first();

echo "Rekaman Stok ID: 175533\n";
echo "- waktu (rekaman_stoks): " . $rec->waktu . "\n";
echo "- id_pembelian: " . ($rec->id_pembelian ?: 'NULL') . "\n";
echo "- id_penjualan: " . ($rec->id_penjualan ?: 'NULL') . "\n";

if ($rec->id_pembelian) {
    $pembelian = DB::table('pembelian')->where('id_pembelian', $rec->id_pembelian)->first();
    if ($pembelian) {
        echo "- Pembelian waktu: " . $pembelian->waktu . "\n";
        echo "- Pembelian no_faktur: " . ($pembelian->no_faktur ?: 'NULL') . "\n";
    } else {
        echo "- Pembelian record NOT FOUND!\n";
    }
}

echo "\n=== This explains the issue ===\n";
echo "The UI uses Pembelian.waktu (if exists) instead of rekaman_stoks.waktu\n";
echo "If Pembelian.waktu is different from rekaman_stoks.waktu, dates will mismatch!\n";
