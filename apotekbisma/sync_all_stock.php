<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== SYNC produk.stok WITH rekaman_stoks ===\n\n";

set_time_limit(600);
ini_set('memory_limit', '512M');

$products = DB::table('produk')->get();
$synced = 0;
$alreadyOk = 0;

foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('created_at', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if (!$lastRekaman) {
        continue;
    }
    
    $rekamanStock = intval($lastRekaman->stok_sisa);
    $produkStock = intval($product->stok);
    
    if ($rekamanStock != $produkStock) {
        DB::table('produk')
            ->where('id_produk', $product->id_produk)
            ->update(['stok' => $rekamanStock]);
        
        $synced++;
    } else {
        $alreadyOk++;
    }
}

echo "Synced: {$synced}\n";
echo "Already OK: {$alreadyOk}\n\n";

echo "=== FIX REMAINING CONTINUITY ERRORS ===\n\n";

$productIds = DB::table('rekaman_stoks')->distinct()->pluck('id_produk');
$fixedProducts = 0;

foreach ($productIds as $pid) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($records->isEmpty()) continue;
    
    $runningStock = intval($records->first()->stok_awal);
    $isFirst = true;
    $needsUpdate = false;
    
    foreach ($records as $r) {
        $expectedAwal = $isFirst ? intval($r->stok_awal) : $runningStock;
        $expectedSisa = $expectedAwal + intval($r->stok_masuk) - intval($r->stok_keluar);
        
        if (intval($r->stok_awal) != $expectedAwal || intval($r->stok_sisa) != $expectedSisa) {
            $needsUpdate = true;
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $r->id_rekaman_stok)
                ->update([
                    'stok_awal' => $expectedAwal,
                    'stok_sisa' => $expectedSisa
                ]);
        }
        
        $runningStock = $expectedSisa;
        $isFirst = false;
    }
    
    if ($needsUpdate) {
        DB::table('produk')
            ->where('id_produk', $pid)
            ->update(['stok' => max(0, $runningStock)]);
        $fixedProducts++;
    }
}

echo "Fixed products: {$fixedProducts}\n\n";

echo "=== VERIFICATION ===\n\n";

$continuityErrors = 0;
foreach ($productIds as $pid) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    $prevSisa = null;
    foreach ($records as $r) {
        if ($prevSisa !== null && intval($r->stok_awal) != intval($prevSisa)) {
            $continuityErrors++;
            $product = DB::table('produk')->where('id_produk', $pid)->first();
            echo "Still error: [{$pid}] " . ($product ? $product->nama_produk : '') . "\n";
            echo "  Expected awal: {$prevSisa}, Actual: {$r->stok_awal}\n";
            break;
        }
        $prevSisa = $r->stok_sisa;
    }
}

$mismatchCount = 0;
$products = DB::table('produk')->get();
foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->first();
    
    if ($lastRekaman && intval($product->stok) != intval($lastRekaman->stok_sisa)) {
        $mismatchCount++;
    }
}

echo "\nContinuity errors remaining: {$continuityErrors}\n";
echo "produk.stok mismatches remaining: {$mismatchCount}\n";

if ($continuityErrors == 0 && $mismatchCount == 0) {
    echo "\nSUCCESS: All stock data is now consistent!\n";
}
