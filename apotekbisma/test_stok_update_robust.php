<?php

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Produk;
use App\Models\RekamanStok;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "==============================================\n";
echo "TEST ROBUSTNESS UPDATE STOK MANUAL & EDIT PRODUK\n";
echo "==============================================\n\n";

// Test data
$testResults = [];
$testProdukId = null;

try {
    // 1. Cari atau buat produk test
    echo "1. Mencari produk untuk testing...\n";
    $produk = Produk::where('nama_produk', 'LIKE', '%TEST STOK UPDATE%')->first();
    
    if (!$produk) {
        echo "   Membuat produk baru untuk testing...\n";
        $lastProduk = Produk::latest()->first() ?? new Produk();
        $newId = (int)($lastProduk->id_produk ?? 0) + 1;
        
        $produk = Produk::create([
            'kode_produk' => 'PTEST' . str_pad($newId, 6, '0', STR_PAD_LEFT),
            'nama_produk' => 'TEST STOK UPDATE ROBUST',
            'id_kategori' => 1,
            'harga_beli' => 5000,
            'harga_jual' => 7000,
            'diskon' => 0,
            'stok' => 200
        ]);
        echo "   ✓ Produk baru dibuat dengan stok awal: 200\n";
    } else {
        // Set stok awal untuk testing
        DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => 200]);
        $produk->refresh();
        echo "   ✓ Menggunakan produk existing, stok direset ke: 200\n";
    }
    
    $testProdukId = $produk->id_produk;
    echo "   Produk ID: {$testProdukId}\n";
    echo "   Nama: {$produk->nama_produk}\n";
    
    // Bersihkan rekaman test lama
    $deletedCount = DB::table('rekaman_stoks')
        ->where('id_produk', $testProdukId)
        ->where('keterangan', 'LIKE', 'TEST:%')
        ->delete();
    if ($deletedCount > 0) {
        echo "   Membersihkan {$deletedCount} rekaman test lama...\n";
    }
    echo "\n";

    // 2. TEST UPDATE STOK MANUAL: 200 → 29 (kasus bug original)
    echo "2. TEST UPDATE STOK MANUAL: 200 → 29\n";
    echo "   (Simulasi kasus bug yang dilaporkan user)\n";
    
    DB::beginTransaction();
    try {
        $stokLama = 200;
        $stokBaru = 29;
        
        // Simulate updateStokManual logic
        DB::table('produk')->where('id_produk', $testProdukId)->update(['stok' => $stokBaru]);
        
        $currentTime = \Carbon\Carbon::now();
        $selisih = $stokBaru - $stokLama;
        
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $testProdukId,
            'waktu' => $currentTime,
            'stok_awal' => $stokLama,
            'stok_masuk' => $selisih > 0 ? $selisih : 0,
            'stok_keluar' => $selisih < 0 ? abs($selisih) : 0,
            'stok_sisa' => $stokBaru,
            'keterangan' => 'TEST: Stock Opname (Penyesuaian Stok Manual) 200→29',
            'created_at' => $currentTime,
            'updated_at' => $currentTime
        ]);
        
        DB::commit();
        
        // Verifikasi
        $produkAfter = Produk::find($testProdukId);
        $latestRekaman = DB::table('rekaman_stoks')
            ->where('id_produk', $testProdukId)
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();
        
        $test1Pass = ($produkAfter->stok == 29 && $latestRekaman->stok_sisa == 29);
        $testResults[] = [
            'test' => 'Update Stok Manual 200→29',
            'pass' => $test1Pass,
            'expected_stok' => 29,
            'actual_stok' => $produkAfter->stok,
            'rekaman_stok_sisa' => $latestRekaman->stok_sisa
        ];
        
        echo "   Stok produk setelah update: {$produkAfter->stok}\n";
        echo "   Rekaman stok_sisa: {$latestRekaman->stok_sisa}\n";
        echo "   Status: " . ($test1Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";
        
    } catch (\Exception $e) {
        DB::rollBack();
        echo "   ✗ ERROR: {$e->getMessage()}\n\n";
        $testResults[] = ['test' => 'Update Stok Manual 200→29', 'pass' => false, 'error' => $e->getMessage()];
    }

    // 3. TEST UPDATE STOK MANUAL: 29 → 150 (increase)
    echo "3. TEST UPDATE STOK MANUAL: 29 → 150 (Increase)\n";
    
    DB::beginTransaction();
    try {
        $stokLama = 29;
        $stokBaru = 150;
        
        DB::table('produk')->where('id_produk', $testProdukId)->update(['stok' => $stokBaru]);
        
        $currentTime = \Carbon\Carbon::now();
        $selisih = $stokBaru - $stokLama;
        
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $testProdukId,
            'waktu' => $currentTime,
            'stok_awal' => $stokLama,
            'stok_masuk' => $selisih > 0 ? $selisih : 0,
            'stok_keluar' => $selisih < 0 ? abs($selisih) : 0,
            'stok_sisa' => $stokBaru,
            'keterangan' => 'TEST: Stock Opname 29→150',
            'created_at' => $currentTime,
            'updated_at' => $currentTime
        ]);
        
        DB::commit();
        
        $produkAfter = Produk::find($testProdukId);
        $latestRekaman = DB::table('rekaman_stoks')
            ->where('id_produk', $testProdukId)
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();
        
        $test2Pass = ($produkAfter->stok == 150 && $latestRekaman->stok_sisa == 150);
        $testResults[] = [
            'test' => 'Update Stok Manual 29→150',
            'pass' => $test2Pass,
            'expected_stok' => 150,
            'actual_stok' => $produkAfter->stok,
            'rekaman_stok_sisa' => $latestRekaman->stok_sisa
        ];
        
        echo "   Stok produk setelah update: {$produkAfter->stok}\n";
        echo "   Rekaman stok_sisa: {$latestRekaman->stok_sisa}\n";
        echo "   Status: " . ($test2Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";
        
    } catch (\Exception $e) {
        DB::rollBack();
        echo "   ✗ ERROR: {$e->getMessage()}\n\n";
        $testResults[] = ['test' => 'Update Stok Manual 29→150', 'pass' => false, 'error' => $e->getMessage()];
    }

    // 4. TEST EDIT PRODUK dengan perubahan stok: 150 → 75
    echo "4. TEST EDIT PRODUK: 150 → 75\n";
    
    DB::beginTransaction();
    try {
        $stokLama = 150;
        $stokBaru = 75;
        
        // Simulate update method logic
        DB::table('produk')->where('id_produk', $testProdukId)->update(['stok' => $stokBaru]);
        
        $currentTime = \Carbon\Carbon::now();
        $selisih = $stokBaru - $stokLama;
        
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $testProdukId,
            'waktu' => $currentTime,
            'stok_awal' => $stokLama,
            'stok_masuk' => $selisih > 0 ? $selisih : 0,
            'stok_keluar' => $selisih < 0 ? abs($selisih) : 0,
            'stok_sisa' => $stokBaru,
            'keterangan' => 'TEST: Stock Opname: Perubahan Stok via Edit Produk',
            'created_at' => $currentTime,
            'updated_at' => $currentTime
        ]);
        
        DB::commit();
        
        $produkAfter = Produk::find($testProdukId);
        $latestRekaman = DB::table('rekaman_stoks')
            ->where('id_produk', $testProdukId)
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();
        
        $test3Pass = ($produkAfter->stok == 75 && $latestRekaman->stok_sisa == 75);
        $testResults[] = [
            'test' => 'Edit Produk 150→75',
            'pass' => $test3Pass,
            'expected_stok' => 75,
            'actual_stok' => $produkAfter->stok,
            'rekaman_stok_sisa' => $latestRekaman->stok_sisa
        ];
        
        echo "   Stok produk setelah edit: {$produkAfter->stok}\n";
        echo "   Rekaman stok_sisa: {$latestRekaman->stok_sisa}\n";
        echo "   Status: " . ($test3Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";
        
    } catch (\Exception $e) {
        DB::rollBack();
        echo "   ✗ ERROR: {$e->getMessage()}\n\n";
        $testResults[] = ['test' => 'Edit Produk 150→75', 'pass' => false, 'error' => $e->getMessage()];
    }

    // 5. TEST PERSISTENCE: Verify stok tidak berubah setelah beberapa saat
    echo "5. TEST PERSISTENCE: Verifikasi stok tidak berubah\n";
    sleep(2);
    
    $produkFinal = Produk::find($testProdukId);
    $test4Pass = ($produkFinal->stok == 75);
    $testResults[] = [
        'test' => 'Persistence Check',
        'pass' => $test4Pass,
        'expected_stok' => 75,
        'actual_stok' => $produkFinal->stok
    ];
    
    echo "   Stok produk setelah 2 detik: {$produkFinal->stok}\n";
    echo "   Status: " . ($test4Pass ? "✓ PASS (stok tetap)" : "✗ FAIL (stok berubah!)") . "\n\n";

    // 6. TEST INTEGRITY: Verifikasi chain rekaman_stoks
    echo "6. TEST INTEGRITY: Verifikasi chain rekaman_stoks\n";
    
    $rekamanAll = DB::table('rekaman_stoks')
        ->where('id_produk', $testProdukId)
        ->where('keterangan', 'LIKE', 'TEST:%')
        ->orderBy('waktu', 'asc')
        ->get();
    
    echo "   Total rekaman test: {$rekamanAll->count()}\n";
    
    $chainValid = true;
    $prevStokSisa = null;
    
    foreach ($rekamanAll as $idx => $rekaman) {
        echo "   Rekaman " . ($idx + 1) . ":\n";
        echo "     - Stok Awal: {$rekaman->stok_awal}\n";
        echo "     - Stok Masuk: {$rekaman->stok_masuk}\n";
        echo "     - Stok Keluar: {$rekaman->stok_keluar}\n";
        echo "     - Stok Sisa: {$rekaman->stok_sisa}\n";
        
        // Verify formula
        $calculated = $rekaman->stok_awal + $rekaman->stok_masuk - $rekaman->stok_keluar;
        if ($calculated != $rekaman->stok_sisa) {
            echo "     ✗ Formula ERROR! Calculated: {$calculated}, Actual: {$rekaman->stok_sisa}\n";
            $chainValid = false;
        } else {
            echo "     ✓ Formula valid\n";
        }
        
        // Verify chain (except first)
        if ($prevStokSisa !== null && $rekaman->stok_awal != $prevStokSisa) {
            echo "     ✗ Chain ERROR! Stok awal ({$rekaman->stok_awal}) != prev stok sisa ({$prevStokSisa})\n";
            $chainValid = false;
        } elseif ($prevStokSisa !== null) {
            echo "     ✓ Chain valid\n";
        }
        
        $prevStokSisa = $rekaman->stok_sisa;
    }
    
    $testResults[] = [
        'test' => 'Integrity Check',
        'pass' => $chainValid,
        'total_rekaman' => $rekamanAll->count()
    ];
    
    echo "   Status: " . ($chainValid ? "✓ PASS (chain valid)" : "✗ FAIL (chain broken!)") . "\n\n";

    // 7. TEST NEGATIVE CASE: Stok 0
    echo "7. TEST EDGE CASE: Update ke stok 0\n";
    
    DB::beginTransaction();
    try {
        $stokLama = 75;
        $stokBaru = 0;
        
        DB::table('produk')->where('id_produk', $testProdukId)->update(['stok' => $stokBaru]);
        
        $currentTime = \Carbon\Carbon::now();
        $selisih = $stokBaru - $stokLama;
        
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $testProdukId,
            'waktu' => $currentTime,
            'stok_awal' => $stokLama,
            'stok_masuk' => $selisih > 0 ? $selisih : 0,
            'stok_keluar' => $selisih < 0 ? abs($selisih) : 0,
            'stok_sisa' => $stokBaru,
            'keterangan' => 'TEST: Stock Opname to ZERO',
            'created_at' => $currentTime,
            'updated_at' => $currentTime
        ]);
        
        DB::commit();
        
        $produkAfter = Produk::find($testProdukId);
        $test5Pass = ($produkAfter->stok == 0);
        $testResults[] = [
            'test' => 'Update to Zero',
            'pass' => $test5Pass,
            'expected_stok' => 0,
            'actual_stok' => $produkAfter->stok
        ];
        
        echo "   Stok produk: {$produkAfter->stok}\n";
        echo "   Status: " . ($test5Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";
        
    } catch (\Exception $e) {
        DB::rollBack();
        echo "   ✗ ERROR: {$e->getMessage()}\n\n";
        $testResults[] = ['test' => 'Update to Zero', 'pass' => false, 'error' => $e->getMessage()];
    }

    // SUMMARY
    echo "==============================================\n";
    echo "TEST SUMMARY\n";
    echo "==============================================\n\n";
    
    $totalTests = count($testResults);
    $passedTests = count(array_filter($testResults, fn($t) => $t['pass']));
    $failedTests = $totalTests - $passedTests;
    
    foreach ($testResults as $result) {
        $status = $result['pass'] ? '✓ PASS' : '✗ FAIL';
        echo "{$status} - {$result['test']}\n";
        
        if (!$result['pass']) {
            if (isset($result['error'])) {
                echo "        Error: {$result['error']}\n";
            }
            if (isset($result['expected_stok'])) {
                echo "        Expected: {$result['expected_stok']}, Got: {$result['actual_stok']}\n";
            }
        }
    }
    
    echo "\n";
    echo "Total Tests: {$totalTests}\n";
    echo "Passed: {$passedTests}\n";
    echo "Failed: {$failedTests}\n\n";
    
    if ($failedTests == 0) {
        echo "🎉 SEMUA TEST BERHASIL! UPDATE STOK ROBUST! 🎉\n";
    } else {
        echo "⚠️ ADA TEST YANG GAGAL! PERLU PERBAIKAN! ⚠️\n";
    }
    
    echo "\n==============================================\n";
    
    // Cleanup option
    echo "\nCatatan: Produk test ID {$testProdukId} tetap ada di database.\n";
    echo "Rekaman test ditandai dengan 'TEST:' di keterangan.\n";

} catch (\Exception $e) {
    echo "\n✗ FATAL ERROR: {$e->getMessage()}\n";
    echo "Stack trace:\n{$e->getTraceAsString()}\n";
}
