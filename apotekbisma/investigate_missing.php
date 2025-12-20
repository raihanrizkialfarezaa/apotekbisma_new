<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== INVESTIGATING MISSING RECORDS ===\n\n";

$pdTotal = DB::table('penjualan_detail')->count();
$bdTotal = DB::table('pembelian_detail')->count();

echo "1. penjualan_detail total: {$pdTotal}\n";
echo "2. pembelian_detail total: {$bdTotal}\n";

$pdWithJoin = DB::select("
    SELECT COUNT(*) as cnt
    FROM penjualan_detail pd
    JOIN penjualan p ON pd.id_penjualan = p.id_penjualan
")[0]->cnt;
echo "3. penjualan_detail with valid penjualan join: {$pdWithJoin}\n";

$bdWithJoin = DB::select("
    SELECT COUNT(*) as cnt
    FROM pembelian_detail pd
    JOIN pembelian b ON pd.id_pembelian = b.id_pembelian
")[0]->cnt;
echo "4. pembelian_detail with valid pembelian join: {$bdWithJoin}\n";

$orphanPd = $pdTotal - $pdWithJoin;
$orphanBd = $bdTotal - $bdWithJoin;
echo "\n5. Orphan penjualan_detail (no parent): {$orphanPd}\n";
echo "6. Orphan pembelian_detail (no parent): {$orphanBd}\n";

if ($orphanPd > 0) {
    echo "\nOrphan penjualan_detail samples:\n";
    $samples = DB::select("
        SELECT pd.id_penjualan_detail, pd.id_penjualan, pd.id_produk, pd.jumlah
        FROM penjualan_detail pd
        LEFT JOIN penjualan p ON pd.id_penjualan = p.id_penjualan
        WHERE p.id_penjualan IS NULL
        LIMIT 10
    ");
    foreach ($samples as $s) {
        echo "   ID:{$s->id_penjualan_detail} Penjualan:{$s->id_penjualan} Produk:{$s->id_produk} Qty:{$s->jumlah}\n";
    }
}

if ($orphanBd > 0) {
    echo "\nOrphan pembelian_detail samples:\n";
    $samples = DB::select("
        SELECT pd.id_pembelian_detail, pd.id_pembelian, pd.id_produk, pd.jumlah
        FROM pembelian_detail pd
        LEFT JOIN pembelian b ON pd.id_pembelian = b.id_pembelian
        WHERE b.id_pembelian IS NULL
        LIMIT 10
    ");
    foreach ($samples as $s) {
        echo "   ID:{$s->id_pembelian_detail} Pembelian:{$s->id_pembelian} Produk:{$s->id_produk} Qty:{$s->jumlah}\n";
    }
}

echo "\n7. CURRENT REKAMAN_STOKS COUNT...\n";
$rsTotal = DB::table('rekaman_stoks')->count();
$rsPenjualan = DB::table('rekaman_stoks')->whereNotNull('id_penjualan')->count();
$rsPembelian = DB::table('rekaman_stoks')->whereNotNull('id_pembelian')->count();
echo "   Total: {$rsTotal}\n";
echo "   With penjualan: {$rsPenjualan}\n";
echo "   With pembelian: {$rsPembelian}\n";

echo "\n8. EXPECTED vs ACTUAL...\n";
echo "   Penjualan: expected {$pdWithJoin}, actual {$rsPenjualan}, diff: " . ($pdWithJoin - $rsPenjualan) . "\n";
echo "   Pembelian: expected {$bdWithJoin}, actual {$rsPembelian}, diff: " . ($bdWithJoin - $rsPembelian) . "\n";
