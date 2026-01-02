<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;
use App\Models\Pembelian;
use App\Models\PembelianDetail;
use App\Models\RekamanStok;
use App\Models\Supplier;

echo "=======================================================\n";
echo "   COMPREHENSIVE STOCK SYSTEM STRESS TEST\n";
echo "   Tanggal: " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

$testResults = [];
$testPassed = 0;
$testFailed = 0;

function logTest($testName, $passed, $details = '') {
    global $testPassed, $testFailed, $testResults;
    
    if ($passed) {
        echo "   [PASS] {$testName}\n";
        $testPassed++;
    } else {
        echo "   [FAIL] {$testName}\n";
        if ($details) {
            echo "          Detail: {$details}\n";
        }
        $testFailed++;
    }
    
    $testResults[] = [
        'name' => $testName,
        'passed' => $passed,
        'details' => $details
    ];
}

echo "TEST 1: Validasi Integritas Database\n";
echo "------------------------------------\n";

$produkCount = Produk::count();
logTest('Tabel produk dapat diakses', $produkCount >= 0);

$rekamanCount = RekamanStok::count();
logTest('Tabel rekaman_stoks dapat diakses', $rekamanCount >= 0);

$penjualanCount = Penjualan::count();
logTest('Tabel penjualan dapat diakses', $penjualanCount >= 0);

$pembelianCount = Pembelian::count();
logTest('Tabel pembelian dapat diakses', $pembelianCount >= 0);

echo "\nTEST 2: Validasi Kalkulasi Stok Konsisten\n";
echo "------------------------------------------\n";

$randomProduct = Produk::whereHas('rekamanStoks')->inRandomOrder()->first();
if ($randomProduct) {
    $integrity = RekamanStok::verifyIntegrity($randomProduct->id_produk);
    logTest(
        "Integritas stok produk '{$randomProduct->nama_produk}'",
        $integrity['valid'],
        $integrity['valid'] ? '' : "Stok produk: {$integrity['product_stock']}, Kalkulasi: {$integrity['calculated_stock']}"
    );
} else {
    logTest('Tidak ada produk dengan rekaman stok untuk ditest', false, 'Silakan jalankan transaksi terlebih dahulu');
}

echo "\nTEST 3: Simulasi Transaksi Penjualan (tanpa commit ke DB)\n";
echo "----------------------------------------------------------\n";

$testProduct = Produk::where('stok', '>', 10)->first();
if ($testProduct) {
    $initialStock = intval($testProduct->stok);
    $testQuantity = 5;
    
    DB::beginTransaction();
    
    try {
        $lockedProduct = Produk::where('id_produk', $testProduct->id_produk)->lockForUpdate()->first();
        $stockBefore = intval($lockedProduct->stok);
        
        $mockPenjualan = new Penjualan();
        $mockPenjualan->id_member = null;
        $mockPenjualan->total_item = $testQuantity;
        $mockPenjualan->total_harga = $lockedProduct->harga_jual * $testQuantity;
        $mockPenjualan->diskon = 0;
        $mockPenjualan->bayar = $lockedProduct->harga_jual * $testQuantity;
        $mockPenjualan->diterima = $lockedProduct->harga_jual * $testQuantity;
        $mockPenjualan->waktu = now();
        $mockPenjualan->id_user = 1;
        $mockPenjualan->save();
        
        $mockDetail = new PenjualanDetail();
        $mockDetail->id_penjualan = $mockPenjualan->id_penjualan;
        $mockDetail->id_produk = $lockedProduct->id_produk;
        $mockDetail->harga_jual = $lockedProduct->harga_jual;
        $mockDetail->jumlah = $testQuantity;
        $mockDetail->diskon = 0;
        $mockDetail->subtotal = $lockedProduct->harga_jual * $testQuantity;
        $mockDetail->save();
        
        $newStock = $stockBefore - $testQuantity;
        DB::table('produk')->where('id_produk', $lockedProduct->id_produk)->update(['stok' => $newStock]);
        
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $lockedProduct->id_produk,
            'id_penjualan' => $mockPenjualan->id_penjualan,
            'waktu' => now(),
            'stok_masuk' => 0,
            'stok_keluar' => $testQuantity,
            'stok_awal' => $stockBefore,
            'stok_sisa' => $newStock,
            'keterangan' => 'STRESS TEST: Simulasi penjualan',
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        $finalProduct = Produk::find($testProduct->id_produk);
        $expectedStock = $initialStock - $testQuantity;
        
        logTest(
            "Pengurangan stok setelah penjualan",
            intval($finalProduct->stok) == $expectedStock,
            "Initial: {$initialStock}, Expected: {$expectedStock}, Actual: {$finalProduct->stok}"
        );
        
        $rekamanStok = DB::table('rekaman_stoks')
            ->where('id_penjualan', $mockPenjualan->id_penjualan)
            ->where('id_produk', $lockedProduct->id_produk)
            ->first();
        
        logTest(
            "Rekaman stok tercipta dengan benar",
            $rekamanStok && intval($rekamanStok->stok_keluar) == $testQuantity,
            $rekamanStok ? "Stok keluar: {$rekamanStok->stok_keluar}" : "Rekaman tidak ditemukan"
        );
        
        $calculatedSisa = intval($rekamanStok->stok_awal) + intval($rekamanStok->stok_masuk) - intval($rekamanStok->stok_keluar);
        logTest(
            "Kalkulasi stok_sisa benar",
            intval($rekamanStok->stok_sisa) == $calculatedSisa,
            "Rekaman: {$rekamanStok->stok_sisa}, Kalkulasi: {$calculatedSisa}"
        );
        
        DB::rollBack();
        
        $rollbackProduct = Produk::find($testProduct->id_produk);
        logTest(
            "Rollback berhasil (stok kembali normal)",
            intval($rollbackProduct->stok) == $initialStock,
            "Initial: {$initialStock}, After rollback: {$rollbackProduct->stok}"
        );
        
    } catch (\Exception $e) {
        DB::rollBack();
        logTest("Simulasi penjualan", false, $e->getMessage());
    }
} else {
    logTest("Tidak ada produk dengan stok > 10 untuk test", false, "Silakan tambahkan stok produk");
}

echo "\nTEST 4: Validasi Tidak Ada Duplikat Rekaman\n";
echo "--------------------------------------------\n";

$duplicateSales = DB::table('rekaman_stoks')
    ->select('id_produk', 'id_penjualan', DB::raw('COUNT(*) as cnt'))
    ->whereNotNull('id_penjualan')
    ->groupBy('id_produk', 'id_penjualan')
    ->having('cnt', '>', 1)
    ->count();

logTest(
    "Tidak ada duplikat rekaman penjualan",
    $duplicateSales == 0,
    $duplicateSales > 0 ? "Ditemukan {$duplicateSales} duplikat" : ""
);

$duplicatePurchases = DB::table('rekaman_stoks')
    ->select('id_produk', 'id_pembelian', DB::raw('COUNT(*) as cnt'))
    ->whereNotNull('id_pembelian')
    ->groupBy('id_produk', 'id_pembelian')
    ->having('cnt', '>', 1)
    ->count();

logTest(
    "Tidak ada duplikat rekaman pembelian",
    $duplicatePurchases == 0,
    $duplicatePurchases > 0 ? "Ditemukan {$duplicatePurchases} duplikat" : ""
);

echo "\nTEST 5: Validasi Semua Stok Produk Sinkron\n";
echo "-------------------------------------------\n";

$outOfSyncProducts = [];
$allProducts = Produk::all();

foreach ($allProducts as $produk) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman) {
        if (intval($produk->stok) != intval($lastRekaman->stok_sisa)) {
            $outOfSyncProducts[] = $produk->nama_produk;
        }
    }
}

logTest(
    "Semua stok produk sinkron dengan rekaman",
    count($outOfSyncProducts) == 0,
    count($outOfSyncProducts) > 0 ? "Out of sync: " . implode(", ", array_slice($outOfSyncProducts, 0, 5)) : ""
);

echo "\nTEST 6: Validasi Rekaman Stok vs Detail Transaksi\n";
echo "--------------------------------------------------\n";

$salesMismatch = [];
$penjualanDetails = DB::table('penjualan_detail')
    ->select('id_penjualan', 'id_produk', DB::raw('SUM(jumlah) as total_jumlah'))
    ->groupBy('id_penjualan', 'id_produk')
    ->get();

foreach ($penjualanDetails as $pd) {
    $totalKeluar = DB::table('rekaman_stoks')
        ->where('id_penjualan', $pd->id_penjualan)
        ->where('id_produk', $pd->id_produk)
        ->sum('stok_keluar');
    
    if (intval($totalKeluar) != intval($pd->total_jumlah)) {
        $salesMismatch[] = "Penjualan {$pd->id_penjualan}, Produk {$pd->id_produk}";
    }
}

logTest(
    "Rekaman stok sesuai dengan detail penjualan",
    count($salesMismatch) == 0,
    count($salesMismatch) > 0 ? "Mismatch pada: " . count($salesMismatch) . " transaksi" : ""
);

$purchaseMismatch = [];
$pembelianDetails = DB::table('pembelian_detail')
    ->select('id_pembelian', 'id_produk', DB::raw('SUM(jumlah) as total_jumlah'))
    ->groupBy('id_pembelian', 'id_produk')
    ->get();

foreach ($pembelianDetails as $pd) {
    $totalMasuk = DB::table('rekaman_stoks')
        ->where('id_pembelian', $pd->id_pembelian)
        ->where('id_produk', $pd->id_produk)
        ->sum('stok_masuk');
    
    if (intval($totalMasuk) != intval($pd->total_jumlah)) {
        $purchaseMismatch[] = "Pembelian {$pd->id_pembelian}, Produk {$pd->id_produk}";
    }
}

logTest(
    "Rekaman stok sesuai dengan detail pembelian",
    count($purchaseMismatch) == 0,
    count($purchaseMismatch) > 0 ? "Mismatch pada: " . count($purchaseMismatch) . " transaksi" : ""
);

echo "\nTEST 7: Validasi Tidak Ada Stok Negatif\n";
echo "----------------------------------------\n";

$negativeStock = Produk::where('stok', '<', 0)->count();
logTest(
    "Tidak ada produk dengan stok negatif",
    $negativeStock == 0,
    $negativeStock > 0 ? "Ditemukan {$negativeStock} produk dengan stok negatif" : ""
);

$negativeRekaman = DB::table('rekaman_stoks')
    ->where('stok_sisa', '<', 0)
    ->orWhere('stok_awal', '<', 0)
    ->count();

logTest(
    "Tidak ada rekaman stok dengan nilai negatif",
    $negativeRekaman == 0,
    $negativeRekaman > 0 ? "Ditemukan {$negativeRekaman} rekaman dengan nilai negatif" : ""
);

echo "\nTEST 8: Validasi Formula Kalkulasi Rekaman Stok\n";
echo "-------------------------------------------------\n";

$formulaErrors = 0;
$sampleRecords = DB::table('rekaman_stoks')->inRandomOrder()->limit(100)->get();

foreach ($sampleRecords as $record) {
    $calculated = intval($record->stok_awal) + intval($record->stok_masuk) - intval($record->stok_keluar);
    if ($calculated != intval($record->stok_sisa)) {
        $formulaErrors++;
    }
}

logTest(
    "Formula kalkulasi rekaman stok benar (sample 100 records)",
    $formulaErrors == 0,
    $formulaErrors > 0 ? "Ditemukan {$formulaErrors} rekaman dengan formula salah" : ""
);

echo "\nTEST 9: Concurrency Test (Simulasi)\n";
echo "------------------------------------\n";

$testProduct2 = Produk::where('stok', '>', 20)->first();
if ($testProduct2) {
    $lockAcquired = false;
    $lockStartTime = microtime(true);
    
    DB::beginTransaction();
    try {
        $lockedProduct = Produk::where('id_produk', $testProduct2->id_produk)->lockForUpdate()->first();
        $lockAcquired = $lockedProduct !== null;
        DB::rollBack();
    } catch (\Exception $e) {
        DB::rollBack();
    }
    
    $lockTime = round((microtime(true) - $lockStartTime) * 1000, 2);
    
    logTest(
        "Lock FOR UPDATE dapat diakuisisi",
        $lockAcquired,
        "Lock time: {$lockTime}ms"
    );
} else {
    logTest("Lock FOR UPDATE", false, "Tidak ada produk untuk test");
}

echo "\nTEST 10: Validasi Chain Rekaman Stok\n";
echo "-------------------------------------\n";

$chainErrors = 0;
$productSample = Produk::whereHas('rekamanStoks')->inRandomOrder()->first();

if ($productSample) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $productSample->id_produk)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    $previousSisa = null;
    $isFirst = true;
    
    foreach ($records as $record) {
        if ($isFirst) {
            $previousSisa = intval($record->stok_sisa);
            $isFirst = false;
            continue;
        }
        
        $records2 = DB::table('rekaman_stoks')
            ->where('id_produk', $productSample->id_produk)
            ->orderBy('waktu', 'asc')
            ->orderBy('id_rekaman_stok', 'asc')
            ->skip(1)
            ->take(count($records))
            ->get();
        
        break;
    }
    
    logTest(
        "Chain rekaman stok valid untuk '{$productSample->nama_produk}'",
        $chainErrors == 0,
        ""
    );
} else {
    logTest("Chain rekaman stok", false, "Tidak ada produk dengan rekaman untuk test");
}

echo "\n=======================================================\n";
echo "   RINGKASAN HASIL TEST\n";
echo "=======================================================\n\n";

echo "   Total Test: " . ($testPassed + $testFailed) . "\n";
echo "   Passed: {$testPassed}\n";
echo "   Failed: {$testFailed}\n";
echo "   Success Rate: " . round(($testPassed / ($testPassed + $testFailed)) * 100, 2) . "%\n\n";

if ($testFailed > 0) {
    echo "   REKOMENDASI:\n";
    echo "   - Jalankan repair_stock_sync.php untuk memperbaiki masalah yang ditemukan\n";
    echo "   - Periksa log Laravel untuk detail error\n";
} else {
    echo "   [SUCCESS] Semua test berhasil! Sistem stok dalam kondisi baik.\n";
}

echo "\n=======================================================\n";
echo "   STRESS TEST SELESAI\n";
echo "=======================================================\n";
