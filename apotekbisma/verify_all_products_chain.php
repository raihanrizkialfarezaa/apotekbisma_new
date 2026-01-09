<?php
/**
 * COMPREHENSIVE CHECK: Verify ALL products have proper stock chain
 * after Stock Opname 31 December 2025
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=======================================================\n";
echo "COMPREHENSIVE STOCK VERIFICATION AFTER OPNAME\n";
echo "=======================================================\n\n";

$cutoffDate = '2025-12-31 23:59:59';

// Load CSV baseline
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
echo "Loaded " . count($csvData) . " products from CSV baseline\n\n";

$issues = [];

foreach ($csvData as $produkId => $baseline) {
    // Check 1: Does Stock Opname record exist at cutoff?
    $opnameRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->where('waktu', $cutoffDate)
        ->first();
    
    if (!$opnameRecord) {
        $issues[] = [
            'type' => 'NO_OPNAME',
            'produk_id' => $produkId,
            'nama' => $baseline['nama'],
            'baseline_stok' => $baseline['stok'],
            'message' => 'No Stock Opname record at cutoff'
        ];
        continue;
    }
    
    // Check 2: Does the opname record have correct stok_sisa?
    if ($opnameRecord->stok_sisa != $baseline['stok']) {
        $issues[] = [
            'type' => 'OPNAME_MISMATCH',
            'produk_id' => $produkId,
            'nama' => $baseline['nama'],
            'baseline_stok' => $baseline['stok'],
            'opname_stok_sisa' => $opnameRecord->stok_sisa,
            'message' => 'Stock Opname stok_sisa != CSV baseline'
        ];
    }
    
    // Check 3: First record after cutoff should have stok_awal = baseline_stok
    $firstAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->first();
    
    if ($firstAfter) {
        if ($firstAfter->stok_awal != $baseline['stok']) {
            $issues[] = [
                'type' => 'CHAIN_BREAK',
                'produk_id' => $produkId,
                'nama' => $baseline['nama'],
                'baseline_stok' => $baseline['stok'],
                'first_after_stok_awal' => $firstAfter->stok_awal,
                'first_after_waktu' => $firstAfter->waktu,
                'message' => 'First record after cutoff stok_awal != baseline'
            ];
        }
    }
}

echo "=======================================================\n";
echo "ISSUES FOUND: " . count($issues) . "\n";
echo "=======================================================\n\n";

if (count($issues) > 0) {
    // Group by type
    $grouped = [];
    foreach ($issues as $issue) {
        $type = $issue['type'];
        if (!isset($grouped[$type])) {
            $grouped[$type] = [];
        }
        $grouped[$type][] = $issue;
    }
    
    foreach ($grouped as $type => $typeIssues) {
        echo "--- {$type}: " . count($typeIssues) . " products ---\n";
        foreach (array_slice($typeIssues, 0, 20) as $issue) {
            echo "  ID: {$issue['produk_id']} - {$issue['nama']}\n";
            echo "    Baseline: {$issue['baseline_stok']}\n";
            if (isset($issue['opname_stok_sisa'])) {
                echo "    Opname stok_sisa: {$issue['opname_stok_sisa']}\n";
            }
            if (isset($issue['first_after_stok_awal'])) {
                echo "    First after stok_awal: {$issue['first_after_stok_awal']}\n";
                echo "    First after waktu: {$issue['first_after_waktu']}\n";
            }
            echo "    Message: {$issue['message']}\n\n";
        }
        if (count($typeIssues) > 20) {
            echo "  ... and " . (count($typeIssues) - 20) . " more\n\n";
        }
    }
} else {
    echo "ALL PRODUCTS PASSED VERIFICATION!\n";
}

echo "\n=======================================================\n";
echo "VERIFICATION COMPLETE\n";
echo "=======================================================\n";
