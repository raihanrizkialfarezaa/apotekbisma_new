<?php
/**
 * PERFECT STOCK FIX SCRIPT v3.0 (ROBUST VERSION)
 * 
 * Script ini memperbaiki semua masalah stok berdasarkan baseline CSV Stock Opname 31 Desember 2025.
 * 
 * MASALAH YANG DITANGANI:
 * 1. Record "Perubahan Stok Manual: SO" duplikat yang menyebabkan stok minus
 * 2. Record dengan waktu di-batch update ke tanggal yang salah
 * 3. Record Stock Opname dengan waktu di 2026 bukan di cutoff 2025
 * 4. Chain stok yang tidak konsisten karena sorting yang salah
 * 5. Negative stock yang terbawa ke transaksi berikutnya
 * 
 * STRATEGI:
 * 1. CLEANUP - Hapus semua adjustment record yang konflik
 * 2. RESTORE - Perbaiki waktu record yang ter-batch update
 * 3. BASELINE - Buat record baseline yang tepat di cutoff
 * 4. RECALCULATE - Hitung ulang chain dari baseline
 * 5. VERIFY - Validasi sebelum commit
 */

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

ini_set('memory_limit', '1024M');
ini_set('max_execution_time', 0);

// Configuration
$cutoffDate = '2025-12-31 23:59:59';
$cutoffDateStart = '2026-01-01 00:00:00';
$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$uniqueMarker = 'BASELINE_OPNAME_31DES2025_V3';

// All markers we've ever used - for cleanup
$allMarkers = [
    'ADJUSTMENT_BY_AGENT_CSV_BASELINE',
    'BASELINE_OPNAME_31DES2025_V2',
    'BASELINE_OPNAME_31DES2025_V3',
];

ob_start();

echo "# PERFECT Stock Fix Report v3.0 (ROBUST)\n";
echo "Mode: Full Cleanup -> Restore -> Baseline -> Recalculate -> Verify\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

if (!file_exists($csvFile)) {
    echo "CRITICAL: CSV not found at $csvFile\n";
    file_put_contents('perfect_fix_report.md', ob_get_clean());
    exit(1);
}

// Load all CSV data first
$csvData = [];
$handle = fopen($csvFile, 'r');
$header = fgetcsv($handle);
while (($row = fgetcsv($handle)) !== FALSE) {
    $productId = (int)$row[0];
    if ($productId > 0) {
        $csvData[$productId] = [
            'name' => $row[1],
            'opname' => (int)$row[2]
        ];
    }
}
fclose($handle);

echo "Loaded " . count($csvData) . " products from CSV\n\n";

$total = 0;
$fixed = 0;
$failed = 0;
$problemProducts = [];

echo "| Product ID | Name | Opname | Status | Final Stock | Note |\n";
echo "|---|---|---|---|---|---|\n";

foreach ($csvData as $productId => $info) {
    $opnameQty = $info['opname'];
    $productName = substr($info['name'], 0, 20);
    $total++;
    
    DB::beginTransaction();
    
    try {
        // =====================================================
        // STEP 1: COMPREHENSIVE CLEANUP
        // =====================================================
        
        // 1a. Remove ALL previous adjustment markers
        DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where(function($q) use ($allMarkers) {
                foreach ($allMarkers as $marker) {
                    $q->orWhere('keterangan', $marker);
                }
                $q->orWhere('keterangan', 'like', 'Stock Opname 31 Desember%');
                $q->orWhere('keterangan', 'like', 'BASELINE_OPNAME%');
            })
            ->delete();
        
        // 1b. Remove problematic "Perubahan Stok Manual: SO" records from around opname time
        // These are system-generated duplicates that cause negative stock
        DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('keterangan', 'Perubahan Stok Manual: SO')
            ->where('created_at', '>=', '2025-12-31 00:00:00')
            ->where('created_at', '<=', '2026-01-02 23:59:59')
            ->delete();
        
        // =====================================================
        // STEP 2: FIX BATCH-UPDATED RECORDS
        // Records with waktu in 2026 but created_at in late Dec 2025
        // These were actual Dec 2025 transactions that got batch-updated
        // =====================================================
        
        $batchUpdated = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('waktu', '>=', $cutoffDateStart)
            ->where('created_at', '>=', '2025-12-24 00:00:00')
            ->where('created_at', '<=', $cutoffDate)
            ->where('keterangan', 'not like', '%Stock Opname%')
            ->where('keterangan', 'not like', '%BASELINE%')
            ->where('keterangan', '!=', 'Perubahan Stok Manual: SO')
            ->get();
        
        // Restore waktu to match created_at for these records
        foreach ($batchUpdated as $rec) {
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $rec->id_rekaman_stok)
                ->update(['waktu' => $rec->created_at]);
        }
        
        // =====================================================
        // STEP 3: GET LAST TRUE RECORD OF 2025
        // =====================================================
        
        $lastRecord2025 = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('waktu', '<=', $cutoffDate)
            ->where('created_at', '<=', $cutoffDate)
            ->orderBy('waktu', 'desc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();
        
        $stockBefore = $lastRecord2025 ? (int)$lastRecord2025->stok_sisa : 0;
        
        // =====================================================
        // STEP 4: CREATE BASELINE ADJUSTMENT AT EXACT CUTOFF
        // =====================================================
        
        if ($stockBefore !== $opnameQty) {
            $diff = $opnameQty - $stockBefore;
            DB::table('rekaman_stoks')->insert([
                'id_produk' => $productId,
                'waktu' => $cutoffDate,
                'stok_awal' => $stockBefore,
                'stok_masuk' => $diff > 0 ? $diff : 0,
                'stok_keluar' => $diff < 0 ? abs($diff) : 0,
                'stok_sisa' => $opnameQty,
                'keterangan' => $uniqueMarker,
                'created_at' => $cutoffDate,
                'updated_at' => now(),
            ]);
        }
        
        // =====================================================
        // STEP 5: RECALCULATE ALL RECORDS AFTER CUTOFF
        // Sorting: waktu ASC, created_at ASC, id ASC
        // =====================================================
        
        $futureRecords = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('waktu', '>', $cutoffDate)
            ->orderBy('waktu', 'asc')
            ->orderBy('created_at', 'asc')
            ->orderBy('id_rekaman_stok', 'asc')
            ->get();
        
        $runningStock = $opnameQty;
        
        foreach ($futureRecords as $rec) {
            $masuk = (int)$rec->stok_masuk;
            $keluar = (int)$rec->stok_keluar;
            $newSisa = $runningStock + $masuk - $keluar;
            
            $updates = [];
            if ((int)$rec->stok_awal !== $runningStock) {
                $updates['stok_awal'] = $runningStock;
            }
            if ((int)$rec->stok_sisa !== $newSisa) {
                $updates['stok_sisa'] = $newSisa;
            }
            
            if (!empty($updates)) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $rec->id_rekaman_stok)
                    ->update($updates);
            }
            
            $runningStock = $newSisa;
        }
        
        // =====================================================
        // STEP 6: UPDATE MASTER STOCK
        // =====================================================
        
        DB::table('produk')
            ->where('id_produk', $productId)
            ->update(['stok' => $runningStock]);
        
        // =====================================================
        // STEP 7: VERIFICATION
        // =====================================================
        
        $verifyRecords = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('waktu', '>', $cutoffDate)
            ->orderBy('waktu', 'asc')
            ->orderBy('created_at', 'asc')
            ->orderBy('id_rekaman_stok', 'asc')
            ->get();
        
        $vStock = $opnameQty;
        $chainOK = true;
        $errorMsg = '';
        
        foreach ($verifyRecords as $vRec) {
            if ((int)$vRec->stok_awal !== $vStock) {
                $chainOK = false;
                $errorMsg = "Awal mismatch at ID {$vRec->id_rekaman_stok}";
                break;
            }
            $calcSisa = $vStock + (int)$vRec->stok_masuk - (int)$vRec->stok_keluar;
            if ((int)$vRec->stok_sisa !== $calcSisa) {
                $chainOK = false;
                $errorMsg = "Sisa mismatch at ID {$vRec->id_rekaman_stok}";
                break;
            }
            $vStock = $calcSisa;
        }
        
        $masterStock = (int)DB::table('produk')->where('id_produk', $productId)->value('stok');
        
        if ($chainOK && $masterStock === $vStock) {
            DB::commit();
            $fixed++;
            echo "| $productId | $productName | $opnameQty | ✅ OK | $runningStock | |\n";
        } else {
            DB::rollBack();
            $failed++;
            $problemProducts[$productId] = $errorMsg ?: "Master mismatch: $masterStock vs $vStock";
            echo "| $productId | $productName | $opnameQty | ❌ FAIL | $runningStock | $errorMsg |\n";
        }
        
    } catch (\Exception $e) {
        DB::rollBack();
        $failed++;
        $problemProducts[$productId] = $e->getMessage();
        echo "| $productId | $productName | $opnameQty | ❌ ERROR | - | " . substr($e->getMessage(), 0, 50) . " |\n";
    }
    
    if ($total % 100 == 0) {
        echo "<!-- Processed $total products -->\n";
    }
}

echo "\n\n# Summary\n";
echo "- Total Processed: $total\n";
echo "- Successfully Fixed: $fixed\n";
echo "- Failed: $failed\n";

if (count($problemProducts) > 0) {
    echo "\n## Problem Products\n";
    foreach ($problemProducts as $pid => $err) {
        echo "- Product $pid: $err\n";
    }
}

$output = ob_get_clean();
file_put_contents('perfect_fix_report.md', $output);
echo $output;
echo "\nDone. Report saved to perfect_fix_report.md\n";
