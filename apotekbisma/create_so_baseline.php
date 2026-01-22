<?php
/**
 * CREATE STOCK OPNAME BASELINE RECORDS
 * 
 * Membuat rekaman stock opname baseline untuk semua produk di CSV
 * agar kartu stok menampilkan dengan benar
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$CUTOFF_DATETIME = '2025-12-31 23:59:59';

echo "=============================================================\n";
echo "CREATE STOCK OPNAME BASELINE RECORDS\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "=============================================================\n\n";

// Load CSV
$csvPath = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$csvData = [];
$handle = fopen($csvPath, 'r');
$header = fgetcsv($handle);

while (($row = fgetcsv($handle)) !== false) {
    if (count($row) >= 3) {
        $id = (int) trim($row[0]);
        $nama = trim($row[1]);
        $stok = (int) trim($row[2]);
        $csvData[$id] = ['nama' => $nama, 'stok' => $stok];
    }
}
fclose($handle);

echo "CSV loaded: " . count($csvData) . " products\n\n";

// Check existing SO records
$existingSO = DB::table('rekaman_stoks')
    ->whereDate('created_at', '2025-12-31')
    ->where('keterangan', 'LIKE', '%Stock Opname%')
    ->count();

echo "Existing Stock Opname records on 2025-12-31: $existingSO\n\n";

$created = 0;
$updated = 0;
$skipped = 0;

DB::beginTransaction();

try {
    foreach ($csvData as $id => $item) {
        // Check if product exists
        $dbProduct = DB::table('produk')->where('id_produk', $id)->first();
        if (!$dbProduct) {
            $skipped++;
            continue;
        }
        
        // Check if SO baseline already exists for this product
        $existing = DB::table('rekaman_stoks')
            ->where('id_produk', $id)
            ->whereDate('created_at', '2025-12-31')
            ->where('keterangan', 'LIKE', '%Stock Opname%')
            ->first();
        
        if ($existing) {
            // Update existing
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $existing->id_rekaman_stok)
                ->update([
                    'stok_masuk' => $item['stok'],
                    'stok_keluar' => 0,
                    'stok_awal' => 0,
                    'stok_sisa' => $item['stok'],
                    'keterangan' => 'Stock Opname Cutoff 31 Desember 2025',
                    'updated_at' => now()
                ]);
            $updated++;
        } else {
            // Create new
            DB::table('rekaman_stoks')->insert([
                'id_produk' => $id,
                'waktu' => $CUTOFF_DATETIME,
                'stok_masuk' => $item['stok'],
                'stok_keluar' => 0,
                'stok_awal' => 0,
                'stok_sisa' => $item['stok'],
                'keterangan' => 'Stock Opname Cutoff 31 Desember 2025',
                'id_penjualan' => null,
                'id_pembelian' => null,
                'created_at' => $CUTOFF_DATETIME,
                'updated_at' => $CUTOFF_DATETIME
            ]);
            $created++;
        }
    }
    
    DB::commit();
    
    echo "=============================================================\n";
    echo "RESULTS\n";
    echo "=============================================================\n\n";
    echo "Created: $created\n";
    echo "Updated: $updated\n";
    echo "Skipped (not in DB): $skipped\n";
    
    echo "\n✓ Stock Opname baseline records created/updated successfully!\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=============================================================\n";
echo "DONE\n";
echo "=============================================================\n";
