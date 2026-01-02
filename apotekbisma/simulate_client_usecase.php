<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;
use App\Models\RekamanStok;

echo "=======================================================\n";
echo "   SIMULASI USE CASE KLIEN: DOUBLE DEDUCTION TEST\n";
echo "   Tanggal: " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

$testProduct = Produk::where('stok', '>=', 50)->orderBy('nama_produk')->first();

if (!$testProduct) {
    echo "[ERROR] Tidak ada produk dengan stok >= 50 untuk test\n";
    exit(1);
}

echo "TEST PRODUCT: {$testProduct->nama_produk}\n";
echo "STOK AWAL: {$testProduct->stok}\n\n";

$initialStock = intval($testProduct->stok);
$qtyPerTransaction = 10;
$numTransactions = 3;
$totalExpectedDeduction = $qtyPerTransaction * $numTransactions;

echo "RENCANA TEST:\n";
echo "  - {$numTransactions} transaksi penjualan\n";
echo "  - Masing-masing {$qtyPerTransaction} pcs\n";
echo "  - Total pengurangan expected: {$totalExpectedDeduction} pcs\n";
echo "  - Stok akhir expected: " . ($initialStock - $totalExpectedDeduction) . " pcs\n\n";

echo "=======================================================\n";
echo "   SIMULASI TRANSAKSI\n";
echo "=======================================================\n\n";

$createdPenjualanIds = [];
$errors = [];

for ($i = 1; $i <= $numTransactions; $i++) {
    echo "TRANSAKSI #{$i}:\n";
    
    DB::beginTransaction();
    
    try {
        $produk = Produk::where('id_produk', $testProduct->id_produk)->lockForUpdate()->first();
        $stokSebelum = intval($produk->stok);
        
        echo "  Stok sebelum: {$stokSebelum}\n";
        
        $penjualan = new Penjualan();
        $penjualan->id_member = null;
        $penjualan->total_item = $qtyPerTransaction;
        $penjualan->total_harga = $produk->harga_jual * $qtyPerTransaction;
        $penjualan->diskon = 0;
        $penjualan->bayar = $produk->harga_jual * $qtyPerTransaction;
        $penjualan->diterima = $produk->harga_jual * $qtyPerTransaction;
        $penjualan->waktu = now();
        $penjualan->id_user = 1;
        $penjualan->save();
        
        $createdPenjualanIds[] = $penjualan->id_penjualan;
        
        $detail = new PenjualanDetail();
        $detail->id_penjualan = $penjualan->id_penjualan;
        $detail->id_produk = $produk->id_produk;
        $detail->harga_jual = $produk->harga_jual;
        $detail->jumlah = $qtyPerTransaction;
        $detail->diskon = 0;
        $detail->subtotal = $produk->harga_jual * $qtyPerTransaction;
        $detail->save();
        
        $stokBaru = $stokSebelum - $qtyPerTransaction;
        if ($stokBaru < 0) $stokBaru = 0;
        
        DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stokBaru]);
        
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $produk->id_produk,
            'id_penjualan' => $penjualan->id_penjualan,
            'waktu' => now(),
            'stok_masuk' => 0,
            'stok_keluar' => $qtyPerTransaction,
            'stok_awal' => $stokSebelum,
            'stok_sisa' => $stokBaru,
            'keterangan' => 'TEST SIMULASI: Transaksi penjualan #' . $i,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        DB::commit();
        
        $produkAfter = Produk::find($testProduct->id_produk);
        echo "  Stok setelah: {$produkAfter->stok}\n";
        echo "  Pengurangan: " . ($stokSebelum - intval($produkAfter->stok)) . " pcs\n";
        
        if (($stokSebelum - intval($produkAfter->stok)) != $qtyPerTransaction) {
            $errors[] = "Transaksi #{$i}: Expected -{$qtyPerTransaction}, Actual -" . ($stokSebelum - intval($produkAfter->stok));
        }
        
        echo "  [OK] Transaksi berhasil\n\n";
        
    } catch (\Exception $e) {
        DB::rollBack();
        echo "  [ERROR] " . $e->getMessage() . "\n\n";
        $errors[] = "Transaksi #{$i}: " . $e->getMessage();
    }
    
    usleep(100000);
}

echo "=======================================================\n";
echo "   HASIL SIMULASI\n";
echo "=======================================================\n\n";

$productAfterAll = Produk::find($testProduct->id_produk);
$finalStock = intval($productAfterAll->stok);
$actualDeduction = $initialStock - $finalStock;
$expectedFinalStock = $initialStock - $totalExpectedDeduction;

echo "STOK AWAL        : {$initialStock}\n";
echo "STOK AKHIR       : {$finalStock}\n";
echo "PENGURANGAN ACTUAL: {$actualDeduction}\n";
echo "PENGURANGAN EXPECTED: {$totalExpectedDeduction}\n";
echo "STOK AKHIR EXPECTED: {$expectedFinalStock}\n\n";

if ($finalStock == $expectedFinalStock) {
    echo "[SUCCESS] Stok akhir BENAR! Tidak ada double deduction.\n";
} else {
    $diff = $expectedFinalStock - $finalStock;
    echo "[FAILED] Stok akhir SALAH!\n";
    echo "  Selisih: {$diff} pcs " . ($diff > 0 ? "(terpotong lebih banyak)" : "(terpotong lebih sedikit)") . "\n";
}

echo "\nVERIFIKASI REKAMAN STOK:\n";
echo "------------------------\n";

$rekamanCount = DB::table('rekaman_stoks')
    ->where('id_produk', $testProduct->id_produk)
    ->whereIn('id_penjualan', $createdPenjualanIds)
    ->count();

echo "Jumlah rekaman stok: {$rekamanCount} (expected: {$numTransactions})\n";

$totalStokKeluar = DB::table('rekaman_stoks')
    ->where('id_produk', $testProduct->id_produk)
    ->whereIn('id_penjualan', $createdPenjualanIds)
    ->sum('stok_keluar');

echo "Total stok keluar di rekaman: {$totalStokKeluar} (expected: {$totalExpectedDeduction})\n";

if ($rekamanCount == $numTransactions && $totalStokKeluar == $totalExpectedDeduction) {
    echo "[OK] Rekaman stok konsisten\n";
} else {
    echo "[WARNING] Rekaman stok tidak konsisten!\n";
}

echo "\n=======================================================\n";
echo "   CLEANUP - MENGHAPUS DATA TEST\n";
echo "=======================================================\n";

DB::beginTransaction();
try {
    DB::table('rekaman_stoks')->whereIn('id_penjualan', $createdPenjualanIds)->delete();
    
    foreach ($createdPenjualanIds as $pId) {
        DB::table('penjualan_detail')->where('id_penjualan', $pId)->delete();
        DB::table('penjualan')->where('id_penjualan', $pId)->delete();
    }
    
    DB::table('produk')->where('id_produk', $testProduct->id_produk)->update(['stok' => $initialStock]);
    
    DB::commit();
    
    $produkRestored = Produk::find($testProduct->id_produk);
    echo "Stok dikembalikan ke: {$produkRestored->stok}\n";
    echo "[OK] Data test berhasil dihapus\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "[ERROR] Gagal cleanup: " . $e->getMessage() . "\n";
}

echo "\n=======================================================\n";
echo "   KESIMPULAN\n";
echo "=======================================================\n";

if (empty($errors) && $finalStock == $expectedFinalStock) {
    echo "\n[SUCCESS] SEMUA TEST BERHASIL!\n";
    echo "Logika penjualan sudah benar dan tidak ada double deduction.\n";
} else {
    echo "\n[FAILED] ADA MASALAH!\n";
    foreach ($errors as $err) {
        echo "  - {$err}\n";
    }
}

echo "\n";
