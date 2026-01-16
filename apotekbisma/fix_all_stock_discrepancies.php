<?php

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Increases execution time and memory for heavy robust process
ini_set('max_execution_time', 1200);
ini_set('memory_limit', '512M');

// Config
$cutoffDate = '2025-12-31 23:59:59';
$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$marker = 'ADJUSTMENT_BY_AGENT_CSV_BASELINE';

ob_start();

echo "# Stock Discrepancy Fix Report\n\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

if (!file_exists($csvFile)) {
    echo "CRITICAL: CSV File not found: $csvFile\n";
    file_put_contents('fix_stock_report.md', ob_get_clean());
    exit(1);
}

$handle = fopen($csvFile, 'r');
if (!$handle) {
    echo "CRITICAL: Could not open CSV.\n";
    file_put_contents('fix_stock_report.md', ob_get_clean());
    exit(1);
}

$processed = 0;
$fixed = 0;
$skipped = 0;
$errors = 0;

echo "| Product ID | Product Name | Opname (CSV) | Pre-Calc 2025 Stock | Adjustment | Valid? | Message |\n";
echo "|---|---|---|---|---|---|---|\n";

// Skip header? Check first line.
$firstLine = fgets($handle);
// Rewind if it looks like data, else skip.
if (strpos($firstLine, 'produk_id_produk') === false) {
    rewind($handle);
}

while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
    $processed++;
    $productId = (int)$data[0];
    $productName = $data[1] ?? 'Unknown';
    $opnameQty = (int)$data[2];
    
    // Safety check
    if ($productId <= 0) continue;

    DB::beginTransaction();
    try {
        // Step 1: Ensure historical consistency up to now (clean the base)
        RekamanStok::recalculateStock($productId);
        
        // Step 2: Get the state at cutoff
        $lastRecord = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('waktu', '<=', $cutoffDate)
            ->where('keterangan', '!=', $marker) // Ignore our own marker if it exists from previous run?
            // Actually, we should probably delete previous marker if re-running to avoid double adjust.
            ->orderBy('waktu', 'desc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();

        // Check if we already have a marker?
        $existingMarker = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('keterangan', $marker)
            ->first();

        if ($existingMarker) {
            // Check if it needs update.
            // Simpler to DELETE existing marker and recalculate logic.
            DB::table('rekaman_stoks')->where('id_rekaman_stok', $existingMarker->id_rekaman_stok)->delete();
            RekamanStok::recalculateStock($productId); // Re-calc after delete
            
            // Re-fetch last record
            $lastRecord = DB::table('rekaman_stoks')
                ->where('id_produk', $productId)
                ->where('waktu', '<=', $cutoffDate)
                ->orderBy('waktu', 'desc')
                ->orderBy('created_at', 'desc')
                ->orderBy('id_rekaman_stok', 'desc')
                ->first();
        }

        $currentStock2025 = $lastRecord ? (int)$lastRecord->stok_sisa : 0;
        
        $diff = $opnameQty - $currentStock2025;
        $action = "None";

        if ($diff != 0) {
            $masuk = $diff > 0 ? $diff : 0;
            $keluar = $diff < 0 ? abs($diff) : 0;
            $stokAwal = $currentStock2025;
            $stokSisa = $opnameQty;

            DB::table('rekaman_stoks')->insert([
                'id_produk' => $productId,
                'waktu' => $cutoffDate,
                'stok_awal' => $stokAwal,
                'stok_masuk' => $masuk,
                'stok_keluar' => $keluar,
                'stok_sisa' => $stokSisa,
                'keterangan' => $marker,
                'created_at' => $cutoffDate,
                'updated_at' => now(),
            ]);
            
            $action = "Adj: " . ($diff > 0 ? "+$diff" : "$diff");
            $fixed++;
        } else {
            $skipped++;
        }

        // Step 3: Recalculate everything including 2026 to ripple the effect
        RekamanStok::recalculateStock($productId);
        
        $integ = RekamanStok::verifyIntegrity($productId);
        $validStr = $integ['valid'] ? 'OK' : 'FAIL';
        
        if (!$integ['valid']) {
             // Try to fix one more time?
             RekamanStok::fullRepair($productId);
             $integ = RekamanStok::verifyIntegrity($productId);
             $validStr = $integ['valid'] ? 'OK (Repaired)' : 'FAIL';
        }

        DB::commit();
        
        echo "| $productId | $productName | $opnameQty | $currentStock2025 | $action | $validStr | |\n";

    } catch (\Exception $e) {
        DB::rollBack();
        $errors++;
        echo "| $productId | $productName | $opnameQty | Err | Err | Error | " . $e->getMessage() . " |\n";
    }
}

fclose($handle);

echo "\n\nSummary: Processed $processed, Fixed $fixed, Skipped $skipped, Errors $errors\n";

$output = ob_get_clean();
file_put_contents('fix_stock_report.md', $output);
echo "Report written to fix_stock_report.md\n";
