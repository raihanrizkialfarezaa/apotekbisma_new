<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== CEK DETAIL RECORD DEMACOLIN TAB (ID: 204) ===\n\n";

$productId = 204;

echo "1. Records sekitar cutoff (25 Des - 5 Jan):\n\n";

$records = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->whereBetween('waktu', ['2025-12-25 00:00:00', '2026-01-05 23:59:59'])
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

foreach ($records as $r) {
    $txType = "";
    if ($r->id_penjualan) $txType = "PENJUALAN ID:{$r->id_penjualan}";
    elseif ($r->id_pembelian) $txType = "PEMBELIAN ID:{$r->id_pembelian}";
    else $txType = "MANUAL/ADJUSTMENT";
    
    echo "[{$r->id_rekaman_stok}] {$r->waktu}\n";
    echo "   Awal:{$r->stok_awal} + Masuk:{$r->stok_masuk} - Keluar:{$r->stok_keluar} = Sisa:{$r->stok_sisa}\n";
    echo "   Type: {$txType}\n";
    echo "   Keterangan: {$r->keterangan}\n";
    echo "   Created: {$r->created_at}\n\n";
}

echo "\n2. Cek record dengan id untuk produk ini:\n";
$lastRecord = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->orderBy('waktu', 'desc')
    ->first();
echo "   Record terakhir: ID {$lastRecord->id_rekaman_stok}, waktu: {$lastRecord->waktu}, sisa: {$lastRecord->stok_sisa}\n";

echo "\n3. Detail record ID 174774 (yg tanpa link transaksi):\n";
$manual = DB::table('rekaman_stoks')->where('id_rekaman_stok', 174774)->first();
if ($manual) {
    echo "   Waktu: {$manual->waktu}\n";
    echo "   Created: {$manual->created_at}\n";
    echo "   Awal:{$manual->stok_awal} Masuk:{$manual->stok_masuk} Keluar:{$manual->stok_keluar} Sisa:{$manual->stok_sisa}\n";
    echo "   id_penjualan: " . ($manual->id_penjualan ?? 'NULL') . "\n";
    echo "   id_pembelian: " . ($manual->id_pembelian ?? 'NULL') . "\n";
    echo "   Keterangan: {$manual->keterangan}\n";
}

echo "\n4. Record pertama di 2026 untuk produk ini:\n";
$first2026 = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where('waktu', '>', '2025-12-31 23:59:59')
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->first();

if ($first2026) {
    echo "   ID: {$first2026->id_rekaman_stok}\n";
    echo "   Waktu: {$first2026->waktu}\n";
    echo "   Created: {$first2026->created_at}\n";
    echo "   Awal:{$first2026->stok_awal} Masuk:{$first2026->stok_masuk} Keluar:{$first2026->stok_keluar} Sisa:{$first2026->stok_sisa}\n";
    echo "   id_penjualan: " . ($first2026->id_penjualan ?? 'NULL') . "\n";
    echo "   Keterangan: {$first2026->keterangan}\n";
    
    if ($first2026->id_penjualan) {
        $penjualan = DB::table('penjualan')->where('id_penjualan', $first2026->id_penjualan)->first();
        if ($penjualan) {
            echo "\n   Detail Penjualan:\n";
            echo "   - Waktu: {$penjualan->waktu}\n";
            echo "   - Total: {$penjualan->bayar}\n";
            echo "   - Created: {$penjualan->created_at}\n";
        }
    }
}

echo "\n\n=== SEMUA PRODUK DENGAN MASALAH SERUPA ===\n\n";

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

$problemProducts = [];

foreach ($opnameData as $pid => $opnameStock) {
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->where('waktu', '<=', '2025-12-31 23:59:59')
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $firstAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->where('waktu', '>', '2025-12-31 23:59:59')
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->first();
    
    if ($lastBefore && $firstAfter) {
        $lastSisa = intval($lastBefore->stok_sisa);
        $firstAwal = intval($firstAfter->stok_awal);
        
        if ($firstAwal != $lastSisa) {
            $product = DB::table('produk')->where('id_produk', $pid)->first();
            $problemProducts[] = [
                'id' => $pid,
                'nama' => $product ? $product->nama_produk : 'Unknown',
                'opname_stock' => $opnameStock,
                'last_2025_sisa' => $lastSisa,
                'first_2026_awal' => $firstAwal,
                'gap' => $firstAwal - $lastSisa
            ];
        }
    }
}

echo "Ditemukan " . count($problemProducts) . " produk dengan gap antara 2025-2026:\n\n";

usort($problemProducts, function($a, $b) {
    return abs($b['gap']) - abs($a['gap']);
});

$shown = 0;
foreach ($problemProducts as $p) {
    if ($shown >= 30) break;
    echo "[{$p['id']}] {$p['nama']}\n";
    echo "   Opname: {$p['opname_stock']}, Last 2025: {$p['last_2025_sisa']}, First 2026 Awal: {$p['first_2026_awal']}, GAP: {$p['gap']}\n\n";
    $shown++;
}

if (count($problemProducts) > 30) {
    echo "... dan " . (count($problemProducts) - 30) . " produk lainnya\n";
}
