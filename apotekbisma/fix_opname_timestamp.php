<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

$cutoffDate = '2025-12-31 23:59:59';
$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';

$dryRun = !in_array('--execute', $argv ?? []);

$md = "# FIX STOCK OPNAME TIMESTAMP\n\n";
$md .= "**Waktu**: " . date('Y-m-d H:i:s') . "\n";
$md .= "**Mode**: " . ($dryRun ? "DRY RUN" : "EXECUTE") . "\n\n";

$csvData = [];
if (($handle = fopen($csvFile, "r")) !== FALSE) {
    fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== FALSE) {
        if (count($row) >= 3) {
            $csvData[(int)$row[0]] = (int)$row[2];
        }
    }
    fclose($handle);
}

$md .= "Loaded " . count($csvData) . " products from CSV\n\n";

$md .= "## Step 1: Fix Stock Opname dengan waktu SALAH\n\n";

$wrongOpname = DB::table('rekaman_stoks')
    ->where('keterangan', 'LIKE', '%Stock Opname 31 Desember%')
    ->where('waktu', '!=', $cutoffDate)
    ->get();

$md .= "Ditemukan **" . count($wrongOpname) . "** Stock Opname dengan waktu salah\n\n";

$fixed = 0;
foreach ($wrongOpname as $opname) {
    $baselineStok = $csvData[$opname->id_produk] ?? null;
    
    if ($baselineStok === null) continue;
    
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $opname->id_produk)
        ->where('waktu', '<', $cutoffDate)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $stokAwal = $lastBefore ? (int)$lastBefore->stok_sisa : 0;
    $diff = $baselineStok - $stokAwal;
    
    if (!$dryRun) {
        DB::table('rekaman_stoks')
            ->where('id_rekaman_stok', $opname->id_rekaman_stok)
            ->update([
                'waktu' => $cutoffDate,
                'stok_awal' => $stokAwal,
                'stok_masuk' => $diff > 0 ? $diff : null,
                'stok_keluar' => $diff < 0 ? abs($diff) : null,
                'stok_sisa' => $baselineStok
            ]);
    }
    $fixed++;
}

$md .= "Fixed: {$fixed}\n\n";

$md .= "## Step 2: Recalculate chain SETELAH cutoff\n\n";

$chainFixed = 0;

foreach ($csvData as $produkId => $baselineStok) {
    $recordsAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($recordsAfter->isEmpty()) continue;
    
    $runningStock = $baselineStok;
    
    foreach ($recordsAfter as $record) {
        $masuk = (int)($record->stok_masuk ?? 0);
        $keluar = (int)($record->stok_keluar ?? 0);
        $newSisa = $runningStock + $masuk - $keluar;
        
        if ((int)$record->stok_awal !== $runningStock || (int)$record->stok_sisa !== $newSisa) {
            if (!$dryRun) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $record->id_rekaman_stok)
                    ->update([
                        'stok_awal' => $runningStock,
                        'stok_sisa' => $newSisa
                    ]);
            }
            $chainFixed++;
        }
        
        $runningStock = $newSisa;
    }
}

$md .= "Chain records fixed: {$chainFixed}\n\n";

$md .= "## Step 3: Sync produk.stok\n\n";

$synced = 0;

foreach ($csvData as $produkId => $baselineStok) {
    $latestRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if (!$latestRecord) continue;
    
    $produk = DB::table('produk')->where('id_produk', $produkId)->first();
    if (!$produk) continue;
    
    if ((int)$produk->stok !== (int)$latestRecord->stok_sisa) {
        if (!$dryRun) {
            DB::table('produk')
                ->where('id_produk', $produkId)
                ->update(['stok' => $latestRecord->stok_sisa]);
        }
        $synced++;
    }
}

$md .= "Synced: {$synced}\n\n";

$md .= "## Verifikasi Produk 994\n\n";

$produk994 = DB::table('produk')->where('id_produk', 994)->first();
$opname994 = DB::table('rekaman_stoks')
    ->where('id_produk', 994)
    ->where('waktu', $cutoffDate)
    ->first();
$latest994 = DB::table('rekaman_stoks')
    ->where('id_produk', 994)
    ->orderBy('waktu', 'desc')
    ->orderBy('id_rekaman_stok', 'desc')
    ->first();

$md .= "- Stok produk: {$produk994->stok}\n";
$md .= "- Stock Opname stok_sisa: " . ($opname994 ? $opname994->stok_sisa : "N/A") . "\n";
$md .= "- Stock Opname waktu: " . ($opname994 ? $opname994->waktu : "N/A") . "\n";
$md .= "- Latest record stok_sisa: " . ($latest994 ? $latest994->stok_sisa : "N/A") . "\n";
$md .= "- Latest record waktu: " . ($latest994 ? $latest994->waktu : "N/A") . "\n";

if ($dryRun) {
    $md .= "\n**DRY RUN - Jalankan dengan --execute untuk apply**\n";
}

file_put_contents(__DIR__ . '/fix_opname_timestamp.md', $md);
echo "Done! Check fix_opname_timestamp.md\n";
