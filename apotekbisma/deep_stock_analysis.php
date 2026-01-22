<?php
/**
 * DEEP STOCK ANALYSIS - Analisis Mendalam Masalah Stok
 * Tujuan: Menemukan akar masalah mengapa stok tidak sinkron dengan CSV
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=============================================================\n";
echo "DEEP STOCK ANALYSIS - " . date('Y-m-d H:i:s') . "\n";
echo "=============================================================\n\n";

// 1. Load CSV data
$csvPath = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
if (!file_exists($csvPath)) {
    die("ERROR: CSV file not found!\n");
}

$csvData = [];
$csvByName = [];
$handle = fopen($csvPath, 'r');
$header = fgetcsv($handle); // Skip header

while (($row = fgetcsv($handle)) !== false) {
    if (count($row) >= 3) {
        $id = trim($row[0]);
        $nama = trim($row[1]);
        $stok = (int) trim($row[2]);
        
        $csvData[$id] = [
            'id' => $id,
            'nama' => $nama,
            'stok' => $stok
        ];
        
        // Index by normalized name for matching
        $normalizedName = strtoupper(trim($nama));
        $csvByName[$normalizedName] = [
            'id' => $id,
            'nama' => $nama,
            'stok' => $stok
        ];
    }
}
fclose($handle);

echo "CSV Products Loaded: " . count($csvData) . "\n\n";

// 2. Get all products from database
$dbProducts = DB::table('produk')->get();
echo "Database Products: " . count($dbProducts) . "\n\n";

// 3. Analyze specific problematic products
echo "=============================================================\n";
echo "ANALYSIS OF PROBLEMATIC PRODUCTS\n";
echo "=============================================================\n\n";

$problemProducts = ['ACTIFED', 'ANACETIN', 'HYPAFIX'];

foreach ($problemProducts as $searchTerm) {
    echo "--- Searching for: $searchTerm ---\n\n";
    
    // Find in CSV
    echo "In CSV:\n";
    foreach ($csvData as $id => $item) {
        if (stripos($item['nama'], $searchTerm) !== false) {
            echo "  ID: {$item['id']}, Name: {$item['nama']}, Stock: {$item['stok']}\n";
        }
    }
    
    // Find in database
    echo "\nIn Database:\n";
    $dbItems = DB::table('produk')
        ->where('nama_produk', 'LIKE', "%{$searchTerm}%")
        ->get();
    
    foreach ($dbItems as $item) {
        echo "  ID: {$item->id_produk}, Name: {$item->nama_produk}, Stock: {$item->stok}\n";
        
        // Check if this ID exists in CSV
        if (isset($csvData[$item->id_produk])) {
            $csvItem = $csvData[$item->id_produk];
            $match = ($item->stok == $csvItem['stok']) ? '✓ MATCH' : '✗ MISMATCH';
            echo "    CSV Match by ID: {$csvItem['nama']} = {$csvItem['stok']} {$match}\n";
        } else {
            echo "    NOT FOUND IN CSV by ID!\n";
            
            // Try to find by name
            $normalizedDbName = strtoupper(trim($item->nama_produk));
            if (isset($csvByName[$normalizedDbName])) {
                $csvItem = $csvByName[$normalizedDbName];
                echo "    BUT FOUND BY NAME: CSV ID={$csvItem['id']}, Stock={$csvItem['stok']}\n";
                echo "    >>> ID MISMATCH! DB ID={$item->id_produk} vs CSV ID={$csvItem['id']}\n";
            }
        }
    }
    echo "\n";
}

// 4. Comprehensive Matching Analysis
echo "=============================================================\n";
echo "COMPREHENSIVE MATCHING ANALYSIS\n";
echo "=============================================================\n\n";

$matchById = 0;
$matchByName = 0;
$notFoundInDb = 0;
$idMismatch = [];
$stockMismatch = [];

foreach ($csvData as $csvId => $csvItem) {
    // Try match by ID first
    $dbProduct = DB::table('produk')->where('id_produk', $csvId)->first();
    
    if ($dbProduct) {
        $matchById++;
        
        // Check stock
        if ($dbProduct->stok != $csvItem['stok']) {
            $stockMismatch[] = [
                'csv_id' => $csvId,
                'csv_name' => $csvItem['nama'],
                'csv_stock' => $csvItem['stok'],
                'db_id' => $dbProduct->id_produk,
                'db_name' => $dbProduct->nama_produk,
                'db_stock' => $dbProduct->stok,
                'diff' => $dbProduct->stok - $csvItem['stok']
            ];
        }
    } else {
        // Try match by name
        $normalizedCsvName = strtoupper(trim($csvItem['nama']));
        $dbProduct = DB::table('produk')
            ->whereRaw('UPPER(TRIM(nama_produk)) = ?', [$normalizedCsvName])
            ->first();
        
        if ($dbProduct) {
            $matchByName++;
            $idMismatch[] = [
                'csv_id' => $csvId,
                'csv_name' => $csvItem['nama'],
                'db_id' => $dbProduct->id_produk,
                'db_name' => $dbProduct->nama_produk
            ];
        } else {
            $notFoundInDb++;
        }
    }
}

echo "Match by ID: $matchById\n";
echo "Match by Name (ID different): $matchByName\n";
echo "Not found in DB: $notFoundInDb\n";
echo "Stock Mismatches: " . count($stockMismatch) . "\n\n";

// 5. Show ID Mismatches
if (count($idMismatch) > 0) {
    echo "=============================================================\n";
    echo "ID MISMATCHES (Same name, different ID)\n";
    echo "=============================================================\n\n";
    
    foreach ($idMismatch as $item) {
        echo "CSV: ID={$item['csv_id']}, Name={$item['csv_name']}\n";
        echo "DB:  ID={$item['db_id']}, Name={$item['db_name']}\n\n";
    }
}

// 6. Show Stock Mismatches
if (count($stockMismatch) > 0) {
    echo "=============================================================\n";
    echo "STOCK MISMATCHES (First 50)\n";
    echo "=============================================================\n\n";
    
    $count = 0;
    foreach ($stockMismatch as $item) {
        if ($count >= 50) break;
        echo sprintf(
            "ID %d: %s | CSV=%d, DB=%d, Diff=%+d\n",
            $item['csv_id'],
            substr($item['csv_name'], 0, 30),
            $item['csv_stock'],
            $item['db_stock'],
            $item['diff']
        );
        $count++;
    }
    
    echo "\n... and " . (count($stockMismatch) - 50) . " more\n";
}

// 7. Check transactions after cutoff
echo "\n=============================================================\n";
echo "TRANSACTION ANALYSIS (After 31 Dec 2025)\n";
echo "=============================================================\n\n";

$cutoff = '2025-12-31 23:59:59';

// Count penjualan after cutoff
$penjualanCount = DB::table('penjualan')
    ->where('created_at', '>', $cutoff)
    ->count();

$pembelianCount = DB::table('pembelian')
    ->where('created_at', '>', $cutoff)
    ->count();

echo "Penjualan after cutoff: $penjualanCount\n";
echo "Pembelian after cutoff: $pembelianCount\n";

// Sample: Check ACTIFED transactions
echo "\n--- ACTIFED 25ML ALL (ID 283) Transaction History ---\n";
$actifedId = 283;

// Check if exists in CSV
if (isset($csvData[$actifedId])) {
    echo "CSV Data: ID={$csvData[$actifedId]['id']}, Stock={$csvData[$actifedId]['stok']}\n";
} else {
    echo "NOT in CSV by ID 283!\n";
    // Search by name
    foreach ($csvData as $id => $item) {
        if (stripos($item['nama'], 'ACTIFED') !== false) {
            echo "Found in CSV: ID=$id, Name={$item['nama']}, Stock={$item['stok']}\n";
        }
    }
}

// Current DB stock
$actifed = DB::table('produk')->where('id_produk', $actifedId)->first();
if ($actifed) {
    echo "Current DB: ID={$actifed->id_produk}, Name={$actifed->nama_produk}, Stock={$actifed->stok}\n";
}

// Penjualan details
echo "\nPenjualan Details after cutoff:\n";
$penjualanDetails = DB::table('penjualan_detail as pd')
    ->join('penjualan as p', 'pd.penjualan_id', '=', 'p.id')
    ->where('pd.produk_id', $actifedId)
    ->where('p.created_at', '>', $cutoff)
    ->select('p.id', 'p.created_at', 'pd.jumlah')
    ->get();

$totalPenjualan = 0;
foreach ($penjualanDetails as $pd) {
    echo "  Sale ID {$pd->id}: {$pd->created_at}, Qty: {$pd->jumlah}\n";
    $totalPenjualan += $pd->jumlah;
}
echo "Total sold after cutoff: $totalPenjualan\n";

// Pembelian details
echo "\nPembelian Details after cutoff:\n";
$pembelianDetails = DB::table('pembelian_detail as pd')
    ->join('pembelian as p', 'pd.pembelian_id', '=', 'p.id')
    ->where('pd.produk_id', $actifedId)
    ->where('p.created_at', '>', $cutoff)
    ->select('p.id', 'p.created_at', 'pd.jumlah')
    ->get();

$totalPembelian = 0;
foreach ($pembelianDetails as $pd) {
    echo "  Purchase ID {$pd->id}: {$pd->created_at}, Qty: {$pd->jumlah}\n";
    $totalPembelian += $pd->pembelian;
}
echo "Total purchased after cutoff: $totalPembelian\n";

// Calculate expected stock
if (isset($csvData[$actifedId])) {
    $expectedStock = $csvData[$actifedId]['stok'] + $totalPembelian - $totalPenjualan;
    echo "\nExpected Stock Calculation:\n";
    echo "CSV Baseline: {$csvData[$actifedId]['stok']}\n";
    echo "+ Pembelian: $totalPembelian\n";
    echo "- Penjualan: $totalPenjualan\n";
    echo "= Expected: $expectedStock\n";
    if ($actifed) {
        echo "Current DB: {$actifed->stok}\n";
        echo "Status: " . ($expectedStock == $actifed->stok ? "✓ CORRECT" : "✗ WRONG") . "\n";
    }
}

// 8. Check rekaman_stoks for baseline
echo "\n=============================================================\n";
echo "REKAMAN STOKS BASELINE CHECK\n";
echo "=============================================================\n\n";

// Check if Stock Opname records exist for Dec 31
$soRecords = DB::table('rekaman_stoks')
    ->where('jenis', 'Stock Opname')
    ->whereDate('created_at', '2025-12-31')
    ->count();

echo "Stock Opname records on 2025-12-31: $soRecords\n";

// Check total unique products with SO baseline
$soProducts = DB::table('rekaman_stoks')
    ->where('jenis', 'Stock Opname')
    ->whereDate('created_at', '2025-12-31')
    ->distinct('produk_id')
    ->count('produk_id');

echo "Unique products with SO baseline: $soProducts\n";

// 9. Final Summary
echo "\n=============================================================\n";
echo "ROOT CAUSE ANALYSIS\n";
echo "=============================================================\n\n";

echo "1. CSV contains " . count($csvData) . " products\n";
echo "2. Database contains " . count($dbProducts) . " products\n";
echo "3. Products matched by ID: $matchById\n";
echo "4. Products with ID mismatch (same name, diff ID): $matchByName\n";
echo "5. Stock mismatches found: " . count($stockMismatch) . "\n";
echo "6. SO baseline records: $soRecords\n";

if (count($stockMismatch) > 0) {
    echo "\n>>> ISSUE: " . count($stockMismatch) . " products have incorrect stock!\n";
    echo ">>> The fix script may not have processed all products correctly.\n";
}

if ($matchByName > 0) {
    echo "\n>>> CRITICAL: $matchByName products have ID mismatches!\n";
    echo ">>> CSV uses different IDs than database for these products.\n";
}

echo "\n=============================================================\n";
echo "ANALYSIS COMPLETE\n";
echo "=============================================================\n";
