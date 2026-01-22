<?php
/**
 * SYNC DUPLICATE PRODUCT STOCK
 * Menyinkronkan stok produk yang sama tapi beda ID di database
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=============================================================\n";
echo "SYNC DUPLICATE PRODUCT STOCK\n";
echo "=============================================================\n\n";

// Mapping: [ID tanpa CSV => ID di CSV]
// Produk yang sama tapi beda ID
$syncMap = [
    8 => 283,    // ACTIFED ALL VAR => ACTIFED 25ML ALL
    364 => 1000, // HYPAFIX 1m => HYPAFIX 5M
];

DB::beginTransaction();

try {
    foreach ($syncMap as $targetId => $sourceId) {
        $source = DB::table('produk')->where('id_produk', $sourceId)->first();
        $target = DB::table('produk')->where('id_produk', $targetId)->first();
        
        if (!$source || !$target) {
            echo "SKIP: Source ID $sourceId or Target ID $targetId not found\n";
            continue;
        }
        
        echo "Syncing: {$target->nama_produk} (ID $targetId)\n";
        echo "  From: {$source->nama_produk} (ID $sourceId)\n";
        echo "  Old stock: {$target->stok}\n";
        echo "  New stock: {$source->stok}\n";
        
        // Update stock
        DB::table('produk')
            ->where('id_produk', $targetId)
            ->update([
                'stok' => $source->stok,
                'updated_at' => now()
            ]);
        
        echo "  ✓ Updated!\n\n";
    }
    
    DB::commit();
    
    echo "=============================================================\n";
    echo "VERIFICATION\n";
    echo "=============================================================\n\n";
    
    foreach ($syncMap as $targetId => $sourceId) {
        $source = DB::table('produk')->where('id_produk', $sourceId)->first();
        $target = DB::table('produk')->where('id_produk', $targetId)->first();
        
        $match = $source->stok === $target->stok ? '✓' : '✗';
        echo "{$target->nama_produk} (ID $targetId): {$target->stok} $match\n";
        echo "{$source->nama_produk} (ID $sourceId): {$source->stok}\n\n";
    }
    
    echo "✓ All duplicate products synced successfully!\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}
