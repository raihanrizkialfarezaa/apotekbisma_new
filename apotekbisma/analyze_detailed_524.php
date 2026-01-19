<?php

use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$productId = 524;

echo "=== DETAILED RECORD ANALYSIS FOR PRODUCT 524 ===\n\n";

// Get ALL records for this product
$allRecords = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

echo "Total records: " . count($allRecords) . "\n\n";

echo "=== ALL RECORDS BY ID (Creation Order) ===\n";
echo sprintf("%-8s | %-20s | %-20s | %-8s | %-8s | %-8s | %-8s | %s\n", 
    'ID', 'Waktu', 'Created_at', 'Awal', 'Masuk', 'Keluar', 'Sisa', 'Keterangan');
echo str_repeat('-', 150) . "\n";

foreach ($allRecords as $r) {
    $ket = substr($r->keterangan ?? '', 0, 45);
    echo sprintf("%-8s | %-20s | %-20s | %-8s | %-8s | %-8s | %-8s | %s\n", 
        $r->id_rekaman_stok, 
        $r->waktu, 
        $r->created_at,
        $r->stok_awal, 
        $r->stok_masuk, 
        $r->stok_keluar, 
        $r->stok_sisa,
        $ket
    );
}

// Check for Stock Opname or Manual adjustment records
echo "\n=== STOCK OPNAME / MANUAL ADJUSTMENT RECORDS ===\n";
$soRecords = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where(function($q) {
        $q->where('keterangan', 'like', '%Stock Opname%')
          ->orWhere('keterangan', 'like', '%Perubahan Stok Manual%')
          ->orWhere('keterangan', 'like', '%ADJUSTMENT%');
    })
    ->get();

foreach ($soRecords as $r) {
    echo "ID: {$r->id_rekaman_stok}\n";
    echo "  Waktu: {$r->waktu}\n";
    echo "  Created: {$r->created_at}\n";
    echo "  Awal: {$r->stok_awal} | Masuk: {$r->stok_masuk} | Keluar: {$r->stok_keluar} | Sisa: {$r->stok_sisa}\n";
    echo "  Keterangan: {$r->keterangan}\n\n";
}

// Analyze what the correct chain should be
echo "=== WHAT HAPPENED ===\n";
echo "1. CSV baseline says stock should be 130 at end of Dec 31, 2025\n";
echo "2. Record 175405 has 'Perubahan Stok Manual: SO' subtracting 220 - this is problematic\n";
echo "3. Record 178299 has Stock Opname adjustment but with wrong waktu (2026-01-01)\n";
echo "4. The sorting order is causing chain to break\n\n";

// Check records with waktu in 2026 but created in Dec 2025
echo "=== RECORDS WITH WAKTU IN 2026 BUT CREATED IN 2025 ===\n";
$anomalies = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->whereRaw("waktu >= '2026-01-01' AND created_at < '2026-01-01'")
    ->get();

foreach ($anomalies as $r) {
    echo "ID: {$r->id_rekaman_stok} | Waktu: {$r->waktu} | Created: {$r->created_at} | {$r->keterangan}\n";
}

echo "\n=== THE PROBLEM ===\n";
echo "The current script uses 'waktu' for sorting, but many records have:\n";
echo "- Same 'waktu' (2026-01-14 21:29:20) but different 'created_at'\n";
echo "- This means the real transaction order is by 'created_at', not 'waktu'\n";
echo "- The Stock Opname record (178299) has waktu=2026-01-01 06:59:59 which puts it FIRST\n";
echo "- But it should NOT exist as a separate record - the baseline should be set differently\n";
