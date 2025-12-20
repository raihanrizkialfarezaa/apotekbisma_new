<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "╔═══════════════════════════════════════════════════════════════════╗\n";
echo "║     SUPER ULTRA ROBUST FINAL VERIFICATION - 100% ACCURACY         ║\n";
echo "╚═══════════════════════════════════════════════════════════════════╝\n\n";

$allPassed = true;
$testResults = [];

echo "TEST 1: Transaction Count Match\n";
echo "────────────────────────────────\n";

$pdCount = DB::select("SELECT COUNT(*) as cnt FROM penjualan_detail pd JOIN penjualan p ON pd.id_penjualan = p.id_penjualan")[0]->cnt;
$bdCount = DB::select("SELECT COUNT(*) as cnt FROM pembelian_detail pd JOIN pembelian b ON pd.id_pembelian = b.id_pembelian")[0]->cnt;
$rsPenjualan = DB::table('rekaman_stoks')->whereNotNull('id_penjualan')->count();
$rsPembelian = DB::table('rekaman_stoks')->whereNotNull('id_pembelian')->count();

$test1 = ($pdCount == $rsPenjualan) && ($bdCount == $rsPembelian);
$testResults['Transaction Count'] = $test1;
echo "  Penjualan: {$pdCount} details = {$rsPenjualan} RS records " . ($pdCount == $rsPenjualan ? "✓" : "✗") . "\n";
echo "  Pembelian: {$bdCount} details = {$rsPembelian} RS records " . ($bdCount == $rsPembelian ? "✓" : "✗") . "\n";

echo "\nTEST 2: Quantity Accuracy (Grouped by Transaction+Product)\n";
echo "───────────────────────────────────────────────────────────\n";

$qtyMismatchP = DB::select("
    SELECT COUNT(*) as cnt FROM (
        SELECT pd.id_penjualan, pd.id_produk, SUM(pd.jumlah) as pd_qty
        FROM penjualan_detail pd
        JOIN penjualan p ON pd.id_penjualan = p.id_penjualan
        GROUP BY pd.id_penjualan, pd.id_produk
    ) AS pd_sum
    LEFT JOIN (
        SELECT id_penjualan, id_produk, SUM(stok_keluar) as rs_qty
        FROM rekaman_stoks WHERE id_penjualan IS NOT NULL
        GROUP BY id_penjualan, id_produk
    ) AS rs_sum ON pd_sum.id_penjualan = rs_sum.id_penjualan AND pd_sum.id_produk = rs_sum.id_produk
    WHERE rs_sum.rs_qty IS NULL OR pd_sum.pd_qty != rs_sum.rs_qty
")[0]->cnt;

$qtyMismatchB = DB::select("
    SELECT COUNT(*) as cnt FROM (
        SELECT pd.id_pembelian, pd.id_produk, SUM(pd.jumlah) as bd_qty
        FROM pembelian_detail pd
        JOIN pembelian b ON pd.id_pembelian = b.id_pembelian
        GROUP BY pd.id_pembelian, pd.id_produk
    ) AS bd_sum
    LEFT JOIN (
        SELECT id_pembelian, id_produk, SUM(stok_masuk) as rs_qty
        FROM rekaman_stoks WHERE id_pembelian IS NOT NULL
        GROUP BY id_pembelian, id_produk
    ) AS rs_sum ON bd_sum.id_pembelian = rs_sum.id_pembelian AND bd_sum.id_produk = rs_sum.id_produk
    WHERE rs_sum.rs_qty IS NULL OR bd_sum.bd_qty != rs_sum.rs_qty
")[0]->cnt;

$test2 = ($qtyMismatchP == 0) && ($qtyMismatchB == 0);
$testResults['Quantity Accuracy'] = $test2;
echo "  Penjualan qty mismatches: {$qtyMismatchP} " . ($qtyMismatchP == 0 ? "✓" : "✗") . "\n";
echo "  Pembelian qty mismatches: {$qtyMismatchB} " . ($qtyMismatchB == 0 ? "✓" : "✗") . "\n";

echo "\nTEST 3: Waktu (Date) Accuracy\n";
echo "─────────────────────────────\n";

$waktuMismatchP = DB::select("
    SELECT COUNT(*) as cnt FROM rekaman_stoks rs
    JOIN penjualan p ON rs.id_penjualan = p.id_penjualan
    WHERE rs.waktu != p.waktu
")[0]->cnt;

$waktuMismatchB = DB::select("
    SELECT COUNT(*) as cnt FROM rekaman_stoks rs
    JOIN pembelian b ON rs.id_pembelian = b.id_pembelian
    WHERE rs.waktu != b.waktu
")[0]->cnt;

$test3 = ($waktuMismatchP == 0) && ($waktuMismatchB == 0);
$testResults['Waktu Accuracy'] = $test3;
echo "  Penjualan waktu mismatches: {$waktuMismatchP} " . ($waktuMismatchP == 0 ? "✓" : "✗") . "\n";
echo "  Pembelian waktu mismatches: {$waktuMismatchB} " . ($waktuMismatchB == 0 ? "✓" : "✗") . "\n";

echo "\nTEST 4: Running Balance Consistency\n";
echo "────────────────────────────────────\n";

$balanceIssues = 0;
$products = DB::table('produk')->select('id_produk', 'nama_produk', 'stok')->get();

foreach ($products as $prod) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $prod->id_produk)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($records->isEmpty()) continue;
    
    $running = $records->first()->stok_awal;
    
    foreach ($records as $r) {
        if ($r->stok_awal != $running) {
            $balanceIssues++;
            break;
        }
        $calc = $running + $r->stok_masuk - $r->stok_keluar;
        if ($r->stok_sisa != $calc) {
            $balanceIssues++;
            break;
        }
        $running = $calc;
    }
}

$test4 = ($balanceIssues == 0);
$testResults['Running Balance'] = $test4;
echo "  Products with balance issues: {$balanceIssues} " . ($balanceIssues == 0 ? "✓" : "✗") . "\n";

echo "\nTEST 5: Final Stock Match\n";
echo "─────────────────────────\n";

$stockMismatch = 0;
foreach ($products as $prod) {
    $lastRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $prod->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('created_at', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRecord) {
        if ($lastRecord->stok_sisa != $prod->stok) {
            $stockMismatch++;
        }
    } else {
        if ($prod->stok != 0) {
            $stockMismatch++;
        }
    }
}

$test5 = ($stockMismatch == 0);
$testResults['Final Stock Match'] = $test5;
echo "  Products with final stock mismatch: {$stockMismatch} " . ($stockMismatch == 0 ? "✓" : "✗") . "\n";

echo "\nTEST 6: No Negative Stock\n";
echo "─────────────────────────\n";

$negativeCount = DB::table('rekaman_stoks')->where('stok_sisa', '<', 0)->count();
$test6 = ($negativeCount == 0);
$testResults['No Negative Stock'] = $test6;
echo "  Records with negative stok_sisa: {$negativeCount} " . ($negativeCount == 0 ? "✓" : "✗") . "\n";

echo "\nTEST 7: No Orphan Records\n";
echo "─────────────────────────\n";

$orphanRS = DB::table('rekaman_stoks')
    ->whereNull('id_penjualan')
    ->whereNull('id_pembelian')
    ->count();
$test7 = ($orphanRS == 0);
$testResults['No Orphan RS'] = $test7;
echo "  Orphan rekaman_stoks (no transaction link): {$orphanRS} " . ($orphanRS == 0 ? "✓" : "✗") . "\n";

echo "\nTEST 8: Sample Kartu Stok Verification (Random 10 Products)\n";
echo "────────────────────────────────────────────────────────────\n";

$sampleProducts = DB::table('produk')->inRandomOrder()->limit(10)->get();
$sampleValid = 0;

foreach ($sampleProducts as $sp) {
    $totalMasuk = DB::select("
        SELECT COALESCE(SUM(pd.jumlah), 0) as total
        FROM pembelian_detail pd
        JOIN pembelian b ON pd.id_pembelian = b.id_pembelian
        WHERE pd.id_produk = ?
    ", [$sp->id_produk])[0]->total;
    
    $totalKeluar = DB::select("
        SELECT COALESCE(SUM(pd.jumlah), 0) as total
        FROM penjualan_detail pd
        JOIN penjualan p ON pd.id_penjualan = p.id_penjualan
        WHERE pd.id_produk = ?
    ", [$sp->id_produk])[0]->total;
    
    $rsTotalMasuk = DB::table('rekaman_stoks')
        ->where('id_produk', $sp->id_produk)
        ->sum('stok_masuk');
    
    $rsTotalKeluar = DB::table('rekaman_stoks')
        ->where('id_produk', $sp->id_produk)
        ->sum('stok_keluar');
    
    $firstRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $sp->id_produk)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->first();
    
    $initialStock = $firstRecord ? $firstRecord->stok_awal : 0;
    $calculatedStock = $initialStock + $totalMasuk - $totalKeluar;
    
    $valid = ($totalMasuk == $rsTotalMasuk) && ($totalKeluar == $rsTotalKeluar) && ($calculatedStock == $sp->stok);
    
    if ($valid) $sampleValid++;
    
    $status = $valid ? "✓" : "✗";
    echo "  {$status} {$sp->nama_produk}: Init:{$initialStock} +{$totalMasuk} -{$totalKeluar} = {$calculatedStock} (DB: {$sp->stok})\n";
}

$test8 = ($sampleValid == 10);
$testResults['Sample Verification'] = $test8;

echo "\n╔═══════════════════════════════════════════════════════════════════╗\n";
echo "║                        FINAL RESULTS                               ║\n";
echo "╚═══════════════════════════════════════════════════════════════════╝\n\n";

$passedCount = 0;
foreach ($testResults as $name => $passed) {
    $status = $passed ? "PASS ✓" : "FAIL ✗";
    echo "  {$name}: {$status}\n";
    if ($passed) $passedCount++;
}

echo "\n";
if ($passedCount == count($testResults)) {
    echo "  ╔═══════════════════════════════════════════════════════════════╗\n";
    echo "  ║   ✓✓✓  ALL 8 TESTS PASSED - DATA 100% ACCURATE!  ✓✓✓         ║\n";
    echo "  ╚═══════════════════════════════════════════════════════════════╝\n";
} else {
    echo "  ⚠ {$passedCount}/" . count($testResults) . " tests passed. Issues remain.\n";
}
