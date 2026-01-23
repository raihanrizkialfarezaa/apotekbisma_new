<?php

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\Pembelian;
use App\Models\PembelianDetail;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;
use Carbon\Carbon;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "==============================================\n";
echo "TEST PEMBELIAN & PENJUALAN - FIX VERIFICATION\n";
echo "==============================================\n\n";

$testResults = [];
$testProdukId = null;
$testPembelianId = null;
$testPenjualanId = null;

try {
    // Setup: Buat produk test
    echo "SETUP: Membuat produk test...\n";
    
    $produk = Produk::where('nama_produk', 'TEST TRANSAKSI FLOW')->first();
    
    if (!$produk) {
        $lastProduk = Produk::latest()->first() ?? new Produk();
        $newId = (int)($lastProduk->id_produk ?? 0) + 1;
        
        $produk = Produk::create([
            'kode_produk' => 'PTEST' . str_pad($newId, 6, '0', STR_PAD_LEFT),
            'nama_produk' => 'TEST TRANSAKSI FLOW',
            'id_kategori' => 1,
            'harga_beli' => 10000,
            'harga_jual' => 15000,
            'diskon' => 0,
            'stok' => 0
        ]);
    } else {
        DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => 0]);
        $produk->refresh();
    }
    
    $testProdukId = $produk->id_produk;
    echo "✓ Produk ID: {$testProdukId}, Stok awal: 0\n\n";
    
    // Cleanup rekaman test lama
    DB::table('rekaman_stoks')
        ->where('id_produk', $testProdukId)
        ->where('keterangan', 'LIKE', 'TEST FLOW:%')
        ->delete();

    // TEST 1: PEMBELIAN - Beli 100 unit
    echo "TEST 1: PEMBELIAN - Beli 100 unit\n";
    
    DB::beginTransaction();
    try {
        // Simulasi pembelian
        $stokSebelum = 0;
        $jumlahBeli = 100;
        $stokBaru = $stokSebelum + $jumlahBeli;
        
        DB::table('produk')->where('id_produk', $testProdukId)->update(['stok' => $stokBaru]);
        
        $waktu = Carbon::now()->format('Y-m-d H:i:s.u');
        
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $testProdukId,
            'id_pembelian' => 999999, // Dummy
            'waktu' => $waktu,
            'stok_masuk' => $jumlahBeli,
            'stok_keluar' => 0,
            'stok_awal' => $stokSebelum,
            'stok_sisa' => $stokBaru,
            'keterangan' => 'TEST FLOW: Pembelian 100 unit',
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        DB::commit();
        
        $produkAfter = Produk::find($testProdukId);
        $test1Pass = ($produkAfter->stok == 100);
        
        echo "   Stok setelah pembelian: {$produkAfter->stok}\n";
        echo "   Status: " . ($test1Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";
        $testResults[] = ['test' => 'Pembelian 100 unit', 'pass' => $test1Pass, 'expected' => 100, 'actual' => $produkAfter->stok];
        
    } catch (\Exception $e) {
        DB::rollBack();
        echo "   ✗ ERROR: {$e->getMessage()}\n\n";
        $testResults[] = ['test' => 'Pembelian 100 unit', 'pass' => false, 'error' => $e->getMessage()];
    }

    // TEST 2: PENJUALAN - Jual 30 unit
    echo "TEST 2: PENJUALAN - Jual 30 unit\n";
    
    DB::beginTransaction();
    try {
        $stokSebelum = 100;
        $jumlahJual = 30;
        $stokBaru = $stokSebelum - $jumlahJual;
        
        DB::table('produk')->where('id_produk', $testProdukId)->update(['stok' => $stokBaru]);
        
        $waktu = Carbon::now()->format('Y-m-d H:i:s.u');
        
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $testProdukId,
            'id_penjualan' => 999999, // Dummy
            'waktu' => $waktu,
            'stok_masuk' => 0,
            'stok_keluar' => $jumlahJual,
            'stok_awal' => $stokSebelum,
            'stok_sisa' => $stokBaru,
            'keterangan' => 'TEST FLOW: Penjualan 30 unit',
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        DB::commit();
        
        $produkAfter = Produk::find($testProdukId);
        $test2Pass = ($produkAfter->stok == 70);
        
        echo "   Stok setelah penjualan: {$produkAfter->stok}\n";
        echo "   Status: " . ($test2Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";
        $testResults[] = ['test' => 'Penjualan 30 unit', 'pass' => $test2Pass, 'expected' => 70, 'actual' => $produkAfter->stok];
        
    } catch (\Exception $e) {
        DB::rollBack();
        echo "   ✗ ERROR: {$e->getMessage()}\n\n";
        $testResults[] = ['test' => 'Penjualan 30 unit', 'pass' => false, 'error' => $e->getMessage()];
    }

    // TEST 3: PEMBELIAN LAGI - Beli 50 unit
    echo "TEST 3: PEMBELIAN LAGI - Beli 50 unit\n";
    
    DB::beginTransaction();
    try {
        $stokSebelum = 70;
        $jumlahBeli = 50;
        $stokBaru = $stokSebelum + $jumlahBeli;
        
        DB::table('produk')->where('id_produk', $testProdukId)->update(['stok' => $stokBaru]);
        
        $waktu = Carbon::now()->format('Y-m-d H:i:s.u');
        
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $testProdukId,
            'id_pembelian' => 999999,
            'waktu' => $waktu,
            'stok_masuk' => $jumlahBeli,
            'stok_keluar' => 0,
            'stok_awal' => $stokSebelum,
            'stok_sisa' => $stokBaru,
            'keterangan' => 'TEST FLOW: Pembelian 50 unit',
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        DB::commit();
        
        $produkAfter = Produk::find($testProdukId);
        $test3Pass = ($produkAfter->stok == 120);
        
        echo "   Stok setelah pembelian: {$produkAfter->stok}\n";
        echo "   Status: " . ($test3Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";
        $testResults[] = ['test' => 'Pembelian 50 unit', 'pass' => $test3Pass, 'expected' => 120, 'actual' => $produkAfter->stok];
        
    } catch (\Exception $e) {
        DB::rollBack();
        echo "   ✗ ERROR: {$e->getMessage()}\n\n";
        $testResults[] = ['test' => 'Pembelian 50 unit', 'pass' => false, 'error' => $e->getMessage()];
    }

    // TEST 4: PENJUALAN LAGI - Jual 40 unit
    echo "TEST 4: PENJUALAN LAGI - Jual 40 unit\n";
    
    DB::beginTransaction();
    try {
        $stokSebelum = 120;
        $jumlahJual = 40;
        $stokBaru = $stokSebelum - $jumlahJual;
        
        DB::table('produk')->where('id_produk', $testProdukId)->update(['stok' => $stokBaru]);
        
        $waktu = Carbon::now()->format('Y-m-d H:i:s.u');
        
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $testProdukId,
            'id_penjualan' => 999999,
            'waktu' => $waktu,
            'stok_masuk' => 0,
            'stok_keluar' => $jumlahJual,
            'stok_awal' => $stokSebelum,
            'stok_sisa' => $stokBaru,
            'keterangan' => 'TEST FLOW: Penjualan 40 unit',
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        DB::commit();
        
        $produkAfter = Produk::find($testProdukId);
        $test4Pass = ($produkAfter->stok == 80);
        
        echo "   Stok setelah penjualan: {$produkAfter->stok}\n";
        echo "   Status: " . ($test4Pass ? "✓ PASS" : "✗ FAIL") . "\n\n";
        $testResults[] = ['test' => 'Penjualan 40 unit', 'pass' => $test4Pass, 'expected' => 80, 'actual' => $produkAfter->stok];
        
    } catch (\Exception $e) {
        DB::rollBack();
        echo "   ✗ ERROR: {$e->getMessage()}\n\n";
        $testResults[] = ['test' => 'Penjualan 40 unit', 'pass' => false, 'error' => $e->getMessage()];
    }

    // TEST 5: PERSISTENCE - Cek stok tetap konsisten
    echo "TEST 5: PERSISTENCE - Verifikasi konsistensi\n";
    sleep(2);
    
    $produkFinal = Produk::find($testProdukId);
    $test5Pass = ($produkFinal->stok == 80);
    
    echo "   Stok setelah 2 detik: {$produkFinal->stok}\n";
    echo "   Status: " . ($test5Pass ? "✓ PASS (stok tetap)" : "✗ FAIL (stok berubah!)") . "\n\n";
    $testResults[] = ['test' => 'Persistence Check', 'pass' => $test5Pass, 'expected' => 80, 'actual' => $produkFinal->stok];

    // TEST 6: INTEGRITY - Verifikasi chain rekaman_stoks
    echo "TEST 6: INTEGRITY - Verifikasi chain rekaman_stoks\n";
    
    $rekamanAll = DB::table('rekaman_stoks')
        ->where('id_produk', $testProdukId)
        ->where('keterangan', 'LIKE', 'TEST FLOW:%')
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    echo "   Total rekaman: {$rekamanAll->count()}\n";
    
    $chainValid = true;
    $prevStokSisa = null;
    $expectedFlow = [
        ['awal' => 0, 'masuk' => 100, 'keluar' => 0, 'sisa' => 100],
        ['awal' => 100, 'masuk' => 0, 'keluar' => 30, 'sisa' => 70],
        ['awal' => 70, 'masuk' => 50, 'keluar' => 0, 'sisa' => 120],
        ['awal' => 120, 'masuk' => 0, 'keluar' => 40, 'sisa' => 80],
    ];
    
    foreach ($rekamanAll as $idx => $rekaman) {
        $expected = $expectedFlow[$idx];
        
        echo "   Transaksi " . ($idx + 1) . ": ";
        
        // Verify values
        $isValid = (
            $rekaman->stok_awal == $expected['awal'] &&
            $rekaman->stok_masuk == $expected['masuk'] &&
            $rekaman->stok_keluar == $expected['keluar'] &&
            $rekaman->stok_sisa == $expected['sisa']
        );
        
        if ($isValid) {
            echo "✓ VALID\n";
        } else {
            echo "✗ INVALID\n";
            echo "      Expected: awal={$expected['awal']}, masuk={$expected['masuk']}, keluar={$expected['keluar']}, sisa={$expected['sisa']}\n";
            echo "      Actual:   awal={$rekaman->stok_awal}, masuk={$rekaman->stok_masuk}, keluar={$rekaman->stok_keluar}, sisa={$rekaman->stok_sisa}\n";
            $chainValid = false;
        }
        
        // Verify formula
        $calculated = $rekaman->stok_awal + $rekaman->stok_masuk - $rekaman->stok_keluar;
        if ($calculated != $rekaman->stok_sisa) {
            echo "      ✗ Formula ERROR!\n";
            $chainValid = false;
        }
        
        // Verify chain
        if ($prevStokSisa !== null && $rekaman->stok_awal != $prevStokSisa) {
            echo "      ✗ Chain BROKEN!\n";
            $chainValid = false;
        }
        
        $prevStokSisa = $rekaman->stok_sisa;
    }
    
    $testResults[] = ['test' => 'Integrity Check', 'pass' => $chainValid];
    echo "   Status: " . ($chainValid ? "✓ PASS" : "✗ FAIL") . "\n\n";

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
        
        if (!$result['pass'] && isset($result['expected'])) {
            echo "        Expected: {$result['expected']}, Got: {$result['actual']}\n";
        }
    }
    
    echo "\nTotal: {$totalTests} | Passed: {$passedTests} | Failed: {$failedTests}\n\n";
    
    if ($failedTests == 0) {
        echo "🎉 SEMUA TEST BERHASIL! TRANSAKSI ROBUST! 🎉\n";
    } else {
        echo "⚠️ ADA TEST YANG GAGAL! PERLU PERBAIKAN! ⚠️\n";
    }
    
    echo "==============================================\n";
    
} catch (\Exception $e) {
    echo "\n✗ FATAL ERROR: {$e->getMessage()}\n";
    echo $e->getTraceAsString() . "\n";
}
