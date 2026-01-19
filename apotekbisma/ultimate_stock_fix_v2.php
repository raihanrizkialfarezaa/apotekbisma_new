<?php
/**
 * ULTIMATE STOCK FIX SCRIPT v2.0
 * 
 * Masalah yang ditemukan dan diperbaiki:
 * 1. Record dengan "Perubahan Stok Manual: SO" yang duplikat/konflik
 * 2. Record Stock Opname dengan waktu di 2026 bukan di cutoff
 * 3. Sorting yang salah - harus consider created_at untuk tie-breaking
 * 4. Record dengan waktu=2026-01-14 21:29:20 (batch update) tapi created_at di 2025
 * 
 * Strategi Fix:
 * 1. HAPUS semua adjustment record yang konflik (SO manual, opname record salah)
 * 2. RESTORE waktu asli untuk record yang di-batch update ke 2026-01-14
 * 3. RECALCULATE chain dari baseline CSV dengan sorting yang benar
 * 4. UPDATE master stock
 */

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
$uniqueMarker = 'BASELINE_OPNAME_31DES2025_V2';

echo "============================================================\n";
echo "ULTIMATE STOCK FIX v2.0\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
echo "============================================================\n\n";

if (!file_exists($csvFile)) {
    die("FATAL: CSV file not found: $csvFile\n");
}

// Load CSV data into memory
$csvData = [];
$handle = fopen($csvFile, 'r');
$header = fgetcsv($handle);
while (($row = fgetcsv($handle)) !== FALSE) {
    $productId = (int)$row[0];
    $productName = $row[1];
    $opnameStock = (int)$row[2];
    if ($productId > 0) {
        $csvData[$productId] = [
            'name' => $productName,
            'opname_stock' => $opnameStock
        ];
    }
}
fclose($handle);

echo "Loaded " . count($csvData) . " products from CSV\n\n";

// Statistics
$stats = [
    'total' => 0,
    'fixed' => 0,
    'already_ok' => 0,
    'failed' => 0,
    'errors' => []
];

$problemProducts = [];

// Process each product
foreach ($csvData as $productId => $csvInfo) {
    $stats['total']++;
    $opnameQty = $csvInfo['opname_stock'];
    
    DB::beginTransaction();
    
    try {
        // =====================================================
        // STEP 1: CLEANUP - Remove problematic records
        // =====================================================
        
        // 1a. Remove old adjustment markers
        DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where(function($q) {
                $q->where('keterangan', 'ADJUSTMENT_BY_AGENT_CSV_BASELINE')
                  ->orWhere('keterangan', 'like', 'BASELINE_OPNAME%')
                  ->orWhere('keterangan', 'like', 'Stock Opname 31 Desember 2025%');
            })
            ->delete();
        
        // 1b. Remove "Perubahan Stok Manual: SO" records that were created for opname
        // These are duplicates/conflicts - the real SO should be in the adjustment we'll create
        $manualSoDeleted = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('keterangan', 'Perubahan Stok Manual: SO')
            ->where('created_at', '>=', '2025-12-31 00:00:00')
            ->where('created_at', '<=', '2026-01-01 23:59:59')
            ->delete();
        
        // =====================================================
        // STEP 2: Get the true last record BEFORE cutoff
        // =====================================================
        
        // The TRUE last record of 2025 is the one with waktu <= cutoff
        // AND we should use created_at for records that might have been backdated
        $lastRecord2025 = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('waktu', '<=', $cutoffDate)
            ->where('created_at', '<=', $cutoffDate) // Also created before cutoff
            ->orderBy('waktu', 'desc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();
        
        $stockBefore2025End = $lastRecord2025 ? (int)$lastRecord2025->stok_sisa : 0;
        
        // =====================================================
        // STEP 3: Create baseline adjustment at EXACT cutoff
        // =====================================================
        
        if ($stockBefore2025End !== $opnameQty) {
            $diff = $opnameQty - $stockBefore2025End;
            
            DB::table('rekaman_stoks')->insert([
                'id_produk' => $productId,
                'waktu' => $cutoffDate, // EXACT cutoff: 2025-12-31 23:59:59
                'stok_awal' => $stockBefore2025End,
                'stok_masuk' => $diff > 0 ? $diff : 0,
                'stok_keluar' => $diff < 0 ? abs($diff) : 0,
                'stok_sisa' => $opnameQty,
                'keterangan' => $uniqueMarker,
                'created_at' => $cutoffDate,
                'updated_at' => now(),
            ]);
        }
        
        // =====================================================
        // STEP 4: Fix records that have waktu in 2026 but are 2025 transactions
        // These were batch-updated with wrong waktu
        // =====================================================
        
        // Find records with waktu >= 2026 but created_at in 2025 (Dec specifically)
        // These are likely the batch-updated records
        $batchUpdatedRecords = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('waktu', '>=', $cutoffDateStart)
            ->where('created_at', '>=', '2025-12-24 00:00:00')
            ->where('created_at', '<=', $cutoffDate)
            ->where('keterangan', 'not like', '%Stock Opname%')
            ->where('keterangan', '!=', 'Perubahan Stok Manual: SO')
            ->get();
        
        // Restore proper waktu based on created_at for these records
        // This fixes the sorting issue
        foreach ($batchUpdatedRecords as $rec) {
            // Set waktu to match created_at (they are real Dec 2025 transactions)
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $rec->id_rekaman_stok)
                ->update(['waktu' => $rec->created_at]);
        }
        
        // =====================================================
        // STEP 5: Get ALL records after baseline and recalculate chain
        // =====================================================
        
        // Now get records AFTER cutoff with PROPER sorting
        // PRIMARY: waktu ASC (transaction time)
        // SECONDARY: created_at ASC (when record was created)
        // TERTIARY: id_rekaman_stok ASC (DB insert order)
        $futureRecords = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('waktu', '>', $cutoffDate)
            ->orderBy('waktu', 'asc')
            ->orderBy('created_at', 'asc')
            ->orderBy('id_rekaman_stok', 'asc')
            ->get();
        
        // Recalculate chain
        $runningStock = $opnameQty;
        
        foreach ($futureRecords as $rec) {
            $masuk = (int)$rec->stok_masuk;
            $keluar = (int)$rec->stok_keluar;
            $newSisa = $runningStock + $masuk - $keluar;
            
            $needsUpdate = false;
            $updates = [];
            
            if ((int)$rec->stok_awal !== $runningStock) {
                $updates['stok_awal'] = $runningStock;
                $needsUpdate = true;
            }
            
            if ((int)$rec->stok_sisa !== $newSisa) {
                $updates['stok_sisa'] = $newSisa;
                $needsUpdate = true;
            }
            
            if ($needsUpdate) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $rec->id_rekaman_stok)
                    ->update($updates);
            }
            
            $runningStock = $newSisa;
        }
        
        // =====================================================
        // STEP 6: Update master stock
        // =====================================================
        
        DB::table('produk')
            ->where('id_produk', $productId)
            ->update(['stok' => $runningStock]);
        
        // =====================================================
        // STEP 7: Verification
        // =====================================================
        
        $verifyRecords = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('waktu', '>', $cutoffDate)
            ->orderBy('waktu', 'asc')
            ->orderBy('created_at', 'asc')
            ->orderBy('id_rekaman_stok', 'asc')
            ->get();
        
        $verifyStock = $opnameQty;
        $chainOK = true;
        $errorDetail = '';
        
        foreach ($verifyRecords as $vRec) {
            if ((int)$vRec->stok_awal !== $verifyStock) {
                $chainOK = false;
                $errorDetail = "Record {$vRec->id_rekaman_stok}: expected awal=$verifyStock, got={$vRec->stok_awal}";
                break;
            }
            $calcSisa = $verifyStock + (int)$vRec->stok_masuk - (int)$vRec->stok_keluar;
            if ((int)$vRec->stok_sisa !== $calcSisa) {
                $chainOK = false;
                $errorDetail = "Record {$vRec->id_rekaman_stok}: expected sisa=$calcSisa, got={$vRec->stok_sisa}";
                break;
            }
            $verifyStock = $calcSisa;
        }
        
        $masterStock = (int)DB::table('produk')->where('id_produk', $productId)->value('stok');
        
        if ($chainOK && $masterStock === $verifyStock) {
            DB::commit();
            $stats['fixed']++;
            
            if ($productId == 524) {
                echo "[MIXALGIN-524] FIXED! Final stock: $runningStock\n";
            }
        } else {
            DB::rollBack();
            $stats['failed']++;
            $problemProducts[$productId] = [
                'name' => $csvInfo['name'],
                'opname' => $opnameQty,
                'calculated' => $verifyStock,
                'master' => $masterStock,
                'error' => $errorDetail
            ];
            
            if ($productId == 524) {
                echo "[MIXALGIN-524] FAILED! Error: $errorDetail\n";
            }
        }
        
    } catch (\Exception $e) {
        DB::rollBack();
        $stats['failed']++;
        $stats['errors'][] = "Product $productId: " . $e->getMessage();
        
        if ($productId == 524) {
            echo "[MIXALGIN-524] EXCEPTION: " . $e->getMessage() . "\n";
        }
    }
    
    // Progress indicator
    if ($stats['total'] % 100 == 0) {
        echo "Processed: {$stats['total']} products...\n";
    }
}

echo "\n============================================================\n";
echo "SUMMARY\n";
echo "============================================================\n";
echo "Total products: {$stats['total']}\n";
echo "Fixed: {$stats['fixed']}\n";
echo "Failed: {$stats['failed']}\n";

if (count($problemProducts) > 0) {
    echo "\n=== PROBLEM PRODUCTS ===\n";
    foreach ($problemProducts as $pid => $info) {
        echo "Product $pid ({$info['name']}): Opname={$info['opname']}, Calc={$info['calculated']}, Master={$info['master']}\n";
        echo "  Error: {$info['error']}\n";
    }
}

if (count($stats['errors']) > 0) {
    echo "\n=== EXCEPTIONS ===\n";
    foreach ($stats['errors'] as $err) {
        echo "  - $err\n";
    }
}

echo "\nDone!\n";
