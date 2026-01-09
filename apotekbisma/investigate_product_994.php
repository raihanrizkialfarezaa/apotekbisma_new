<?php
/**
 * Deep Investigation for Product 994 (AMOXICILIN 500mg HJ)
 * Analyzing the stock card anomaly
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=======================================================\n";
echo "DEEP INVESTIGATION: PRODUCT 994 (AMOXICILIN 500mg HJ)\n";
echo "=======================================================\n\n";

$produkId = 994;
$cutoffDate = '2025-12-31 23:59:59';

// 1. Get product info
$produk = DB::table('produk')->where('id_produk', $produkId)->first();
echo "PRODUCT INFO:\n";
echo "  ID: {$produk->id_produk}\n";
echo "  Name: {$produk->nama_produk}\n";
echo "  Current Stock (produk.stok): {$produk->stok}\n\n";

// 2. Get ALL stock records for this product
echo "ALL STOCK RECORDS (ordered by waktu DESC, created_at DESC, id DESC):\n";
echo str_repeat("-", 160) . "\n";
printf("%-8s | %-22s | %-22s | %-10s | %-10s | %-10s | %-12s | %-40s\n", 
    "ID", "WAKTU", "CREATED_AT", "MASUK", "KELUAR", "STOK_AWAL", "STOK_SISA", "KETERANGAN");
echo str_repeat("-", 160) . "\n";

$records = DB::table('rekaman_stoks')
    ->where('id_produk', $produkId)
    ->orderBy('waktu', 'desc')
    ->orderBy('created_at', 'desc')
    ->orderBy('id_rekaman_stok', 'desc')
    ->get();

foreach ($records as $r) {
    $ket = substr($r->keterangan ?? '', 0, 40);
    printf("%-8d | %-22s | %-22s | %-10s | %-10s | %-10s | %-12s | %-40s\n",
        $r->id_rekaman_stok,
        $r->waktu,
        $r->created_at,
        $r->stok_masuk ?: '-',
        $r->stok_keluar ?: '-',
        $r->stok_awal ?? '-',
        $r->stok_sisa ?? '-',
        $ket
    );
}
echo str_repeat("-", 160) . "\n";
echo "Total records: " . count($records) . "\n\n";

// 3. Check records around cutoff date
echo "RECORDS AROUND CUTOFF (2025-12-25 to 2026-01-05):\n";
echo str_repeat("-", 160) . "\n";

$aroundCutoff = DB::table('rekaman_stoks')
    ->where('id_produk', $produkId)
    ->whereBetween('waktu', ['2025-12-25 00:00:00', '2026-01-05 00:00:00'])
    ->orderBy('waktu', 'desc')
    ->orderBy('created_at', 'desc')
    ->orderBy('id_rekaman_stok', 'desc')
    ->get();

printf("%-8s | %-22s | %-22s | %-10s | %-10s | %-10s | %-12s | %-50s\n", 
    "ID", "WAKTU", "CREATED_AT", "MASUK", "KELUAR", "STOK_AWAL", "STOK_SISA", "KETERANGAN");
echo str_repeat("-", 160) . "\n";

foreach ($aroundCutoff as $r) {
    $ket = substr($r->keterangan ?? '', 0, 50);
    printf("%-8d | %-22s | %-22s | %-10s | %-10s | %-10s | %-12s | %-50s\n",
        $r->id_rekaman_stok,
        $r->waktu,
        $r->created_at,
        $r->stok_masuk ?: '-',
        $r->stok_keluar ?: '-',
        $r->stok_awal ?? '-',
        $r->stok_sisa ?? '-',
        $ket
    );
}
echo str_repeat("-", 160) . "\n\n";

// 4. Check for Stock Opname records
echo "STOCK OPNAME RECORDS (keterangan LIKE '%SO%' or '%Opname%' or '%Penyesuaian%'):\n";
$soRecords = DB::table('rekaman_stoks')
    ->where('id_produk', $produkId)
    ->where(function($q) {
        $q->where('keterangan', 'LIKE', '%SO%')
          ->orWhere('keterangan', 'LIKE', '%Opname%')
          ->orWhere('keterangan', 'LIKE', '%Penyesuaian%')
          ->orWhere('keterangan', 'LIKE', '%Manual%');
    })
    ->orderBy('waktu', 'desc')
    ->get();

if ($soRecords->isEmpty()) {
    echo "  >> NO STOCK OPNAME RECORDS FOUND!\n";
} else {
    foreach ($soRecords as $r) {
        echo "  ID: {$r->id_rekaman_stok}, Waktu: {$r->waktu}, Masuk: " . ($r->stok_masuk ?: '-') . ", Keluar: " . ($r->stok_keluar ?: '-') . ", Sisa: {$r->stok_sisa}, Ket: {$r->keterangan}\n";
    }
}
echo "\n";

// 5. Check CSV value
echo "CSV BASELINE CHECK:\n";
$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$csvData = [];
if (($handle = fopen($csvFile, "r")) !== FALSE) {
    $header = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== FALSE) {
        if (count($row) >= 3) {
            $csvData[(int)$row[0]] = [
                'nama' => $row[1],
                'stok' => (int)$row[2]
            ];
        }
    }
    fclose($handle);
}

if (isset($csvData[$produkId])) {
    echo "  CSV Stock for Product {$produkId}: {$csvData[$produkId]['stok']}\n";
} else {
    echo "  Product {$produkId} NOT FOUND in CSV!\n";
}
echo "\n";

// 6. Analyze the gap
echo "GAP ANALYSIS:\n";
$lastBefore = DB::table('rekaman_stoks')
    ->where('id_produk', $produkId)
    ->where('waktu', '<=', $cutoffDate)
    ->orderBy('waktu', 'desc')
    ->orderBy('created_at', 'desc')
    ->orderBy('id_rekaman_stok', 'desc')
    ->first();

$firstAfter = DB::table('rekaman_stoks')
    ->where('id_produk', $produkId)
    ->where('waktu', '>', $cutoffDate)
    ->orderBy('waktu', 'asc')
    ->orderBy('created_at', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->first();

if ($lastBefore) {
    echo "  Last record BEFORE/AT cutoff:\n";
    echo "    ID: {$lastBefore->id_rekaman_stok}\n";
    echo "    Waktu: {$lastBefore->waktu}\n";
    echo "    Stok Sisa: {$lastBefore->stok_sisa}\n";
    echo "    Keterangan: {$lastBefore->keterangan}\n";
} else {
    echo "  NO record before cutoff!\n";
}

if ($firstAfter) {
    echo "  First record AFTER cutoff:\n";
    echo "    ID: {$firstAfter->id_rekaman_stok}\n";
    echo "    Waktu: {$firstAfter->waktu}\n";
    echo "    Stok Masuk: " . ($firstAfter->stok_masuk ?: '-') . "\n";
    echo "    Stok Keluar: " . ($firstAfter->stok_keluar ?: '-') . "\n";
    echo "    Stok Awal: {$firstAfter->stok_awal}\n";
    echo "    Stok Sisa: {$firstAfter->stok_sisa}\n";
    echo "    Keterangan: {$firstAfter->keterangan}\n";
    
    // Expected first after
    $baselineStok = $csvData[$produkId]['stok'] ?? 0;
    $expectedStokAwal = $baselineStok;
    $expectedStokSisa = $baselineStok;
    if ($firstAfter->stok_masuk) {
        $expectedStokSisa += $firstAfter->stok_masuk;
    }
    if ($firstAfter->stok_keluar) {
        $expectedStokSisa -= $firstAfter->stok_keluar;
    }
    echo "\n  EXPECTED VALUES (if baseline was {$baselineStok}):\n";
    echo "    Expected stok_awal: {$expectedStokAwal}\n";
    echo "    Expected stok_sisa: {$expectedStokSisa}\n";
    
    if ($firstAfter->stok_awal != $expectedStokAwal || $firstAfter->stok_sisa != $expectedStokSisa) {
        echo "\n  !! MISMATCH DETECTED !!\n";
        echo "    stok_awal difference: " . ($firstAfter->stok_awal - $expectedStokAwal) . "\n";
        echo "    stok_sisa difference: " . ($firstAfter->stok_sisa - $expectedStokSisa) . "\n";
    }
}

echo "\n";

// 7. Check if there's a record exactly at cutoff
echo "RECORDS EXACTLY AT CUTOFF (2025-12-31 23:59:59):\n";
$atCutoff = DB::table('rekaman_stoks')
    ->where('id_produk', $produkId)
    ->where('waktu', $cutoffDate)
    ->get();

if ($atCutoff->isEmpty()) {
    echo "  >> NO RECORD AT CUTOFF! This is the problem - no baseline anchor.\n";
} else {
    foreach ($atCutoff as $r) {
        echo "  ID: {$r->id_rekaman_stok}, Awal: {$r->stok_awal}, Sisa: {$r->stok_sisa}, Ket: {$r->keterangan}\n";
    }
}

echo "\n=======================================================\n";
echo "DIAGNOSIS COMPLETE\n";
echo "=======================================================\n";
