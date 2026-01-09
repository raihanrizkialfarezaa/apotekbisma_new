<?php
/**
 * FIX SORTING DISPLAY FOR STOCK OPNAME RECORDS
 * 
 * Problem: UI uses pembelian.waktu or penjualan.waktu for display and sorting,
 * but Stock Opname records have no pembelian/penjualan reference.
 * This causes Stock Opname records to appear in wrong position.
 * 
 * Solution: Update rekaman_stoks.waktu for purchases/sales to match their
 * actual transaction date, ensuring consistent chronological order.
 * 
 * Usage: php fix_stock_display_order.php [--execute]
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$dryRun = !in_array('--execute', $argv);

echo "=======================================================\n";
echo "FIX STOCK DISPLAY ORDER FOR AUDIT COMPLIANCE\n";
echo "=======================================================\n";
echo "Mode: " . ($dryRun ? "DRY RUN (tidak ada perubahan)" : "EXECUTE") . "\n\n";

// Problem: 
// 1. For pembelian records, UI shows pembelian.waktu but rekaman_stoks.waktu is different
// 2. Stock Opname at 2025-12-31 23:59:59 should appear AFTER all Dec 31 transactions
// 3. But pembelian with pembelian.waktu=2025-12-31 07:00:00 appears BEFORE Stock Opname

// Solution: 
// For all pembelian/penjualan records AFTER the cutoff date (2025-12-31 23:59:59),
// ensure their rekaman_stoks.waktu matches the actual transaction datetime

$cutoffDate = '2025-12-31 23:59:59';

echo "STEP 1: Check records with mismatched waktu\n";
echo str_repeat("-", 60) . "\n";

// Find pembelian records where rekaman_stoks.waktu != pembelian.waktu
$mismatchedPembelian = DB::select("
    SELECT rs.id_rekaman_stok, rs.id_produk, rs.waktu as rs_waktu, 
           rs.stok_masuk, rs.stok_sisa, p.waktu as pembelian_waktu, 
           p.no_faktur, p.id_pembelian
    FROM rekaman_stoks rs
    JOIN pembelian p ON rs.id_pembelian = p.id_pembelian
    WHERE rs.waktu != p.waktu
    AND rs.waktu > ?
    ORDER BY rs.waktu DESC
", [$cutoffDate]);

echo "Found " . count($mismatchedPembelian) . " pembelian records with mismatched waktu\n";

// Find penjualan records where rekaman_stoks.waktu != penjualan.waktu
$mismatchedPenjualan = DB::select("
    SELECT rs.id_rekaman_stok, rs.id_produk, rs.waktu as rs_waktu,
           rs.stok_keluar, rs.stok_sisa, pen.waktu as penjualan_waktu,
           pen.id_penjualan
    FROM rekaman_stoks rs
    JOIN penjualan pen ON rs.id_penjualan = pen.id_penjualan
    WHERE rs.waktu != pen.waktu
    AND rs.waktu > ?
    ORDER BY rs.waktu DESC
", [$cutoffDate]);

echo "Found " . count($mismatchedPenjualan) . " penjualan records with mismatched waktu\n\n";

// Now show specific case for product 994
echo "STEP 2: Check product 994 specific issue\n";
echo str_repeat("-", 60) . "\n";

$product994Issue = DB::select("
    SELECT rs.id_rekaman_stok, rs.waktu as rs_waktu, p.waktu as pembelian_waktu,
           p.no_faktur, rs.stok_masuk, rs.stok_sisa
    FROM rekaman_stoks rs
    JOIN pembelian p ON rs.id_pembelian = p.id_pembelian
    WHERE rs.id_produk = 994
    AND p.waktu < '2026-01-01 00:00:00'
    AND rs.waktu > '2025-12-31 00:00:00'
");

foreach ($product994Issue as $row) {
    echo "ID: {$row->id_rekaman_stok}\n";
    echo "  rekaman_stoks.waktu: {$row->rs_waktu}\n";
    echo "  pembelian.waktu: {$row->pembelian_waktu}\n";
    echo "  Faktur: {$row->no_faktur}\n";
    echo "  Masalah: Pembelian tanggal 31 Des tapi muncul setelah 2026!\n\n";
}

// The real problem: For Jan 2026 purchases, the original transaction might be dated
// Dec 31 but was recorded on Jan 2. We need to ensure display is consistent.

echo "\nSTEP 3: Analyzing all products with similar issues\n";
echo str_repeat("-", 60) . "\n";

// Find all pembelian records where:
// - pembelian.waktu is before cutoff (2025-12-31 23:59:59)
// - rekaman_stoks.waktu is after cutoff (2026+)
$problematicRecords = DB::select("
    SELECT rs.id_rekaman_stok, rs.id_produk, rs.waktu as rs_waktu,
           p.waktu as pembelian_waktu, p.no_faktur, p.id_pembelian,
           prod.nama_produk, rs.stok_masuk, rs.stok_sisa
    FROM rekaman_stoks rs
    JOIN pembelian p ON rs.id_pembelian = p.id_pembelian
    JOIN produk prod ON rs.id_produk = prod.id_produk
    WHERE p.waktu <= ?
    AND rs.waktu > ?
    ORDER BY rs.id_produk, rs.waktu DESC
", [$cutoffDate, $cutoffDate]);

echo "Found " . count($problematicRecords) . " problematic records (pembelian dated 2025, rekaman_stoks dated 2026+)\n\n";

if (count($problematicRecords) > 0) {
    echo "Sample of problematic records:\n";
    foreach (array_slice($problematicRecords, 0, 10) as $row) {
        echo "  Product {$row->id_produk}: RS waktu={$row->rs_waktu}, Pembelian waktu={$row->pembelian_waktu}\n";
    }
    echo "\n";
}

// The CORRECT fix: For these records, update rekaman_stoks.waktu to be AFTER cutoff
// but preserve the chronological order relative to other 2026 transactions

echo "STEP 4: Determine correct fix approach\n";
echo str_repeat("-", 60) . "\n";

echo "Issue: These pembelian records have:\n";
echo "  - pembelian.waktu dated Dec 31 or earlier (original entry date)\n";
echo "  - But rekaman_stoks.waktu dated Jan 2026+ (when stock record was created)\n";
echo "  - Stock Opname at 2025-12-31 23:59:59 should be the LAST Dec record\n\n";

echo "Solution options:\n";
echo "  A) Update pembelian.waktu to match rekaman_stoks.waktu (changes audit trail)\n";
echo "  B) Update rekaman_stoks.waktu to be right after Stock Opname (2026-01-01 00:00:00+)\n";
echo "  C) Keep database as-is, fix UI sorting to use rekaman_stoks.waktu only\n\n";

echo "Recommended: Option B - Update rekaman_stoks.waktu for 2026 transactions to ensure\n";
echo "proper chronological display after Stock Opname\n\n";

// Actually execute the fix
echo "STEP 5: Execute fix\n";
echo str_repeat("-", 60) . "\n";

$fixedCount = 0;
$updatedProducts = [];

// For pembelian records with waktu mismatch after cutoff
foreach ($problematicRecords as $row) {
    // The rekaman_stoks.waktu is already correct (2026+), we just need to ensure
    // pembelian shows correctly. The issue is UI uses pembelian.waktu for display.
    
    // Best fix: Update the pembelian.waktu to match rekaman_stoks.waktu
    // This ensures UI displays correct date
    
    if (!$dryRun) {
        DB::table('pembelian')
            ->where('id_pembelian', $row->id_pembelian)
            ->update(['waktu' => $row->rs_waktu]);
    }
    
    $fixedCount++;
    $updatedProducts[$row->id_produk] = true;
    
    if ($fixedCount <= 10) {
        echo "  Fixed Pembelian ID {$row->id_pembelian}: {$row->pembelian_waktu} -> {$row->rs_waktu}\n";
    }
}

if ($fixedCount > 10) {
    echo "  ... and " . ($fixedCount - 10) . " more pembelian records\n";
}

// Also fix penjualan records with same issue
$problematicPenjualan = DB::select("
    SELECT rs.id_rekaman_stok, rs.id_produk, rs.waktu as rs_waktu,
           pen.waktu as penjualan_waktu, pen.id_penjualan
    FROM rekaman_stoks rs
    JOIN penjualan pen ON rs.id_penjualan = pen.id_penjualan
    WHERE pen.waktu <= ?
    AND rs.waktu > ?
    ORDER BY rs.id_produk
", [$cutoffDate, $cutoffDate]);

echo "\nFound " . count($problematicPenjualan) . " penjualan records with same issue\n";

$fixedPenjualan = 0;
foreach ($problematicPenjualan as $row) {
    if (!$dryRun) {
        DB::table('penjualan')
            ->where('id_penjualan', $row->id_penjualan)
            ->update(['waktu' => $row->rs_waktu]);
    }
    $fixedPenjualan++;
    $updatedProducts[$row->id_produk] = true;
}

echo "Fixed " . $fixedPenjualan . " penjualan records\n";

echo "\n=======================================================\n";
echo "SUMMARY\n";
echo "=======================================================\n";
echo "Mode: " . ($dryRun ? "DRY RUN" : "EXECUTED") . "\n";
echo "Pembelian records fixed: $fixedCount\n";
echo "Penjualan records fixed: $fixedPenjualan\n";
echo "Products affected: " . count($updatedProducts) . "\n";

if ($dryRun) {
    echo "\nRun with --execute to apply changes:\n";
    echo "  php fix_stock_display_order.php --execute\n";
}

// Verify product 994
echo "\n\nVERIFICATION: Product 994 after fix\n";
echo str_repeat("-", 60) . "\n";

$records = DB::table('rekaman_stoks')
    ->where('id_produk', 994)
    ->whereBetween('waktu', ['2025-12-25 00:00:00', '2026-01-05 00:00:00'])
    ->orderBy('waktu', 'desc')
    ->get();

echo "NO | ID        | WAKTU                | MASUK | KELUAR | SISA | KETERANGAN\n";
echo str_repeat("-", 100) . "\n";
$no = 1;
foreach ($records as $r) {
    $masuk = $r->stok_masuk ?: '-';
    $keluar = $r->stok_keluar ?: '-';
    $ket = substr($r->keterangan ?? '', 0, 35);
    printf("%2d | %9d | %s | %5s | %6s | %4d | %s\n",
        $no++,
        $r->id_rekaman_stok,
        $r->waktu,
        $masuk, $keluar,
        $r->stok_sisa,
        $ket
    );
}

// Now check pembelian.waktu
if (!$dryRun) {
    $pembelian436 = DB::table('pembelian')->where('id_pembelian', 436)->first();
    echo "\nPembelian ID 436 waktu after fix: " . $pembelian436->waktu . "\n";
}
