<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(300);

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\RekamanStok;

$output = [];
$output[] = "================================================================";
$output[] = "   STOCK HEALTH CHECK - APOTEK BISMA";
$output[] = "   Waktu: " . date('Y-m-d H:i:s');
$output[] = "================================================================";
$output[] = "";

$issues = [];

$output[] = "1. CEK MISMATCH STOK PRODUK vs REKAMAN TERAKHIR";
$output[] = str_repeat("-", 50);

$mismatchCount = 0;
$allProducts = DB::table('produk')->get();

foreach ($allProducts as $p) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $p->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman) {
        $stokProduk = intval($p->stok);
        $stokRekaman = intval($lastRekaman->stok_sisa);
        
        if ($stokProduk !== $stokRekaman) {
            $mismatchCount++;
            $issues[] = [
                'type' => 'mismatch',
                'id_produk' => $p->id_produk,
                'nama' => $p->nama_produk,
                'stok_produk' => $stokProduk,
                'stok_rekaman' => $stokRekaman,
                'selisih' => $stokProduk - $stokRekaman,
            ];
        }
    }
}

if ($mismatchCount == 0) {
    $output[] = "✅ Tidak ada mismatch ditemukan.";
} else {
    $output[] = "❌ Ditemukan {$mismatchCount} produk dengan mismatch!";
    foreach (array_slice($issues, 0, 10) as $i) {
        $output[] = "   - {$i['nama']}: produk={$i['stok_produk']}, rekaman={$i['stok_rekaman']}";
    }
    if ($mismatchCount > 10) {
        $output[] = "   ... dan " . ($mismatchCount - 10) . " lainnya";
    }
}
$output[] = "";

$output[] = "2. CEK DUPLIKAT REKAMAN STOK";
$output[] = str_repeat("-", 50);

$duplicatePenjualan = DB::select("
    SELECT id_produk, id_penjualan, COUNT(*) as cnt
    FROM rekaman_stoks
    WHERE id_penjualan IS NOT NULL AND id_penjualan > 0
    GROUP BY id_produk, id_penjualan
    HAVING cnt > 1
");

$duplicatePembelian = DB::select("
    SELECT id_produk, id_pembelian, COUNT(*) as cnt
    FROM rekaman_stoks
    WHERE id_pembelian IS NOT NULL AND id_pembelian > 0
    GROUP BY id_produk, id_pembelian
    HAVING cnt > 1
");

$totalDuplicates = count($duplicatePenjualan) + count($duplicatePembelian);

if ($totalDuplicates == 0) {
    $output[] = "✅ Tidak ada duplikat rekaman ditemukan.";
} else {
    $output[] = "❌ Ditemukan {$totalDuplicates} grup duplikat!";
    $output[] = "   Duplikat Penjualan: " . count($duplicatePenjualan);
    $output[] = "   Duplikat Pembelian: " . count($duplicatePembelian);
}
$output[] = "";

$output[] = "3. CEK FORMULA REKAMAN STOK";
$output[] = str_repeat("-", 50);

$invalidFormula = DB::select("
    SELECT id_rekaman_stok, id_produk
    FROM rekaman_stoks
    WHERE stok_sisa != (stok_awal + stok_masuk - stok_keluar)
    AND NOT (stok_sisa = 0 AND (stok_awal + stok_masuk - stok_keluar) < 0)
    LIMIT 100
");

if (count($invalidFormula) == 0) {
    $output[] = "✅ Semua rekaman memiliki formula yang valid.";
} else {
    $output[] = "❌ Ditemukan " . count($invalidFormula) . " rekaman dengan formula tidak valid!";
}
$output[] = "";

$output[] = "4. CEK TRANSAKSI TANPA REKAMAN (7 HARI TERAKHIR)";
$output[] = str_repeat("-", 50);

$sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));

$penjualanTanpaRekaman = DB::select("
    SELECT pd.id_penjualan, pd.id_produk, pd.jumlah
    FROM penjualan_detail pd
    JOIN penjualan pj ON pd.id_penjualan = pj.id_penjualan
    WHERE pj.waktu >= ?
    AND NOT EXISTS (
        SELECT 1 FROM rekaman_stoks rs 
        WHERE rs.id_penjualan = pd.id_penjualan 
        AND rs.id_produk = pd.id_produk
    )
", [$sevenDaysAgo]);

$pembelianTanpaRekaman = DB::select("
    SELECT pd.id_pembelian, pd.id_produk, pd.jumlah
    FROM pembelian_detail pd
    JOIN pembelian pb ON pd.id_pembelian = pb.id_pembelian
    WHERE pb.waktu >= ?
    AND NOT EXISTS (
        SELECT 1 FROM rekaman_stoks rs 
        WHERE rs.id_pembelian = pd.id_pembelian 
        AND rs.id_produk = pd.id_produk
    )
", [$sevenDaysAgo]);

$totalTanpaRekaman = count($penjualanTanpaRekaman) + count($pembelianTanpaRekaman);

if ($totalTanpaRekaman == 0) {
    $output[] = "✅ Semua transaksi 7 hari terakhir memiliki rekaman.";
} else {
    $output[] = "❌ Ditemukan {$totalTanpaRekaman} transaksi tanpa rekaman!";
    $output[] = "   Penjualan: " . count($penjualanTanpaRekaman);
    $output[] = "   Pembelian: " . count($pembelianTanpaRekaman);
}
$output[] = "";

$output[] = "5. STATISTIK UMUM";
$output[] = str_repeat("-", 50);

$totalProduk = DB::table('produk')->count();
$produkStokNegatif = DB::table('produk')->where('stok', '<', 0)->count();
$totalRekamanStok = DB::table('rekaman_stoks')->count();
$rekamanHariIni = DB::table('rekaman_stoks')
    ->where('waktu', '>=', date('Y-m-d 00:00:00'))
    ->count();

$output[] = "Total Produk: {$totalProduk}";
$output[] = "Produk Stok Negatif: {$produkStokNegatif}";
$output[] = "Total Rekaman Stok: {$totalRekamanStok}";
$output[] = "Rekaman Hari Ini: {$rekamanHariIni}";
$output[] = "";

$output[] = "================================================================";
$output[] = "   RINGKASAN";
$output[] = "================================================================";
$output[] = "";

$totalIssues = $mismatchCount + $totalDuplicates + count($invalidFormula) + $totalTanpaRekaman;

if ($totalIssues == 0) {
    $output[] = "✅ SISTEM SEHAT - Tidak ada masalah terdeteksi!";
} else {
    $output[] = "❌ DITEMUKAN {$totalIssues} MASALAH!";
    $output[] = "";
    $output[] = "REKOMENDASI:";
    if ($mismatchCount > 0) {
        $output[] = "- Jalankan: php robust_recalculate_from_opname.php --execute";
    }
    if ($totalDuplicates > 0) {
        $output[] = "- Jalankan: php fix_duplicates.php";
    }
    if (count($invalidFormula) > 0) {
        $output[] = "- Jalankan: php repair_stock_sync.php";
    }
}

$output[] = "";
$output[] = "================================================================";

$content = implode("\n", $output);
$outputFile = __DIR__ . '/health_check_' . date('Y-m-d_His') . '.txt';
file_put_contents($outputFile, $content);

echo $content;
echo "\n\nHasil disimpan ke: {$outputFile}\n";
