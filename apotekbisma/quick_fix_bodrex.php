<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "\n";
echo "=============================================================\n";
echo "  QUICK FIX: SYNC STOK BODREX\n";
echo "=============================================================\n\n";

DB::beginTransaction();

try {
    $produk = DB::table('produk')->where('id_produk', 108)->first();
    $rekaman = DB::table('rekaman_stoks')
        ->where('id_produk', 108)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    echo "Stok sekarang   : {$produk->stok}\n";
    echo "Stok rekaman    : {$rekaman->stok_sisa}\n";
    echo "Waktu rekaman   : {$rekaman->waktu}\n";
    echo "Keterangan      : {$rekaman->keterangan}\n\n";
    
    if ($produk->stok == $rekaman->stok_sisa) {
        echo "✅ Stok sudah sinkron!\n";
        DB::rollBack();
        exit(0);
    }
    
    echo "Updating produk.stok {$produk->stok} → {$rekaman->stok_sisa}...\n";
    
    DB::table('produk')
        ->where('id_produk', 108)
        ->update(['stok' => $rekaman->stok_sisa]);
    
    DB::commit();
    
    echo "✅ SUCCESS! Stok BODREX sekarang: {$rekaman->stok_sisa}\n\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "❌ ERROR: {$e->getMessage()}\n";
    exit(1);
}

echo "=============================================================\n\n";
