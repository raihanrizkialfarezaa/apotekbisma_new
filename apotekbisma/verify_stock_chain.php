<?php
/**
 * STOCK VERIFICATION SCRIPT
 * Verifikasi bahwa semua chain stok sudah benar setelah fix
 */

use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$cutoffDate = '2025-12-31 23:59:59';

if (!file_exists($csvFile)) {
    die("CSV not found!\n");
}

// Load CSV
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

echo "============================================================\n";
echo "STOCK VERIFICATION REPORT\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "============================================================\n\n";

$errors = [];
$warnings = [];
$okCount = 0;

foreach ($csvData as $productId => $info) {
    $opnameQty = $info['opname'];
    
    // Get baseline record
    $baselineRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '<=', $cutoffDate)
        ->orderBy('waktu', 'desc')
        ->orderBy('created_at', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $baselineStock = $baselineRecord ? (int)$baselineRecord->stok_sisa : 0;
    
    if ($baselineStock !== $opnameQty) {
        $errors[] = "Product $productId ({$info['name']}): Baseline mismatch - expected $opnameQty, got $baselineStock";
        continue;
    }
    
    // Verify chain after cutoff
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '>', $cutoffDate)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    $runningStock = $opnameQty;
    $chainOK = true;
    $chainError = '';
    
    foreach ($records as $rec) {
        if ((int)$rec->stok_awal !== $runningStock) {
            $chainOK = false;
            $chainError = "Record {$rec->id_rekaman_stok}: stok_awal should be $runningStock, got {$rec->stok_awal}";
            break;
        }
        
        $calcSisa = $runningStock + (int)$rec->stok_masuk - (int)$rec->stok_keluar;
        if ((int)$rec->stok_sisa !== $calcSisa) {
            $chainOK = false;
            $chainError = "Record {$rec->id_rekaman_stok}: stok_sisa should be $calcSisa, got {$rec->stok_sisa}";
            break;
        }
        
        $runningStock = $calcSisa;
    }
    
    if (!$chainOK) {
        $errors[] = "Product $productId ({$info['name']}): Chain error - $chainError";
        continue;
    }
    
    // Verify master stock
    $masterStock = (int)DB::table('produk')->where('id_produk', $productId)->value('stok');
    
    if ($masterStock !== $runningStock) {
        $errors[] = "Product $productId ({$info['name']}): Master mismatch - chain ends at $runningStock, master shows $masterStock";
        continue;
    }
    
    // Check for negative stock (warning only)
    if ($runningStock < 0) {
        $warnings[] = "Product $productId ({$info['name']}): Negative stock: $runningStock";
    }
    
    $okCount++;
}

echo "=== SUMMARY ===\n";
echo "Total products checked: " . count($csvData) . "\n";
echo "OK: $okCount\n";
echo "Errors: " . count($errors) . "\n";
echo "Warnings (negative stock): " . count($warnings) . "\n\n";

if (count($errors) > 0) {
    echo "=== ERRORS ===\n";
    foreach ($errors as $err) {
        echo "❌ $err\n";
    }
    echo "\n";
}

if (count($warnings) > 0) {
    echo "=== WARNINGS (Negative Stock) ===\n";
    foreach ($warnings as $warn) {
        echo "⚠️ $warn\n";
    }
    echo "\n";
}

if (count($errors) == 0) {
    echo "✅ ALL PRODUCTS VERIFIED SUCCESSFULLY!\n";
} else {
    echo "❌ SOME PRODUCTS HAVE ISSUES - RUN perfect_stock_fix.php AGAIN\n";
}
