<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$lines = [];
$lines[] = "=== ANALISIS KARTU STOK PRODUK 48 ===\n";

$produk = DB::table('produk')->where('id_produk', 48)->first();
$lines[] = "Produk: " . $produk->nama_produk;
$lines[] = "Stok di DB: " . $produk->stok . "\n";

$recs = DB::table('rekaman_stoks')
    ->where('id_produk', 48)
    ->orderBy('waktu', 'desc')
    ->orderBy('id_rekaman_stok', 'desc')
    ->limit(15)
    ->get();

$lines[] = "=== 15 REKAMAN TERAKHIR (DESC) ===";
$lines[] = sprintf("%-8s | %-20s | %-6s | %-6s | %-6s | %-6s | %-6s | %-6s", 
    "ID", "WAKTU", "AWAL", "MASUK", "KELUAR", "SISA", "JualID", "BeliID");
$lines[] = str_repeat("-", 80);

foreach($recs as $r) {
    $lines[] = sprintf("%-8d | %-20s | %-6d | %-6d | %-6d | %-6d | %-6s | %-6s",
        $r->id_rekaman_stok,
        $r->waktu,
        $r->stok_awal,
        $r->stok_masuk,
        $r->stok_keluar,
        $r->stok_sisa,
        $r->id_penjualan ?? '-',
        $r->id_pembelian ?? '-'
    );
}

$lines[] = "\n=== CEK KONSISTENSI ===";

$allRecs = DB::table('rekaman_stoks')
    ->where('id_produk', 48)
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

$lines[] = "Total rekaman: " . count($allRecs);

$runningStock = 0;
$isFirst = true;
$errors = 0;
$errorDetails = [];

foreach($allRecs as $r) {
    if ($isFirst) {
        $runningStock = $r->stok_awal;
        $isFirst = false;
    }
    
    $expected = $runningStock + $r->stok_masuk - $r->stok_keluar;
    
    if ($r->stok_awal != $runningStock || $r->stok_sisa != $expected) {
        $errors++;
        if ($errors <= 10) {
            $errorDetails[] = "ID:{$r->id_rekaman_stok} waktu:{$r->waktu} awal:{$r->stok_awal}(exp:{$runningStock}) sisa:{$r->stok_sisa}(exp:{$expected})";
        }
    }
    
    $runningStock = $expected;
}

$lines[] = "Errors found: " . $errors;
$lines[] = "Final calculated stock: " . $runningStock;
$lines[] = "Actual produk.stok: " . $produk->stok;
$lines[] = "Difference: " . ($runningStock - $produk->stok);

if (count($errorDetails) > 0) {
    $lines[] = "\nFirst 10 errors:";
    foreach($errorDetails as $e) {
        $lines[] = "  " . $e;
    }
}

$dups = DB::table('rekaman_stoks')
    ->select('id_penjualan', DB::raw('COUNT(*) as cnt'))
    ->where('id_produk', 48)
    ->whereNotNull('id_penjualan')
    ->groupBy('id_penjualan')
    ->having('cnt', '>', 1)
    ->get();

$lines[] = "\n=== DUPLIKAT PENJUALAN: " . count($dups) . " ===";
foreach($dups as $d) {
    $lines[] = "id_penjualan " . $d->id_penjualan . ": " . $d->cnt . "x";
}

echo implode("\n", $lines);
