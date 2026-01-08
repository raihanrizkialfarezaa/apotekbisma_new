<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=======================================================\n";
echo "  RECALCULATE ALL STOCK (COMPLETE FIX)\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

$dryRun = true;
if (isset($argv[1]) && $argv[1] === '--fix') {
    $dryRun = false;
    echo "*** MODE: FIX (akan mengubah database) ***\n\n";
} else {
    echo "*** MODE: DRY RUN (tambahkan --fix untuk memperbaiki) ***\n\n";
}

$baselineFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$baseline = [];

if (file_exists($baselineFile)) {
    echo "Loading baseline from CSV...\n";
    $handle = fopen($baselineFile, 'r');
    $header = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        if (isset($row[0]) && isset($row[2])) {
            $baseline[(int)$row[0]] = (int)$row[2];
        }
    }
    fclose($handle);
    echo "Loaded baseline for " . count($baseline) . " products.\n\n";
} else {
    echo "No baseline CSV found. Will calculate from first transaction.\n\n";
}

$baselineDate = '2025-12-31 23:59:59';

$products = DB::table('produk')->select('id_produk', 'nama_produk', 'stok')->get();

$totalProducts = count($products);
$productsFixed = 0;
$recordsFixed = 0;
$stokProdukUpdated = 0;

foreach ($products as $index => $product) {
    $hasBaseline = isset($baseline[$product->id_produk]);
    $baselineStock = $hasBaseline ? $baseline[$product->id_produk] : 0;
    
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();

    if ($records->isEmpty()) {
        continue;
    }

    $runningStock = 0;
    $productNeedsUpdate = false;
    $updatesForProduct = [];
    
    if ($hasBaseline) {
        $beforeBaselineRecords = $records->filter(function($r) use ($baselineDate) {
            return $r->waktu <= $baselineDate;
        });
        $afterBaselineRecords = $records->filter(function($r) use ($baselineDate) {
            return $r->waktu > $baselineDate;
        });
        
        $runningStock = $baselineStock;
        
        foreach ($afterBaselineRecords as $record) {
            $masuk = $record->stok_masuk ?? 0;
            $keluar = $record->stok_keluar ?? 0;
            $stokAwal = $runningStock;
            $expectedStock = $runningStock + $masuk - $keluar;
            
            if ($record->stok_sisa != $expectedStock || $record->stok_awal != $stokAwal) {
                $productNeedsUpdate = true;
                $updatesForProduct[] = [
                    'id' => $record->id_rekaman_stok,
                    'stok_awal' => $stokAwal,
                    'stok_sisa' => $expectedStock
                ];
            }
            
            $runningStock = $expectedStock;
        }
    } else {
        $firstRecord = $records->first();
        if ($firstRecord) {
            $masuk = $firstRecord->stok_masuk ?? 0;
            $keluar = $firstRecord->stok_keluar ?? 0;
            $runningStock = $firstRecord->stok_sisa;
            $impliedStartStock = $runningStock - $masuk + $keluar;
            $runningStock = $impliedStartStock;
        }
        
        foreach ($records as $record) {
            $masuk = $record->stok_masuk ?? 0;
            $keluar = $record->stok_keluar ?? 0;
            $stokAwal = $runningStock;
            $expectedStock = $runningStock + $masuk - $keluar;
            
            if ($record->stok_sisa != $expectedStock || $record->stok_awal != $stokAwal) {
                $productNeedsUpdate = true;
                $updatesForProduct[] = [
                    'id' => $record->id_rekaman_stok,
                    'stok_awal' => $stokAwal,
                    'stok_sisa' => $expectedStock
                ];
            }
            
            $runningStock = $expectedStock;
        }
    }
    
    if ($productNeedsUpdate) {
        $productsFixed++;
        $recordsFixed += count($updatesForProduct);
        
        if (!$dryRun) {
            foreach ($updatesForProduct as $update) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $update['id'])
                    ->update([
                        'stok_sisa' => $update['stok_sisa'],
                        'stok_awal' => $update['stok_awal']
                    ]);
            }
        }
    }
    
    if ($runningStock != $product->stok) {
        $stokProdukUpdated++;
        if (!$dryRun) {
            DB::table('produk')
                ->where('id_produk', $product->id_produk)
                ->update(['stok' => $runningStock]);
        }
    }
    
    if (($index + 1) % 100 == 0) {
        echo "Processed " . ($index + 1) . " / $totalProducts products...\n";
    }
}

echo "\n=======================================================\n";
echo "  SUMMARY\n";
echo "=======================================================\n";
echo "Total Products Checked: $totalProducts\n";
echo "Products with rekaman_stoks fixes needed: $productsFixed\n";
echo "Total rekaman_stoks records to fix: $recordsFixed\n";
echo "produk.stok values to update: $stokProdukUpdated\n";

if ($dryRun) {
    echo "\n*** DRY RUN - No changes made ***\n";
    echo "Run with --fix to apply changes.\n";
} else {
    echo "\n*** CHANGES APPLIED ***\n";
}

echo "\nDone.\n";
