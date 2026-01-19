<?php

use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$productId = 524;
$csvBaseline = 130; // From CSV: MIXALGIN = 130
$cutoffDate = '2025-12-31 23:59:59';

echo "=== PRODUCT 524 (MIXALGIN) DEEP ANALYSIS ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// Get product info
$product = DB::table('produk')->where('id_produk', $productId)->first();
echo "Product Name: " . $product->nama_produk . "\n";
echo "Master Stock (produk.stok): " . $product->stok . "\n";
echo "CSV Baseline (31 Dec 2025): " . $csvBaseline . "\n\n";

// Check for adjustment record
$adjustmentRecord = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where('keterangan', 'ADJUSTMENT_BY_AGENT_CSV_BASELINE')
    ->first();

if ($adjustmentRecord) {
    echo "=== ADJUSTMENT RECORD FOUND ===\n";
    echo "ID: " . $adjustmentRecord->id_rekaman_stok . "\n";
    echo "Waktu: " . $adjustmentRecord->waktu . "\n";
    echo "Stok Awal: " . $adjustmentRecord->stok_awal . "\n";
    echo "Stok Masuk: " . $adjustmentRecord->stok_masuk . "\n";
    echo "Stok Keluar: " . $adjustmentRecord->stok_keluar . "\n";
    echo "Stok Sisa: " . $adjustmentRecord->stok_sisa . "\n\n";
} else {
    echo "!!! NO ADJUSTMENT RECORD FOUND !!!\n\n";
}

// Get last record before cutoff
$lastRecordBeforeCutoff = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where('waktu', '<=', $cutoffDate)
    ->orderBy('waktu', 'desc')
    ->orderBy('created_at', 'desc')
    ->orderBy('id_rekaman_stok', 'desc')
    ->first();

echo "=== LAST RECORD BEFORE/AT CUTOFF ===\n";
if ($lastRecordBeforeCutoff) {
    echo "ID: " . $lastRecordBeforeCutoff->id_rekaman_stok . "\n";
    echo "Waktu: " . $lastRecordBeforeCutoff->waktu . "\n";
    echo "Stok Sisa: " . $lastRecordBeforeCutoff->stok_sisa . "\n";
    echo "Keterangan: " . $lastRecordBeforeCutoff->keterangan . "\n\n";
} else {
    echo "No record found before cutoff\n\n";
}

// Get all records after cutoff
echo "=== ALL RECORDS AFTER CUTOFF (2026+) ===\n";
$futureRecords = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where('waktu', '>', $cutoffDate)
    ->orderBy('waktu', 'asc')
    ->orderBy('created_at', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

echo "Total records after cutoff: " . count($futureRecords) . "\n\n";

echo sprintf("%-8s | %-20s | %-20s | %-8s | %-8s | %-8s | %-8s | %-8s | %s\n", 
    'ID', 'Waktu', 'Created_at', 'Awal', 'Masuk', 'Keluar', 'Sisa', 'Calc', 'Keterangan');
echo str_repeat('-', 160) . "\n";

$runningStock = $csvBaseline;
$errors = [];

foreach ($futureRecords as $idx => $r) {
    $expectedAwal = $runningStock;
    $calcSisa = $runningStock + (int)$r->stok_masuk - (int)$r->stok_keluar;
    
    $awalOK = ((int)$r->stok_awal === $expectedAwal) ? '' : ' ERR_AWAL';
    $sisaOK = ((int)$r->stok_sisa === $calcSisa) ? '' : ' ERR_SISA';
    
    $status = '';
    if ($awalOK || $sisaOK) {
        $status = "[MISMATCH]";
        $errors[] = [
            'id' => $r->id_rekaman_stok,
            'expected_awal' => $expectedAwal,
            'actual_awal' => $r->stok_awal,
            'expected_sisa' => $calcSisa,
            'actual_sisa' => $r->stok_sisa,
        ];
    }
    
    $ket = substr($r->keterangan ?? '', 0, 40);
    echo sprintf("%-8s | %-20s | %-20s | %-8s | %-8s | %-8s | %-8s | %-8s | %s %s\n", 
        $r->id_rekaman_stok, 
        $r->waktu, 
        $r->created_at,
        $r->stok_awal . $awalOK, 
        $r->stok_masuk, 
        $r->stok_keluar, 
        $r->stok_sisa . $sisaOK,
        $calcSisa,
        $ket,
        $status
    );
    
    // Move running stock based on ACTUAL database value for next iteration analysis
    $runningStock = (int)$r->stok_sisa;
}

echo "\n=== CHAIN VALIDATION (What it SHOULD be) ===\n";
$correctRunning = $csvBaseline;
echo "Starting from CSV baseline: $csvBaseline\n\n";

foreach ($futureRecords as $r) {
    $newStock = $correctRunning + (int)$r->stok_masuk - (int)$r->stok_keluar;
    echo "Record {$r->id_rekaman_stok}: Start=$correctRunning + {$r->stok_masuk} - {$r->stok_keluar} = $newStock (DB shows: {$r->stok_sisa})\n";
    $correctRunning = $newStock;
}

echo "\n=== FINAL ANALYSIS ===\n";
echo "Correct final stock should be: $correctRunning\n";
echo "Current master stock (produk.stok): " . $product->stok . "\n";

if ($correctRunning != $product->stok) {
    echo "!!! MISMATCH DETECTED !!!\n";
}

if (count($errors) > 0) {
    echo "\n=== ERRORS FOUND: " . count($errors) . " ===\n";
    foreach ($errors as $err) {
        echo "Record ID {$err['id']}: Expected awal={$err['expected_awal']}, got={$err['actual_awal']}; Expected sisa={$err['expected_sisa']}, got={$err['actual_sisa']}\n";
    }
}

echo "\n=== CHECK: Records at exact cutoff time ===\n";
$cutoffRecords = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where('waktu', '=', $cutoffDate)
    ->get();

foreach ($cutoffRecords as $r) {
    echo "ID: {$r->id_rekaman_stok} | Waktu: {$r->waktu} | Awal: {$r->stok_awal} | +{$r->stok_masuk} | -{$r->stok_keluar} | Sisa: {$r->stok_sisa} | {$r->keterangan}\n";
}

echo "\nAnalysis complete.\n";
