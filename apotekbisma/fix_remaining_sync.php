<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\RekamanStok;

echo "=======================================================\n";
echo "   RECALCULATE & FINAL SYNC\n";
echo "   Tanggal: " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

DB::beginTransaction();

try {
    $allProducts = Produk::all();
    $count = 0;
    
    echo "Memproses " . $allProducts->count() . " produk...\n";
    
    foreach ($allProducts as $produk) {
        // Recalculate will fix the chain (stok_awal = prev_stok_sisa)
        // and update the Product table at the end.
        RekamanStok::recalculateStock($produk->id_produk);
        $count++;
        
        if ($count % 100 == 0) {
            echo "  Processed {$count}...\n";
        }
    }

    DB::commit();
    echo "\n[SUKSES] Semua produk ({$count}) telah dihitung ulang dan disinkronkan.\n";

} catch (\Exception $e) {
    DB::rollBack();
    echo "\n[ERROR] " . $e->getMessage() . "\n";
}
