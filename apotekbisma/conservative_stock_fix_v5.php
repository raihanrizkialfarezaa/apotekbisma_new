<?php
/**
 * CONSERVATIVE STOCK FIX v5.0
 * ===========================
 * Script KONSERVATIF yang TIDAK merusak data historis:
 * 
 * PRINSIP UTAMA:
 * - JANGAN SENTUH records sebelum cutoff (data historis aman)
 * - HANYA UPDATE Stock Opname supaya stok_sisa = CSV value
 * - HANYA RECALCULATE records SETELAH cutoff mulai dari baseline CSV
 * - JANGAN UBAH timestamp apapun
 * 
 * USAGE:
 *   DRY RUN:  php conservative_stock_fix_v5.php
 *   EXECUTE:  php conservative_stock_fix_v5.php --execute
 */

ini_set('memory_limit', '1024M');
set_time_limit(0);

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

$cutoffDate = '2025-12-31 23:59:59';
$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';

$dryRun = true;
if (isset($argv[1]) && $argv[1] === '--execute') {
    $dryRun = false;
}

$stats = [
    'opname_fixed' => 0,
    'opname_inserted' => 0,
    'after_cutoff_fixed' => 0,
    'produk_synced' => 0,
    'duplicate_sales_fixed' => 0,
    'duplicate_purchases_fixed' => 0,
];

echo "=======================================================\n";
echo "    CONSERVATIVE STOCK FIX v5.0\n";
echo "    " . ($dryRun ? "*** DRY RUN MODE ***" : "!!! EXECUTE MODE !!!") . "\n";
echo "=======================================================\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "Cutoff Date: {$cutoffDate}\n\n";

echo "STEP 1: Loading CSV baseline data...\n";

$csvData = [];
if (($handle = fopen($csvFile, "r")) !== FALSE) {
    fgetcsv($handle);
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

echo "  Loaded " . count($csvData) . " products from CSV\n\n";

echo "STEP 2: Fixing duplicate transaction records (keep first, delete rest)...\n";

$duplicateSales = DB::table('rekaman_stoks')
    ->select('id_produk', 'id_penjualan', DB::raw('MIN(id_rekaman_stok) as keep_id'), DB::raw('COUNT(*) as cnt'))
    ->whereNotNull('id_penjualan')
    ->groupBy('id_produk', 'id_penjualan')
    ->having('cnt', '>', 1)
    ->get();

foreach ($duplicateSales as $dup) {
    if (!$dryRun) {
        DB::table('rekaman_stoks')
            ->where('id_produk', $dup->id_produk)
            ->where('id_penjualan', $dup->id_penjualan)
            ->where('id_rekaman_stok', '!=', $dup->keep_id)
            ->delete();
    }
    $stats['duplicate_sales_fixed'] += ($dup->cnt - 1);
}

$duplicatePurchases = DB::table('rekaman_stoks')
    ->select('id_produk', 'id_pembelian', DB::raw('MIN(id_rekaman_stok) as keep_id'), DB::raw('COUNT(*) as cnt'))
    ->whereNotNull('id_pembelian')
    ->groupBy('id_produk', 'id_pembelian')
    ->having('cnt', '>', 1)
    ->get();

foreach ($duplicatePurchases as $dup) {
    if (!$dryRun) {
        DB::table('rekaman_stoks')
            ->where('id_produk', $dup->id_produk)
            ->where('id_pembelian', $dup->id_pembelian)
            ->where('id_rekaman_stok', '!=', $dup->keep_id)
            ->delete();
    }
    $stats['duplicate_purchases_fixed'] += ($dup->cnt - 1);
}

echo "  Duplicate sales fixed: {$stats['duplicate_sales_fixed']}\n";
echo "  Duplicate purchases fixed: {$stats['duplicate_purchases_fixed']}\n\n";

echo "STEP 3: Fixing Stock Opname records (ONLY stok_sisa to match CSV)...\n";

foreach ($csvData as $produkId => $baseline) {
    $produk = DB::table('produk')->where('id_produk', $produkId)->first();
    if (!$produk) continue;
    
    $baselineStok = $baseline['stok'];
    
    $opnameRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->where('waktu', $cutoffDate)
        ->first();
    
    if ($opnameRecord) {
        if ((int)$opnameRecord->stok_sisa !== $baselineStok) {
            $stokAwal = (int)$opnameRecord->stok_awal;
            $diff = $baselineStok - $stokAwal;
            
            if (!$dryRun) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $opnameRecord->id_rekaman_stok)
                    ->update([
                        'stok_masuk' => $diff > 0 ? $diff : null,
                        'stok_keluar' => $diff < 0 ? abs($diff) : null,
                        'stok_sisa' => $baselineStok
                    ]);
            }
            $stats['opname_fixed']++;
        }
    } else {
        $lastBefore = DB::table('rekaman_stoks')
            ->where('id_produk', $produkId)
            ->where('waktu', '<', $cutoffDate)
            ->orderBy('waktu', 'desc')
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();
        
        $stokAwal = $lastBefore ? (int)$lastBefore->stok_sisa : 0;
        $diff = $baselineStok - $stokAwal;
        
        if (!$dryRun) {
            DB::table('rekaman_stoks')->insert([
                'id_produk' => $produkId,
                'waktu' => $cutoffDate,
                'stok_awal' => $stokAwal,
                'stok_masuk' => $diff > 0 ? $diff : null,
                'stok_keluar' => $diff < 0 ? abs($diff) : null,
                'stok_sisa' => $baselineStok,
                'keterangan' => 'Stock Opname 31 Desember 2025',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ]);
        }
        $stats['opname_inserted']++;
    }
}

echo "  Stock Opname fixed: {$stats['opname_fixed']}\n";
echo "  Stock Opname inserted: {$stats['opname_inserted']}\n\n";

echo "STEP 4: Recalculating ONLY records AFTER cutoff (using CSV baseline)...\n";

foreach ($csvData as $produkId => $baseline) {
    $baselineStok = $baseline['stok'];
    
    $recordsAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($recordsAfter->isEmpty()) continue;
    
    $runningStock = $baselineStok;
    
    foreach ($recordsAfter as $record) {
        $needsUpdate = false;
        $updateData = [];
        
        if ((int)$record->stok_awal !== $runningStock) {
            $updateData['stok_awal'] = $runningStock;
            $needsUpdate = true;
        }
        
        $masuk = (int)($record->stok_masuk ?? 0);
        $keluar = (int)($record->stok_keluar ?? 0);
        $calculatedSisa = $runningStock + $masuk - $keluar;
        
        if ((int)$record->stok_sisa !== $calculatedSisa) {
            $updateData['stok_sisa'] = $calculatedSisa;
            $needsUpdate = true;
        }
        
        if ($needsUpdate && !$dryRun) {
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $record->id_rekaman_stok)
                ->update($updateData);
            $stats['after_cutoff_fixed']++;
        } elseif ($needsUpdate) {
            $stats['after_cutoff_fixed']++;
        }
        
        $runningStock = $calculatedSisa;
    }
}

echo "  Records after cutoff fixed: {$stats['after_cutoff_fixed']}\n\n";

echo "STEP 5: Syncing produk.stok with latest rekaman_stoks...\n";

foreach ($csvData as $produkId => $baseline) {
    $latestRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->orderBy('waktu', 'desc')
        ->orderBy('created_at', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if (!$latestRecord) continue;
    
    $produk = DB::table('produk')->where('id_produk', $produkId)->first();
    if (!$produk) continue;
    
    $correctStock = (int)$latestRecord->stok_sisa;
    
    if ((int)$produk->stok !== $correctStock) {
        if (!$dryRun) {
            DB::table('produk')
                ->where('id_produk', $produkId)
                ->update(['stok' => $correctStock]);
        }
        $stats['produk_synced']++;
    }
}

echo "  Produk.stok synced: {$stats['produk_synced']}\n\n";

echo "=======================================================\n";
echo "SUMMARY\n";
echo "=======================================================\n";
echo "Duplicate sales fixed:         {$stats['duplicate_sales_fixed']}\n";
echo "Duplicate purchases fixed:     {$stats['duplicate_purchases_fixed']}\n";
echo "Stock Opname fixed:            {$stats['opname_fixed']}\n";
echo "Stock Opname inserted:         {$stats['opname_inserted']}\n";
echo "Records after cutoff fixed:    {$stats['after_cutoff_fixed']}\n";
echo "Produk.stok synced:            {$stats['produk_synced']}\n";
echo "=======================================================\n\n";

if ($dryRun) {
    echo "*** DRY RUN - No changes were made ***\n";
    echo "Run with --execute to apply changes\n";
} else {
    echo "*** CHANGES APPLIED ***\n";
}

$logFile = __DIR__ . '/conservative_fix_log_' . date('Ymd_His') . '.json';
file_put_contents($logFile, json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'mode' => $dryRun ? 'DRY_RUN' : 'EXECUTE',
    'stats' => $stats
], JSON_PRETTY_PRINT));

echo "Log saved to: {$logFile}\n";
