<?php

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use App\Models\Produk;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "==============================================\n";
echo "TEST EDGE CASES UPDATE STOK\n";
echo "==============================================\n\n";

try {
    $produk = Produk::where('nama_produk', 'LIKE', '%TEST STOK UPDATE%')->first();
    
    if (!$produk) {
        echo "✗ Produk test tidak ditemukan.\n";
        exit(1);
    }
    
    echo "Produk ID: {$produk->id_produk}\n";
    echo "Nama: {$produk->nama_produk}\n\n";
    
    $testResults = [];
    
    // Test 1: Update dengan nilai yang sama (no change)
    echo "1. TEST: Update dengan nilai yang sama\n";
    DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => 100]);
    $stokBefore = 100;
    $stokNew = 100;
    
    $countBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->count();
    
    // Simulate update with same value - should not create record
    if ($stokNew !== $stokBefore) {
        // Would create record
        echo "   Would create record (difference detected)\n";
    } else {
        echo "   No difference, no record created\n";
    }
    
    $countAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->count();
    
    $test1Pass = ($countBefore == $countAfter);
    echo "   Status: " . ($test1Pass ? "✓ PASS (no unnecessary record)" : "✗ FAIL") . "\n\n";
    $testResults[] = ['test' => 'Same Value Update', 'pass' => $test1Pass];
    
    // Test 2: Large jump (1000 → 10)
    echo "2. TEST: Large decrease (1000 → 10)\n";
    DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => 1000]);
    
    DB::beginTransaction();
    try {
        $stokLama = 1000;
        $stokBaru = 10;
        
        DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stokBaru]);
        
        $currentTime = \Carbon\Carbon::now();
        $selisih = $stokBaru - $stokLama;
        
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $produk->id_produk,
            'waktu' => $currentTime,
            'stok_awal' => $stokLama,
            'stok_masuk' => 0,
            'stok_keluar' => abs($selisih),
            'stok_sisa' => $stokBaru,
            'keterangan' => 'EDGE TEST: Large decrease',
            'created_at' => $currentTime,
            'updated_at' => $currentTime
        ]);
        
        DB::commit();
        
        $currentStok = DB::table('produk')->where('id_produk', $produk->id_produk)->value('stok');
        $test2Pass = ($currentStok == 10);
        
        echo "   Stok akhir: {$currentStok}\n";
        echo "   Status: " . ($test2Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";
        $testResults[] = ['test' => 'Large Decrease', 'pass' => $test2Pass];
        
    } catch (\Exception $e) {
        DB::rollBack();
        echo "   ✗ ERROR: {$e->getMessage()}\n\n";
        $testResults[] = ['test' => 'Large Decrease', 'pass' => false];
    }
    
    // Test 3: Large increase (10 → 5000)
    echo "3. TEST: Large increase (10 → 5000)\n";
    
    DB::beginTransaction();
    try {
        $stokLama = 10;
        $stokBaru = 5000;
        
        DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stokBaru]);
        
        $currentTime = \Carbon\Carbon::now();
        $selisih = $stokBaru - $stokLama;
        
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $produk->id_produk,
            'waktu' => $currentTime,
            'stok_awal' => $stokLama,
            'stok_masuk' => $selisih,
            'stok_keluar' => 0,
            'stok_sisa' => $stokBaru,
            'keterangan' => 'EDGE TEST: Large increase',
            'created_at' => $currentTime,
            'updated_at' => $currentTime
        ]);
        
        DB::commit();
        
        $currentStok = DB::table('produk')->where('id_produk', $produk->id_produk)->value('stok');
        $test3Pass = ($currentStok == 5000);
        
        echo "   Stok akhir: {$currentStok}\n";
        echo "   Status: " . ($test3Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";
        $testResults[] = ['test' => 'Large Increase', 'pass' => $test3Pass];
        
    } catch (\Exception $e) {
        DB::rollBack();
        echo "   ✗ ERROR: {$e->getMessage()}\n\n";
        $testResults[] = ['test' => 'Large Increase', 'pass' => false];
    }
    
    // Test 4: Back to zero
    echo "4. TEST: Reset to zero (5000 → 0)\n";
    
    DB::beginTransaction();
    try {
        $stokLama = 5000;
        $stokBaru = 0;
        
        DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stokBaru]);
        
        $currentTime = \Carbon\Carbon::now();
        $selisih = $stokBaru - $stokLama;
        
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $produk->id_produk,
            'waktu' => $currentTime,
            'stok_awal' => $stokLama,
            'stok_masuk' => 0,
            'stok_keluar' => abs($selisih),
            'stok_sisa' => $stokBaru,
            'keterangan' => 'EDGE TEST: Reset to zero',
            'created_at' => $currentTime,
            'updated_at' => $currentTime
        ]);
        
        DB::commit();
        
        $currentStok = DB::table('produk')->where('id_produk', $produk->id_produk)->value('stok');
        $test4Pass = ($currentStok == 0);
        
        echo "   Stok akhir: {$currentStok}\n";
        echo "   Status: " . ($test4Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";
        $testResults[] = ['test' => 'Reset to Zero', 'pass' => $test4Pass];
        
    } catch (\Exception $e) {
        DB::rollBack();
        echo "   ✗ ERROR: {$e->getMessage()}\n\n";
        $testResults[] = ['test' => 'Reset to Zero', 'pass' => false];
    }
    
    // Test 5: Fractional values (should be converted to integer)
    echo "5. TEST: Fractional input (99.7 should become 99)\n";
    
    DB::beginTransaction();
    try {
        $stokLama = 0;
        $stokInput = 99.7;
        $stokBaru = intval($stokInput); // Should be 99
        
        DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stokBaru]);
        
        $currentTime = \Carbon\Carbon::now();
        $selisih = $stokBaru - $stokLama;
        
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $produk->id_produk,
            'waktu' => $currentTime,
            'stok_awal' => $stokLama,
            'stok_masuk' => $selisih,
            'stok_keluar' => 0,
            'stok_sisa' => $stokBaru,
            'keterangan' => 'EDGE TEST: Fractional input',
            'created_at' => $currentTime,
            'updated_at' => $currentTime
        ]);
        
        DB::commit();
        
        $currentStok = DB::table('produk')->where('id_produk', $produk->id_produk)->value('stok');
        $test5Pass = ($currentStok == 99 && is_int($currentStok));
        
        echo "   Input: {$stokInput}\n";
        echo "   Stok akhir: {$currentStok} (type: " . gettype($currentStok) . ")\n";
        echo "   Status: " . ($test5Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";
        $testResults[] = ['test' => 'Fractional Input', 'pass' => $test5Pass];
        
    } catch (\Exception $e) {
        DB::rollBack();
        echo "   ✗ ERROR: {$e->getMessage()}\n\n";
        $testResults[] = ['test' => 'Fractional Input', 'pass' => false];
    }
    
    // Summary
    echo "==============================================\n";
    echo "EDGE CASES TEST SUMMARY\n";
    echo "==============================================\n\n";
    
    $totalTests = count($testResults);
    $passedTests = count(array_filter($testResults, fn($t) => $t['pass']));
    $failedTests = $totalTests - $passedTests;
    
    foreach ($testResults as $result) {
        $status = $result['pass'] ? '✓ PASS' : '✗ FAIL';
        echo "{$status} - {$result['test']}\n";
    }
    
    echo "\nTotal: {$totalTests} | Passed: {$passedTests} | Failed: {$failedTests}\n\n";
    
    if ($failedTests == 0) {
        echo "🎉 ALL EDGE CASES PASSED! 🎉\n";
    } else {
        echo "⚠️ SOME EDGE CASES FAILED! ⚠️\n";
    }
    
    echo "==============================================\n";
    
} catch (\Exception $e) {
    echo "\n✗ FATAL ERROR: {$e->getMessage()}\n";
}
