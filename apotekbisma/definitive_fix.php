<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '1G');
set_time_limit(600);

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\RekamanStok;

echo "================================================================\n";
echo "   DEFINITIVE STOCK FIX\n";
echo "   Waktu: " . date('Y-m-d H:i:s') . "\n";
echo "================================================================\n\n";

echo "STEP 1: Fix all formula issues...\n";
$invalidFormula = DB::select("
    SELECT id_rekaman_stok, stok_awal, stok_masuk, stok_keluar, stok_sisa,
           (stok_awal + stok_masuk - stok_keluar) as correct_sisa
    FROM rekaman_stoks
    WHERE stok_sisa != (stok_awal + stok_masuk - stok_keluar)
");

foreach ($invalidFormula as $rec) {
    $correctSisa = max(0, $rec->correct_sisa);
    DB::table('rekaman_stoks')
        ->where('id_rekaman_stok', $rec->id_rekaman_stok)
        ->update(['stok_sisa' => $correctSisa]);
}
echo "  Fixed: " . count($invalidFormula) . " formula issues\n\n";

echo "STEP 2: Fix negative stok_sisa...\n";
$negFixed = DB::table('rekaman_stoks')->where('stok_sisa', '<', 0)->update(['stok_sisa' => 0]);
echo "  Fixed: {$negFixed} negative stok_sisa\n\n";

echo "STEP 3: Analyze missing pembelian rekaman...\n";

$sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));

$missingPembelian = DB::select("
    SELECT pd.id_pembelian, pd.id_produk, SUM(pd.jumlah) as total, pb.waktu, p.nama_produk
    FROM pembelian_detail pd
    JOIN pembelian pb ON pd.id_pembelian = pb.id_pembelian
    JOIN produk p ON pd.id_produk = p.id_produk
    WHERE pb.waktu >= ?
    AND NOT EXISTS (
        SELECT 1 FROM rekaman_stoks rs 
        WHERE rs.id_pembelian = pd.id_pembelian AND rs.id_produk = pd.id_produk
    )
    GROUP BY pd.id_pembelian, pd.id_produk, pb.waktu, p.nama_produk
", [$sevenDaysAgo]);

echo "  Missing: " . count($missingPembelian) . " pembelian rekaman\n";

foreach ($missingPembelian as $mp) {
    $produk = DB::table('produk')->where('id_produk', $mp->id_produk)->first();
    $stokSekarang = intval($produk->stok);
    $stokAwal = max(0, $stokSekarang - intval($mp->total));
    
    DB::table('rekaman_stoks')->insert([
        'id_produk' => $mp->id_produk,
        'id_pembelian' => $mp->id_pembelian,
        'waktu' => $mp->waktu,
        'stok_awal' => $stokAwal,
        'stok_masuk' => intval($mp->total),
        'stok_keluar' => 0,
        'stok_sisa' => $stokSekarang,
        'keterangan' => 'Pembelian: Auto-fix rekaman hilang',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    echo "  Created: {$mp->nama_produk} - Pembelian #{$mp->id_pembelian}\n";
}
echo "\n";

echo "STEP 4: Sync all products with their last rekaman...\n";

$syncCount = 0;
$allProducts = DB::table('produk')->get();

foreach ($allProducts as $produk) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman) {
        $stokRekaman = max(0, intval($lastRekaman->stok_sisa));
        
        if (intval($produk->stok) !== $stokRekaman) {
            DB::table('produk')
                ->where('id_produk', $produk->id_produk)
                ->update(['stok' => $stokRekaman]);
            $syncCount++;
        }
    }
}
echo "  Synced: {$syncCount} products\n\n";

echo "STEP 5: Final health check...\n";

$mismatch = 0;
$allProducts = DB::table('produk')->get();
foreach ($allProducts as $p) {
    $last = DB::table('rekaman_stoks')
        ->where('id_produk', $p->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($last && intval($p->stok) !== intval($last->stok_sisa)) {
        $mismatch++;
    }
}

$dupPenjualan = DB::select("
    SELECT COUNT(*) as cnt FROM (
        SELECT id_produk, id_penjualan 
        FROM rekaman_stoks 
        WHERE id_penjualan IS NOT NULL AND id_penjualan > 0
        GROUP BY id_produk, id_penjualan 
        HAVING COUNT(*) > 1
    ) dup
")[0]->cnt;

$invalidFormula = DB::select("
    SELECT COUNT(*) as cnt FROM rekaman_stoks 
    WHERE stok_sisa != (stok_awal + stok_masuk - stok_keluar)
")[0]->cnt;

$negStok = DB::table('produk')->where('stok', '<', 0)->count();

echo "\n================================================================\n";
echo "   HASIL FINAL\n";
echo "================================================================\n";
echo "Mismatch produk vs rekaman: {$mismatch}\n";
echo "Duplikat rekaman: {$dupPenjualan}\n";
echo "Formula tidak valid: {$invalidFormula}\n";
echo "Stok negatif: {$negStok}\n";
echo "================================================================\n";

if ($mismatch == 0 && $dupPenjualan == 0 && $invalidFormula == 0 && $negStok == 0) {
    echo "✅ SEMUA MASALAH TELAH DIPERBAIKI!\n";
} else {
    echo "❌ Masih ada masalah yang perlu ditangani manual.\n";
}
