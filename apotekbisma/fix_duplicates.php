<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\RekamanStok;

echo "=======================================================\n";
echo "   FIX DUPLICATE REKAMAN STOK\n";
echo "   Tanggal: " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

DB::beginTransaction();

try {
    $duplicates = DB::table('rekaman_stoks')
        ->select('id_produk', 'id_penjualan', DB::raw('COUNT(*) as cnt'), DB::raw('MIN(id_rekaman_stok) as keep_id'))
        ->whereNotNull('id_penjualan')
        ->groupBy('id_produk', 'id_penjualan')
        ->having('cnt', '>', 1)
        ->get();
    
    echo "Ditemukan " . count($duplicates) . " duplikat penjualan\n\n";
    
    $deletedCount = 0;
    $affectedProducts = [];
    
    foreach ($duplicates as $dup) {
        $affectedProducts[] = $dup->id_produk;
        
        $totalJumlah = DB::table('penjualan_detail')
            ->where('id_penjualan', $dup->id_penjualan)
            ->where('id_produk', $dup->id_produk)
            ->sum('jumlah');
        
        $deleted = DB::table('rekaman_stoks')
            ->where('id_produk', $dup->id_produk)
            ->where('id_penjualan', $dup->id_penjualan)
            ->where('id_rekaman_stok', '!=', $dup->keep_id)
            ->delete();
        
        $deletedCount += $deleted;
        
        $kept = DB::table('rekaman_stoks')->where('id_rekaman_stok', $dup->keep_id)->first();
        if ($kept) {
            $newStokSisa = intval($kept->stok_awal) - intval($totalJumlah);
            if ($newStokSisa < 0) $newStokSisa = 0;
            
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $dup->keep_id)
                ->update([
                    'stok_keluar' => $totalJumlah,
                    'stok_sisa' => $newStokSisa
                ]);
        }
        
        $produk = Produk::find($dup->id_produk);
        echo "Fixed: " . ($produk ? $produk->nama_produk : "ID {$dup->id_produk}") . " (penjualan {$dup->id_penjualan})\n";
    }
    
    $duplicatesPembelian = DB::table('rekaman_stoks')
        ->select('id_produk', 'id_pembelian', DB::raw('COUNT(*) as cnt'), DB::raw('MIN(id_rekaman_stok) as keep_id'))
        ->whereNotNull('id_pembelian')
        ->groupBy('id_produk', 'id_pembelian')
        ->having('cnt', '>', 1)
        ->get();
    
    echo "\nDitemukan " . count($duplicatesPembelian) . " duplikat pembelian\n\n";
    
    foreach ($duplicatesPembelian as $dup) {
        $affectedProducts[] = $dup->id_produk;
        
        $totalJumlah = DB::table('pembelian_detail')
            ->where('id_pembelian', $dup->id_pembelian)
            ->where('id_produk', $dup->id_produk)
            ->sum('jumlah');
        
        $deleted = DB::table('rekaman_stoks')
            ->where('id_produk', $dup->id_produk)
            ->where('id_pembelian', $dup->id_pembelian)
            ->where('id_rekaman_stok', '!=', $dup->keep_id)
            ->delete();
        
        $deletedCount += $deleted;
        
        $kept = DB::table('rekaman_stoks')->where('id_rekaman_stok', $dup->keep_id)->first();
        if ($kept) {
            $newStokSisa = intval($kept->stok_awal) + intval($totalJumlah);
            
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $dup->keep_id)
                ->update([
                    'stok_masuk' => $totalJumlah,
                    'stok_sisa' => $newStokSisa
                ]);
        }
        
        $produk = Produk::find($dup->id_produk);
        echo "Fixed: " . ($produk ? $produk->nama_produk : "ID {$dup->id_produk}") . " (pembelian {$dup->id_pembelian})\n";
    }
    
    DB::commit();
    
    echo "\n=======================================================\n";
    echo "   Deleted {$deletedCount} duplicate records\n";
    echo "=======================================================\n\n";
    
    echo "Recalculating affected products...\n";
    $uniqueProducts = array_unique($affectedProducts);
    foreach ($uniqueProducts as $produkId) {
        try {
            RekamanStok::recalculateStock($produkId);
        } catch (\Exception $e) {
        }
    }
    echo "Done.\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "[ERROR] " . $e->getMessage() . "\n";
}
