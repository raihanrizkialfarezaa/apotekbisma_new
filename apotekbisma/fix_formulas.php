<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Fixing all invalid formula records...\n";

$updated = DB::statement("
    UPDATE rekaman_stoks 
    SET stok_sisa = GREATEST(0, stok_awal + stok_masuk - stok_keluar) 
    WHERE stok_sisa != (stok_awal + stok_masuk - stok_keluar)
");

$remaining = DB::select("
    SELECT COUNT(*) as cnt 
    FROM rekaman_stoks 
    WHERE stok_sisa != (stok_awal + stok_masuk - stok_keluar)
")[0]->cnt;

echo "Remaining invalid formulas: {$remaining}\n";

echo "\nSyncing products with their last rekaman...\n";
$syncCount = 0;

$allProducts = DB::table('produk')->get();
foreach ($allProducts as $p) {
    $lastRek = DB::table('rekaman_stoks')
        ->where('id_produk', $p->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRek && intval($p->stok) !== intval($lastRek->stok_sisa)) {
        DB::table('produk')
            ->where('id_produk', $p->id_produk)
            ->update(['stok' => max(0, $lastRek->stok_sisa)]);
        $syncCount++;
    }
}

echo "Synced: {$syncCount} products\n";
echo "\nDone!\n";
