<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║     COMPREHENSIVE STOCK FIX - BASED ON STOCK OPNAME 31 DEC 2025              ║\n";
echo "║     Date: " . date('Y-m-d H:i:s') . "                                             ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";

set_time_limit(600);
ini_set('memory_limit', '512M');

$stockOpnameData = [];
$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
if (file_exists($csvFile)) {
    $handle = fopen($csvFile, 'r');
    $header = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) >= 3 && !empty($row[0])) {
            $stockOpnameData[intval($row[0])] = intval($row[2]);
        }
    }
    fclose($handle);
    echo "[INFO] Loaded " . count($stockOpnameData) . " products from stock opname file\n\n";
} else {
    die("[ERROR] Stock opname file not found!\n");
}

$cutoffDate = '2025-12-31 23:59:59';

$dryRun = isset($argv[1]) && $argv[1] === '--execute' ? false : true;

if ($dryRun) {
    echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║  DRY RUN MODE - No changes will be made. Use --execute to apply changes.     ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";
} else {
    echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║  EXECUTE MODE - Changes will be applied to database!                         ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";
}

$productIds = array_keys($stockOpnameData);
$totalProducts = count($productIds);
$fixedProducts = 0;
$skippedProducts = 0;
$errorProducts = 0;

$fixes = [];
$errors = [];

echo "Processing {$totalProducts} products...\n\n";

foreach ($productIds as $productId) {
    $opnameStock = $stockOpnameData[$productId];
    
    $product = DB::table('produk')->where('id_produk', $productId)->first();
    if (!$product) {
        $errors[] = "Product ID {$productId} not found in database";
        $errorProducts++;
        continue;
    }
    
    $allRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($allRekaman->isEmpty()) {
        $skippedProducts++;
        continue;
    }
    
    $rekamanBeforeCutoff = $allRekaman->filter(function($r) use ($cutoffDate) {
        return $r->waktu <= $cutoffDate;
    });
    
    $firstRekamanAfterCutoff = $allRekaman->filter(function($r) use ($cutoffDate) {
        return $r->waktu > $cutoffDate;
    })->first();
    
    $needsfix = false;
    $oldChain = [];
    $newChain = [];
    
    $lastRekamanBeforeCutoff = $rekamanBeforeCutoff->last();
    if ($lastRekamanBeforeCutoff) {
        if (intval($lastRekamanBeforeCutoff->stok_sisa) != $opnameStock) {
            $needsfix = true;
        }
    }
    
    if ($firstRekamanAfterCutoff && $lastRekamanBeforeCutoff) {
        if (intval($firstRekamanAfterCutoff->stok_awal) != intval($lastRekamanBeforeCutoff->stok_sisa)) {
            $needsfix = true;
        }
    }
    
    $prevSisa = null;
    foreach ($allRekaman as $r) {
        if ($prevSisa !== null && intval($r->stok_awal) != intval($prevSisa)) {
            $needsfix = true;
            break;
        }
        $prevSisa = $r->stok_sisa;
    }
    
    $lastRekaman = $allRekaman->last();
    if ($lastRekaman && intval($product->stok) != intval($lastRekaman->stok_sisa)) {
        $needsfix = true;
    }
    
    if (!$needsfix) {
        $skippedProducts++;
        continue;
    }
    
    echo "Fixing [{$productId}] {$product->nama_produk}...\n";
    
    $runningStock = $opnameStock;
    $updates = [];
    $adjustmentNeeded = false;
    
    $firstRecord = true;
    foreach ($allRekaman as $r) {
        $oldAwal = intval($r->stok_awal);
        $oldSisa = intval($r->stok_sisa);
        
        $newAwal = $runningStock;
        $newSisa = $runningStock + intval($r->stok_masuk) - intval($r->stok_keluar);
        
        if ($oldAwal != $newAwal || $oldSisa != $newSisa) {
            $updates[] = [
                'id' => $r->id_rekaman_stok,
                'old_awal' => $oldAwal,
                'new_awal' => $newAwal,
                'old_sisa' => $oldSisa,
                'new_sisa' => $newSisa,
                'waktu' => $r->waktu,
                'keterangan' => $r->keterangan
            ];
        }
        
        $runningStock = $newSisa;
        $firstRecord = false;
    }
    
    if (!empty($updates)) {
        $fixes[$productId] = [
            'nama' => $product->nama_produk,
            'opname_stock' => $opnameStock,
            'old_produk_stok' => $product->stok,
            'new_produk_stok' => $runningStock,
            'updates_count' => count($updates),
            'updates' => $updates
        ];
        
        if (!$dryRun) {
            DB::beginTransaction();
            try {
                foreach ($updates as $u) {
                    DB::table('rekaman_stoks')
                        ->where('id_rekaman_stok', $u['id'])
                        ->update([
                            'stok_awal' => $u['new_awal'],
                            'stok_sisa' => $u['new_sisa']
                        ]);
                }
                
                DB::table('produk')
                    ->where('id_produk', $productId)
                    ->update(['stok' => $runningStock]);
                
                DB::commit();
                echo "  [FIXED] Updated " . count($updates) . " records, produk.stok: {$product->stok} -> {$runningStock}\n";
            } catch (\Exception $e) {
                DB::rollBack();
                $errors[] = "Failed to fix product {$productId}: " . $e->getMessage();
                $errorProducts++;
                continue;
            }
        } else {
            echo "  [DRY RUN] Would update " . count($updates) . " records, produk.stok: {$product->stok} -> {$runningStock}\n";
        }
        
        $fixedProducts++;
    }
}

echo "\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "SUMMARY\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

echo "Total products in opname: {$totalProducts}\n";
echo "Products fixed: {$fixedProducts}\n";
echo "Products skipped (no issues): {$skippedProducts}\n";
echo "Products with errors: {$errorProducts}\n\n";

if (!empty($errors)) {
    echo "ERRORS:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
    echo "\n";
}

if (!empty($fixes)) {
    echo "═══════════════════════════════════════════════════════════════════════════════\n";
    echo "DETAILED FIXES (Top 20 products with most changes):\n";
    echo "═══════════════════════════════════════════════════════════════════════════════\n\n";
    
    uasort($fixes, function($a, $b) {
        return $b['updates_count'] - $a['updates_count'];
    });
    
    $shown = 0;
    foreach ($fixes as $productId => $fix) {
        if ($shown >= 20) break;
        
        echo "[{$productId}] {$fix['nama']}\n";
        echo "  Stock Opname 31 Dec: {$fix['opname_stock']}\n";
        echo "  produk.stok: {$fix['old_produk_stok']} -> {$fix['new_produk_stok']}\n";
        echo "  Records modified: {$fix['updates_count']}\n";
        
        if ($fix['updates_count'] <= 5) {
            foreach ($fix['updates'] as $u) {
                echo "    - [{$u['id']}] {$u['waktu']}: awal {$u['old_awal']}->{$u['new_awal']}, sisa {$u['old_sisa']}->{$u['new_sisa']}\n";
            }
        } else {
            $first = $fix['updates'][0];
            $last = end($fix['updates']);
            echo "    First: [{$first['id']}] {$first['waktu']}: awal {$first['old_awal']}->{$first['new_awal']}\n";
            echo "    Last:  [{$last['id']}] {$last['waktu']}: sisa {$last['old_sisa']}->{$last['new_sisa']}\n";
        }
        
        echo "\n";
        $shown++;
    }
}

if ($dryRun) {
    echo "═══════════════════════════════════════════════════════════════════════════════\n";
    echo "To apply these fixes, run:\n";
    echo "  php " . basename(__FILE__) . " --execute\n";
    echo "═══════════════════════════════════════════════════════════════════════════════\n";
}

$reportFile = __DIR__ . '/stock_fix_report_' . date('Y-m-d_His') . '.json';
file_put_contents($reportFile, json_encode([
    'generated_at' => date('Y-m-d H:i:s'),
    'mode' => $dryRun ? 'dry_run' : 'execute',
    'cutoff_date' => $cutoffDate,
    'summary' => [
        'total_products' => $totalProducts,
        'fixed' => $fixedProducts,
        'skipped' => $skippedProducts,
        'errors' => $errorProducts
    ],
    'fixes' => $fixes,
    'errors' => $errors
], JSON_PRETTY_PRINT));

echo "\nReport saved to: {$reportFile}\n";
