<?php
/**
 * Script untuk menganalisis ketidaksinkronan data stok
 * Analisis masalah antara tabel produk dan rekaman_stoks
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

echo "=== ANALISIS SINKRONISASI STOK ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Analisis stok produk yang sudah tidak minus
echo "1. ANALISIS STOK PRODUK\n";
echo str_repeat("=", 50) . "\n";

$total_produk = Produk::count();
$produk_minus = Produk::where('stok', '<', 0)->count();
$produk_normal = Produk::where('stok', '>=', 0)->count();

echo "Total produk: {$total_produk}\n";
echo "Produk dengan stok minus: {$produk_minus}\n";
echo "Produk dengan stok normal (>=0): {$produk_normal}\n\n";

// 2. Analisis rekaman stok yang masih minus
echo "2. ANALISIS REKAMAN STOK\n";
echo str_repeat("=", 50) . "\n";

$total_rekaman = RekamanStok::count();
$rekaman_stok_awal_minus = RekamanStok::where('stok_awal', '<', 0)->count();
$rekaman_stok_sisa_minus = RekamanStok::where('stok_sisa', '<', 0)->count();

echo "Total rekaman stok: {$total_rekaman}\n";
echo "Rekaman dengan stok_awal minus: {$rekaman_stok_awal_minus}\n";
echo "Rekaman dengan stok_sisa minus: {$rekaman_stok_sisa_minus}\n\n";

// 3. Analisis ketidaksinkronan
echo "3. ANALISIS KETIDAKSINKRONAN\n";
echo str_repeat("=", 50) . "\n";

// Cari produk yang stoknya sudah normal tapi masih ada rekaman minus
$problematic_products = DB::select("
    SELECT 
        p.id_produk,
        p.nama_produk,
        p.stok as stok_current,
        COUNT(rs.id_rekaman_stok) as total_rekaman_minus,
        MIN(rs.stok_awal) as min_stok_awal,
        MIN(rs.stok_sisa) as min_stok_sisa
    FROM produk p
    LEFT JOIN rekaman_stoks rs ON p.id_produk = rs.id_produk 
    WHERE p.stok >= 0 
    AND (rs.stok_awal < 0 OR rs.stok_sisa < 0)
    GROUP BY p.id_produk, p.nama_produk, p.stok
    ORDER BY total_rekaman_minus DESC
    LIMIT 20
");

if (count($problematic_products) > 0) {
    echo "Ditemukan " . count($problematic_products) . " produk bermasalah:\n";
    echo sprintf("%-5s | %-30s | %-8s | %-8s | %-10s | %-10s\n", 
        "ID", "Nama Produk", "Stok", "Rekaman", "Min Awal", "Min Sisa");
    echo str_repeat("-", 80) . "\n";
    
    foreach ($problematic_products as $product) {
        echo sprintf("%-5s | %-30s | %-8s | %-8s | %-10s | %-10s\n",
            $product->id_produk,
            substr($product->nama_produk, 0, 28),
            $product->stok_current,
            $product->total_rekaman_minus,
            $product->min_stok_awal,
            $product->min_stok_sisa
        );
    }
} else {
    echo "✓ Tidak ada produk yang bermasalah ditemukan.\n";
}

echo "\n";

// 4. Detail rekaman stok minus untuk produk tertentu
echo "4. SAMPLE DETAIL REKAMAN MINUS\n";
echo str_repeat("=", 50) . "\n";

$sample_records = DB::select("
    SELECT 
        rs.id_rekaman_stok,
        rs.id_produk,
        p.nama_produk,
        rs.waktu,
        rs.stok_awal,
        rs.stok_sisa,
        rs.stok_masuk,
        rs.stok_keluar,
        rs.keterangan
    FROM rekaman_stoks rs
    JOIN produk p ON rs.id_produk = p.id_produk
    WHERE (rs.stok_awal < 0 OR rs.stok_sisa < 0)
    AND p.stok >= 0
    ORDER BY rs.waktu DESC
    LIMIT 10
");

if (count($sample_records) > 0) {
    foreach ($sample_records as $record) {
        echo "ID Rekaman: {$record->id_rekaman_stok}\n";
        echo "Produk: {$record->nama_produk} (ID: {$record->id_produk})\n";
        echo "Waktu: {$record->waktu}\n";
        echo "Stok Awal: {$record->stok_awal} | Stok Sisa: {$record->stok_sisa}\n";
        echo "Masuk: " . ($record->stok_masuk ?? 0) . " | Keluar: " . ($record->stok_keluar ?? 0) . "\n";
        echo "Keterangan: " . ($record->keterangan ?? 'Tidak ada') . "\n";
        echo str_repeat("-", 50) . "\n";
    }
} else {
    echo "✓ Tidak ada rekaman minus untuk produk dengan stok normal.\n";
}

echo "\n=== ANALISIS SELESAI ===\n";
echo "Silakan review hasil di atas untuk memahami scope masalah.\n";
