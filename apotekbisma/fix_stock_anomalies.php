<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=======================================================\n";
echo "  AUDIT & FIX STOCK ANOMALIES - ALL PRODUCTS\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

$dryRun = true;
if (isset($argv[1]) && $argv[1] === '--fix') {
    $dryRun = false;
    echo "*** MODE: FIX (akan mengubah database) ***\n\n";
} else {
    echo "*** MODE: AUDIT ONLY (tambahkan --fix untuk memperbaiki) ***\n\n";
}

$products = DB::table('produk')->select('id_produk', 'nama_produk', 'stok')->get();

$totalProducts = count($products);
$productsWithAnomalies = 0;
$totalAnomalies = 0;
$totalFixed = 0;

$anomalyReport = [];

foreach ($products as $product) {
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
    $productAnomalies = [];

    foreach ($records as $record) {
        $masuk = $record->stok_masuk ?? 0;
        $keluar = $record->stok_keluar ?? 0;
        
        $expectedStock = $runningStock + $masuk - $keluar;
        $actualStock = $record->stok_sisa;
        
        if ($expectedStock != $actualStock) {
            $productAnomalies[] = [
                'id' => $record->id_rekaman_stok,
                'waktu' => $record->waktu,
                'masuk' => $masuk,
                'keluar' => $keluar,
                'expected' => $expectedStock,
                'actual' => $actualStock,
                'diff' => $actualStock - $expectedStock
            ];
            
            if (!$dryRun) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $record->id_rekaman_stok)
                    ->update([
                        'stok_sisa' => $expectedStock,
                        'stok_awal' => $runningStock
                    ]);
                $totalFixed++;
            }
            
            $runningStock = $expectedStock;
        } else {
            $runningStock = $actualStock;
        }
    }

    if (!empty($productAnomalies)) {
        $productsWithAnomalies++;
        $totalAnomalies += count($productAnomalies);
        $anomalyReport[$product->id_produk] = [
            'name' => $product->nama_produk,
            'current_stock' => $product->stok,
            'calculated_final_stock' => $runningStock,
            'anomalies' => $productAnomalies
        ];
    }
    
    if (!$dryRun && $runningStock != $product->stok) {
        DB::table('produk')
            ->where('id_produk', $product->id_produk)
            ->update(['stok' => $runningStock]);
    }
}

echo "=======================================================\n";
echo "  SUMMARY\n";
echo "=======================================================\n";
echo "Total Products Checked: $totalProducts\n";
echo "Products with Anomalies: $productsWithAnomalies\n";
echo "Total Anomalies Found: $totalAnomalies\n";

if (!$dryRun) {
    echo "Total Records Fixed: $totalFixed\n";
}

echo "\n";

if (!empty($anomalyReport)) {
    echo "=======================================================\n";
    echo "  DETAILED ANOMALY REPORT (Max 10 products shown)\n";
    echo "=======================================================\n\n";
    
    $shown = 0;
    foreach ($anomalyReport as $productId => $data) {
        if ($shown >= 10) {
            echo "... and " . (count($anomalyReport) - 10) . " more products with anomalies.\n";
            break;
        }
        
        echo "Product ID: $productId\n";
        echo "Name: {$data['name']}\n";
        echo "Current Stock (DB): {$data['current_stock']}\n";
        echo "Calculated Final Stock: {$data['calculated_final_stock']}\n";
        echo "Anomalies (" . count($data['anomalies']) . "):\n";
        
        $anomalyCount = 0;
        foreach ($data['anomalies'] as $a) {
            if ($anomalyCount >= 5) {
                echo "  ... and " . (count($data['anomalies']) - 5) . " more anomalies for this product.\n";
                break;
            }
            echo "  - ID {$a['id']} | {$a['waktu']} | +{$a['masuk']} -{$a['keluar']} | Expected: {$a['expected']} | Actual: {$a['actual']} | Diff: {$a['diff']}\n";
            $anomalyCount++;
        }
        echo "\n";
        $shown++;
    }
}

if ($dryRun && $totalAnomalies > 0) {
    echo "=======================================================\n";
    echo "  TO FIX ALL ANOMALIES, RUN:\n";
    echo "  php fix_stock_anomalies.php --fix\n";
    echo "=======================================================\n";
}

echo "\nDone.\n";
