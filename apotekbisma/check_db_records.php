<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== INVESTIGASI DATABASE REKAMAN_STOKS ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

$productId = 204;
$product = DB::table('produk')->where('id_produk', $productId)->first();

echo "PRODUK: [{$productId}] {$product->nama_produk}\n";
echo "Current produk.stok: {$product->stok}\n\n";

$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$opnameStock = null;
$handle = fopen($csvFile, 'r');
fgetcsv($handle);
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) >= 3 && intval($row[0]) == $productId) {
        $opnameStock = intval($row[2]);
        break;
    }
}
fclose($handle);
echo "Stock Opname 31 Des 2025 (dari CSV): {$opnameStock}\n\n";

echo "=== SEMUA REKAMAN_STOKS UNTUK PRODUK INI ===\n";
echo "Menampilkan SEMUA data dari database:\n\n";

$allRecords = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->orderBy('waktu', 'asc')
    ->orderBy('created_at', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

echo "Total records: {$allRecords->count()}\n\n";

echo sprintf("%-4s | %-8s | %-22s | %-22s | %-6s | %-6s | %-6s | %-6s | %s\n", 
    "NO", "ID", "WAKTU", "CREATED_AT", "AWAL", "MASUK", "KELUAR", "SISA", "KETERANGAN");
echo str_repeat("-", 150) . "\n";

$no = 1;
$prevSisa = null;

foreach ($allRecords as $r) {
    $gap = "";
    if ($prevSisa !== null && intval($r->stok_awal) != intval($prevSisa)) {
        $gap = " [GAP:" . (intval($r->stok_awal) - intval($prevSisa)) . "]";
    }
    
    $keterangan = substr($r->keterangan ?? '-', 0, 50);
    
    echo sprintf("%-4d | %-8d | %-22s | %-22s | %-6d | %-6s | %-6s | %-6d | %s%s\n",
        $no,
        $r->id_rekaman_stok,
        $r->waktu,
        $r->created_at,
        $r->stok_awal,
        $r->stok_masuk ?: '-',
        $r->stok_keluar ?: '-',
        $r->stok_sisa,
        $keterangan,
        $gap
    );
    
    $prevSisa = $r->stok_sisa;
    $no++;
}

echo "\n=== ANALISIS SEKITAR TANGGAL CUTOFF (31 Des 2025) ===\n\n";

$beforeCutoff = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where('waktu', '<=', '2025-12-31 23:59:59')
    ->orderBy('waktu', 'desc')
    ->orderBy('id_rekaman_stok', 'desc')
    ->limit(5)
    ->get()
    ->reverse();

echo "5 Record terakhir SEBELUM/SAAT 31 Des 2025:\n";
foreach ($beforeCutoff as $r) {
    echo "  [{$r->id_rekaman_stok}] {$r->waktu} | Awal:{$r->stok_awal} +{$r->stok_masuk} -{$r->stok_keluar} = Sisa:{$r->stok_sisa}\n";
    echo "       Keterangan: {$r->keterangan}\n";
}

$afterCutoff = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where('waktu', '>', '2025-12-31 23:59:59')
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->limit(5)
    ->get();

echo "\n5 Record pertama SETELAH 31 Des 2025:\n";
foreach ($afterCutoff as $r) {
    echo "  [{$r->id_rekaman_stok}] {$r->waktu} | Awal:{$r->stok_awal} +{$r->stok_masuk} -{$r->stok_keluar} = Sisa:{$r->stok_sisa}\n";
    echo "       Keterangan: {$r->keterangan}\n";
}

echo "\n=== CARI RECORD STOCK OPNAME / PENYESUAIAN ===\n\n";

$adjustmentRecords = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where(function($q) {
        $q->where('keterangan', 'LIKE', '%opname%')
          ->orWhere('keterangan', 'LIKE', '%penyesuaian%')
          ->orWhere('keterangan', 'LIKE', '%adjustment%')
          ->orWhere('keterangan', 'LIKE', '%manual%')
          ->orWhere('keterangan', 'LIKE', '%SO%');
    })
    ->orderBy('waktu', 'desc')
    ->get();

if ($adjustmentRecords->isEmpty()) {
    echo "TIDAK ADA record dengan keterangan 'opname', 'penyesuaian', 'adjustment', 'manual', atau 'SO'\n";
} else {
    echo "Ditemukan " . $adjustmentRecords->count() . " record penyesuaian:\n";
    foreach ($adjustmentRecords as $r) {
        echo "  [{$r->id_rekaman_stok}] {$r->waktu} | Awal:{$r->stok_awal} +{$r->stok_masuk} -{$r->stok_keluar} = Sisa:{$r->stok_sisa}\n";
        echo "       Keterangan: {$r->keterangan}\n";
    }
}

echo "\n=== CARI RECORD DENGAN id_penjualan DAN id_pembelian = NULL (Manual/Adjustment) ===\n\n";

$nullTransactionRecords = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->whereNull('id_penjualan')
    ->whereNull('id_pembelian')
    ->orderBy('waktu', 'asc')
    ->get();

if ($nullTransactionRecords->isEmpty()) {
    echo "TIDAK ADA record tanpa id_penjualan dan id_pembelian (semua terhubung ke transaksi)\n";
} else {
    echo "Ditemukan " . $nullTransactionRecords->count() . " record tanpa link transaksi:\n";
    foreach ($nullTransactionRecords as $r) {
        echo "  [{$r->id_rekaman_stok}] {$r->waktu} | Awal:{$r->stok_awal} +{$r->stok_masuk} -{$r->stok_keluar} = Sisa:{$r->stok_sisa}\n";
        echo "       Keterangan: {$r->keterangan}\n";
    }
}

echo "\n=== KESIMPULAN ===\n\n";

$lastBeforeCutoff = $beforeCutoff->last();
$firstAfterCutoff = $afterCutoff->first();

if ($lastBeforeCutoff && $firstAfterCutoff) {
    echo "Stok terakhir sebelum 2026 (stok_sisa): {$lastBeforeCutoff->stok_sisa}\n";
    echo "Stok awal pertama 2026 (stok_awal): {$firstAfterCutoff->stok_awal}\n";
    echo "Stok Opname 31 Des 2025: {$opnameStock}\n";
    
    $gapToOpname = intval($lastBeforeCutoff->stok_sisa) - $opnameStock;
    $gapToNext = intval($firstAfterCutoff->stok_awal) - intval($lastBeforeCutoff->stok_sisa);
    
    echo "\nSelisih rekaman terakhir 2025 dengan opname: {$gapToOpname}\n";
    echo "Selisih stok_awal 2026 dengan stok_sisa 2025: {$gapToNext}\n";
    
    if ($gapToNext != 0) {
        echo "\n>>> MASALAH: Ada lompatan stok dari {$lastBeforeCutoff->stok_sisa} ke {$firstAfterCutoff->stok_awal}\n";
        echo ">>> Apakah ada record penyesuaian yang hilang? Atau stok_awal diambil dari produk.stok yang sudah di-update?\n";
    }
}
