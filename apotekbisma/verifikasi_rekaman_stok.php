<?php
/**
 * SCRIPT TEST - VERIFIKASI REKAMAN STOK
 * 
 * Script ini untuk mengecek kondisi rekaman stok sebelum dan sesudah perbaikan
 */

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

echo "=== VERIFIKASI KONDISI REKAMAN STOK ===\n\n";

// 1. Cek total statistik
echo "1. STATISTIK UMUM\n";
echo str_repeat("=", 60) . "\n";

$total_products = Produk::count();
$total_records = RekamanStok::count();
$products_with_records = DB::select("
    SELECT COUNT(DISTINCT id_produk) as count
    FROM rekaman_stoks
")[0]->count;

echo "Total Produk                 : {$total_products}\n";
echo "Total Rekaman Stok           : {$total_records}\n";
echo "Produk dengan Rekaman        : {$products_with_records}\n";
echo "Rata-rata Rekaman per Produk : " . round($total_records / max($products_with_records, 1), 2) . "\n\n";

// 2. Cek inkonsistensi
echo "2. CEK INKONSISTENSI\n";
echo str_repeat("=", 60) . "\n";

$inconsistencies = DB::select("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN diff > 0 THEN 1 ELSE 0 END) as positive_diff,
        SUM(CASE WHEN diff < 0 THEN 1 ELSE 0 END) as negative_diff,
        MAX(ABS(diff)) as max_diff,
        AVG(ABS(diff)) as avg_diff
    FROM (
        SELECT 
            id_rekaman_stok,
            stok_sisa,
            (stok_awal + COALESCE(stok_masuk, 0) - COALESCE(stok_keluar, 0)) as calculated_sisa,
            (stok_sisa - (stok_awal + COALESCE(stok_masuk, 0) - COALESCE(stok_keluar, 0))) as diff
        FROM rekaman_stoks
        WHERE stok_sisa != (stok_awal + COALESCE(stok_masuk, 0) - COALESCE(stok_keluar, 0))
    ) as inconsistent_records
")[0];

if ($inconsistencies->total > 0) {
    echo "❌ DITEMUKAN INKONSISTENSI!\n";
    echo "Total Rekaman Inkonsisten    : {$inconsistencies->total}\n";
    echo "Selisih Positif (lebih besar): {$inconsistencies->positive_diff}\n";
    echo "Selisih Negatif (lebih kecil): {$inconsistencies->negative_diff}\n";
    echo "Selisih Maksimal             : {$inconsistencies->max_diff}\n";
    echo "Selisih Rata-rata            : " . round($inconsistencies->avg_diff, 2) . "\n\n";
    
    echo "PERSENTASE INKONSISTENSI     : " . round(($inconsistencies->total / $total_records) * 100, 2) . "%\n\n";
} else {
    echo "✅ TIDAK ADA INKONSISTENSI!\n";
    echo "Semua rekaman stok sudah konsisten.\n\n";
}

// 3. Sample inkonsistensi (10 teratas)
if ($inconsistencies->total > 0) {
    echo "3. CONTOH INKONSISTENSI (10 Teratas)\n";
    echo str_repeat("=", 60) . "\n";
    
    $samples = DB::select("
        SELECT 
            rs.id_rekaman_stok,
            rs.id_produk,
            p.nama_produk,
            rs.waktu,
            rs.stok_awal,
            rs.stok_masuk,
            rs.stok_keluar,
            rs.stok_sisa as stok_sisa_db,
            (rs.stok_awal + COALESCE(rs.stok_masuk, 0) - COALESCE(rs.stok_keluar, 0)) as stok_sisa_calculated,
            (rs.stok_sisa - (rs.stok_awal + COALESCE(rs.stok_masuk, 0) - COALESCE(rs.stok_keluar, 0))) as selisih
        FROM rekaman_stoks rs
        JOIN produk p ON rs.id_produk = p.id_produk
        WHERE rs.stok_sisa != (rs.stok_awal + COALESCE(rs.stok_masuk, 0) - COALESCE(rs.stok_keluar, 0))
        ORDER BY ABS(rs.stok_sisa - (rs.stok_awal + COALESCE(rs.stok_masuk, 0) - COALESCE(rs.stok_keluar, 0))) DESC
        LIMIT 10
    ");
    
    foreach ($samples as $sample) {
        echo "\nID Rekaman  : {$sample->id_rekaman_stok}\n";
        echo "Produk      : {$sample->nama_produk}\n";
        echo "Waktu       : {$sample->waktu}\n";
        echo "Stok Awal   : {$sample->stok_awal}\n";
        echo "Stok Masuk  : " . ($sample->stok_masuk ?? 0) . "\n";
        echo "Stok Keluar : " . ($sample->stok_keluar ?? 0) . "\n";
        echo "Stok Sisa (DB)        : {$sample->stok_sisa_db}\n";
        echo "Stok Sisa (Hitung)    : {$sample->stok_sisa_calculated}\n";
        echo "Selisih               : {$sample->selisih}\n";
        echo str_repeat("-", 60) . "\n";
    }
}

// 4. Cek produk dengan rekaman stok terbanyak
echo "\n4. PRODUK DENGAN REKAMAN STOK TERBANYAK\n";
echo str_repeat("=", 60) . "\n";

$top_products = DB::select("
    SELECT 
        p.id_produk,
        p.nama_produk,
        p.stok as stok_realtime,
        COUNT(rs.id_rekaman_stok) as jumlah_rekaman,
        MAX(rs.waktu) as transaksi_terakhir
    FROM produk p
    JOIN rekaman_stoks rs ON p.id_produk = rs.id_produk
    GROUP BY p.id_produk, p.nama_produk, p.stok
    ORDER BY COUNT(rs.id_rekaman_stok) DESC
    LIMIT 5
");

foreach ($top_products as $prod) {
    echo "- {$prod->nama_produk}\n";
    echo "  Stok Realtime     : {$prod->stok_realtime}\n";
    echo "  Jumlah Rekaman    : {$prod->jumlah_rekaman}\n";
    echo "  Transaksi Terakhir: {$prod->transaksi_terakhir}\n\n";
}

// 5. Cek continuity antar rekaman (stok_awal rekaman N+1 harus = stok_sisa rekaman N)
echo "5. CEK CONTINUITY ANTAR REKAMAN\n";
echo str_repeat("=", 60) . "\n";

$continuity_issues = DB::select("
    SELECT COUNT(*) as total
    FROM (
        SELECT 
            r1.id_rekaman_stok as current_id,
            r1.id_produk,
            r1.stok_awal as current_stok_awal,
            r2.stok_sisa as previous_stok_sisa
        FROM rekaman_stoks r1
        LEFT JOIN rekaman_stoks r2 ON r1.id_produk = r2.id_produk 
            AND r2.waktu < r1.waktu
            AND r2.id_rekaman_stok = (
                SELECT id_rekaman_stok 
                FROM rekaman_stoks 
                WHERE id_produk = r1.id_produk 
                    AND waktu < r1.waktu 
                ORDER BY waktu DESC, id_rekaman_stok DESC 
                LIMIT 1
            )
        WHERE r2.id_rekaman_stok IS NOT NULL
            AND r1.stok_awal != r2.stok_sisa
    ) as discontinuity
")[0]->total;

if ($continuity_issues > 0) {
    echo "❌ DITEMUKAN MASALAH CONTINUITY!\n";
    echo "Total Discontinuity: {$continuity_issues} rekaman\n";
    echo "Artinya: stok_awal tidak sama dengan stok_sisa rekaman sebelumnya\n\n";
} else {
    echo "✅ CONTINUITY BAGUS!\n";
    echo "Semua rekaman berurutan dengan benar.\n\n";
}

// 6. Rekomendasi
echo "6. REKOMENDASI\n";
echo str_repeat("=", 60) . "\n";

if ($inconsistencies->total > 0 || $continuity_issues > 0) {
    echo "🔧 PERLU PERBAIKAN!\n\n";
    echo "Jalankan script perbaikan dengan cara:\n";
    echo "1. Via Web: Buka http://127.0.0.1:8000/kartustok\n";
    echo "   Klik tombol 'Perbaiki Semua Rekaman Stok'\n\n";
    echo "2. Via Terminal: php perbaiki_rekaman_stok.php\n\n";
    echo "3. Via Browser: http://127.0.0.1:8000/perbaiki_rekaman_stok.php\n\n";
} else {
    echo "✅ DATA SUDAH BAGUS!\n";
    echo "Tidak perlu perbaikan. Semua rekaman stok sudah konsisten.\n\n";
}

// 7. Ringkasan Akhir
echo "7. RINGKASAN\n";
echo str_repeat("=", 60) . "\n";

$percentage_good = $total_records > 0 ? round((($total_records - $inconsistencies->total) / $total_records) * 100, 2) : 0;

echo "Status Rekaman Stok:\n";
echo "- Konsisten  : " . ($total_records - $inconsistencies->total) . " rekaman ({$percentage_good}%)\n";
echo "- Inkonsisten: {$inconsistencies->total} rekaman (" . (100 - $percentage_good) . "%)\n\n";

if ($percentage_good >= 99) {
    echo "✅ STATUS: SANGAT BAIK\n";
} elseif ($percentage_good >= 95) {
    echo "⚠️  STATUS: BAIK (Ada sedikit inkonsistensi)\n";
} elseif ($percentage_good >= 90) {
    echo "⚠️  STATUS: CUKUP (Perlu perbaikan minor)\n";
} else {
    echo "❌ STATUS: BURUK (Perlu perbaikan segera)\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Verifikasi selesai pada: " . date('d-m-Y H:i:s') . "\n";
