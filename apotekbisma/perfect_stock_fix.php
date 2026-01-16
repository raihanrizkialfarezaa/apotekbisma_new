<?php

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

ini_set('memory_limit', '1024M');
ini_set('max_execution_time', 0); // No time limit

// Configuration
$cutoffDate = '2025-12-31 23:59:59';
$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$uniqueMarker = 'ADJUSTMENT_BY_AGENT_CSV_BASELINE';

ob_start();

echo "# PERFECT Zero-Error Stock Fix Report\n";
echo "Mode: Auto-Correction Loop (Validate -> Fix -> Re-Validate)\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

if (!file_exists($csvFile)) {
    echo "CRITICAL: CSV not found.\n";
    file_put_contents('perfect_fix_report.md', ob_get_clean());
    exit(1);
}

$handle = fopen($csvFile, 'r');
$firstLine = fgets($handle);
if (strpos($firstLine, 'produk_id_produk') === false) rewind($handle);

$total = 0;
$fixed = 0;
$failed = 0;

echo "| Product ID | Opname | Initial Status | Retries | Final Status | Note |\n";
echo "|---|---|---|---|---|---|\n";

while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
    
    $productId = (int)$data[0];
    $opnameQty = (int)$data[2];
    if ($productId <= 0) continue;
    
    $total++;
    
    // START PROCESSING PRODUCT
    DB::beginTransaction();
    $status = "PENDING";
    $attempts = 0;
    $maxAttempts = 3;
    $finalError = "";
    
    try {
        do {
            $attempts++;
            
            // 1. CLEAN PHANTOM RECORDS (Sanitization)
            // Delete ANY auto-adjustments in the future (Ghost Records)
            DB::table('rekaman_stoks')
                ->where('id_produk', $productId)
                ->where('keterangan', 'like', '%Stock Opname 31 Desember%') // Catch generic text
                ->where('waktu', '>', '2026-01-01 00:00:00')
                ->delete();
            
            DB::table('rekaman_stoks')
                ->where('id_produk', $productId)
                ->where('keterangan', $uniqueMarker) // Catch our marker
                ->where('waktu', '>', '2026-01-01 00:00:00')
                ->delete();

            // 2. ESTABLISH BASELINE (End of 2025)
            // Clean specific adjustments at cutoff for clean re-insertion
            DB::table('rekaman_stoks')
                ->where('id_produk', $productId)
                ->where('keterangan', $uniqueMarker)
                ->delete();

            // Get last legit record of 2025
            $lastRecord2025 = DB::table('rekaman_stoks')
                ->where('id_produk', $productId)
                ->where('waktu', '<=', $cutoffDate)
                ->orderBy('waktu', 'desc')
                ->orderBy('created_at', 'desc')
                ->orderBy('id_rekaman_stok', 'desc')
                ->first();
                
            $currentStock2025 = $lastRecord2025 ? (int)$lastRecord2025->stok_sisa : 0;
            
            // Create Bridge Adjustment if needed
            if ($currentStock2025 !== $opnameQty) {
                $diff = $opnameQty - $currentStock2025;
                DB::table('rekaman_stoks')->insert([
                    'id_produk' => $productId,
                    'waktu' => $cutoffDate,
                    'stok_awal' => $currentStock2025,
                    'stok_masuk' => $diff > 0 ? $diff : 0,
                    'stok_keluar' => $diff < 0 ? abs($diff) : 0,
                    'stok_sisa' => $opnameQty,
                    'keterangan' => $uniqueMarker,
                    'created_at' => $cutoffDate,
                    'updated_at' => now(),
                ]);
            }
            
            // 3. RIPPLE CALCULATION (2026 Onwards)
            // Deterministic Sorting: Waktu ASC -> Created ASC -> ID ASC
            $futureRecords = DB::table('rekaman_stoks')
                ->where('id_produk', $productId)
                ->where('waktu', '>', $cutoffDate)
                ->orderBy('waktu', 'asc')
                ->orderBy('created_at', 'asc')
                ->orderBy('id_rekaman_stok', 'asc')
                ->get();
                
            $runningStock = $opnameQty;
            
            foreach ($futureRecords as $rec) {
                $updates = [];
                $mustUpdate = false;
                
                // Validate Start
                if ((int)$rec->stok_awal !== $runningStock) {
                    $updates['stok_awal'] = $runningStock;
                    $mustUpdate = true;
                }
                
                // Calculate Correct End
                $masuk = (int)$rec->stok_masuk;
                $keluar = (int)$rec->stok_keluar;
                $calcSisa = $runningStock + $masuk - $keluar;
                
                // Validate End
                if ((int)$rec->stok_sisa !== $calcSisa) {
                    $updates['stok_sisa'] = $calcSisa;
                    $mustUpdate = true;
                }
                
                if ($mustUpdate) {
                    DB::table('rekaman_stoks')
                        ->where('id_rekaman_stok', $rec->id_rekaman_stok)
                        ->update($updates);
                }
                
                $runningStock = $calcSisa;
            }
            
            // 4. SYNC MASTER
            DB::table('produk')
                ->where('id_produk', $productId)
                ->update(['stok' => max(0, $runningStock)]); // Prevent negative master stock display
                
            // 5. INTERNAL VERIFICATION (The "Zero Error" Check)
            // We re-query locally to verification
            $verifyMaster = (int)DB::table('produk')->where('id_produk', $productId)->value('stok');
            
            // Re-fetch chain to verify DB persistence
            $verifyChain = DB::table('rekaman_stoks')
                ->where('id_produk', $productId)
                ->where('waktu', '>', $cutoffDate)
                ->orderBy('waktu', 'asc')
                ->orderBy('created_at', 'asc')
                ->orderBy('id_rekaman_stok', 'asc')
                ->get();
            
            $vStock = $opnameQty;
            $chainValid = true;
            foreach ($verifyChain as $vRec) {
                if ((int)$vRec->stok_awal !== $vStock) { $chainValid = false; break; }
                $vStock = $vStock + (int)$vRec->stok_masuk - (int)$vRec->stok_keluar;
                if ((int)$vRec->stok_sisa !== $vStock) { $chainValid = false; break; }
            }
            
            // Success Condition
            if ($chainValid && $verifyMaster === max(0, $vStock)) {
                $status = "SUCCESS";
                // Commit ONLY if successful
                DB::commit(); 
                $fixed++;
                echo "| $productId | $opnameQty | Fixed | $attempts | **SAFE** | OK |\n";
                break; // Exit do-while loop
            } else {
                // If fail, roll back transaction to try again cleanly or just loop again?
                // Actually rolling back counters updates, so next loop starts fresh. 
                // Useful if we want to try different sorting or logic (though logic is static here).
                // Let's NOT rollback, but just let it run again to catch edge cases where updates lagged?
                // No, DB updates are atomic in transaction.
                // If it failed, it means the logic didn't account for something. 
                $status = "RETRYING";
                
                // Special Handling for stubborn cases (e.g. duplicate IDs?)
                // On retry, maybe we just didn't catch it. 
                // We'll retry 3 times.
                if ($attempts >= $maxAttempts) {
                    $status = "FAILED";
                    $finalError = "Chain validation failed after $attempts tries. Last Calc: $vStock, Master: $verifyMaster";
                    DB::commit(); // Commit anyway as it's better than nothing, user can inspect.
                    $failed++;
                    echo "| $productId | $opnameQty | Stubborn | $attempts | **FAIL** | $finalError |\n";
                }
            }
            
        } while ($attempts < $maxAttempts && $status != "SUCCESS");
        
    } catch (\Exception $e) {
        DB::rollBack();
        $failed++;
        echo "| $productId | $opnameQty | Err | $attempts | ERROR | " . $e->getMessage() . " |\n";
    }
}

fclose($handle);

echo "\n\n# Summary\n";
echo "Total Processed: $total\n";
echo "Zero-Error Verified: $fixed\n";
echo "Failed/Stubborn: $failed\n";

$output = ob_get_clean();
file_put_contents('perfect_fix_report.md', $output);
echo "Done. Report saved to perfect_fix_report.md\n";
