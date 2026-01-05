<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$OPNAME_END = '2025-12-31 23:59:59';
$TX_START = '2026-01-01 00:00:00';

$output = [];
$output[] = "================================================================";
$output[] = "   VERIFIKASI AKHIR - STOK SETELAH RECALCULATION";
$output[] = "   Waktu: " . date('Y-m-d H:i:s');
$output[] = "================================================================";
$output[] = "";

$produkOpname = DB::table('rekaman_stoks as rs')
    ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
    ->where('rs.waktu', '>=', '2025-12-31 00:00:00')
    ->where('rs.waktu', '<=', $OPNAME_END)
    ->select('p.id_produk', 'p.nama_produk', 'p.stok as stok_sistem')
    ->distinct()
    ->get();

$output[] = "Memeriksa " . $produkOpname->count() . " produk yang di-opname...";
$output[] = "";

$discrepancies = [];
$allOk = true;

foreach ($produkOpname as $produk) {
    $opnameRec = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->where('waktu', '>=', '2025-12-31 00:00:00')
        ->where('waktu', '<=', $OPNAME_END)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $stokOpname = $opnameRec ? intval($opnameRec->stok_sisa) : 0;
    
    $totalBeli = DB::table('pembelian_detail as pd')
        ->join('pembelian as pb', 'pd.id_pembelian', '=', 'pb.id_pembelian')
        ->where('pd.id_produk', $produk->id_produk)
        ->where('pb.waktu', '>=', $TX_START)
        ->sum('pd.jumlah');
    
    $totalJual = DB::table('penjualan_detail as pd')
        ->join('penjualan as pj', 'pd.id_penjualan', '=', 'pj.id_penjualan')
        ->where('pd.id_produk', $produk->id_produk)
        ->where('pj.waktu', '>=', $TX_START)
        ->sum('pd.jumlah');
    
    $stokSeharusnya = $stokOpname + intval($totalBeli) - intval($totalJual);
    if ($stokSeharusnya < 0) $stokSeharusnya = 0;
    
    $stokSistem = intval($produk->stok_sistem);
    
    if ($stokSistem != $stokSeharusnya) {
        $allOk = false;
        $discrepancies[] = [
            'id' => $produk->id_produk,
            'nama' => $produk->nama_produk,
            'opname' => $stokOpname,
            'beli' => intval($totalBeli),
            'jual' => intval($totalJual),
            'seharusnya' => $stokSeharusnya,
            'sistem' => $stokSistem,
            'selisih' => $stokSistem - $stokSeharusnya,
        ];
    }
}

if ($allOk) {
    $output[] = "================================================================";
    $output[] = "   ✅ SEMUA STOK SUDAH SESUAI!";
    $output[] = "================================================================";
    $output[] = "";
    $output[] = "Semua " . $produkOpname->count() . " produk telah diverifikasi.";
    $output[] = "Tidak ada discrepancy ditemukan.";
} else {
    $output[] = "================================================================";
    $output[] = "   ❌ MASIH ADA DISCREPANCY!";
    $output[] = "================================================================";
    $output[] = "";
    $output[] = "Ditemukan " . count($discrepancies) . " produk dengan stok tidak sesuai:";
    $output[] = "";
    
    foreach ($discrepancies as $d) {
        $output[] = "{$d['nama']} (ID:{$d['id']})";
        $output[] = "  Opname: {$d['opname']} + Beli: {$d['beli']} - Jual: {$d['jual']} = Seharusnya: {$d['seharusnya']}";
        $output[] = "  Stok Sistem: {$d['sistem']} | Selisih: {$d['selisih']}";
        $output[] = "";
    }
}

$output[] = "";
$output[] = "================================================================";

$content = implode("\n", $output);
$outputFile = __DIR__ . '/final_verification_' . date('Y-m-d_His') . '.txt';
file_put_contents($outputFile, $content);

echo $content;
echo "\n\nHasil disimpan ke: {$outputFile}\n";
