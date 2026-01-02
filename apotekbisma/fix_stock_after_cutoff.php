<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\RekamanStok;

echo "=======================================================\n";
echo "   FIX STOCK BASED ON TRANSACTIONS AFTER 1 JAN 2026\n";
echo "   Tanggal: " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

$cutoffDate = '2026-01-01 23:59:59';

echo "CUTOFF: {$cutoffDate}\n\n";

DB::beginTransaction();

try {
    $allProducts = Produk::orderBy('nama_produk')->get();
    $fixed = 0;
    $unchanged = 0;
    
    foreach ($allProducts as $produk) {
        $lastBefore = DB::table('rekaman_stoks')
            ->where('id_produk', $produk->id_produk)
            ->where('waktu', '<=', $cutoffDate)
            ->orderBy('waktu', 'desc')
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();
        
        $stokCutoff = $lastBefore ? intval($lastBefore->stok_sisa) : 0;
        
        $totalKeluar = DB::table('penjualan_detail')
            ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
            ->where('penjualan.waktu', '>', $cutoffDate)
            ->where('penjualan_detail.id_produk', $produk->id_produk)
            ->sum('penjualan_detail.jumlah');
        
        $totalMasuk = DB::table('pembelian_detail')
            ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
            ->where('pembelian.waktu', '>', $cutoffDate)
            ->where('pembelian_detail.id_produk', $produk->id_produk)
            ->sum('pembelian_detail.jumlah');
        
        $expectedStock = $stokCutoff + intval($totalMasuk) - intval($totalKeluar);
        if ($expectedStock < 0) $expectedStock = 0;
        
        $currentStock = intval($produk->stok);
        
        if ($currentStock != $expectedStock) {
            echo "FIXING: {$produk->nama_produk}\n";
            echo "  Stok cutoff: {$stokCutoff}\n";
            echo "  + Masuk setelah: {$totalMasuk}\n";
            echo "  - Keluar setelah: {$totalKeluar}\n";
            echo "  = Expected: {$expectedStock}\n";
            echo "  Current: {$currentStock}\n";
            echo "  Fixing...\n\n";
            
            DB::table('produk')
                ->where('id_produk', $produk->id_produk)
                ->update(['stok' => $expectedStock]);
            
            $fixed++;
        } else {
            $unchanged++;
        }
    }
    
    DB::commit();
    
    echo "=======================================================\n";
    echo "   HASIL\n";
    echo "=======================================================\n";
    echo "Fixed: {$fixed}\n";
    echo "Unchanged: {$unchanged}\n\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "[ERROR] " . $e->getMessage() . "\n";
}

echo "Recalculating rekaman stok untuk produk yang terpengaruh...\n";

$productsWithRecentTrans = DB::table('penjualan_detail')
    ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
    ->where('penjualan.waktu', '>', $cutoffDate)
    ->distinct()
    ->pluck('penjualan_detail.id_produk');

foreach ($productsWithRecentTrans as $produkId) {
    try {
        RekamanStok::recalculateStock($produkId);
    } catch (\Exception $e) {
    }
}

echo "Done.\n";
