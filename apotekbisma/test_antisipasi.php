<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;
use App\Models\Pembelian;
use App\Models\PembelianDetail;
use App\Http\Controllers\PenjualanDetailController;
use App\Http\Controllers\PembelianDetailController;
use App\Http\Controllers\ProdukController;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Carbon\Carbon;

echo "=== TEST ANTISIPASI SISTEM TERBARU ===\n\n";

DB::beginTransaction();

try {
    echo "1. TEST ANTISIPASI PRODUK TANPA REKAMAN STOK\n";
    echo str_repeat("=", 50) . "\n";
    
    $produkBaru = new Produk();
    $produkBaru->kode_produk = 'TEST999';
    $produkBaru->nama_produk = 'TEST PRODUK ANTISIPASI';
    $produkBaru->id_kategori = 1;
    $produkBaru->merk = 'TEST';
    $produkBaru->harga_beli = 5000;
    $produkBaru->diskon = 0;
    $produkBaru->harga_jual = 6000;
    $produkBaru->stok = 10;
    $produkBaru->save();
    
    echo "✓ Produk test dibuat: {$produkBaru->nama_produk} (ID: {$produkBaru->id_produk})\n";
    
    $hasRekamanBefore = RekamanStok::where('id_produk', $produkBaru->id_produk)->exists();
    echo "- Rekaman stok sebelum: " . ($hasRekamanBefore ? "ADA" : "TIDAK ADA") . "\n";
    
    $controller = new PenjualanDetailController();
    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('ensureProdukHasRekamanStok');
    $method->setAccessible(true);
    $method->invoke($controller, $produkBaru);
    
    $hasRekamanAfter = RekamanStok::where('id_produk', $produkBaru->id_produk)->exists();
    echo "- Rekaman stok setelah: " . ($hasRekamanAfter ? "ADA" : "TIDAK ADA") . "\n";
    echo "✅ ANTISIPASI PRODUK TANPA REKAMAN: " . ($hasRekamanAfter ? "BERHASIL" : "GAGAL") . "\n\n";
    
    echo "2. TEST ANTISIPASI TRANSAKSI TANPA WAKTU\n";
    echo str_repeat("=", 50) . "\n";
    
    $penjualanNull = new Penjualan();
    $penjualanNull->id_member = null;
    $penjualanNull->total_item = 1;
    $penjualanNull->total_harga = 10000;
    $penjualanNull->diskon = 0;
    $penjualanNull->bayar = 10000;
    $penjualanNull->diterima = 10000;
    $penjualanNull->waktu = null;
    $penjualanNull->id_user = 1;
    $penjualanNull->save();
    
    echo "✓ Penjualan test dibuat dengan waktu NULL (ID: {$penjualanNull->id_penjualan})\n";
    echo "- Waktu sebelum: " . ($penjualanNull->waktu ?? "NULL") . "\n";
    
    $controller = new PenjualanDetailController();
    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('ensurePenjualanHasWaktu');
    $method->setAccessible(true);
    $method->invoke($controller, $penjualanNull);
    
    $penjualanNull->refresh();
    echo "- Waktu setelah: " . ($penjualanNull->waktu ?? "NULL") . "\n";
    echo "✅ ANTISIPASI WAKTU NULL: " . ($penjualanNull->waktu ? "BERHASIL" : "GAGAL") . "\n\n";
    
    echo "3. TEST COMMAND SINKRONISASI KOMPREHENSIF\n";
    echo str_repeat("=", 50) . "\n";
    
    $exitCode = \Illuminate\Support\Facades\Artisan::call('stok:sinkronisasi');
    $output = \Illuminate\Support\Facades\Artisan::output();
    
    echo "Exit code: {$exitCode}\n";
    echo "Output:\n{$output}\n";
    echo "✅ COMMAND KOMPREHENSIF: " . ($exitCode === 0 ? "BERHASIL" : "GAGAL") . "\n\n";
    
    DB::rollBack();
    echo "✓ Semua test data di-rollback\n\n";
    
    echo str_repeat("=", 60) . "\n";
    echo "HASIL TEST ANTISIPASI SISTEM\n";
    echo str_repeat("=", 60) . "\n";
    
    $results = [
        'Antisipasi Produk Tanpa Rekaman' => $hasRekamanAfter,
        'Antisipasi Waktu NULL' => (bool)$penjualanNull->waktu,
        'Command Komprehensif' => $exitCode === 0
    ];
    
    $allPassed = true;
    foreach ($results as $test => $passed) {
        $status = $passed ? "✅ PASS" : "❌ FAIL";
        echo "{$status} {$test}\n";
        if (!$passed) $allPassed = false;
    }
    
    echo "\n";
    if ($allPassed) {
        echo "🎉 SEMUA ANTISIPASI BERFUNGSI DENGAN SEMPURNA!\n";
        echo "Sistem sekarang memiliki perlindungan otomatis terhadap:\n";
        echo "- Produk tanpa rekaman stok\n";
        echo "- Transaksi dengan waktu NULL\n";
        echo "- Inkonsistensi data stok\n";
    } else {
        echo "⚠ ADA ANTISIPASI YANG PERLU DIPERBAIKI\n";
    }
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

echo "\nTest selesai pada: " . Carbon::now()->format('Y-m-d H:i:s') . "\n";
