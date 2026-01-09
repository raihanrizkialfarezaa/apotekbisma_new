<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║   FIX STOCK GAP - INSERT ADJUSTMENT RECORDS FOR STOCK OPNAME                 ║\n";
echo "║   Date: " . date('Y-m-d H:i:s') . "                                               ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";

$dryRun = !isset($argv[1]) || $argv[1] !== '--execute';

if ($dryRun) {
    echo "[DRY RUN MODE] Tidak ada perubahan yang akan dibuat.\n";
    echo "Jalankan dengan --execute untuk menerapkan perubahan.\n\n";
} else {
    echo "[EXECUTE MODE] Perubahan AKAN diterapkan ke database!\n\n";
}

$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$opnameData = [];
$handle = fopen($csvFile, 'r');
fgetcsv($handle);
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) >= 3 && !empty($row[0])) {
        $opnameData[intval($row[0])] = intval($row[2]);
    }
}
fclose($handle);

echo "Loaded " . count($opnameData) . " products from stock opname file\n\n";

$problems = [];
foreach ($opnameData as $pid => $opnameStock) {
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->where('waktu', '<=', '2025-12-31 23:59:59')
        ->orderBy('waktu', 'desc')
        ->first();
    
    $firstAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->where('waktu', '>', '2025-12-31 23:59:59')
        ->orderBy('waktu', 'asc')
        ->first();
    
    if ($lastBefore && $firstAfter && intval($firstAfter->stok_awal) != intval($lastBefore->stok_sisa)) {
        $problems[] = [
            'id' => $pid,
            'opname' => $opnameStock,
            'last_sisa' => intval($lastBefore->stok_sisa),
            'first_awal' => intval($firstAfter->stok_awal),
            'gap' => intval($firstAfter->stok_awal) - intval($lastBefore->stok_sisa),
            'last_rekaman_id' => $lastBefore->id_rekaman_stok,
            'first_rekaman_id' => $firstAfter->id_rekaman_stok
        ];
    }
}

echo "Ditemukan " . count($problems) . " produk dengan gap antara 2025-2026\n\n";

$fixedCount = 0;
$errorCount = 0;

foreach ($problems as $p) {
    $product = DB::table('produk')->where('id_produk', $p['id'])->first();
    $productName = $product ? $product->nama_produk : 'Unknown';
    
    echo "[{$p['id']}] {$productName}\n";
    echo "  Last 2025 stok_sisa: {$p['last_sisa']}\n";
    echo "  Opname 31 Des: {$p['opname']}\n";
    echo "  First 2026 stok_awal: {$p['first_awal']}\n";
    echo "  Gap: {$p['gap']}\n";
    
    $adjustmentAmount = $p['opname'] - $p['last_sisa'];
    
    if ($adjustmentAmount > 0) {
        $stokMasuk = $adjustmentAmount;
        $stokKeluar = 0;
        $jenis = "Penambahan";
    } else {
        $stokMasuk = 0;
        $stokKeluar = abs($adjustmentAmount);
        $jenis = "Pengurangan";
    }
    
    echo "  Penyesuaian: {$jenis} " . abs($adjustmentAmount) . " unit\n";
    
    $existingAdjustment = DB::table('rekaman_stoks')
        ->where('id_produk', $p['id'])
        ->where('waktu', '2025-12-31 23:59:59')
        ->where('keterangan', 'LIKE', '%Stock Opname%')
        ->first();
    
    if ($existingAdjustment) {
        echo "  [SKIP] Record penyesuaian stock opname sudah ada (ID: {$existingAdjustment->id_rekaman_stok})\n";
    } else {
        if (!$dryRun) {
            try {
                $insertId = DB::table('rekaman_stoks')->insertGetId([
                    'id_produk' => $p['id'],
                    'id_penjualan' => null,
                    'id_pembelian' => null,
                    'stok_awal' => $p['last_sisa'],
                    'stok_masuk' => $stokMasuk,
                    'stok_keluar' => $stokKeluar,
                    'stok_sisa' => $p['opname'],
                    'waktu' => '2025-12-31 23:59:59',
                    'keterangan' => 'Stock Opname 31 Desember 2025: Penyesuaian dari ' . $p['last_sisa'] . ' ke ' . $p['opname'],
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                echo "  [FIXED] Inserted adjustment record ID: {$insertId}\n";
                $fixedCount++;
            } catch (\Exception $e) {
                echo "  [ERROR] " . $e->getMessage() . "\n";
                $errorCount++;
            }
        } else {
            echo "  [DRY RUN] Akan insert record penyesuaian\n";
            $fixedCount++;
        }
    }
    
    echo "\n";
}

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "SUMMARY:\n";
echo "  Total problems: " . count($problems) . "\n";
echo "  Fixed/Would fix: {$fixedCount}\n";
echo "  Errors: {$errorCount}\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n";

if ($dryRun) {
    echo "\nUntuk menerapkan perbaikan, jalankan:\n";
    echo "  php " . basename(__FILE__) . " --execute\n";
}
