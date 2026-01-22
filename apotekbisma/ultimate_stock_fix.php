<?php
/**
 * ULTIMATE STOCK FIX SCRIPT
 * ========================
 * 
 * Tujuan: Menyinkronkan SEMUA produk yang ada di CSV dengan database
 * 
 * Logika:
 * 1. CSV adalah SUMBER KEBENARAN untuk baseline 31 Desember 2025
 * 2. Untuk SETIAP produk di CSV:
 *    - Cari produk di database berdasarkan ID
 *    - Hitung transaksi SETELAH cutoff (31 Dec 2025 23:59:59)
 *    - Stok Final = CSV_Baseline + Pembelian - Penjualan
 *    - Update stok produk
 * 3. Produk yang tidak ada di CSV TIDAK disentuh
 * 4. Buat rekaman_stoks baseline jika belum ada
 * 
 * Author: AI Assistant
 * Date: 2026-01-22
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

// Configuration
$CUTOFF_DATETIME = '2025-12-31 23:59:59';
$DRY_RUN = false; // Set to true for testing without changes

echo "=============================================================\n";
echo "ULTIMATE STOCK FIX SCRIPT\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Mode: " . ($DRY_RUN ? "DRY RUN (no changes)" : "LIVE (will update database)") . "\n";
echo "Cutoff: $CUTOFF_DATETIME\n";
echo "=============================================================\n\n";

// 1. Load CSV Data
echo "[STEP 1] Loading CSV data...\n";
$csvPath = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';

if (!file_exists($csvPath)) {
    die("ERROR: CSV file not found at: $csvPath\n");
}

$csvData = [];
$handle = fopen($csvPath, 'r');

// Read header
$header = fgetcsv($handle);
echo "CSV Header: " . implode(', ', $header) . "\n";

// Read data
$lineNumber = 1;
while (($row = fgetcsv($handle)) !== false) {
    $lineNumber++;
    
    if (count($row) < 3) {
        echo "WARNING: Line $lineNumber has less than 3 columns, skipping.\n";
        continue;
    }
    
    $id = trim($row[0]);
    $nama = trim($row[1]);
    $stok = (int) trim($row[2]);
    
    // Skip empty IDs
    if (empty($id) || !is_numeric($id)) {
        echo "WARNING: Line $lineNumber has invalid ID '$id', skipping.\n";
        continue;
    }
    
    // Handle duplicate IDs in CSV (use last occurrence)
    if (isset($csvData[$id])) {
        echo "INFO: Duplicate ID $id found. Previous: {$csvData[$id]['nama']}={$csvData[$id]['stok']}, New: $nama=$stok\n";
    }
    
    $csvData[$id] = [
        'id' => (int) $id,
        'nama' => $nama,
        'stok' => $stok
    ];
}
fclose($handle);

echo "CSV products loaded: " . count($csvData) . "\n\n";

// 2. Get transaction data after cutoff
echo "[STEP 2] Analyzing transactions after cutoff...\n";

// Get all penjualan (sales) after cutoff, grouped by product
// Column names: id_penjualan, id_produk, jumlah
$penjualanData = DB::table('penjualan_detail as pd')
    ->join('penjualan as p', 'pd.id_penjualan', '=', 'p.id_penjualan')
    ->where('p.created_at', '>', $CUTOFF_DATETIME)
    ->select('pd.id_produk', DB::raw('SUM(pd.jumlah) as total_sold'))
    ->groupBy('pd.id_produk')
    ->get()
    ->keyBy('id_produk');

echo "Products with sales after cutoff: " . count($penjualanData) . "\n";

// Get all pembelian (purchases) after cutoff, grouped by product
// Column names: id_pembelian, id_produk, jumlah
$pembelianData = DB::table('pembelian_detail as pd')
    ->join('pembelian as p', 'pd.id_pembelian', '=', 'p.id_pembelian')
    ->where('p.created_at', '>', $CUTOFF_DATETIME)
    ->select('pd.id_produk', DB::raw('SUM(pd.jumlah) as total_purchased'))
    ->groupBy('pd.id_produk')
    ->get()
    ->keyBy('id_produk');

echo "Products with purchases after cutoff: " . count($pembelianData) . "\n\n";

// 3. Process each CSV product
echo "[STEP 3] Processing products...\n\n";

$stats = [
    'total_csv' => count($csvData),
    'found_in_db' => 0,
    'not_found_in_db' => 0,
    'updated' => 0,
    'already_correct' => 0,
    'errors' => 0,
    'baseline_created' => 0
];

$updates = [];
$notFound = [];
$errors = [];

foreach ($csvData as $csvId => $csvItem) {
    // Find product in database by ID
    $dbProduct = DB::table('produk')->where('id_produk', $csvId)->first();
    
    if (!$dbProduct) {
        $stats['not_found_in_db']++;
        $notFound[] = $csvItem;
        continue;
    }
    
    $stats['found_in_db']++;
    
    // Get baseline stock from CSV
    $baseline = $csvItem['stok'];
    
    // Get transactions after cutoff
    $totalSold = isset($penjualanData[$csvId]) ? (int) $penjualanData[$csvId]->total_sold : 0;
    $totalPurchased = isset($pembelianData[$csvId]) ? (int) $pembelianData[$csvId]->total_purchased : 0;
    
    // Calculate expected stock
    $expectedStock = $baseline + $totalPurchased - $totalSold;
    
    // Compare with current DB stock
    $currentStock = (int) $dbProduct->stok;
    
    if ($currentStock === $expectedStock) {
        $stats['already_correct']++;
    } else {
        // Prepare update
        $updates[] = [
            'id' => $csvId,
            'nama' => $csvItem['nama'],
            'csv_baseline' => $baseline,
            'purchased' => $totalPurchased,
            'sold' => $totalSold,
            'expected' => $expectedStock,
            'current' => $currentStock,
            'diff' => $expectedStock - $currentStock
        ];
    }
}

echo "=============================================================\n";
echo "ANALYSIS RESULTS\n";
echo "=============================================================\n\n";

echo "Total products in CSV: {$stats['total_csv']}\n";
echo "Found in database: {$stats['found_in_db']}\n";
echo "NOT found in database: {$stats['not_found_in_db']}\n";
echo "Already correct: {$stats['already_correct']}\n";
echo "Need update: " . count($updates) . "\n\n";

// Show products not found
if (count($notFound) > 0) {
    echo "=============================================================\n";
    echo "PRODUCTS IN CSV BUT NOT IN DATABASE (" . count($notFound) . ")\n";
    echo "=============================================================\n\n";
    
    foreach ($notFound as $item) {
        echo "  ID {$item['id']}: {$item['nama']} (stock: {$item['stok']})\n";
    }
    echo "\n";
}

// Show updates needed
if (count($updates) > 0) {
    echo "=============================================================\n";
    echo "PRODUCTS TO UPDATE (" . count($updates) . ")\n";
    echo "=============================================================\n\n";
    
    echo sprintf("%-6s | %-35s | %-6s | %-5s | %-5s | %-8s | %-6s | %-6s\n",
        "ID", "NAMA", "CSV", "+BUY", "-SELL", "EXPECTED", "CURRENT", "DIFF");
    echo str_repeat("-", 110) . "\n";
    
    foreach ($updates as $item) {
        echo sprintf("%-6d | %-35s | %-6d | %-5d | %-5d | %-8d | %-6d | %+6d\n",
            $item['id'],
            substr($item['nama'], 0, 35),
            $item['csv_baseline'],
            $item['purchased'],
            $item['sold'],
            $item['expected'],
            $item['current'],
            $item['diff']
        );
    }
    echo "\n";
}

// 4. Apply updates
if (!$DRY_RUN && count($updates) > 0) {
    echo "=============================================================\n";
    echo "APPLYING UPDATES...\n";
    echo "=============================================================\n\n";
    
    DB::beginTransaction();
    
    try {
        foreach ($updates as $item) {
            // Update product stock
            DB::table('produk')
                ->where('id_produk', $item['id'])
                ->update([
                    'stok' => $item['expected'],
                    'updated_at' => now()
                ]);
            
            $stats['updated']++;
            
            if ($stats['updated'] % 50 === 0) {
                echo "Updated {$stats['updated']} products...\n";
            }
        }
        
        DB::commit();
        
        echo "\n✓ Successfully updated {$stats['updated']} products!\n\n";
        
    } catch (\Exception $e) {
        DB::rollBack();
        echo "ERROR: " . $e->getMessage() . "\n";
        $stats['errors']++;
    }
}

// 5. Create/Update Stock Opname baseline records
echo "=============================================================\n";
echo "CREATING STOCK OPNAME BASELINE RECORDS...\n";
echo "=============================================================\n\n";

if (!$DRY_RUN) {
    $baselineDate = '2025-12-31 23:59:59';
    
    foreach ($csvData as $csvId => $csvItem) {
        // Check if product exists
        $dbProduct = DB::table('produk')->where('id_produk', $csvId)->first();
        if (!$dbProduct) continue;
        
        // Check if SO baseline already exists
        $existingSO = DB::table('rekaman_stoks')
            ->where('produk_id', $csvId)
            ->where('jenis', 'Stock Opname')
            ->whereDate('created_at', '2025-12-31')
            ->first();
        
        if (!$existingSO) {
            // Create SO baseline record
            try {
                // The stok_sisa should be the CSV baseline
                // This becomes the "starting point" for post-cutoff transactions
                DB::table('rekaman_stoks')->insert([
                    'produk_id' => $csvId,
                    'jumlah' => $csvItem['stok'], // The baseline stock
                    'jenis' => 'Stock Opname',
                    'stok_sebelum' => 0, // Before opname was 0 (reset point)
                    'stok_sisa' => $csvItem['stok'], // After opname = CSV baseline
                    'keterangan' => 'Stock Opname Cutoff 31 Desember 2025 (CSV Baseline)',
                    'created_at' => $baselineDate,
                    'updated_at' => $baselineDate
                ]);
                
                $stats['baseline_created']++;
                
            } catch (\Exception $e) {
                echo "WARNING: Could not create baseline for ID $csvId: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "✓ Created {$stats['baseline_created']} new Stock Opname baseline records.\n\n";
}

// 6. Verification - Re-fetch from database
echo "=============================================================\n";
echo "VERIFICATION (Re-fetching from database)\n";
echo "=============================================================\n\n";

$verifyFailed = 0;
$verifySuccess = 0;
$verifyFailedItems = [];

foreach ($csvData as $csvId => $csvItem) {
    // Re-fetch product from database
    $dbProduct = DB::table('produk')->where('id_produk', $csvId)->first();
    if (!$dbProduct) continue;
    
    $baseline = $csvItem['stok'];
    $totalSold = isset($penjualanData[$csvId]) ? (int) $penjualanData[$csvId]->total_sold : 0;
    $totalPurchased = isset($pembelianData[$csvId]) ? (int) $pembelianData[$csvId]->total_purchased : 0;
    $expectedStock = $baseline + $totalPurchased - $totalSold;
    
    if ((int) $dbProduct->stok === $expectedStock) {
        $verifySuccess++;
    } else {
        $verifyFailed++;
        $verifyFailedItems[] = [
            'id' => $csvId,
            'nama' => $csvItem['nama'],
            'expected' => $expectedStock,
            'actual' => $dbProduct->stok
        ];
    }
}

if ($verifyFailed > 0) {
    echo "VERIFICATION FAILED FOR:\n";
    foreach ($verifyFailedItems as $item) {
        echo "  ID {$item['id']}: {$item['nama']} - Expected: {$item['expected']}, Actual: {$item['actual']}\n";
    }
    echo "\n";
}

echo "Verification Results:\n";
echo "  Success: $verifySuccess\n";
echo "  Failed: $verifyFailed\n\n";

// Final Summary
echo "=============================================================\n";
echo "FINAL SUMMARY\n";
echo "=============================================================\n\n";

echo "Total CSV Products: {$stats['total_csv']}\n";
echo "Found in Database: {$stats['found_in_db']}\n";
echo "Not Found in DB: {$stats['not_found_in_db']}\n";
echo "Already Correct: {$stats['already_correct']}\n";
echo "Updated: {$stats['updated']}\n";
echo "Baseline Records Created: {$stats['baseline_created']}\n";
echo "Errors: {$stats['errors']}\n";
echo "\nVerification: $verifySuccess passed, $verifyFailed failed\n";

if ($verifyFailed === 0 && $stats['found_in_db'] > 0) {
    echo "\n";
    echo "╔═══════════════════════════════════════════════════════════╗\n";
    echo "║   ✓✓✓ ALL PRODUCTS SYNCHRONIZED SUCCESSFULLY! ✓✓✓        ║\n";
    echo "╚═══════════════════════════════════════════════════════════╝\n";
} else {
    echo "\n⚠ WARNING: $verifyFailed products still have mismatched stock!\n";
}

echo "\n=============================================================\n";
echo "SCRIPT COMPLETED AT " . date('Y-m-d H:i:s') . "\n";
echo "=============================================================\n";

// Save report
$reportPath = __DIR__ . '/ultimate_fix_report_' . date('Y-m-d_His') . '.txt';
$reportContent = "ULTIMATE STOCK FIX REPORT\n";
$reportContent .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
$reportContent .= "Statistics:\n";
$reportContent .= "  Total CSV Products: {$stats['total_csv']}\n";
$reportContent .= "  Found in Database: {$stats['found_in_db']}\n";
$reportContent .= "  Not Found in DB: {$stats['not_found_in_db']}\n";
$reportContent .= "  Already Correct: {$stats['already_correct']}\n";
$reportContent .= "  Updated: {$stats['updated']}\n";
$reportContent .= "  Baseline Records Created: {$stats['baseline_created']}\n";
$reportContent .= "  Errors: {$stats['errors']}\n";
$reportContent .= "\nVerification: $verifySuccess passed, $verifyFailed failed\n";
$reportContent .= "\nProducts Updated:\n";
foreach ($updates as $item) {
    $reportContent .= "  {$item['id']}: {$item['nama']} - Changed from {$item['current']} to {$item['expected']}\n";
}
file_put_contents($reportPath, $reportContent);
echo "\nReport saved to: $reportPath\n";
