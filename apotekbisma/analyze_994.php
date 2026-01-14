<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$productId = 994;
$cutoffDate = '2025-12-31 23:59:59';

$md = "# ANALISIS PRODUK ID {$productId}\n\n";

$produk = DB::table('produk')->where('id_produk', $productId)->first();
$md .= "## Info Produk\n\n";
$md .= "- **Nama**: {$produk->nama_produk}\n";
$md .= "- **Stok di tabel produk**: {$produk->stok}\n\n";

$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$csvData = null;
if (($handle = fopen($csvFile, "r")) !== FALSE) {
    fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== FALSE) {
        if (count($row) >= 3 && (int)$row[0] == $productId) {
            $csvData = ['nama' => $row[1], 'stok' => (int)$row[2]];
            break;
        }
    }
    fclose($handle);
}

$md .= "## Data CSV Baseline (31 Des 2025)\n\n";
if ($csvData) {
    $md .= "- **Nama**: {$csvData['nama']}\n";
    $md .= "- **Stok per 31 Des 2025**: {$csvData['stok']}\n\n";
} else {
    $md .= "**PRODUK TIDAK ADA DI CSV!**\n\n";
}

$md .= "## Rekaman Stok (Kronologis)\n\n";
$md .= "| ID | Waktu | Stok Awal | Masuk | Keluar | Stok Sisa | Keterangan |\n";
$md .= "|---|---|---|---|---|---|---|\n";

$records = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->orderBy('waktu', 'asc')
    ->orderBy('created_at', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

$runningStock = 0;
$isFirst = true;
$errors = [];

foreach ($records as $r) {
    $keterangan = substr($r->keterangan ?? '-', 0, 40);
    
    $expectedAwal = $isFirst ? $r->stok_awal : $runningStock;
    $expectedSisa = $expectedAwal + ($r->stok_masuk ?? 0) - ($r->stok_keluar ?? 0);
    
    $awalMark = (int)$r->stok_awal === $expectedAwal ? "" : " ❌";
    $sisaMark = (int)$r->stok_sisa === $expectedSisa ? "" : " ❌";
    
    if ($awalMark || $sisaMark) {
        $errors[] = [
            'id' => $r->id_rekaman_stok,
            'expected_awal' => $expectedAwal,
            'actual_awal' => $r->stok_awal,
            'expected_sisa' => $expectedSisa,
            'actual_sisa' => $r->stok_sisa
        ];
    }
    
    $md .= "| {$r->id_rekaman_stok} | {$r->waktu} | {$r->stok_awal}{$awalMark} | " . ($r->stok_masuk ?? 0) . " | " . ($r->stok_keluar ?? 0) . " | {$r->stok_sisa}{$sisaMark} | {$keterangan} |\n";
    
    if ($isFirst) {
        $runningStock = (int)$r->stok_awal;
        $isFirst = false;
    }
    $runningStock = $runningStock + ((int)($r->stok_masuk ?? 0)) - ((int)($r->stok_keluar ?? 0));
}

$md .= "\n## Hasil Kalkulasi\n\n";
$md .= "- **Running stock setelah kalkulasi**: {$runningStock}\n";
$md .= "- **Stok di produk**: {$produk->stok}\n";
$md .= "- **Jumlah error chain**: " . count($errors) . "\n\n";

if (count($errors) > 0) {
    $md .= "### Detail Error\n\n";
    $md .= "| ID | Expected Awal | Actual Awal | Expected Sisa | Actual Sisa |\n";
    $md .= "|---|---|---|---|---|\n";
    foreach ($errors as $e) {
        $md .= "| {$e['id']} | {$e['expected_awal']} | {$e['actual_awal']} | {$e['expected_sisa']} | {$e['actual_sisa']} |\n";
    }
    $md .= "\n";
}

$md .= "## Analisis Stock Opname\n\n";

$opnameRecord = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where('waktu', $cutoffDate)
    ->first();

if ($opnameRecord) {
    $md .= "Stock Opname record **ditemukan**:\n\n";
    $md .= "| Field | Value |\n";
    $md .= "|---|---|\n";
    $md .= "| ID | {$opnameRecord->id_rekaman_stok} |\n";
    $md .= "| Stok Awal | {$opnameRecord->stok_awal} |\n";
    $md .= "| Stok Masuk | " . ($opnameRecord->stok_masuk ?? 0) . " |\n";
    $md .= "| Stok Keluar | " . ($opnameRecord->stok_keluar ?? 0) . " |\n";
    $md .= "| Stok Sisa | {$opnameRecord->stok_sisa} |\n";
    $md .= "| Keterangan | {$opnameRecord->keterangan} |\n\n";
    
    if ($csvData && $opnameRecord->stok_sisa != $csvData['stok']) {
        $md .= "⚠️ **TIDAK SESUAI CSV!** Seharusnya stok_sisa = {$csvData['stok']}\n\n";
    }
} else {
    $md .= "❌ **Stock Opname record TIDAK DITEMUKAN** untuk cutoff!\n\n";
}

$md .= "## Records Sebelum Cutoff\n\n";

$beforeRecords = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where('waktu', '<', $cutoffDate)
    ->orderBy('waktu', 'desc')
    ->limit(5)
    ->get();

$md .= "Jumlah total: " . DB::table('rekaman_stoks')->where('id_produk', $productId)->where('waktu', '<', $cutoffDate)->count() . "\n\n";

if ($beforeRecords->count() > 0) {
    $md .= "| Waktu | Stok Awal | Masuk | Keluar | Stok Sisa |\n";
    $md .= "|---|---|---|---|---|\n";
    foreach ($beforeRecords as $r) {
        $md .= "| {$r->waktu} | {$r->stok_awal} | " . ($r->stok_masuk ?? 0) . " | " . ($r->stok_keluar ?? 0) . " | {$r->stok_sisa} |\n";
    }
}

file_put_contents(__DIR__ . '/analysis_994.md', $md);
echo "Done! Check analysis_994.md\n";
