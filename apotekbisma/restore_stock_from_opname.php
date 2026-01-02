<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\RekamanStok;

echo "=======================================================\n";
echo "   RESTORE STOCK TO STOCK OPNAME VALUES\n";
echo "   (Mengembalikan stok ke nilai stock opname kemarin)\n";
echo "   Tanggal: " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

echo "Script ini akan:\n";
echo "1. Mencari rekaman Stock Opname terakhir untuk setiap produk\n";
echo "2. Menghitung HANYA transaksi SETELAH stock opname tersebut\n";
echo "3. Memastikan stok produk = stok_opname +/- transaksi_setelahnya\n";
echo "\n";

$restored = 0;
$unchanged = 0;
$errors = [];

DB::beginTransaction();

try {
    $allProducts = Produk::orderBy('nama_produk')->get();
    
    foreach ($allProducts as $produk) {
        $lastOpname = DB::table('rekaman_stoks')
            ->where('id_produk', $produk->id_produk)
            ->where(function($q) {
                $q->where('keterangan', 'LIKE', '%Stock Opname%')
                  ->orWhere('keterangan', 'LIKE', '%Perubahan Stok Manual%')
                  ->orWhere('keterangan', 'LIKE', '%Penyesuaian%');
            })
            ->whereNull('id_penjualan')
            ->whereNull('id_pembelian')
            ->orderBy('waktu', 'desc')
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();
        
        if (!$lastOpname) {
            continue;
        }
        
        $stokOpname = intval($lastOpname->stok_sisa);
        $waktuOpname = $lastOpname->waktu;
        
        $totalMasukSetelahOpname = DB::table('rekaman_stoks')
            ->where('id_produk', $produk->id_produk)
            ->where('waktu', '>', $waktuOpname)
            ->whereNotNull('id_pembelian')
            ->sum('stok_masuk');
        
        $totalKeluarSetelahOpname = DB::table('rekaman_stoks')
            ->where('id_produk', $produk->id_produk)
            ->where('waktu', '>', $waktuOpname)
            ->whereNotNull('id_penjualan')
            ->sum('stok_keluar');
        
        $stokSeharusnya = $stokOpname + $totalMasukSetelahOpname - $totalKeluarSetelahOpname;
        if ($stokSeharusnya < 0) $stokSeharusnya = 0;
        
        $stokSekarang = intval($produk->stok);
        
        if ($stokSekarang != $stokSeharusnya) {
            echo "RESTORING: {$produk->nama_produk}\n";
            echo "  Stok Opname: {$stokOpname}\n";
            echo "  + Masuk setelah opname: {$totalMasukSetelahOpname}\n";
            echo "  - Keluar setelah opname: {$totalKeluarSetelahOpname}\n";
            echo "  = Seharusnya: {$stokSeharusnya}\n";
            echo "  Stok sekarang: {$stokSekarang}\n";
            echo "  SELISIH: " . ($stokSekarang - $stokSeharusnya) . " (akan diperbaiki)\n\n";
            
            DB::table('produk')
                ->where('id_produk', $produk->id_produk)
                ->update(['stok' => $stokSeharusnya]);
            
            $restored++;
        } else {
            $unchanged++;
        }
    }
    
    DB::commit();
    
    echo "=======================================================\n";
    echo "   HASIL RESTORE\n";
    echo "=======================================================\n";
    echo "   Produk diperbaiki: {$restored}\n";
    echo "   Produk sudah benar: {$unchanged}\n\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "[ERROR] " . $e->getMessage() . "\n";
}
