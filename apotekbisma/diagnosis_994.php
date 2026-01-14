<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$productId = 994;
$cutoffDate = '2025-12-31 23:59:59';
$csvBaseline = 140;

$md = "# DIAGNOSIS MENDALAM PRODUK 994\n\n";
$md .= "**Waktu analisis**: " . date('Y-m-d H:i:s') . "\n";
$md .= "**Cutoff date**: {$cutoffDate}\n";
$md .= "**Baseline CSV**: {$csvBaseline}\n\n";

$produk = DB::table('produk')->where('id_produk', $productId)->first();
$md .= "## Status Produk\n\n";
$md .= "- **ID**: {$produk->id_produk}\n";
$md .= "- **Nama**: {$produk->nama_produk}\n";
$md .= "- **Stok saat ini**: {$produk->stok}\n\n";

$md .= "## Statistik Rekaman Stok\n\n";

$totalRecords = DB::table('rekaman_stoks')->where('id_produk', $productId)->count();
$recordsBefore = DB::table('rekaman_stoks')->where('id_produk', $productId)->where('waktu', '<', $cutoffDate)->count();
$recordsAt = DB::table('rekaman_stoks')->where('id_produk', $productId)->where('waktu', '=', $cutoffDate)->count();
$recordsAfter = DB::table('rekaman_stoks')->where('id_produk', $productId)->where('waktu', '>', $cutoffDate)->count();

$md .= "| Periode | Jumlah Records |\n";
$md .= "|---|---|\n";
$md .= "| Sebelum cutoff | {$recordsBefore} |\n";
$md .= "| Di cutoff (opname) | {$recordsAt} |\n";
$md .= "| Setelah cutoff | {$recordsAfter} |\n";
$md .= "| **Total** | {$totalRecords} |\n\n";

$md .= "## Transaksi Setelah Cutoff (Detail)\n\n";

$afterCutoff = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where('waktu', '>', $cutoffDate)
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

$totalMasukAfter = 0;
$totalKeluarAfter = 0;

$md .= "| No | Waktu | Masuk | Keluar | Stok Awal | Stok Sisa | Keterangan |\n";
$md .= "|---|---|---|---|---|---|---|\n";

$no = 1;
foreach ($afterCutoff as $r) {
    $masuk = (int)($r->stok_masuk ?? 0);
    $keluar = (int)($r->stok_keluar ?? 0);
    $totalMasukAfter += $masuk;
    $totalKeluarAfter += $keluar;
    $ket = substr($r->keterangan ?? '-', 0, 30);
    $md .= "| {$no} | {$r->waktu} | {$masuk} | {$keluar} | {$r->stok_awal} | {$r->stok_sisa} | {$ket} |\n";
    $no++;
}

$md .= "\n**Total Masuk setelah cutoff**: {$totalMasukAfter}\n";
$md .= "**Total Keluar setelah cutoff**: {$totalKeluarAfter}\n";
$md .= "**Net change**: " . ($totalMasukAfter - $totalKeluarAfter) . "\n\n";

$md .= "## Kalkulasi yang Benar\n\n";
$md .= "```\n";
$md .= "Baseline CSV (31 Des 2025)     = {$csvBaseline}\n";
$md .= "Total Masuk setelah cutoff     = +{$totalMasukAfter}\n";
$md .= "Total Keluar setelah cutoff    = -{$totalKeluarAfter}\n";
$md .= "----------------------------------------\n";
$correctFinal = $csvBaseline + $totalMasukAfter - $totalKeluarAfter;
$md .= "Stok Akhir yang BENAR          = {$correctFinal}\n";
$md .= "Stok di produk saat ini        = {$produk->stok}\n";
$md .= "```\n\n";

$md .= "## Record Stock Opname\n\n";

$opname = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where('waktu', $cutoffDate)
    ->first();

if ($opname) {
    $md .= "| Field | Value |\n";
    $md .= "|---|---|\n";
    $md .= "| ID | {$opname->id_rekaman_stok} |\n";
    $md .= "| Waktu | {$opname->waktu} |\n";
    $md .= "| Stok Awal | {$opname->stok_awal} |\n";
    $md .= "| Stok Masuk | " . ($opname->stok_masuk ?? 0) . " |\n";
    $md .= "| Stok Keluar | " . ($opname->stok_keluar ?? 0) . " |\n";
    $md .= "| Stok Sisa | {$opname->stok_sisa} |\n";
    $md .= "| Keterangan | {$opname->keterangan} |\n";
    
    if ((int)$opname->stok_sisa !== $csvBaseline) {
        $md .= "\n**ERROR**: stok_sisa ({$opname->stok_sisa}) != CSV baseline ({$csvBaseline})\n";
    }
} else {
    $md .= "**TIDAK ADA RECORD STOCK OPNAME DI CUTOFF DATE!**\n";
}

$md .= "\n## 10 Records Terakhir Sebelum Cutoff\n\n";

$beforeCutoff = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where('waktu', '<', $cutoffDate)
    ->orderBy('waktu', 'desc')
    ->orderBy('id_rekaman_stok', 'desc')
    ->limit(10)
    ->get();

$md .= "| Waktu | Masuk | Keluar | Stok Sisa | Keterangan |\n";
$md .= "|---|---|---|---|---|\n";

foreach ($beforeCutoff as $r) {
    $ket = substr($r->keterangan ?? '-', 0, 25);
    $md .= "| {$r->waktu} | " . ($r->stok_masuk ?? 0) . " | " . ($r->stok_keluar ?? 0) . " | {$r->stok_sisa} | {$ket} |\n";
}

file_put_contents(__DIR__ . '/diagnosis_994.md', $md);
echo "Done! Check diagnosis_994.md\n";
