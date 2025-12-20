<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\RekamanStok;
use App\Models\Produk;
use App\Models\Penjualan;
use App\Models\Pembelian;
use Illuminate\Support\Facades\DB;

echo "=== SCRIPT PERBAIKAN REKAMAN STOK (COMPREHENSIVE) ===\n";
echo "Mulai perbaikan...\n\n";

DB::connection()->disableQueryLog();

echo "=== PHASE 1: FIX WAKTU FIELD ===\n";
echo "Memperbaiki field waktu berdasarkan transaksi asli...\n";

$fixedWaktu = 0;

$penjualanFix = DB::update("
    UPDATE rekaman_stoks rs
    INNER JOIN penjualan p ON rs.id_penjualan = p.id_penjualan
    SET rs.waktu = p.waktu
    WHERE rs.id_penjualan IS NOT NULL 
    AND DATE(rs.waktu) != DATE(p.waktu)
");
$fixedWaktu += $penjualanFix;
echo "Fixed {$penjualanFix} records from penjualan.\n";

$pembelianFix = DB::update("
    UPDATE rekaman_stoks rs
    INNER JOIN pembelian pb ON rs.id_pembelian = pb.id_pembelian
    SET rs.waktu = pb.waktu
    WHERE rs.id_pembelian IS NOT NULL 
    AND DATE(rs.waktu) != DATE(pb.waktu)
");
$fixedWaktu += $pembelianFix;
echo "Fixed {$pembelianFix} records from pembelian.\n";

$orphanFix = DB::update("
    UPDATE rekaman_stoks
    SET waktu = created_at
    WHERE id_penjualan IS NULL 
    AND id_pembelian IS NULL
    AND DATE(waktu) != DATE(created_at)
");
$fixedWaktu += $orphanFix;
echo "Fixed {$orphanFix} orphan records (using created_at).\n";

echo "Total waktu fixed: {$fixedWaktu}\n\n";

echo "=== PHASE 2: FIX STOK AWAL & SISA ===\n";

$products = Produk::all();
$count = 0;
$total = $products->count();

foreach ($products as $produk) {
    $count++;
    echo "Processing Product {$count}/{$total}: {$produk->nama_produk} (ID: {$produk->id_produk})... ";
    
    $stokRecords = RekamanStok::where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
        
    if ($stokRecords->isEmpty()) {
        echo "No records found.\n";
        continue;
    }

    $simulatedStock = $stokRecords->first()->stok_awal;
    $minStock = 0;
    
    if ($simulatedStock < 0) $minStock = $simulatedStock;

    foreach ($stokRecords as $rec) {
        $simulatedStock = $simulatedStock + $rec->stok_masuk - $rec->stok_keluar;
        if ($simulatedStock < $minStock) {
            $minStock = $simulatedStock;
        }
    }

    $adjustment = 0;
    if ($minStock < 0) {
        $adjustment = abs($minStock);
        echo " [AUTO-FIX: Dip {$minStock}, +{$adjustment}] ";
    }
    
    $runningStock = 0;
    $isFirst = true;
    $changesCount = 0;
    
    DB::beginTransaction();
    try {
        foreach ($stokRecords as $record) {
            $needsUpdate = false;
            
            if ($isFirst) {
                $targetStokAwal = $record->stok_awal + $adjustment;
                
                if ($record->stok_awal != $targetStokAwal) {
                    $record->stok_awal = $targetStokAwal;
                    $needsUpdate = true;
                }
                
                $runningStock = $record->stok_awal;
                $isFirst = false;
            } else {
                if ($record->stok_awal != $runningStock) {
                    $record->stok_awal = $runningStock;
                    $needsUpdate = true;
                }
            }
            
            $calculatedSisa = $runningStock + $record->stok_masuk - $record->stok_keluar;
            
            if ($record->stok_sisa != $calculatedSisa) {
                $record->stok_sisa = $calculatedSisa;
                $needsUpdate = true;
            }
            
            if ($needsUpdate) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $record->id_rekaman_stok)
                    ->update([
                        'stok_awal' => $record->stok_awal,
                        'stok_sisa' => $record->stok_sisa
                    ]);
                $changesCount++;
            }
            
            $runningStock = $calculatedSisa;
        }
        
        if ($produk->stok != $runningStock) {
            echo "Stock: {$produk->stok} -> {$runningStock}. ";
            $produk->stok = $runningStock;
            $produk->save();
        }
        
        DB::commit();
        echo "Done. Updated {$changesCount} records.\n";
        
    } catch (\Exception $e) {
        DB::rollBack();
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\n=== PERBAIKAN SELESAI ===\n";
