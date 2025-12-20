<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== SUPER ULTRA ROBUST AUDIT ===\n\n";

DB::connection()->disableQueryLog();

$issues = [];

echo "1. CHECKING PENJUALAN vs REKAMAN_STOK...\n";

$penjualanCheck = DB::select("
    SELECT 
        pd.id_penjualan,
        pd.id_produk,
        p.waktu as penjualan_waktu,
        pd.jumlah as qty_sold,
        rs.id_rekaman_stok,
        rs.stok_keluar,
        rs.waktu as rs_waktu,
        pr.nama_produk
    FROM penjualan_detail pd
    JOIN penjualan p ON pd.id_penjualan = p.id_penjualan
    JOIN produk pr ON pd.id_produk = pr.id_produk
    LEFT JOIN rekaman_stoks rs ON rs.id_penjualan = pd.id_penjualan AND rs.id_produk = pd.id_produk
    WHERE rs.id_rekaman_stok IS NULL
       OR rs.stok_keluar != pd.jumlah
       OR DATE(rs.waktu) != DATE(p.waktu)
    LIMIT 50
");

echo "   Missing/mismatched penjualan records: " . count($penjualanCheck) . "\n";
if (count($penjualanCheck) > 0) {
    foreach (array_slice($penjualanCheck, 0, 5) as $r) {
        $rsInfo = $r->id_rekaman_stok ? "RS:{$r->id_rekaman_stok} keluar:{$r->stok_keluar}" : "NO RS RECORD";
        echo "   - Penjualan #{$r->id_penjualan} {$r->nama_produk}: qty={$r->qty_sold}, {$rsInfo}\n";
    }
    $issues[] = "Penjualan mismatch: " . count($penjualanCheck);
}

echo "\n2. CHECKING PEMBELIAN vs REKAMAN_STOK...\n";

$pembelianCheck = DB::select("
    SELECT 
        pd.id_pembelian,
        pd.id_produk,
        b.waktu as pembelian_waktu,
        pd.jumlah as qty_bought,
        rs.id_rekaman_stok,
        rs.stok_masuk,
        rs.waktu as rs_waktu,
        pr.nama_produk
    FROM pembelian_detail pd
    JOIN pembelian b ON pd.id_pembelian = b.id_pembelian
    JOIN produk pr ON pd.id_produk = pr.id_produk
    LEFT JOIN rekaman_stoks rs ON rs.id_pembelian = pd.id_pembelian AND rs.id_produk = pd.id_produk
    WHERE rs.id_rekaman_stok IS NULL
       OR rs.stok_masuk != pd.jumlah
       OR DATE(rs.waktu) != DATE(b.waktu)
    LIMIT 50
");

echo "   Missing/mismatched pembelian records: " . count($pembelianCheck) . "\n";
if (count($pembelianCheck) > 0) {
    foreach (array_slice($pembelianCheck, 0, 5) as $r) {
        $rsInfo = $r->id_rekaman_stok ? "RS:{$r->id_rekaman_stok} masuk:{$r->stok_masuk}" : "NO RS RECORD";
        echo "   - Pembelian #{$r->id_pembelian} {$r->nama_produk}: qty={$r->qty_bought}, {$rsInfo}\n";
    }
    $issues[] = "Pembelian mismatch: " . count($pembelianCheck);
}

echo "\n3. CHECKING ORPHAN REKAMAN_STOK (no transaction link)...\n";

$orphans = DB::select("
    SELECT COUNT(*) as cnt FROM rekaman_stoks 
    WHERE id_penjualan IS NULL AND id_pembelian IS NULL
");
echo "   Orphan records: {$orphans[0]->cnt}\n";

echo "\n4. CHECKING WAKTU EXACT MATCH...\n";

$waktuMismatch = DB::select("
    SELECT 
        (SELECT COUNT(*) FROM rekaman_stoks rs 
         INNER JOIN penjualan p ON rs.id_penjualan = p.id_penjualan 
         WHERE rs.waktu != p.waktu) as penjualan_mismatch,
        (SELECT COUNT(*) FROM rekaman_stoks rs 
         INNER JOIN pembelian b ON rs.id_pembelian = b.id_pembelian 
         WHERE rs.waktu != b.waktu) as pembelian_mismatch
")[0];
echo "   Penjualan waktu mismatch: {$waktuMismatch->penjualan_mismatch}\n";
echo "   Pembelian waktu mismatch: {$waktuMismatch->pembelian_mismatch}\n";

if ($waktuMismatch->penjualan_mismatch > 0 || $waktuMismatch->pembelian_mismatch > 0) {
    $issues[] = "Waktu mismatch found";
}

echo "\n5. CHECKING RUNNING BALANCE CONSISTENCY...\n";

$balanceIssues = 0;
$products = DB::table('produk')->select('id_produk', 'nama_produk', 'stok')->get();

foreach ($products as $prod) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $prod->id_produk)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($records->isEmpty()) continue;
    
    $running = $records->first()->stok_awal;
    $valid = true;
    
    foreach ($records as $r) {
        if ($r->stok_awal != $running) {
            $valid = false;
            break;
        }
        $calc = $running + $r->stok_masuk - $r->stok_keluar;
        if ($r->stok_sisa != $calc) {
            $valid = false;
            break;
        }
        $running = $calc;
    }
    
    if (!$valid) {
        $balanceIssues++;
        if ($balanceIssues <= 5) {
            echo "   - {$prod->nama_produk}: INVALID running balance\n";
        }
    }
    
    if ($running != $prod->stok) {
        $balanceIssues++;
        if ($balanceIssues <= 10) {
            echo "   - {$prod->nama_produk}: Final stock mismatch (calc:{$running} vs db:{$prod->stok})\n";
        }
    }
}

echo "   Products with balance issues: {$balanceIssues}\n";
if ($balanceIssues > 0) $issues[] = "Balance issues: {$balanceIssues}";

echo "\n6. CHECKING NEGATIVE STOCK...\n";
$negativeCount = DB::table('rekaman_stoks')->where('stok_sisa', '<', 0)->count();
echo "   Negative stok_sisa: {$negativeCount}\n";
if ($negativeCount > 0) $issues[] = "Negative stock: {$negativeCount}";

echo "\n7. TRANSACTION COUNT COMPARISON...\n";
$penjualanDetailCount = DB::table('penjualan_detail')->count();
$pembelianDetailCount = DB::table('pembelian_detail')->count();
$rsWithPenjualan = DB::table('rekaman_stoks')->whereNotNull('id_penjualan')->count();
$rsWithPembelian = DB::table('rekaman_stoks')->whereNotNull('id_pembelian')->count();

echo "   Penjualan details: {$penjualanDetailCount} vs RS with penjualan: {$rsWithPenjualan}\n";
echo "   Pembelian details: {$pembelianDetailCount} vs RS with pembelian: {$rsWithPembelian}\n";

if ($penjualanDetailCount != $rsWithPenjualan) {
    $issues[] = "Penjualan count mismatch: {$penjualanDetailCount} vs {$rsWithPenjualan}";
}
if ($pembelianDetailCount != $rsWithPembelian) {
    $issues[] = "Pembelian count mismatch: {$pembelianDetailCount} vs {$rsWithPembelian}";
}

echo "\n=== AUDIT SUMMARY ===\n";
if (empty($issues)) {
    echo "ALL CHECKS PASSED!\n";
} else {
    echo "ISSUES FOUND:\n";
    foreach ($issues as $issue) {
        echo "- {$issue}\n";
    }
}
