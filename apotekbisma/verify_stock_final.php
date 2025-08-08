<?php
/**
 * Script verifikasi final untuk memastikan tidak ada masalah stok
 * Cek menyeluruh konsistensi data
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

echo "=== VERIFIKASI FINAL KONSISTENSI STOK ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n\n";

$errors = [];
$warnings = [];

// 1. Cek stok produk minus
echo "1. VERIFIKASI STOK PRODUK\n";
echo str_repeat("=", 40) . "\n";

$produk_minus = Produk::where('stok', '<', 0)->count();
if ($produk_minus > 0) {
    $errors[] = "Masih ada {$produk_minus} produk dengan stok minus";
    echo "❌ Masih ada {$produk_minus} produk dengan stok minus\n";
} else {
    echo "✅ Semua produk memiliki stok >= 0\n";
}

// 2. Cek rekaman stok minus
echo "\n2. VERIFIKASI REKAMAN STOK\n";
echo str_repeat("=", 40) . "\n";

$rekaman_awal_minus = RekamanStok::where('stok_awal', '<', 0)->count();
$rekaman_sisa_minus = RekamanStok::where('stok_sisa', '<', 0)->count();

if ($rekaman_awal_minus > 0) {
    $errors[] = "Masih ada {$rekaman_awal_minus} rekaman dengan stok_awal minus";
    echo "❌ Masih ada {$rekaman_awal_minus} rekaman dengan stok_awal minus\n";
} else {
    echo "✅ Semua rekaman memiliki stok_awal >= 0\n";
}

if ($rekaman_sisa_minus > 0) {
    $errors[] = "Masih ada {$rekaman_sisa_minus} rekaman dengan stok_sisa minus";
    echo "❌ Masih ada {$rekaman_sisa_minus} rekaman dengan stok_sisa minus\n";
} else {
    echo "✅ Semua rekaman memiliki stok_sisa >= 0\n";
}

// 3. Cek konsistensi logic rekaman stok
echo "\n3. VERIFIKASI LOGIKA REKAMAN STOK\n";
echo str_repeat("=", 40) . "\n";

$inconsistent_records = DB::select("
    SELECT 
        id_rekaman_stok,
        id_produk,
        stok_awal,
        stok_masuk,
        stok_keluar,
        stok_sisa,
        (stok_awal + COALESCE(stok_masuk, 0) - COALESCE(stok_keluar, 0)) as calculated_sisa
    FROM rekaman_stoks 
    WHERE (stok_awal + COALESCE(stok_masuk, 0) - COALESCE(stok_keluar, 0)) != stok_sisa
    AND stok_awal IS NOT NULL 
    AND stok_sisa IS NOT NULL
    LIMIT 10
");

if (count($inconsistent_records) > 0) {
    $warnings[] = "Ditemukan " . count($inconsistent_records) . " rekaman dengan logika tidak konsisten";
    echo "⚠️  Ditemukan rekaman dengan logika tidak konsisten:\n";
    foreach ($inconsistent_records as $record) {
        echo "   ID {$record->id_rekaman_stok}: {$record->stok_awal} + {$record->stok_masuk} - {$record->stok_keluar} ≠ {$record->stok_sisa}\n";
    }
} else {
    echo "✅ Semua rekaman memiliki logika yang konsisten\n";
}

// 4. Cek produk dengan stok 0 yang masih memiliki rekaman minus
echo "\n4. VERIFIKASI PRODUK STOK 0\n";
echo str_repeat("=", 40) . "\n";

$zero_stock_with_minus_records = DB::select("
    SELECT 
        p.id_produk,
        p.nama_produk,
        p.stok,
        COUNT(rs.id_rekaman_stok) as minus_records
    FROM produk p
    JOIN rekaman_stoks rs ON p.id_produk = rs.id_produk
    WHERE p.stok = 0 
    AND (rs.stok_awal < 0 OR rs.stok_sisa < 0)
    GROUP BY p.id_produk, p.nama_produk, p.stok
    LIMIT 5
");

if (count($zero_stock_with_minus_records) > 0) {
    $warnings[] = "Ditemukan produk stok 0 dengan rekaman minus";
    echo "⚠️  Produk stok 0 dengan rekaman minus:\n";
    foreach ($zero_stock_with_minus_records as $record) {
        echo "   {$record->nama_produk} (ID: {$record->id_produk}) - {$record->minus_records} rekaman minus\n";
    }
} else {
    echo "✅ Tidak ada produk stok 0 dengan rekaman minus\n";
}

// 5. Summary
echo "\n" . str_repeat("=", 50) . "\n";
echo "HASIL VERIFIKASI FINAL\n";
echo str_repeat("=", 50) . "\n";

if (empty($errors)) {
    echo "🎉 SEMPURNA! Tidak ada error ditemukan.\n";
    echo "✅ Database sudah konsisten dan sinkron\n";
    echo "✅ Semua stok produk >= 0\n";
    echo "✅ Semua rekaman stok >= 0\n";
    echo "✅ Sistem siap untuk beroperasi normal\n";
} else {
    echo "❌ MASIH ADA MASALAH:\n";
    foreach ($errors as $error) {
        echo "   - {$error}\n";
    }
    echo "\n🔧 Jalankan fix_stock_records.php untuk memperbaiki masalah ini.\n";
}

if (!empty($warnings)) {
    echo "\n⚠️  PERINGATAN:\n";
    foreach ($warnings as $warning) {
        echo "   - {$warning}\n";
    }
    echo "\n💡 Peringatan ini tidak kritis tapi perlu diperhatikan.\n";
}

// 6. Statistik akhir
echo "\n📊 STATISTIK DATABASE:\n";
echo str_repeat("-", 30) . "\n";
echo "Total produk: " . Produk::count() . "\n";
echo "Total rekaman stok: " . RekamanStok::count() . "\n";
echo "Produk stok > 0: " . Produk::where('stok', '>', 0)->count() . "\n";
echo "Produk stok = 0: " . Produk::where('stok', '=', 0)->count() . "\n";
echo "Rekaman hari ini: " . RekamanStok::whereDate('created_at', today())->count() . "\n";

echo "\nVerifikasi selesai pada: " . date('Y-m-d H:i:s') . "\n";
