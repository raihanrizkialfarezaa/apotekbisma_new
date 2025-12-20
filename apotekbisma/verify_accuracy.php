<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== VERIFYING DATA ACCURACY ===\n\n";

echo "1. CHECKING PENJUALAN QTY MATCH...\n";

$penjualanByTransaction = DB::select("
    SELECT 
        pd.id_penjualan,
        pd.id_produk,
        SUM(pd.jumlah) as total_qty
    FROM penjualan_detail pd
    JOIN penjualan p ON pd.id_penjualan = p.id_penjualan
    GROUP BY pd.id_penjualan, pd.id_produk
");

$rsPenjualanByTransaction = DB::select("
    SELECT 
        id_penjualan,
        id_produk,
        SUM(stok_keluar) as total_keluar
    FROM rekaman_stoks
    WHERE id_penjualan IS NOT NULL
    GROUP BY id_penjualan, id_produk
");

$pdMap = [];
foreach ($penjualanByTransaction as $p) {
    $key = $p->id_penjualan . '-' . $p->id_produk;
    $pdMap[$key] = $p->total_qty;
}

$rsMap = [];
foreach ($rsPenjualanByTransaction as $r) {
    $key = $r->id_penjualan . '-' . $r->id_produk;
    $rsMap[$key] = $r->total_keluar;
}

$mismatchCount = 0;
$missingCount = 0;
foreach ($pdMap as $key => $qty) {
    if (!isset($rsMap[$key])) {
        $missingCount++;
    } elseif ($rsMap[$key] != $qty) {
        $mismatchCount++;
        if ($mismatchCount <= 5) {
            echo "   Mismatch: {$key} - PD:{$qty} vs RS:{$rsMap[$key]}\n";
        }
    }
}
echo "   Qty mismatches: {$mismatchCount}\n";
echo "   Missing in RS: {$missingCount}\n";

echo "\n2. CHECKING PEMBELIAN QTY MATCH...\n";

$pembelianByTransaction = DB::select("
    SELECT 
        pd.id_pembelian,
        pd.id_produk,
        SUM(pd.jumlah) as total_qty
    FROM pembelian_detail pd
    JOIN pembelian b ON pd.id_pembelian = b.id_pembelian
    GROUP BY pd.id_pembelian, pd.id_produk
");

$rsPembelianByTransaction = DB::select("
    SELECT 
        id_pembelian,
        id_produk,
        SUM(stok_masuk) as total_masuk
    FROM rekaman_stoks
    WHERE id_pembelian IS NOT NULL
    GROUP BY id_pembelian, id_produk
");

$bdMap = [];
foreach ($pembelianByTransaction as $b) {
    $key = $b->id_pembelian . '-' . $b->id_produk;
    $bdMap[$key] = $b->total_qty;
}

$rsbMap = [];
foreach ($rsPembelianByTransaction as $r) {
    $key = $r->id_pembelian . '-' . $r->id_produk;
    $rsbMap[$key] = $r->total_masuk;
}

$mismatchCount2 = 0;
$missingCount2 = 0;
foreach ($bdMap as $key => $qty) {
    if (!isset($rsbMap[$key])) {
        $missingCount2++;
    } elseif ($rsbMap[$key] != $qty) {
        $mismatchCount2++;
        if ($mismatchCount2 <= 5) {
            echo "   Mismatch: {$key} - BD:{$qty} vs RS:{$rsbMap[$key]}\n";
        }
    }
}
echo "   Qty mismatches: {$mismatchCount2}\n";
echo "   Missing in RS: {$missingCount2}\n";

echo "\n3. CHECKING RUNNING BALANCE...\n";
$balanceIssues = 0;
$stockMismatch = 0;

$products = DB::table('produk')->select('id_produk', 'nama_produk', 'stok')->get();

foreach ($products as $prod) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $prod->id_produk)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($records->isEmpty()) {
        if ($prod->stok != 0) {
            $stockMismatch++;
        }
        continue;
    }
    
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
    
    if (!$valid) $balanceIssues++;
    if ($running != $prod->stok) $stockMismatch++;
}

echo "   Running balance issues: {$balanceIssues}\n";
echo "   Final stock mismatches: {$stockMismatch}\n";

echo "\n4. NEGATIVE STOCK CHECK...\n";
$negCount = DB::table('rekaman_stoks')->where('stok_sisa', '<', 0)->count();
echo "   Negative stok_sisa: {$negCount}\n";

echo "\n5. WAKTU MATCH CHECK...\n";
$waktuMismatchP = DB::select("
    SELECT COUNT(*) as cnt FROM rekaman_stoks rs
    JOIN penjualan p ON rs.id_penjualan = p.id_penjualan
    WHERE rs.waktu != p.waktu
")[0]->cnt;
$waktuMismatchB = DB::select("
    SELECT COUNT(*) as cnt FROM rekaman_stoks rs
    JOIN pembelian b ON rs.id_pembelian = b.id_pembelian
    WHERE rs.waktu != b.waktu
")[0]->cnt;
echo "   Penjualan waktu mismatch: {$waktuMismatchP}\n";
echo "   Pembelian waktu mismatch: {$waktuMismatchB}\n";

echo "\n=== SUMMARY ===\n";
$totalIssues = $mismatchCount + $missingCount + $mismatchCount2 + $missingCount2 + 
               $balanceIssues + $stockMismatch + $negCount + $waktuMismatchP + $waktuMismatchB;

if ($totalIssues == 0) {
    echo "✓✓✓ ALL DATA 100% ACCURATE! ✓✓✓\n";
} else {
    echo "Total issues found: {$totalIssues}\n";
}
