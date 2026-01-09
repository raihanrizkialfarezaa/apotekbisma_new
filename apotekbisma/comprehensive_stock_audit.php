<?php
require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║           COMPREHENSIVE STOCK AUDIT - ALL PRODUCTS                           ║\n";
echo "║           Date: " . date('Y-m-d H:i:s') . "                                      ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";

$cutoffDate = '2025-12-31 23:59:59';
$stockOpnameData = [];

$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
if (file_exists($csvFile)) {
    $handle = fopen($csvFile, 'r');
    $header = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) >= 3) {
            $stockOpnameData[intval($row[0])] = [
                'nama' => $row[1],
                'stok_opname' => intval($row[2])
            ];
        }
    }
    fclose($handle);
    echo "[INFO] Loaded " . count($stockOpnameData) . " products from stock opname file\n\n";
}

$allIssues = [];
$issueTypes = [
    'produk_vs_rekaman_mismatch' => [],
    'calculation_errors' => [],
    'stock_gaps' => [],
    'opname_vs_calculated_mismatch' => [],
    'missing_rekaman_for_transactions' => [],
    'duplicate_rekaman' => [],
    'negative_stock' => [],
    'timeline_anomaly' => []
];

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "STEP 1: Checking produk.stok vs last rekaman_stoks.stok_sisa\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

$products = DB::table('produk')->orderBy('nama_produk')->get();

foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('created_at', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman) {
        $rekamanStock = intval($lastRekaman->stok_sisa);
        $produkStock = intval($product->stok);
        
        if ($rekamanStock !== $produkStock) {
            $issueTypes['produk_vs_rekaman_mismatch'][] = [
                'id_produk' => $product->id_produk,
                'nama_produk' => $product->nama_produk,
                'produk_stok' => $produkStock,
                'rekaman_stok_sisa' => $rekamanStock,
                'selisih' => $produkStock - $rekamanStock
            ];
        }
    }
}

if (!empty($issueTypes['produk_vs_rekaman_mismatch'])) {
    echo "[ISSUE TYPE 1] " . count($issueTypes['produk_vs_rekaman_mismatch']) . " products with produk.stok != last rekaman.stok_sisa:\n\n";
    foreach ($issueTypes['produk_vs_rekaman_mismatch'] as $issue) {
        echo "  - [{$issue['id_produk']}] {$issue['nama_produk']}\n";
        echo "    produk.stok: {$issue['produk_stok']}, rekaman.stok_sisa: {$issue['rekaman_stok_sisa']}, Selisih: {$issue['selisih']}\n\n";
    }
} else {
    echo "[OK] All products have matching produk.stok and last rekaman.stok_sisa\n\n";
}

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "STEP 2: Checking calculation errors in rekaman_stoks (stok_awal + masuk - keluar != sisa)\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

$allRekaman = DB::table('rekaman_stoks')->orderBy('id_produk')->orderBy('waktu')->get();
$calcErrors = [];

foreach ($allRekaman as $r) {
    $expected = intval($r->stok_awal) + intval($r->stok_masuk) - intval($r->stok_keluar);
    $actual = intval($r->stok_sisa);
    
    if ($expected !== $actual) {
        $product = DB::table('produk')->where('id_produk', $r->id_produk)->first();
        $calcErrors[] = [
            'id_rekaman' => $r->id_rekaman_stok,
            'id_produk' => $r->id_produk,
            'nama_produk' => $product ? $product->nama_produk : 'Unknown',
            'waktu' => $r->waktu,
            'stok_awal' => $r->stok_awal,
            'stok_masuk' => $r->stok_masuk,
            'stok_keluar' => $r->stok_keluar,
            'expected_sisa' => $expected,
            'actual_sisa' => $actual,
            'keterangan' => $r->keterangan
        ];
    }
}

$issueTypes['calculation_errors'] = $calcErrors;

if (!empty($calcErrors)) {
    echo "[ISSUE TYPE 2] " . count($calcErrors) . " records with calculation errors:\n\n";
    foreach (array_slice($calcErrors, 0, 20) as $error) {
        echo "  - Rekaman #{$error['id_rekaman']} [{$error['id_produk']}] {$error['nama_produk']}\n";
        echo "    Waktu: {$error['waktu']}\n";
        echo "    Formula: {$error['stok_awal']} + {$error['stok_masuk']} - {$error['stok_keluar']} = {$error['expected_sisa']} (expected)\n";
        echo "    Actual stok_sisa: {$error['actual_sisa']}, Selisih: " . ($error['actual_sisa'] - $error['expected_sisa']) . "\n\n";
    }
    if (count($calcErrors) > 20) {
        echo "  ... and " . (count($calcErrors) - 20) . " more errors\n\n";
    }
} else {
    echo "[OK] All rekaman_stoks records have correct calculations\n\n";
}

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "STEP 3: Checking stock continuity (stok_awal should match previous stok_sisa)\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

$productIds = DB::table('rekaman_stoks')->distinct()->pluck('id_produk');
$gapIssues = [];

foreach ($productIds as $productId) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    $prevSisa = null;
    foreach ($records as $r) {
        if ($prevSisa !== null) {
            if (intval($r->stok_awal) !== intval($prevSisa)) {
                $product = DB::table('produk')->where('id_produk', $productId)->first();
                $gapIssues[] = [
                    'id_rekaman' => $r->id_rekaman_stok,
                    'id_produk' => $productId,
                    'nama_produk' => $product ? $product->nama_produk : 'Unknown',
                    'waktu' => $r->waktu,
                    'expected_stok_awal' => $prevSisa,
                    'actual_stok_awal' => $r->stok_awal,
                    'gap' => intval($r->stok_awal) - intval($prevSisa),
                    'keterangan' => $r->keterangan
                ];
            }
        }
        $prevSisa = $r->stok_sisa;
    }
}

$issueTypes['stock_gaps'] = $gapIssues;

if (!empty($gapIssues)) {
    echo "[ISSUE TYPE 3] " . count($gapIssues) . " records with stock continuity gaps:\n\n";
    foreach (array_slice($gapIssues, 0, 20) as $gap) {
        echo "  - Rekaman #{$gap['id_rekaman']} [{$gap['id_produk']}] {$gap['nama_produk']}\n";
        echo "    Waktu: {$gap['waktu']}\n";
        echo "    Expected stok_awal: {$gap['expected_stok_awal']}, Actual: {$gap['actual_stok_awal']}, Gap: {$gap['gap']}\n";
        echo "    Keterangan: {$gap['keterangan']}\n\n";
    }
    if (count($gapIssues) > 20) {
        echo "  ... and " . (count($gapIssues) - 20) . " more gaps\n\n";
    }
} else {
    echo "[OK] All rekaman_stoks records have proper continuity\n\n";
}

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "STEP 4: Checking stock opname vs calculated stock (31 Dec 2025 + subsequent transactions)\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

$opnameMismatches = [];

foreach ($stockOpnameData as $productId => $opname) {
    $product = DB::table('produk')->where('id_produk', $productId)->first();
    if (!$product) continue;
    
    $transactionsAfterCutoff = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->get();
    
    $totalMasukAfter = $transactionsAfterCutoff->sum('stok_masuk');
    $totalKeluarAfter = $transactionsAfterCutoff->sum('stok_keluar');
    
    $expectedStock = $opname['stok_opname'] + $totalMasukAfter - $totalKeluarAfter;
    $actualStock = intval($product->stok);
    
    if ($expectedStock !== $actualStock) {
        $lastRekaman = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->orderBy('waktu', 'desc')
            ->first();
        
        $opnameMismatches[] = [
            'id_produk' => $productId,
            'nama_produk' => $product->nama_produk,
            'stok_opname_31dec' => $opname['stok_opname'],
            'total_masuk_after' => $totalMasukAfter,
            'total_keluar_after' => $totalKeluarAfter,
            'expected_stock' => $expectedStock,
            'actual_produk_stok' => $actualStock,
            'last_rekaman_sisa' => $lastRekaman ? $lastRekaman->stok_sisa : 'N/A',
            'selisih' => $actualStock - $expectedStock
        ];
    }
}

$issueTypes['opname_vs_calculated_mismatch'] = $opnameMismatches;

if (!empty($opnameMismatches)) {
    echo "[ISSUE TYPE 4] " . count($opnameMismatches) . " products with stock opname mismatch:\n\n";
    foreach ($opnameMismatches as $m) {
        echo "  - [{$m['id_produk']}] {$m['nama_produk']}\n";
        echo "    Stock Opname (31 Dec 2025): {$m['stok_opname_31dec']}\n";
        echo "    After cutoff: +{$m['total_masuk_after']} (masuk), -{$m['total_keluar_after']} (keluar)\n";
        echo "    Expected: {$m['expected_stock']}, Actual produk.stok: {$m['actual_produk_stok']}\n";
        echo "    Last rekaman.stok_sisa: {$m['last_rekaman_sisa']}\n";
        echo "    SELISIH: {$m['selisih']}\n\n";
    }
} else {
    echo "[OK] All products match stock opname + subsequent transactions\n\n";
}

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "STEP 5: Checking for duplicate rekaman_stoks entries\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

$penjualanDuplicates = DB::select("
    SELECT id_produk, id_penjualan, COUNT(*) as cnt, GROUP_CONCAT(id_rekaman_stok) as rekaman_ids
    FROM rekaman_stoks 
    WHERE id_penjualan IS NOT NULL 
    GROUP BY id_produk, id_penjualan 
    HAVING COUNT(*) > 1
");

$pembelianDuplicates = DB::select("
    SELECT id_produk, id_pembelian, COUNT(*) as cnt, GROUP_CONCAT(id_rekaman_stok) as rekaman_ids
    FROM rekaman_stoks 
    WHERE id_pembelian IS NOT NULL 
    GROUP BY id_produk, id_pembelian 
    HAVING COUNT(*) > 1
");

if (!empty($penjualanDuplicates) || !empty($pembelianDuplicates)) {
    echo "[ISSUE TYPE 5] Duplicate rekaman entries found:\n\n";
    
    if (!empty($penjualanDuplicates)) {
        echo "  Duplicate Penjualan entries:\n";
        foreach ($penjualanDuplicates as $d) {
            $product = DB::table('produk')->where('id_produk', $d->id_produk)->first();
            echo "    - Product [{$d->id_produk}] " . ($product ? $product->nama_produk : 'Unknown') . "\n";
            echo "      Penjualan ID: {$d->id_penjualan}, Count: {$d->cnt}, Rekaman IDs: {$d->rekaman_ids}\n";
            $issueTypes['duplicate_rekaman'][] = [
                'type' => 'penjualan',
                'id_produk' => $d->id_produk,
                'id_penjualan' => $d->id_penjualan,
                'count' => $d->cnt,
                'rekaman_ids' => $d->rekaman_ids
            ];
        }
    }
    
    if (!empty($pembelianDuplicates)) {
        echo "\n  Duplicate Pembelian entries:\n";
        foreach ($pembelianDuplicates as $d) {
            $product = DB::table('produk')->where('id_produk', $d->id_produk)->first();
            echo "    - Product [{$d->id_produk}] " . ($product ? $product->nama_produk : 'Unknown') . "\n";
            echo "      Pembelian ID: {$d->id_pembelian}, Count: {$d->cnt}, Rekaman IDs: {$d->rekaman_ids}\n";
            $issueTypes['duplicate_rekaman'][] = [
                'type' => 'pembelian',
                'id_produk' => $d->id_produk,
                'id_pembelian' => $d->id_pembelian,
                'count' => $d->cnt,
                'rekaman_ids' => $d->rekaman_ids
            ];
        }
    }
    echo "\n";
} else {
    echo "[OK] No duplicate rekaman entries found\n\n";
}

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "STEP 6: Checking for negative stock in rekaman_stoks\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

$negativeStocks = DB::table('rekaman_stoks')
    ->where('stok_sisa', '<', 0)
    ->orWhere('stok_awal', '<', 0)
    ->get();

if ($negativeStocks->isNotEmpty()) {
    echo "[ISSUE TYPE 6] " . $negativeStocks->count() . " records with negative stock:\n\n";
    foreach ($negativeStocks as $ns) {
        $product = DB::table('produk')->where('id_produk', $ns->id_produk)->first();
        echo "  - Rekaman #{$ns->id_rekaman_stok} [{$ns->id_produk}] " . ($product ? $product->nama_produk : 'Unknown') . "\n";
        echo "    stok_awal: {$ns->stok_awal}, stok_masuk: {$ns->stok_masuk}, stok_keluar: {$ns->stok_keluar}, stok_sisa: {$ns->stok_sisa}\n";
        echo "    Waktu: {$ns->waktu}, Keterangan: {$ns->keterangan}\n\n";
        $issueTypes['negative_stock'][] = [
            'id_rekaman' => $ns->id_rekaman_stok,
            'id_produk' => $ns->id_produk,
            'stok_awal' => $ns->stok_awal,
            'stok_sisa' => $ns->stok_sisa
        ];
    }
} else {
    echo "[OK] No negative stock found in rekaman_stoks\n\n";
}

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "STEP 7: Deep analysis of specific problem products (Demacolin Tab, Amoxicilin HJ)\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

$problemProducts = [
    ['id' => 204, 'name' => 'DEMACOLIN TAB'],
    ['id' => 994, 'name' => 'AMOXICILIN 500mg HJ']
];

foreach ($problemProducts as $pp) {
    $product = DB::table('produk')->where('id_produk', $pp['id'])->first();
    
    if (!$product) {
        echo "Product ID {$pp['id']} ({$pp['name']}) not found in database\n\n";
        continue;
    }
    
    echo "══════════════════════════════════════════════════════════════════════════\n";
    echo "DEEP ANALYSIS: [{$product->id_produk}] {$product->nama_produk}\n";
    echo "══════════════════════════════════════════════════════════════════════════\n\n";
    
    echo "Current produk.stok: {$product->stok}\n";
    
    if (isset($stockOpnameData[$product->id_produk])) {
        echo "Stock Opname (31 Dec 2025): {$stockOpnameData[$product->id_produk]['stok_opname']}\n";
    } else {
        echo "Stock Opname (31 Dec 2025): NOT FOUND in opname file\n";
    }
    
    $allRecords = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    echo "\nTotal rekaman_stoks records: {$allRecords->count()}\n";
    
    $penjualanDetails = DB::table('penjualan_detail')
        ->where('id_produk', $product->id_produk)
        ->count();
    
    $pembelianDetails = DB::table('pembelian_detail')
        ->where('id_produk', $product->id_produk)
        ->count();
    
    echo "Total penjualan_detail records: {$penjualanDetails}\n";
    echo "Total pembelian_detail records: {$pembelianDetails}\n";
    echo "Total transaction records expected: " . ($penjualanDetails + $pembelianDetails) . "\n";
    
    if ($allRecords->count() != ($penjualanDetails + $pembelianDetails)) {
        $manualAdjustments = $allRecords->filter(function($r) {
            return empty($r->id_penjualan) && empty($r->id_pembelian);
        })->count();
        
        $withPenjualan = $allRecords->whereNotNull('id_penjualan')->count();
        $withPembelian = $allRecords->whereNotNull('id_pembelian')->count();
        
        echo "\nBreakdown of rekaman_stoks:\n";
        echo "  - With id_penjualan: {$withPenjualan}\n";
        echo "  - With id_pembelian: {$withPembelian}\n";
        echo "  - Manual adjustments (no transaction link): {$manualAdjustments}\n";
    }
    
    echo "\n--- STOCK CARD TIMELINE (chronological) ---\n\n";
    echo sprintf("%-4s | %-20s | %-10s | %-10s | %-10s | %-10s | %-10s | %s\n",
        "No", "Waktu", "Awal", "Masuk", "Keluar", "Sisa", "Expected", "Keterangan");
    echo str_repeat("-", 120) . "\n";
    
    $prevSisa = null;
    $no = 1;
    $hasIssue = false;
    
    foreach ($allRecords as $r) {
        $expectedAwal = $prevSisa !== null ? $prevSisa : $r->stok_awal;
        $expectedSisa = intval($r->stok_awal) + intval($r->stok_masuk) - intval($r->stok_keluar);
        
        $awalMismatch = ($prevSisa !== null && intval($r->stok_awal) != intval($prevSisa));
        $sisaMismatch = (intval($r->stok_sisa) != $expectedSisa);
        
        $awalDisplay = $awalMismatch ? "*{$r->stok_awal}" : $r->stok_awal;
        $sisaDisplay = $sisaMismatch ? "*{$r->stok_sisa}" : $r->stok_sisa;
        
        if ($awalMismatch || $sisaMismatch) {
            $hasIssue = true;
        }
        
        $keteranganShort = substr($r->keterangan ?? '-', 0, 30);
        
        echo sprintf("%-4d | %-20s | %-10s | %-10s | %-10s | %-10s | %-10d | %s\n",
            $no,
            $r->waktu,
            $awalDisplay,
            $r->stok_masuk ?: '-',
            $r->stok_keluar ?: '-',
            $sisaDisplay,
            $expectedSisa,
            $keteranganShort);
        
        if ($awalMismatch) {
            echo "    ^^^ ISSUE: stok_awal should be {$prevSisa} (gap: " . (intval($r->stok_awal) - intval($prevSisa)) . ")\n";
        }
        if ($sisaMismatch) {
            echo "    ^^^ ISSUE: stok_sisa should be {$expectedSisa} (error: " . (intval($r->stok_sisa) - $expectedSisa) . ")\n";
        }
        
        $prevSisa = $r->stok_sisa;
        $no++;
        
        if ($no > 30) {
            echo "\n... (showing first 30 records, total: {$allRecords->count()})\n";
            break;
        }
    }
    
    $lastRecord = $allRecords->last();
    if ($lastRecord) {
        echo "\nLast rekaman stok_sisa: {$lastRecord->stok_sisa}\n";
        echo "Current produk.stok: {$product->stok}\n";
        
        if (intval($lastRecord->stok_sisa) != intval($product->stok)) {
            echo "*** MISMATCH: produk.stok does not match last rekaman.stok_sisa! ***\n";
        }
    }
    
    if (isset($stockOpnameData[$product->id_produk])) {
        $opnameStock = $stockOpnameData[$product->id_produk]['stok_opname'];
        
        $recordsUntilCutoff = DB::table('rekaman_stoks')
            ->where('id_produk', $product->id_produk)
            ->where('waktu', '<=', $cutoffDate)
            ->orderBy('waktu', 'desc')
            ->first();
        
        $recordsAfterCutoff = DB::table('rekaman_stoks')
            ->where('id_produk', $product->id_produk)
            ->where('waktu', '>', $cutoffDate)
            ->get();
        
        echo "\n--- STOCK OPNAME ANALYSIS ---\n";
        echo "Stock Opname (31 Dec 2025): {$opnameStock}\n";
        
        if ($recordsUntilCutoff) {
            echo "Last rekaman at/before cutoff (stok_sisa): {$recordsUntilCutoff->stok_sisa}\n";
            if (intval($recordsUntilCutoff->stok_sisa) != $opnameStock) {
                echo "*** DISCREPANCY: rekaman at cutoff ({$recordsUntilCutoff->stok_sisa}) != opname ({$opnameStock})\n";
                echo "    Difference: " . (intval($recordsUntilCutoff->stok_sisa) - $opnameStock) . "\n";
            }
        } else {
            echo "No rekaman found at or before cutoff date\n";
        }
        
        echo "\nTransactions AFTER cutoff (2026):\n";
        $totalMasukAfter = $recordsAfterCutoff->sum('stok_masuk');
        $totalKeluarAfter = $recordsAfterCutoff->sum('stok_keluar');
        echo "  Total Masuk: {$totalMasukAfter}\n";
        echo "  Total Keluar: {$totalKeluarAfter}\n";
        echo "  Net Change: " . ($totalMasukAfter - $totalKeluarAfter) . "\n";
        
        $expectedFromOpname = $opnameStock + $totalMasukAfter - $totalKeluarAfter;
        echo "\nExpected current stock (opname + net change): {$expectedFromOpname}\n";
        echo "Actual produk.stok: {$product->stok}\n";
        
        if ($expectedFromOpname != intval($product->stok)) {
            echo "*** MAJOR DISCREPANCY: Expected {$expectedFromOpname}, Got {$product->stok}\n";
            echo "    Difference: " . (intval($product->stok) - $expectedFromOpname) . "\n";
        }
    }
    
    echo "\n";
}

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "SUMMARY OF ALL ISSUES FOUND\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

$totalIssues = 0;
foreach ($issueTypes as $type => $issues) {
    $count = count($issues);
    $totalIssues += $count;
    echo "  " . str_pad($type, 40) . ": " . $count . " issues\n";
}

echo "\n  TOTAL ISSUES: {$totalIssues}\n\n";

$reportFile = __DIR__ . '/stock_audit_report_' . date('Y-m-d_His') . '.json';
file_put_contents($reportFile, json_encode([
    'generated_at' => date('Y-m-d H:i:s'),
    'summary' => [
        'total_products' => $products->count(),
        'products_in_opname' => count($stockOpnameData),
        'total_issues' => $totalIssues
    ],
    'issues' => $issueTypes
], JSON_PRETTY_PRINT));

echo "Full report saved to: {$reportFile}\n\n";

echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                            AUDIT COMPLETE                                     ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
