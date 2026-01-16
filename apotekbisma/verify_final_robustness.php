<?php

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);

$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$cutoffDate = '2025-12-31 23:59:59';

ob_start();

echo "# FINAL ROBUSTNESS VERIFICATION REPORT\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Baseline: CSV Opname (Dec 31, 2025)\n";
echo "Scope: Verification of 2026 Transactions & Final Stock consistency.\n\n";

if (!file_exists($csvFile)) {
    echo "CRITICAL: CSV File not found.\n";
    file_put_contents('final_robustness_verification.md', ob_get_clean());
    exit(1);
}

$handle = fopen($csvFile, 'r');
// Skip header
$firstLine = fgets($handle);
if (strpos($firstLine, 'produk_id_produk') === false) rewind($handle);

$totalChecked = 0;
$totalValid = 0;
$totalErrors = 0;

echo "| Product ID | Product Name | Baseline (CSV) | Calc. Final | DB Final | Status | Details |\n";
echo "|---|---|---|---|---|---|---|\n";

while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
    $productId = (int)$data[0];
    $productName = $data[1] ?? 'Unknown';
    $baselineStock = (int)$data[2];
    
    if ($productId <= 0) continue;
    
    $totalChecked++;
    
    // 1. Get Transactions > Cutoff
    // STRICT SORTING is crucial here to match the fix logic
    $transactions = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
        
    $runningStock = $baselineStock;
    $isChainValid = true;
    $errorMsg = "";
    
    // 2. Validate Chain
    foreach ($transactions as $idx => $tx) {
        $masuk = (int)$tx->stok_masuk;
        $keluar = (int)$tx->stok_keluar;
        $dbAwal = (int)$tx->stok_awal;
        $dbSisa = (int)$tx->stok_sisa;
        
        // Expected Start for this transaction = Current Running Stock
        if ($dbAwal !== $runningStock) {
            $isChainValid = false;
            $errorMsg .= "Trans #{$tx->id_rekaman_stok} Mismatch Start (Exp: $runningStock, DB: $dbAwal). ";
        }
        
        // Calculate Expected End
        $expectedSisa = $runningStock + $masuk - $keluar;
        
        // Check DB Sisa
        if ($dbSisa !== $expectedSisa) {
            $isChainValid = false;
            $errorMsg .= "Trans #{$tx->id_rekaman_stok} Mismatch End (Exp: $expectedSisa, DB: $dbSisa). ";
        }
        
        // Update running stock for next iteration
        $runningStock = $expectedSisa;
    }
    
    // 3. Verify Product Master Table
    $masterStock = DB::table('produk')->where('id_produk', $productId)->value('stok');
    $masterStock = (int)$masterStock;
    
    // 4. Final Validations
    // We allow master stock to be 0 if calculated is negative (though loop logic usually prevents negative if valid)
    // But strict check: Master should equal Calculated.
    
    $finalStatus = "OK";
    if (!$isChainValid) {
        $finalStatus = "CHAINFAIL";
        $totalErrors++;
    } elseif ($masterStock !== $runningStock) {
        $finalStatus = "SYNCFAIL";
        $errorMsg .= "Master Stock ($masterStock) != Calculated ($runningStock). ";
        $totalErrors++;
    } else {
        $totalValid++;
    }
    
    if ($finalStatus !== "OK") {
        echo "| $productId | $productName | $baselineStock | $runningStock | $masterStock | **$finalStatus** | $errorMsg |\n";
    }
}

fclose($handle);

echo "\n\n";
echo "## Summary\n";
echo "- Total Products Checked: $totalChecked\n";
echo "- Fully Robust (100% Match): $totalValid\n";
echo "- Errors / Mismatches: $totalErrors\n\n";

if ($totalErrors === 0) {
    echo "**CONCLUSION: SYSTEM IS 100% ROBUST & CONSISTENT.**\n";
    echo "All transaction chains from 2026 onwards align perfectly with the Dec 31 2025 Baseline.\n";
    echo "Product master stock matches the calculated transactional history exactly.\n";
} else {
    echo "**CONCLUSION: Found discrepancies in $totalErrors products.**\n";
    echo "Please run the fix script again or investigate the specific IDs listed above.\n";
}

$output = ob_get_clean();
file_put_contents('final_robustness_verification.md', $output);
echo "Verification complete. Report saved to final_robustness_verification.md\n";
