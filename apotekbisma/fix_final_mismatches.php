<?php
/**
 * Find and fix the 13 remaining mismatch issues
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "============================================================\n";
echo "  FIND AND FIX REMAINING MISMATCH ISSUES\n";
echo "============================================================\n\n";

$dryRun = !isset($argv[1]) || $argv[1] !== '--execute';
echo "Mode: " . ($dryRun ? "DRY RUN" : "EXECUTE") . "\n\n";

$products = DB::table('produk')->get();
$mismatches = [];

foreach ($products as $product) {
    // Query sama persis dengan verification di master_stock_fix.php
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->first();
    
    if ($lastRekaman && intval($product->stok) != intval($lastRekaman->stok_sisa)) {
        $mismatches[] = [
            'id' => $product->id_produk,
            'nama' => $product->nama_produk,
            'produk_stok' => intval($product->stok),
            'rekaman_sisa' => intval($lastRekaman->stok_sisa),
            'rekaman_id' => $lastRekaman->id_rekaman_stok,
            'rekaman_waktu' => $lastRekaman->waktu
        ];
    }
}

echo "Found " . count($mismatches) . " mismatches:\n\n";

foreach ($mismatches as $m) {
    echo "[{$m['id']}] {$m['nama']}\n";
    echo "  produk.stok = {$m['produk_stok']}\n";
    echo "  rekaman.stok_sisa = {$m['rekaman_sisa']} (ID={$m['rekaman_id']}, waktu={$m['rekaman_waktu']})\n";
    
    // Check if there's another record with same waktu but different id
    $sameWaktuRecords = DB::table('rekaman_stoks')
        ->where('id_produk', $m['id'])
        ->where('waktu', $m['rekaman_waktu'])
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($sameWaktuRecords->count() > 1) {
        echo "  NOTE: Multiple records at same timestamp:\n";
        foreach ($sameWaktuRecords as $r) {
            echo "    ID={$r->id_rekaman_stok}, sisa={$r->stok_sisa}\n";
        }
    }
    
    echo "\n";
}

// Fix by getting the TRULY last record (by waktu DESC, then id_rekaman_stok DESC)
echo "============================================================\n";
echo "  APPLYING FIXES\n";
echo "============================================================\n\n";

$fixCount = 0;

foreach ($mismatches as $m) {
    // Get the truly last record
    $trueLastRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $m['id'])
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $correctStock = max(0, intval($trueLastRecord->stok_sisa));
    
    echo "[FIX] Product {$m['id']}: setting stok to {$correctStock}\n";
    
    if (!$dryRun) {
        DB::table('produk')
            ->where('id_produk', $m['id'])
            ->update(['stok' => $correctStock]);
    }
    
    $fixCount++;
}

echo "\nFixed: {$fixCount} products\n\n";

// Verify
echo "============================================================\n";
echo "  VERIFICATION\n";
echo "============================================================\n\n";

$products = DB::table('produk')->get();
$remainingMismatches = 0;

foreach ($products as $product) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'desc')
        ->first();
    
    if ($lastRekaman && intval($product->stok) != intval($lastRekaman->stok_sisa)) {
        $remainingMismatches++;
        echo "[STILL MISMATCH] {$product->id_produk}: stok={$product->stok}, rekaman={$lastRekaman->stok_sisa}\n";
    }
}

echo "\nRemaining mismatches: {$remainingMismatches}\n";

if ($remainingMismatches == 0) {
    echo "\nSUCCESS! All issues resolved.\n";
} else {
    if ($dryRun) {
        echo "\nRun with --execute to apply fixes.\n";
    }
}
