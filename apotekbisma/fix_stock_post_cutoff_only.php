<?php

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Config
ini_set('max_execution_time', 1200);
ini_set('memory_limit', '512M');
$cutoffDate = '2025-12-31 23:59:59';
$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$marker = 'ADJUSTMENT_BY_AGENT_CSV_BASELINE';

ob_start();
echo "# Safe Stock Fix Report (Post-Cutoff Only)\n";
echo "Mode: Frozen Pre-2026 History. Only fixing 2026 onwards.\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

if (!file_exists($csvFile)) {
    echo "CRITICAL: CSV not found.\n";
    file_put_contents('fix_stock_report_safe.md', ob_get_clean());
    exit(1);
}

$handle = fopen($csvFile, 'r');
$processed = 0;
$fixed = 0;
$skipped = 0;
$errors = 0;

echo "| Product ID | Opname (CSV) | DB End 2025 | Action | Transaksi 2026 Fixed |\n";
echo "|---|---|---|---|---|\n";

// Skip header check
$firstLine = fgets($handle);
if (strpos($firstLine, 'produk_id_produk') === false) rewind($handle);

while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
    $processed++;
    $productId = (int)$data[0];
    $opnameQty = (int)$data[2];
    
    if ($productId <= 0) continue;

    DB::beginTransaction();
    try {
        // 1. Get status at cutoff WITHOUT touching history
        // We look for the latest record on or before cutoff
        $lastRecord2025 = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('waktu', '<=', $cutoffDate)
            ->where('keterangan', '!=', $marker) // Ignore previous runs of this script
            ->orderBy('waktu', 'desc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();

        // 2. Determine Bridge
        $currentStock2025 = $lastRecord2025 ? (int)$lastRecord2025->stok_sisa : 0;
        $runningStock = $currentStock2025;
        $action = "Match";
        
        // Cleanup ANY existing adjustments from previous runs of this agent to prevent duplicates
        DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('keterangan', $marker)
            ->delete();

        // If mismatch, create Bridge Adjustment
        if ($currentStock2025 != $opnameQty) {
            $diff = $opnameQty - $currentStock2025;
            $masuk = $diff > 0 ? $diff : 0;
            $keluar = $diff < 0 ? abs($diff) : 0;
            
            DB::table('rekaman_stoks')->insert([
                'id_produk' => $productId,
                'waktu' => $cutoffDate,
                'stok_awal' => $currentStock2025,
                'stok_masuk' => $masuk,
                'stok_keluar' => $keluar,
                'stok_sisa' => $opnameQty,
                'keterangan' => $marker,
                'created_at' => $cutoffDate, // Insert exactly at barrier
                'updated_at' => now(),
            ]);
            
            $runningStock = $opnameQty; // This is our clean start for 2026
            $action = "Adj: " . ($diff > 0 ? "+$diff" : "$diff");
            $fixed++;
        } else {
            // Even if match, we must ensure $runningStock is set correctly for the ripple
            $runningStock = $currentStock2025; 
            $skipped++;
        }

        // 3. Ripple Forward (ONLY for records > cutoff)
        // STRICT SORDERING: Waktu -> CreatedAt -> ID to handle same-second transactions deterministicallly
        $futureRecords = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('waktu', '>', $cutoffDate) // Strictly AFTER cutoff
            ->orderBy('waktu', 'asc')
            ->orderBy('created_at', 'asc')
            ->orderBy('id_rekaman_stok', 'asc')
            ->get();
            
        $recordsFixed = 0;
        foreach ($futureRecords as $rec) {
            $updates = [];
            
            // Check Integrity: Start Stock vs Running Stock
            if ((int)$rec->stok_awal != $runningStock) {
                $updates['stok_awal'] = $runningStock;
            }
            
            // Calculate correct end stock
            $calcSisa = $runningStock + (int)$rec->stok_masuk - (int)$rec->stok_keluar;
            
            // Check Integrity: End Stock vs Calculated
            if ((int)$rec->stok_sisa != $calcSisa) {
                $updates['stok_sisa'] = $calcSisa;
            }
            
            if (!empty($updates)) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $rec->id_rekaman_stok)
                    ->update($updates);
                $recordsFixed++;
            }
            
            $runningStock = $calcSisa;
        }

        // 4. Final Sync to Product Table
        // Ensure the product master table reflects the final running stock of the timeline
        DB::table('produk')
            ->where('id_produk', $productId)
            ->update(['stok' => max(0, $runningStock)]);

        DB::commit();
        
        echo "| $productId | $opnameQty | $currentStock2025 | $action | $recordsFixed |\n";

    } catch (\Exception $e) {
        DB::rollBack();
        $errors++;
        echo "| $productId | $opnameQty | Err | Error | " . $e->getMessage() . " |\n";
    }
}

echo "\n\nSummary: Processed $processed. Fixed Baseline for $fixed products. Errors: $errors\n";
$output = ob_get_clean();
file_put_contents('fix_stock_report_safe.md', $output);
echo "Done. Report saved to fix_stock_report_safe.md\n";
