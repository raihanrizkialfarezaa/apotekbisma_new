<?php
/**
 * ULTIMATE STOCK FIX v4.0
 * =======================
 * Script komprehensif untuk memperbaiki SEMUA masalah stok:
 * 
 * STEP 0: Load CSV baseline
 * STEP 1: Hapus SEMUA duplikat Stock Opname yang BUKAN di cutoff date
 * STEP 2: Hapus duplikat transaksi penjualan/pembelian di rekaman_stoks
 * STEP 3: Pastikan Stock Opname di cutoff date untuk SEMUA produk
 * STEP 4: Recalculate chain untuk SEMUA produk (dari awal, bukan hanya setelah cutoff)
 * STEP 5: Sync produk.stok dengan rekaman_stoks terakhir
 * STEP 6: Verifikasi final dan fix produk negatif
 * 
 * USAGE:
 *   DRY RUN:  php ultimate_stock_fix_v4.php
 *   EXECUTE:  php ultimate_stock_fix_v4.php --execute
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
if (isset($_GET['execute']) && $_GET['execute'] == '1') {
    $dryRun = false;
}

$stats = [
    'wrong_opname_deleted' => 0,
    'duplicate_sales_deleted' => 0,
    'duplicate_purchases_deleted' => 0,
    'opname_inserted' => 0,
    'opname_updated' => 0,
    'chain_fixed_products' => 0,
    'chain_fixed_records' => 0,
    'produk_synced' => 0,
    'negative_fixed' => 0,
];

$now = Carbon::now();

echo "=======================================================\n";
echo "    ULTIMATE STOCK FIX v4.0\n";
echo "    " . ($dryRun ? "*** DRY RUN MODE ***" : "!!! EXECUTE MODE !!!") . "\n";
echo "=======================================================\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "Cutoff Date: {$cutoffDate}\n\n";

echo "STEP 0: Loading CSV baseline data...\n";

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

if (empty($csvData)) {
    echo "ERROR: CSV file is empty or not readable!\n";
    exit(1);
}

echo "  Loaded " . count($csvData) . " products from CSV\n\n";

echo "STEP 1: Deleting ALL wrong Stock Opname (not at cutoff)...\n";

$wrongOpname = DB::table('rekaman_stoks')
    ->where('keterangan', 'LIKE', '%Stock Opname%')
    ->where('waktu', '!=', $cutoffDate)
    ->get();

echo "  Found " . count($wrongOpname) . " wrong Stock Opname records\n";

if (!$dryRun && count($wrongOpname) > 0) {
    $deleteIds = $wrongOpname->pluck('id_rekaman_stok')->toArray();
    $chunks = array_chunk($deleteIds, 500);
    foreach ($chunks as $chunk) {
        DB::table('rekaman_stoks')->whereIn('id_rekaman_stok', $chunk)->delete();
    }
}
$stats['wrong_opname_deleted'] = count($wrongOpname);
echo "  Deleted: {$stats['wrong_opname_deleted']}\n\n";

echo "STEP 2: Deleting duplicate transaction records...\n";

$duplicateSales = DB::table('rekaman_stoks')
    ->select('id_produk', 'id_penjualan', DB::raw('MIN(id_rekaman_stok) as keep_id'), DB::raw('COUNT(*) as cnt'))
    ->whereNotNull('id_penjualan')
    ->groupBy('id_produk', 'id_penjualan')
    ->having('cnt', '>', 1)
    ->get();

echo "  Found " . count($duplicateSales) . " duplicate sale transactions\n";

foreach ($duplicateSales as $dup) {
    $toDelete = DB::table('rekaman_stoks')
        ->where('id_produk', $dup->id_produk)
        ->where('id_penjualan', $dup->id_penjualan)
        ->where('id_rekaman_stok', '!=', $dup->keep_id)
        ->pluck('id_rekaman_stok');
    
    if (!$dryRun && $toDelete->count() > 0) {
        DB::table('rekaman_stoks')->whereIn('id_rekaman_stok', $toDelete)->delete();
    }
    $stats['duplicate_sales_deleted'] += $toDelete->count();
}

$duplicatePurchases = DB::table('rekaman_stoks')
    ->select('id_produk', 'id_pembelian', DB::raw('MIN(id_rekaman_stok) as keep_id'), DB::raw('COUNT(*) as cnt'))
    ->whereNotNull('id_pembelian')
    ->groupBy('id_produk', 'id_pembelian')
    ->having('cnt', '>', 1)
    ->get();

echo "  Found " . count($duplicatePurchases) . " duplicate purchase transactions\n";

foreach ($duplicatePurchases as $dup) {
    $toDelete = DB::table('rekaman_stoks')
        ->where('id_produk', $dup->id_produk)
        ->where('id_pembelian', $dup->id_pembelian)
        ->where('id_rekaman_stok', '!=', $dup->keep_id)
        ->pluck('id_rekaman_stok');
    
    if (!$dryRun && $toDelete->count() > 0) {
        DB::table('rekaman_stoks')->whereIn('id_rekaman_stok', $toDelete)->delete();
    }
    $stats['duplicate_purchases_deleted'] += $toDelete->count();
}

echo "  Deleted sales duplicates: {$stats['duplicate_sales_deleted']}\n";
echo "  Deleted purchase duplicates: {$stats['duplicate_purchases_deleted']}\n\n";

echo "STEP 3: Ensuring Stock Opname at cutoff for ALL products...\n";

foreach ($csvData as $produkId => $baseline) {
    $produk = DB::table('produk')->where('id_produk', $produkId)->first();
    if (!$produk) continue;
    
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->where('waktu', '<', $cutoffDate)
        ->orderBy('waktu', 'desc')
        ->orderBy('created_at', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $stokAwalBeforeOpname = $lastBefore ? (int)$lastBefore->stok_sisa : 0;
    $baselineStok = $baseline['stok'];
    $diff = $baselineStok - $stokAwalBeforeOpname;
    
    $existingOpname = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->where('waktu', $cutoffDate)
        ->first();
    
    $stokMasuk = $diff > 0 ? $diff : null;
    $stokKeluar = $diff < 0 ? abs($diff) : null;
    
    if ($existingOpname) {
        if ((int)$existingOpname->stok_sisa !== $baselineStok || 
            (int)$existingOpname->stok_awal !== $stokAwalBeforeOpname) {
            if (!$dryRun) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $existingOpname->id_rekaman_stok)
                    ->update([
                        'stok_awal' => $stokAwalBeforeOpname,
                        'stok_masuk' => $stokMasuk,
                        'stok_keluar' => $stokKeluar,
                        'stok_sisa' => $baselineStok,
                        'keterangan' => "Stock Opname 31 Desember 2025",
                        'updated_at' => $now
                    ]);
            }
            $stats['opname_updated']++;
        }
    } else {
        if (!$dryRun) {
            DB::table('rekaman_stoks')->insert([
                'id_produk' => $produkId,
                'waktu' => $cutoffDate,
                'stok_awal' => $stokAwalBeforeOpname,
                'stok_masuk' => $stokMasuk,
                'stok_keluar' => $stokKeluar,
                'stok_sisa' => $baselineStok,
                'keterangan' => "Stock Opname 31 Desember 2025",
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }
        $stats['opname_inserted']++;
    }
}

echo "  Inserted: {$stats['opname_inserted']}\n";
echo "  Updated: {$stats['opname_updated']}\n\n";

echo "STEP 4: Recalculating chain for ALL products in CSV...\n";

foreach ($csvData as $produkId => $baseline) {
    $allRecords = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($allRecords->isEmpty()) continue;
    
    $runningStock = (int)$allRecords->first()->stok_awal;
    $isFirst = true;
    $hasChanges = false;
    $updates = [];
    
    foreach ($allRecords as $record) {
        $updateData = [];
        
        if ($isFirst) {
            $isFirst = false;
        } else {
            if ((int)$record->stok_awal !== $runningStock) {
                $updateData['stok_awal'] = $runningStock;
            }
        }
        
        $masuk = (int)($record->stok_masuk ?? 0);
        $keluar = (int)($record->stok_keluar ?? 0);
        $calculatedSisa = $runningStock + $masuk - $keluar;
        
        if ((int)$record->stok_sisa !== $calculatedSisa) {
            $updateData['stok_sisa'] = $calculatedSisa;
        }
        
        if (!empty($updateData)) {
            $updates[$record->id_rekaman_stok] = $updateData;
            $hasChanges = true;
        }
        
        $runningStock = $calculatedSisa;
    }
    
    if ($hasChanges) {
        $stats['chain_fixed_products']++;
        $stats['chain_fixed_records'] += count($updates);
        
        if (!$dryRun) {
            foreach ($updates as $recordId => $data) {
                $data['updated_at'] = $now;
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $recordId)
                    ->update($data);
            }
        }
    }
}

echo "  Fixed products: {$stats['chain_fixed_products']}\n";
echo "  Fixed records: {$stats['chain_fixed_records']}\n\n";

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
    
    $correctStock = max(0, (int)$latestRecord->stok_sisa);
    
    if ((int)$produk->stok !== $correctStock) {
        if (!$dryRun) {
            DB::table('produk')
                ->where('id_produk', $produkId)
                ->update(['stok' => $correctStock, 'updated_at' => $now]);
        }
        $stats['produk_synced']++;
    }
}

echo "  Synced: {$stats['produk_synced']}\n\n";

echo "STEP 6: Final verification - fixing any remaining negative stock...\n";

$negativeProducts = DB::table('produk')->where('stok', '<', 0)->get();

foreach ($negativeProducts as $produk) {
    $latestRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $correctStock = $latestRecord ? max(0, (int)$latestRecord->stok_sisa) : 0;
    
    if (!$dryRun) {
        DB::table('produk')
            ->where('id_produk', $produk->id_produk)
            ->update(['stok' => $correctStock, 'updated_at' => $now]);
    }
    $stats['negative_fixed']++;
}

echo "  Fixed negative: {$stats['negative_fixed']}\n\n";

echo "=======================================================\n";
echo "SUMMARY\n";
echo "=======================================================\n";
echo "Wrong Stock Opname deleted:    {$stats['wrong_opname_deleted']}\n";
echo "Duplicate sales deleted:       {$stats['duplicate_sales_deleted']}\n";
echo "Duplicate purchases deleted:   {$stats['duplicate_purchases_deleted']}\n";
echo "Stock Opname inserted:         {$stats['opname_inserted']}\n";
echo "Stock Opname updated:          {$stats['opname_updated']}\n";
echo "Chain fixed (products):        {$stats['chain_fixed_products']}\n";
echo "Chain fixed (records):         {$stats['chain_fixed_records']}\n";
echo "Produk.stok synced:            {$stats['produk_synced']}\n";
echo "Negative stock fixed:          {$stats['negative_fixed']}\n";
echo "=======================================================\n\n";

echo "Completed at: " . date('Y-m-d H:i:s') . "\n";

if ($dryRun) {
    echo "\n*** DRY RUN - No changes were made ***\n";
    echo "Run with --execute to apply changes\n";
} else {
    echo "\n*** CHANGES APPLIED ***\n";
}

$logFile = __DIR__ . '/ultimate_stock_fix_log_' . date('Ymd_His') . '.json';
file_put_contents($logFile, json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'mode' => $dryRun ? 'DRY_RUN' : 'EXECUTE',
    'stats' => $stats
], JSON_PRETTY_PRINT));

echo "Log saved to: {$logFile}\n";
