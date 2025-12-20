<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== ULTIMATE STOCK FIX ===\n\n";

DB::connection()->disableQueryLog();

// ========================
// STEP 1: FIX ALL WAKTU - AGGRESSIVE
// ========================
echo "STEP 1: Forcing ALL waktu to match source...\n";

// Direct raw SQL update for penjualan - no conditions
DB::unprepared("
    UPDATE rekaman_stoks rs
    INNER JOIN penjualan p ON rs.id_penjualan = p.id_penjualan
    SET rs.waktu = p.waktu
");
echo "- Penjualan waktu synced\n";

// Direct raw SQL update for pembelian - no conditions  
DB::unprepared("
    UPDATE rekaman_stoks rs
    INNER JOIN pembelian b ON rs.id_pembelian = b.id_pembelian
    SET rs.waktu = b.waktu
");
echo "- Pembelian waktu synced\n";

// Verify immediately
$mismatch = DB::select("
    SELECT 
        (SELECT COUNT(*) FROM rekaman_stoks rs 
         INNER JOIN penjualan p ON rs.id_penjualan = p.id_penjualan 
         WHERE rs.waktu != p.waktu) as p_mismatch,
        (SELECT COUNT(*) FROM rekaman_stoks rs 
         INNER JOIN pembelian b ON rs.id_pembelian = b.id_pembelian 
         WHERE rs.waktu != b.waktu) as b_mismatch
")[0];
echo "- Penjualan timestamp mismatch: {$mismatch->p_mismatch}\n";
echo "- Pembelian timestamp mismatch: {$mismatch->b_mismatch}\n";

// ========================
// STEP 2: RECALCULATE ALL STOCK BALANCES
// ========================
echo "\nSTEP 2: Recalculating all stock balances...\n";

$products = DB::table('produk')->select('id_produk', 'nama_produk', 'stok')->get();
$total = $products->count();
$processed = 0;
$fixed = 0;

foreach ($products as $produk) {
    $processed++;
    
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get()
        ->toArray();
        
    if (empty($records)) continue;
    
    // Calculate minimum stock point to find adjustment
    $firstAwal = $records[0]->stok_awal;
    $simStock = $firstAwal;
    $minStock = $simStock;
    
    foreach ($records as $r) {
        $simStock = $simStock + $r->stok_masuk - $r->stok_keluar;
        if ($simStock < $minStock) $minStock = $simStock;
    }
    
    $adj = ($minStock < 0) ? abs($minStock) : 0;
    
    // Apply fixes
    $running = $firstAwal + $adj;
    $isFirst = true;
    $hasChanges = false;
    
    foreach ($records as $rec) {
        $newAwal = $isFirst ? ($firstAwal + $adj) : $running;
        $newSisa = $newAwal + $rec->stok_masuk - $rec->stok_keluar;
        
        if ($rec->stok_awal != $newAwal || $rec->stok_sisa != $newSisa) {
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $rec->id_rekaman_stok)
                ->update([
                    'stok_awal' => $newAwal,
                    'stok_sisa' => $newSisa
                ]);
            $hasChanges = true;
        }
        
        $running = $newSisa;
        $isFirst = false;
    }
    
    // Update product stock to match last record
    if ($produk->stok != $running) {
        DB::table('produk')
            ->where('id_produk', $produk->id_produk)
            ->update(['stok' => $running]);
    }
    
    if ($hasChanges || $adj > 0) $fixed++;
    
    if ($processed % 200 == 0) {
        echo "- {$processed}/{$total}...\n";
    }
}

echo "- Completed. Fixed {$fixed} products.\n";

// ========================
// FINAL VERIFICATION
// ========================
echo "\n=== FINAL VERIFICATION ===\n";

// 1. Waktu check with exact match
$exactMismatch = DB::select("
    SELECT 
        (SELECT COUNT(*) FROM rekaman_stoks rs 
         INNER JOIN penjualan p ON rs.id_penjualan = p.id_penjualan 
         WHERE rs.waktu != p.waktu) +
        (SELECT COUNT(*) FROM rekaman_stoks rs 
         INNER JOIN pembelian b ON rs.id_pembelian = b.id_pembelian 
         WHERE rs.waktu != b.waktu) as total
")[0]->total;
echo "1. Waktu exact mismatch: {$exactMismatch}\n";

// 2. Negative stock
$neg = DB::table('rekaman_stoks')->where('stok_sisa', '<', 0)->count();
echo "2. Negative stok_sisa: {$neg}\n";

// 3. Sample validation
echo "3. Running balance samples:\n";
$samples = DB::table('produk')->inRandomOrder()->limit(10)->get();
$allValid = true;

foreach ($samples as $sp) {
    $recs = DB::table('rekaman_stoks')
        ->where('id_produk', $sp->id_produk)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    if ($recs->isEmpty()) continue;
    
    $valid = true;
    $run = $recs->first()->stok_awal;
    
    foreach ($recs as $r) {
        if ($r->stok_awal != $run) { $valid = false; break; }
        $calc = $run + $r->stok_masuk - $r->stok_keluar;
        if ($r->stok_sisa != $calc) { $valid = false; break; }
        $run = $calc;
    }
    
    $status = $valid ? "✓" : "✗";
    echo "   {$status} {$sp->nama_produk}\n";
    if (!$valid) $allValid = false;
}

echo "\n";
if ($exactMismatch == 0 && $neg == 0 && $allValid) {
    echo "✓✓✓ ALL CHECKS PASSED! ✓✓✓\n";
} else {
    echo "⚠ Some issues may remain.\n";
}
