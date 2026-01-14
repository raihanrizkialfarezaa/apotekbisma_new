<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== FIX KARTU STOK PRODUK 48 ===\n\n";

DB::beginTransaction();

try {
    $produkId = 48;
    
    echo "Step 1: Hapus duplikat rekaman penjualan...\n";
    
    $dups = DB::table('rekaman_stoks')
        ->select('id_penjualan', DB::raw('MIN(id_rekaman_stok) as keep_id'), DB::raw('COUNT(*) as cnt'))
        ->where('id_produk', $produkId)
        ->whereNotNull('id_penjualan')
        ->groupBy('id_penjualan')
        ->having('cnt', '>', 1)
        ->get();
    
    $deletedCount = 0;
    foreach($dups as $d) {
        $deleted = DB::table('rekaman_stoks')
            ->where('id_produk', $produkId)
            ->where('id_penjualan', $d->id_penjualan)
            ->where('id_rekaman_stok', '!=', $d->keep_id)
            ->delete();
        $deletedCount += $deleted;
    }
    
    echo "   Dihapus: {$deletedCount} record duplikat\n\n";
    
    echo "Step 2: Recalculate stok_awal dan stok_sisa secara kronologis...\n";
    
    $allRecs = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    echo "   Total record setelah hapus duplikat: " . count($allRecs) . "\n";
    
    $runningStock = 0;
    $updatedCount = 0;
    
    foreach($allRecs as $index => $r) {
        $needsUpdate = false;
        $updateData = [];
        
        if ($index == 0) {
            if ($r->stok_awal != 0) {
                $updateData['stok_awal'] = 0;
                $needsUpdate = true;
            }
            $runningStock = 0;
        } else {
            if ($r->stok_awal != $runningStock) {
                $updateData['stok_awal'] = $runningStock;
                $needsUpdate = true;
            }
        }
        
        $expectedSisa = $runningStock + $r->stok_masuk - $r->stok_keluar;
        
        if ($r->stok_sisa != $expectedSisa) {
            $updateData['stok_sisa'] = $expectedSisa;
            $needsUpdate = true;
        }
        
        if ($needsUpdate) {
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $r->id_rekaman_stok)
                ->update($updateData);
            $updatedCount++;
        }
        
        $runningStock = $expectedSisa;
    }
    
    echo "   Record diupdate: {$updatedCount}\n";
    echo "   Stok akhir dari kalkulasi: {$runningStock}\n\n";
    
    echo "Step 3: Update produk.stok...\n";
    
    $oldStock = DB::table('produk')->where('id_produk', $produkId)->value('stok');
    
    DB::table('produk')
        ->where('id_produk', $produkId)
        ->update(['stok' => $runningStock]);
    
    echo "   Stok lama: {$oldStock}\n";
    echo "   Stok baru: {$runningStock}\n\n";
    
    DB::commit();
    echo "=== SELESAI - FIX BERHASIL ===\n";
    
    echo "\nVERIFIKASI:\n";
    $produk = DB::table('produk')->where('id_produk', $produkId)->first();
    $lastRec = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    echo "produk.stok: {$produk->stok}\n";
    echo "stok_sisa terakhir: " . ($lastRec ? $lastRec->stok_sisa : 'N/A') . "\n";
    echo "Match: " . ($produk->stok == $lastRec->stok_sisa ? "YES" : "NO") . "\n";
    
} catch (Exception $e) {
    DB::rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Rollback dilakukan.\n";
}
