<?php

require_once 'vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\RekamanStok;
use App\Http\Controllers\StockSyncController;

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);

echo "=== TEST FITUR SINKRONISASI ===\n";
echo "Waktu: " . now()->format('Y-m-d H:i:s') . "\n\n";

// Test sinkronisasi melalui controller
$controller = new StockSyncController();

echo "1. ANALISIS SEBELUM SINKRONISASI:\n";
echo str_repeat("-", 50) . "\n";

$analysis_before = $controller->getStockAnalysis();

echo "Total produk: " . $analysis_before['summary']['total_produk'] . "\n";
echo "Produk stok minus: " . $analysis_before['summary']['produk_stok_minus'] . "\n";
echo "Produk stok nol: " . $analysis_before['summary']['produk_stok_nol'] . "\n";
echo "Total rekaman: " . $analysis_before['summary']['total_rekaman'] . "\n";
echo "Rekaman awal minus: " . $analysis_before['summary']['rekaman_awal_minus'] . "\n";
echo "Rekaman sisa minus: " . $analysis_before['summary']['rekaman_sisa_minus'] . "\n";
echo "Health score: " . $analysis_before['health_score'] . "%\n";
echo "Inconsistent products: " . $analysis_before['inconsistent_products']->count() . "\n\n";

if ($analysis_before['inconsistent_products']->count() > 0) {
    echo "Produk tidak konsisten (5 teratas):\n";
    foreach ($analysis_before['inconsistent_products']->take(5) as $product) {
        echo "- {$product->nama_produk}: Stok={$product->stok}, Rekaman_sisa={$product->stok_sisa}\n";
    }
    echo "\n";
}

echo "2. MENJALANKAN SINKRONISASI:\n";
echo str_repeat("-", 50) . "\n";

try {
    // Simulasi request sinkronisasi
    $request = new \Illuminate\Http\Request();
    $response = $controller->performSync($request);
    $responseData = $response->getData(true);
    
    if ($responseData['success']) {
        echo "✅ Sinkronisasi berhasil!\n";
        echo "Output:\n" . $responseData['data']['output'] . "\n";
    } else {
        echo "❌ Sinkronisasi gagal: " . $responseData['message'] . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error saat sinkronisasi: " . $e->getMessage() . "\n";
}

echo "3. ANALISIS SETELAH SINKRONISASI:\n";
echo str_repeat("-", 50) . "\n";

$analysis_after = $controller->getStockAnalysis();

echo "Total produk: " . $analysis_after['summary']['total_produk'] . "\n";
echo "Produk stok minus: " . $analysis_after['summary']['produk_stok_minus'] . "\n";
echo "Produk stok nol: " . $analysis_after['summary']['produk_stok_nol'] . "\n";
echo "Total rekaman: " . $analysis_after['summary']['total_rekaman'] . "\n";
echo "Rekaman awal minus: " . $analysis_after['summary']['rekaman_awal_minus'] . "\n";
echo "Rekaman sisa minus: " . $analysis_after['summary']['rekaman_sisa_minus'] . "\n";
echo "Health score: " . $analysis_after['health_score'] . "%\n";
echo "Inconsistent products: " . $analysis_after['inconsistent_products']->count() . "\n\n";

// Bandingkan sebelum dan sesudah
echo "4. PERBANDINGAN HASIL:\n";
echo str_repeat("-", 50) . "\n";

$improvement = [];

if ($analysis_before['summary']['produk_stok_minus'] > $analysis_after['summary']['produk_stok_minus']) {
    $improvement[] = "Produk stok minus berkurang: " . 
        $analysis_before['summary']['produk_stok_minus'] . " → " . 
        $analysis_after['summary']['produk_stok_minus'];
}

if ($analysis_before['summary']['rekaman_awal_minus'] > $analysis_after['summary']['rekaman_awal_minus']) {
    $improvement[] = "Rekaman awal minus berkurang: " . 
        $analysis_before['summary']['rekaman_awal_minus'] . " → " . 
        $analysis_after['summary']['rekaman_awal_minus'];
}

if ($analysis_before['summary']['rekaman_sisa_minus'] > $analysis_after['summary']['rekaman_sisa_minus']) {
    $improvement[] = "Rekaman sisa minus berkurang: " . 
        $analysis_before['summary']['rekaman_sisa_minus'] . " → " . 
        $analysis_after['summary']['rekaman_sisa_minus'];
}

if ($analysis_before['inconsistent_products']->count() > $analysis_after['inconsistent_products']->count()) {
    $improvement[] = "Produk tidak konsisten berkurang: " . 
        $analysis_before['inconsistent_products']->count() . " → " . 
        $analysis_after['inconsistent_products']->count();
}

if ($analysis_before['health_score'] < $analysis_after['health_score']) {
    $improvement[] = "Health score meningkat: " . 
        $analysis_before['health_score'] . "% → " . 
        $analysis_after['health_score'] . "%";
}

if (empty($improvement)) {
    echo "✅ SISTEM SUDAH DALAM KONDISI OPTIMAL\n";
    echo "   Tidak ada perbaikan yang diperlukan\n";
} else {
    echo "✅ PERBAIKAN BERHASIL:\n";
    foreach ($improvement as $improve) {
        echo "   - {$improve}\n";
    }
}

echo "\n5. TEST KONSISTENSI SPESIFIK PRODUK:\n";
echo str_repeat("-", 50) . "\n";

$produk_test = Produk::find(2);
$rekaman_terbaru = RekamanStok::where('id_produk', 2)
    ->orderBy('id_rekaman_stok', 'desc')
    ->first();

echo "Produk: {$produk_test->nama_produk}\n";
echo "Stok produk: {$produk_test->stok}\n";

if ($rekaman_terbaru) {
    echo "Rekaman terbaru:\n";
    echo "- Stok awal: {$rekaman_terbaru->stok_awal}\n";
    echo "- Stok sisa: {$rekaman_terbaru->stok_sisa}\n";
    echo "- Waktu: " . $rekaman_terbaru->waktu->format('Y-m-d H:i:s') . "\n";
    echo "- Keterangan: {$rekaman_terbaru->keterangan}\n";
    
    if ($rekaman_terbaru->stok_sisa == $produk_test->stok) {
        echo "✅ Konsistensi data perfect!\n";
    } else {
        echo "⚠️  Masih ada inkonsistensi:\n";
        echo "   Selisih: " . abs($rekaman_terbaru->stok_sisa - $produk_test->stok) . "\n";
    }
} else {
    echo "ℹ️  Tidak ada rekaman stok\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "TEST FITUR SINKRONISASI SELESAI\n";
echo str_repeat("=", 60) . "\n";

?>
