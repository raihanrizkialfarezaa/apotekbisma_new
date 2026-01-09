<?php
/**
 * ============================================================================
 * DETAILED DIAGNOSIS OF ALL STOCK ISSUES
 * ============================================================================
 * 
 * Analisis detail untuk semua masalah stok:
 * - 2 Gap issues antara 2025-2026
 * - 2 Continuity errors
 * - 125 produk_rekaman_mismatch
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

set_time_limit(300);
ini_set('memory_limit', '512M');

echo "=======================================================\n";
echo "  DETAILED DIAGNOSIS OF ALL STOCK ISSUES\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

$cutoffDate = '2025-12-31 23:59:59';

// Load opname data
$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$opnameData = [];
$handle = fopen($csvFile, 'r');
$header = fgetcsv($handle);
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) >= 3 && !empty($row[0])) {
        $opnameData[intval($row[0])] = [
            'nama' => $row[1],
            'stok' => intval($row[2])
        ];
    }
}
fclose($handle);

echo "Loaded " . count($opnameData) . " products from CSV\n\n";

// ============================================================================
// DIAGNOSIS 1: GAP ISSUES BETWEEN 2025-2026
// ============================================================================
echo "=======================================================\n";
echo "  DIAGNOSIS 1: GAP ISSUES (2025-2026)\n";
echo "=======================================================\n\n";

$gapIssues = [];
foreach ($opnameData as $productId => $opname) {
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '<=', $cutoffDate)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $firstAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->first();
    
    if ($lastBefore && $firstAfter && intval($firstAfter->stok_awal) != intval($lastBefore->stok_sisa)) {
        $gapIssues[] = [
            'id' => $productId,
            'nama' => $opname['nama'],
            'last_waktu' => $lastBefore->waktu,
            'last_sisa' => $lastBefore->stok_sisa,
            'last_id' => $lastBefore->id_rekaman_stok,
            'first_waktu' => $firstAfter->waktu,
            'first_awal' => $firstAfter->stok_awal,
            'first_id' => $firstAfter->id_rekaman_stok,
            'gap' => intval($firstAfter->stok_awal) - intval($lastBefore->stok_sisa)
        ];
    }
}

echo "Found " . count($gapIssues) . " gap issues:\n\n";

foreach ($gapIssues as $issue) {
    echo "Product ID: {$issue['id']} - {$issue['nama']}\n";
    echo "  Last 2025: ID={$issue['last_id']}, waktu={$issue['last_waktu']}, stok_sisa={$issue['last_sisa']}\n";
    echo "  First 2026: ID={$issue['first_id']}, waktu={$issue['first_waktu']}, stok_awal={$issue['first_awal']}\n";
    echo "  Gap: {$issue['gap']}\n\n";
}

// ============================================================================
// DIAGNOSIS 2: CONTINUITY ERRORS
// ============================================================================
echo "=======================================================\n";
echo "  DIAGNOSIS 2: CONTINUITY ERRORS\n";
echo "=======================================================\n\n";

$allProductIds = DB::table('rekaman_stoks')
    ->distinct()
    ->pluck('id_produk')
    ->toArray();

$continuityIssues = [];

foreach ($allProductIds as $productId) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    $prevSisa = null;
    $prevId = null;
    $prevWaktu = null;
    
    foreach ($records as $r) {
        if ($prevSisa !== null && intval($r->stok_awal) != intval($prevSisa)) {
            $productName = $opnameData[$productId]['nama'] ?? DB::table('produk')->where('id_produk', $productId)->value('nama_produk');
            $continuityIssues[] = [
                'product_id' => $productId,
                'product_name' => $productName,
                'prev_id' => $prevId,
                'prev_waktu' => $prevWaktu,
                'prev_sisa' => $prevSisa,
                'curr_id' => $r->id_rekaman_stok,
                'curr_waktu' => $r->waktu,
                'curr_awal' => $r->stok_awal,
                'difference' => intval($r->stok_awal) - intval($prevSisa)
            ];
            break;
        }
        $prevSisa = $r->stok_sisa;
        $prevId = $r->id_rekaman_stok;
        $prevWaktu = $r->waktu;
    }
}

echo "Found " . count($continuityIssues) . " continuity errors:\n\n";

foreach ($continuityIssues as $issue) {
    echo "Product ID: {$issue['product_id']} - {$issue['product_name']}\n";
    echo "  Record {$issue['prev_id']} (waktu={$issue['prev_waktu']}): stok_sisa={$issue['prev_sisa']}\n";
    echo "  Record {$issue['curr_id']} (waktu={$issue['curr_waktu']}): stok_awal={$issue['curr_awal']}\n";
    echo "  Difference: {$issue['difference']}\n\n";
}

// ============================================================================
// DIAGNOSIS 3: PRODUK-REKAMAN MISMATCH
// ============================================================================
echo "=======================================================\n";
echo "  DIAGNOSIS 3: PRODUK-REKAMAN MISMATCH\n";
echo "=======================================================\n\n";

$products = DB::table('produk')->get();
$mismatchIssues = [];

foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('created_at', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman && intval($product->stok) != intval($lastRekaman->stok_sisa)) {
        $mismatchIssues[] = [
            'product_id' => $product->id_produk,
            'product_name' => $product->nama_produk,
            'produk_stok' => intval($product->stok),
            'rekaman_sisa' => intval($lastRekaman->stok_sisa),
            'rekaman_id' => $lastRekaman->id_rekaman_stok,
            'rekaman_waktu' => $lastRekaman->waktu,
            'difference' => intval($product->stok) - intval($lastRekaman->stok_sisa)
        ];
    }
}

echo "Found " . count($mismatchIssues) . " mismatch issues:\n\n";

$showCount = min(20, count($mismatchIssues));
echo "Showing first {$showCount} mismatches:\n\n";

for ($i = 0; $i < $showCount; $i++) {
    $issue = $mismatchIssues[$i];
    echo "[{$issue['product_id']}] {$issue['product_name']}\n";
    echo "  produk.stok = {$issue['produk_stok']}\n";
    echo "  rekaman_stoks.stok_sisa = {$issue['rekaman_sisa']} (ID={$issue['rekaman_id']}, waktu={$issue['rekaman_waktu']})\n";
    echo "  Difference: {$issue['difference']}\n\n";
}

if (count($mismatchIssues) > 20) {
    echo "... dan " . (count($mismatchIssues) - 20) . " produk lainnya\n\n";
}

// ============================================================================
// ROOT CAUSE ANALYSIS
// ============================================================================
echo "=======================================================\n";
echo "  ROOT CAUSE ANALYSIS\n";
echo "=======================================================\n\n";

// Check if the gap issues are in different products from continuity
$gapProductIds = array_column($gapIssues, 'id');
$continuityProductIds = array_column($continuityIssues, 'product_id');
$overlap = array_intersect($gapProductIds, $continuityProductIds);

echo "Gap issues products: " . implode(', ', $gapProductIds) . "\n";
echo "Continuity issues products: " . implode(', ', $continuityProductIds) . "\n";
echo "Overlap: " . (empty($overlap) ? 'None' : implode(', ', $overlap)) . "\n\n";

// Check ordering used in verification vs recalculation
echo "Investigating ordering inconsistencies...\n\n";

foreach ($gapIssues as $issue) {
    $productId = $issue['id'];
    
    echo "Product {$productId}:\n";
    
    // Get records around cutoff with different orderings
    $recordsStandard = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '>=', '2025-12-31 00:00:00')
        ->where('waktu', '<=', '2026-01-01 12:00:00')
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    echo "  Records around cutoff (ordered by waktu, id):\n";
    foreach ($recordsStandard as $r) {
        echo "    ID={$r->id_rekaman_stok}, waktu={$r->waktu}, awal={$r->stok_awal}, masuk={$r->stok_masuk}, keluar={$r->stok_keluar}, sisa={$r->stok_sisa}\n";
        if ($r->keterangan) {
            echo "      ket: " . substr($r->keterangan, 0, 60) . "...\n";
        }
    }
    echo "\n";
}

// ============================================================================
// SUMMARY
// ============================================================================
echo "=======================================================\n";
echo "  SUMMARY\n";
echo "=======================================================\n\n";

echo "Total Issues: " . (count($gapIssues) + count($continuityIssues) + count($mismatchIssues)) . "\n";
echo "  - Gap 2025-2026: " . count($gapIssues) . "\n";
echo "  - Continuity Errors: " . count($continuityIssues) . "\n";
echo "  - Produk-Rekaman Mismatch: " . count($mismatchIssues) . "\n\n";

// Store for later fix script
file_put_contents(__DIR__ . '/diagnosis_gap_issues.json', json_encode($gapIssues, JSON_PRETTY_PRINT));
file_put_contents(__DIR__ . '/diagnosis_continuity_issues.json', json_encode($continuityIssues, JSON_PRETTY_PRINT));
file_put_contents(__DIR__ . '/diagnosis_mismatch_issues.json', json_encode($mismatchIssues, JSON_PRETTY_PRINT));

echo "Diagnosis data saved to JSON files for further analysis.\n";
echo "\nDone.\n";
