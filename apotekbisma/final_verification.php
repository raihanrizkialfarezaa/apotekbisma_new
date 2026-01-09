<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║              FINAL STOCK INTEGRITY VERIFICATION                               ║\n";
echo "║              Date: " . date('Y-m-d H:i:s') . "                                    ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";

$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$opnameData = [];
$handle = fopen($csvFile, 'r');
fgetcsv($handle);
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) >= 3 && !empty($row[0])) {
        $opnameData[intval($row[0])] = intval($row[2]);
    }
}
fclose($handle);

$checks = [
    'gap_2025_2026' => 0,
    'calculation_errors' => 0,
    'produk_rekaman_mismatch' => 0,
    'opname_adjustment_missing' => 0,
    'continuity_errors' => 0
];

$issues = [];

echo "CHECK 1: Gap between 2025 and 2026\n";
foreach ($opnameData as $pid => $opnameStock) {
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->where('waktu', '<=', '2025-12-31 23:59:59')
        ->orderBy('waktu', 'desc')
        ->first();
    
    $firstAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->where('waktu', '>', '2025-12-31 23:59:59')
        ->orderBy('waktu', 'asc')
        ->first();
    
    if ($lastBefore && $firstAfter && intval($firstAfter->stok_awal) != intval($lastBefore->stok_sisa)) {
        $checks['gap_2025_2026']++;
        $issues[] = "Gap 2025-2026: Product {$pid}";
    }
}
echo "  Issues: {$checks['gap_2025_2026']}\n\n";

echo "CHECK 2: Stock continuity (stok_awal = previous stok_sisa)\n";
$productIds = DB::table('rekaman_stoks')->distinct()->pluck('id_produk');
foreach ($productIds as $pid) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    $prevSisa = null;
    foreach ($records as $r) {
        if ($prevSisa !== null && intval($r->stok_awal) != intval($prevSisa)) {
            $checks['continuity_errors']++;
            break;
        }
        $prevSisa = $r->stok_sisa;
    }
}
echo "  Issues: {$checks['continuity_errors']}\n\n";

echo "CHECK 3: Calculation errors (stok_awal + masuk - keluar != sisa)\n";
$allRekaman = DB::table('rekaman_stoks')->get();
foreach ($allRekaman as $r) {
    $expected = intval($r->stok_awal) + intval($r->stok_masuk) - intval($r->stok_keluar);
    if ($expected != intval($r->stok_sisa)) {
        $checks['calculation_errors']++;
    }
}
echo "  Issues: {$checks['calculation_errors']}\n\n";

echo "CHECK 4: produk.stok vs last rekaman.stok_sisa\n";
$products = DB::table('produk')->get();
foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman && intval($product->stok) != intval($lastRekaman->stok_sisa)) {
        $checks['produk_rekaman_mismatch']++;
    }
}
echo "  Issues: {$checks['produk_rekaman_mismatch']}\n\n";

echo "CHECK 5: Stock opname adjustment records\n";
foreach ($opnameData as $pid => $opnameStock) {
    $opnameRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->where('waktu', '2025-12-31 23:59:59')
        ->where('keterangan', 'LIKE', '%Stock Opname%')
        ->first();
    
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->where('waktu', '<', '2025-12-31 23:59:59')
        ->orderBy('waktu', 'desc')
        ->first();
    
    $firstAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->where('waktu', '>', '2025-12-31 23:59:59')
        ->first();
    
    if ($lastBefore && $firstAfter && !$opnameRecord) {
        if (intval($lastBefore->stok_sisa) != $opnameStock) {
            $checks['opname_adjustment_missing']++;
        }
    }
}
echo "  Issues: {$checks['opname_adjustment_missing']}\n\n";

$totalIssues = array_sum($checks);

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "                              SUMMARY                                           \n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

foreach ($checks as $check => $count) {
    $status = $count == 0 ? "[OK]" : "[ISSUE]";
    echo "  {$status} {$check}: {$count}\n";
}

echo "\n  TOTAL ISSUES: {$totalIssues}\n\n";

if ($totalIssues == 0) {
    echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║                  ALL STOCK DATA IS 100% CONSISTENT!                          ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
} else {
    echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║                SOME ISSUES REMAIN - MANUAL REVIEW NEEDED                     ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
}
