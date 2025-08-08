<?php
/**
 * Script untuk mencegah stok minus di masa depan
 * Script monitoring dan pencegahan otomatis
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

echo "=== STOCK PREVENTION & MONITORING SYSTEM ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Monitoring real-time
echo "1. MONITORING STOK REAL-TIME\n";
echo str_repeat("=", 40) . "\n";

$produk_minus = Produk::where('stok', '<', 0)->get();
$rekaman_awal_minus = RekamanStok::where('stok_awal', '<', 0)->count();
$rekaman_sisa_minus = RekamanStok::where('stok_sisa', '<', 0)->count();

if ($produk_minus->count() > 0 || $rekaman_awal_minus > 0 || $rekaman_sisa_minus > 0) {
    echo "🚨 ALERT: Ditemukan masalah stok!\n";
    echo "   - Produk stok minus: " . $produk_minus->count() . "\n";
    echo "   - Rekaman stok_awal minus: {$rekaman_awal_minus}\n";
    echo "   - Rekaman stok_sisa minus: {$rekaman_sisa_minus}\n";
    
    // Auto-fix jika diperlukan
    echo "\n🔧 MENJALANKAN AUTO-FIX...\n";
    
    if ($produk_minus->count() > 0) {
        DB::beginTransaction();
        try {
            foreach ($produk_minus as $produk) {
                $old_stock = $produk->stok;
                $produk->stok = 0;
                $produk->save();
                
                RekamanStok::create([
                    'id_produk' => $produk->id_produk,
                    'waktu' => now(),
                    'stok_masuk' => abs($old_stock),
                    'stok_awal' => $old_stock,
                    'stok_sisa' => 0,
                    'keterangan' => 'Auto-fix: Pencegahan stok minus otomatis - ' . date('Y-m-d H:i:s')
                ]);
                
                echo "   ✓ {$produk->nama_produk}: {$old_stock} → 0\n";
            }
            DB::commit();
            echo "   ✅ Auto-fix produk selesai\n";
        } catch (\Exception $e) {
            DB::rollback();
            echo "   ❌ Auto-fix gagal: " . $e->getMessage() . "\n";
        }
    }
    
} else {
    echo "✅ Semua stok dalam kondisi normal\n";
    echo "✅ Tidak ada masalah yang terdeteksi\n";
}

// 2. Analisis tren stok rendah
echo "\n2. ANALISIS STOK RENDAH (≤ 10)\n";
echo str_repeat("=", 40) . "\n";

$stok_rendah = Produk::where('stok', '>', 0)
                    ->where('stok', '<=', 10)
                    ->orderBy('stok', 'asc')
                    ->limit(10)
                    ->get();

if ($stok_rendah->count() > 0) {
    echo "⚠️  Produk dengan stok rendah:\n";
    foreach ($stok_rendah as $produk) {
        echo "   - {$produk->nama_produk}: {$produk->stok} unit\n";
    }
    echo "\n💡 Pertimbangkan untuk menambah stok produk di atas.\n";
} else {
    echo "✅ Tidak ada produk dengan stok rendah\n";
}

// 3. Validasi integritas data
echo "\n3. VALIDASI INTEGRITAS DATA\n";
echo str_repeat("=", 40) . "\n";

$inconsistent_count = DB::selectOne("
    SELECT COUNT(*) as count
    FROM rekaman_stoks 
    WHERE (stok_awal + COALESCE(stok_masuk, 0) - COALESCE(stok_keluar, 0)) != stok_sisa
    AND stok_awal IS NOT NULL 
    AND stok_sisa IS NOT NULL
")->count;

if ($inconsistent_count > 0) {
    echo "⚠️  Ditemukan {$inconsistent_count} rekaman dengan logika tidak konsisten\n";
    echo "💡 Jalankan 'php artisan stock:fix-missing-records' jika diperlukan\n";
} else {
    echo "✅ Semua rekaman stok memiliki logika yang konsisten\n";
}

// 4. Rekomendasi pemeliharaan
echo "\n4. REKOMENDASI PEMELIHARAAN\n";
echo str_repeat("=", 40) . "\n";

$total_rekaman = RekamanStok::count();
$rekaman_minggu_ini = RekamanStok::where('created_at', '>=', now()->subWeek())->count();

echo "📊 Statistik transaksi:\n";
echo "   - Total rekaman stok: {$total_rekaman}\n";
echo "   - Rekaman minggu ini: {$rekaman_minggu_ini}\n";

if ($rekaman_minggu_ini > 1000) {
    echo "💡 Volume transaksi tinggi. Pertimbangkan untuk:\n";
    echo "   - Archive data lama secara berkala\n";
    echo "   - Monitoring performa database\n";
}

// 5. Health check
echo "\n5. HEALTH CHECK SUMMARY\n";
echo str_repeat("=", 40) . "\n";

$health_score = 100;
$issues = [];

if ($produk_minus->count() > 0) {
    $health_score -= 30;
    $issues[] = "Produk dengan stok minus";
}

if ($rekaman_awal_minus > 0 || $rekaman_sisa_minus > 0) {
    $health_score -= 20;
    $issues[] = "Rekaman stok minus";
}

if ($inconsistent_count > 10) {
    $health_score -= 15;
    $issues[] = "Banyak rekaman tidak konsisten";
}

if ($stok_rendah->count() > 20) {
    $health_score -= 10;
    $issues[] = "Banyak produk stok rendah";
}

if ($health_score >= 90) {
    echo "🟢 HEALTH SCORE: {$health_score}/100 - EXCELLENT\n";
    echo "✅ Sistem stok berjalan dengan sangat baik\n";
} elseif ($health_score >= 70) {
    echo "🟡 HEALTH SCORE: {$health_score}/100 - GOOD\n";
    echo "⚠️  Ada beberapa hal yang perlu diperhatikan:\n";
    foreach ($issues as $issue) {
        echo "   - {$issue}\n";
    }
} else {
    echo "🔴 HEALTH SCORE: {$health_score}/100 - NEEDS ATTENTION\n";
    echo "🚨 Masalah yang perlu segera ditangani:\n";
    foreach ($issues as $issue) {
        echo "   - {$issue}\n";
    }
}

echo "\n📅 Monitoring selesai pada: " . date('Y-m-d H:i:s') . "\n";
echo "💡 Jalankan script ini secara berkala untuk monitoring rutin.\n";
