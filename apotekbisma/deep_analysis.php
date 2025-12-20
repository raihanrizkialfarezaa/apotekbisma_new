<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== DEEP ANALYSIS OF TRANSACTION DATA ===\n\n";

echo "1. CHECKING PENJUALAN_DETAIL STRUCTURE...\n";
$pdStructure = DB::select("DESCRIBE penjualan_detail");
$hasPdPK = false;
foreach ($pdStructure as $col) {
    if ($col->Key == 'PRI') {
        echo "   Primary Key: {$col->Field}\n";
        $hasPdPK = true;
    }
}

echo "\n2. CHECKING FOR DUPLICATE PENJUALAN_DETAIL...\n";
$dupPenjualan = DB::select("
    SELECT id_penjualan, id_produk, COUNT(*) as cnt
    FROM penjualan_detail
    GROUP BY id_penjualan, id_produk
    HAVING COUNT(*) > 1
    LIMIT 10
");
echo "   Duplicate (same penjualan+produk) entries: " . count($dupPenjualan) . "\n";
if (count($dupPenjualan) > 0) {
    foreach ($dupPenjualan as $d) {
        echo "   - Penjualan #{$d->id_penjualan}, Produk #{$d->id_produk}: {$d->cnt} records\n";
    }
}

echo "\n3. CHECKING FOR DUPLICATE PEMBELIAN_DETAIL...\n";
$dupPembelian = DB::select("
    SELECT id_pembelian, id_produk, COUNT(*) as cnt
    FROM pembelian_detail
    GROUP BY id_pembelian, id_produk
    HAVING COUNT(*) > 1
    LIMIT 10
");
echo "   Duplicate (same pembelian+produk) entries: " . count($dupPembelian) . "\n";

echo "\n4. ACTUAL COUNT ANALYSIS...\n";
$pdCount = DB::table('penjualan_detail')->count();
$pdDistinct = DB::select("SELECT COUNT(DISTINCT CONCAT(id_penjualan, '-', id_produk)) as cnt FROM penjualan_detail")[0]->cnt;
echo "   penjualan_detail total rows: {$pdCount}\n";
echo "   penjualan_detail distinct (penjualan+produk): {$pdDistinct}\n";

$bdCount = DB::table('pembelian_detail')->count();
$bdDistinct = DB::select("SELECT COUNT(DISTINCT CONCAT(id_pembelian, '-', id_produk)) as cnt FROM pembelian_detail")[0]->cnt;
echo "   pembelian_detail total rows: {$bdCount}\n";
echo "   pembelian_detail distinct (pembelian+produk): {$bdDistinct}\n";

echo "\n5. SAMPLE OF MISMATCH...\n";
$samples = DB::select("
    SELECT 
        pd.id_penjualan,
        pd.id_produk,
        pd.jumlah,
        pr.nama_produk,
        rs.stok_keluar
    FROM penjualan_detail pd
    JOIN produk pr ON pd.id_produk = pr.id_produk
    LEFT JOIN rekaman_stoks rs ON rs.id_penjualan = pd.id_penjualan AND rs.id_produk = pd.id_produk
    WHERE rs.stok_keluar IS NULL OR rs.stok_keluar != pd.jumlah
    ORDER BY pd.id_penjualan
    LIMIT 15
");
foreach ($samples as $s) {
    $rsKeluar = $s->stok_keluar ?? 'NULL';
    echo "   Penjualan #{$s->id_penjualan} {$s->nama_produk}: detail.jumlah={$s->jumlah}, rs.keluar={$rsKeluar}\n";
}
