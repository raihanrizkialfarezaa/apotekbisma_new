<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

$dryRun = true;
if (isset($argv[1]) && $argv[1] === '--execute') {
    $dryRun = false;
}

$md = "# FIX DUPLIKAT STOCK OPNAME & RECALCULATE\n\n";
$md .= "Tanggal: " . date('Y-m-d H:i:s') . "\n";
$md .= "Mode: " . ($dryRun ? "**DRY RUN**" : "**EXECUTE**") . "\n\n";

$cutoffDate = '2025-12-31 23:59:59';

$md .= "## STEP 1: Hapus Duplikat Stock Opname yang BUKAN di cutoff date\n\n";

$wrongOpname = DB::table('rekaman_stoks')
    ->where('keterangan', 'LIKE', '%Stock Opname 31 Desember%')
    ->where('waktu', '!=', $cutoffDate)
    ->get();

$md .= "Ditemukan **" . count($wrongOpname) . "** Stock Opname dengan waktu SALAH (bukan cutoff date)\n\n";

if (count($wrongOpname) > 0) {
    $md .= "| ID | Produk | Waktu | Stok Keluar | Stok Sisa |\n";
    $md .= "|---|---|---|---|---|\n";
    
    foreach ($wrongOpname as $w) {
        $md .= "| {$w->id_rekaman_stok} | {$w->id_produk} | {$w->waktu} | {$w->stok_keluar} | {$w->stok_sisa} |\n";
        
        if (!$dryRun) {
            DB::table('rekaman_stoks')->where('id_rekaman_stok', $w->id_rekaman_stok)->delete();
        }
    }
    $md .= "\n";
}

$md .= "## STEP 2: Cari semua produk dengan stok negatif\n\n";

$negativeProducts = DB::table('produk')
    ->where('stok', '<', 0)
    ->get();

$md .= "Ditemukan **" . count($negativeProducts) . "** produk dengan stok negatif\n\n";

if (count($negativeProducts) > 0) {
    $md .= "| ID | Nama | Stok |\n";
    $md .= "|---|---|---|\n";
    foreach ($negativeProducts as $p) {
        $md .= "| {$p->id_produk} | {$p->nama_produk} | {$p->stok} |\n";
    }
    $md .= "\n";
}

$md .= "## STEP 3: Recalculate Chain untuk Produk Negatif\n\n";

$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
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

$fixedProducts = 0;
$fixedRecords = 0;

foreach ($negativeProducts as $produk) {
    $produkId = $produk->id_produk;
    
    $opnameRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->where('waktu', $cutoffDate)
        ->first();
    
    $baselineStok = $csvData[$produkId] ?? null;
    
    if (!$opnameRecord || !$baselineStok) {
        $md .= "- Produk {$produkId}: Skip (tidak ada di CSV atau tidak ada Stock Opname)\n";
        continue;
    }
    
    $allRecords = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    $runningStock = 0;
    $isFirst = true;
    $updates = [];
    
    foreach ($allRecords as $record) {
        if ($isFirst) {
            $runningStock = (int)$record->stok_awal;
            $isFirst = false;
        } else {
            if ((int)$record->stok_awal !== $runningStock) {
                $updates[$record->id_rekaman_stok]['stok_awal'] = $runningStock;
            }
        }
        
        $calculatedSisa = $runningStock + (int)($record->stok_masuk ?? 0) - (int)($record->stok_keluar ?? 0);
        
        if ((int)$record->stok_sisa !== $calculatedSisa) {
            $updates[$record->id_rekaman_stok]['stok_sisa'] = $calculatedSisa;
        }
        
        $runningStock = $calculatedSisa;
    }
    
    if (count($updates) > 0) {
        $md .= "- Produk {$produkId} ({$produk->nama_produk}): Fixing " . count($updates) . " records\n";
        $fixedProducts++;
        $fixedRecords += count($updates);
        
        if (!$dryRun) {
            foreach ($updates as $recordId => $data) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $recordId)
                    ->update($data);
            }
            
            DB::table('produk')
                ->where('id_produk', $produkId)
                ->update(['stok' => max(0, $runningStock)]);
        }
    }
}

$md .= "\n";
$md .= "## SUMMARY\n\n";
$md .= "- Duplikat Stock Opname dihapus: " . count($wrongOpname) . "\n";
$md .= "- Produk negatif ditemukan: " . count($negativeProducts) . "\n";
$md .= "- Produk yang di-fix: {$fixedProducts}\n";
$md .= "- Records yang di-fix: {$fixedRecords}\n\n";

if ($dryRun) {
    $md .= "**DRY RUN - Tidak ada perubahan yang dibuat. Jalankan dengan --execute untuk menerapkan.**\n";
}

file_put_contents(__DIR__ . '/fix_duplikat_opname.md', $md);
echo "Done! Check fix_duplikat_opname.md\n";
