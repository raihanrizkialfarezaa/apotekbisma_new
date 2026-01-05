<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(300);

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$TX_START = '2026-01-01 00:00:00';

$output = [];
$output[] = "================================================================";
$output[] = "   FINAL CLEANUP - APOTEK BISMA";
$output[] = "   Waktu: " . date('Y-m-d H:i:s');
$output[] = "================================================================";
$output[] = "";

$output[] = "1. PERBAIKI FORMULA TIDAK VALID";
$output[] = str_repeat("-", 50);

$invalidFormula = DB::select("
    SELECT id_rekaman_stok, id_produk, stok_awal, stok_masuk, stok_keluar, stok_sisa,
           (stok_awal + stok_masuk - stok_keluar) as calculated_sisa
    FROM rekaman_stoks
    WHERE stok_sisa != (stok_awal + stok_masuk - stok_keluar)
");

$output[] = "Rekaman dengan formula tidak valid: " . count($invalidFormula);

foreach ($invalidFormula as $rec) {
    DB::table('rekaman_stoks')
        ->where('id_rekaman_stok', $rec->id_rekaman_stok)
        ->update(['stok_sisa' => $rec->calculated_sisa]);
    
    $output[] = "  Fixed: Rekaman #{$rec->id_rekaman_stok} - stok_sisa {$rec->stok_sisa} -> {$rec->calculated_sisa}";
}
$output[] = "";

$output[] = "2. BUAT REKAMAN UNTUK PEMBELIAN YANG HILANG";
$output[] = str_repeat("-", 50);

$pembelianTanpaRekaman = DB::select("
    SELECT pd.id_pembelian, pd.id_produk, SUM(pd.jumlah) as total_jumlah, pb.waktu
    FROM pembelian_detail pd
    JOIN pembelian pb ON pd.id_pembelian = pb.id_pembelian
    WHERE pb.waktu >= ?
    AND NOT EXISTS (
        SELECT 1 FROM rekaman_stoks rs 
        WHERE rs.id_pembelian = pd.id_pembelian 
        AND rs.id_produk = pd.id_produk
    )
    GROUP BY pd.id_pembelian, pd.id_produk, pb.waktu
", [$TX_START]);

$output[] = "Pembelian tanpa rekaman: " . count($pembelianTanpaRekaman);

foreach ($pembelianTanpaRekaman as $pb) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $pb->id_produk)
        ->where('waktu', '<', $pb->waktu)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $stokAwal = $lastRekaman ? intval($lastRekaman->stok_sisa) : 0;
    $stokSisa = $stokAwal + intval($pb->total_jumlah);
    
    DB::table('rekaman_stoks')->insert([
        'id_produk' => $pb->id_produk,
        'id_pembelian' => $pb->id_pembelian,
        'waktu' => $pb->waktu,
        'stok_awal' => $stokAwal,
        'stok_masuk' => intval($pb->total_jumlah),
        'stok_keluar' => 0,
        'stok_sisa' => $stokSisa,
        'keterangan' => 'Pembelian: Auto-created rekaman yang hilang',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    
    $produk = DB::table('produk')->where('id_produk', $pb->id_produk)->first();
    $output[] = "  Created: {$produk->nama_produk} - Pembelian #{$pb->id_pembelian}";
}
$output[] = "";

$output[] = "3. PERBAIKI STOK NEGATIF";
$output[] = str_repeat("-", 50);

$produkNegatif = DB::table('produk')->where('stok', '<', 0)->get();

$output[] = "Produk dengan stok negatif: " . $produkNegatif->count();

foreach ($produkNegatif as $p) {
    DB::table('produk')
        ->where('id_produk', $p->id_produk)
        ->update(['stok' => 0]);
    
    $output[] = "  Fixed: {$p->nama_produk} - stok {$p->stok} -> 0";
}
$output[] = "";

$output[] = "4. RECALCULATE PRODUK YANG TERDAMPAK";
$output[] = str_repeat("-", 50);

$affectedProducts = [];
foreach ($pembelianTanpaRekaman as $pb) {
    $affectedProducts[$pb->id_produk] = true;
}
foreach ($produkNegatif as $p) {
    $affectedProducts[$p->id_produk] = true;
}

$output[] = "Produk terdampak: " . count($affectedProducts);

use App\Models\RekamanStok;

foreach (array_keys($affectedProducts) as $idProduk) {
    try {
        RekamanStok::recalculateStock($idProduk);
    } catch (\Exception $e) {
    }
}
$output[] = "";

$output[] = "5. FINAL SYNC";
$output[] = str_repeat("-", 50);

$syncCount = 0;
$allProducts = DB::table('produk')->get();

foreach ($allProducts as $produk) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman) {
        $stokRekaman = intval($lastRekaman->stok_sisa);
        if ($stokRekaman < 0) $stokRekaman = 0;
        
        if (intval($produk->stok) !== $stokRekaman) {
            DB::table('produk')
                ->where('id_produk', $produk->id_produk)
                ->update(['stok' => $stokRekaman]);
            $syncCount++;
        }
    }
}

$output[] = "Produk disync: {$syncCount}";
$output[] = "";

$output[] = "================================================================";
$output[] = "   SELESAI";
$output[] = "================================================================";

$content = implode("\n", $output);
$outputFile = __DIR__ . '/final_cleanup_' . date('Y-m-d_His') . '.txt';
file_put_contents($outputFile, $content);

echo $content;
echo "\n\nHasil disimpan ke: {$outputFile}\n";
