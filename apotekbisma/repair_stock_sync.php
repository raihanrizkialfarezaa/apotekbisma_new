<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\RekamanStok;

echo "=======================================================\n";
echo "   ROBUST STOCK SYNCHRONIZATION & REPAIR TOOL\n";
echo "   Tanggal: " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

$startTime = microtime(true);
$repairCount = 0;
$duplicateDeleted = 0;
$errors = [];

DB::beginTransaction();

try {
    echo "LANGKAH 1: Menghapus Rekaman Stok Duplikat\n";
    echo "------------------------------------------\n";
    
    $duplicates = DB::table('rekaman_stoks')
        ->select(
            'id_produk',
            'id_penjualan',
            DB::raw('COUNT(*) as cnt'),
            DB::raw('MIN(id_rekaman_stok) as keep_id')
        )
        ->whereNotNull('id_penjualan')
        ->groupBy('id_produk', 'id_penjualan')
        ->having('cnt', '>', 1)
        ->get();
    
    foreach ($duplicates as $dup) {
        $totalStokKeluar = DB::table('penjualan_detail')
            ->where('id_penjualan', $dup->id_penjualan)
            ->where('id_produk', $dup->id_produk)
            ->sum('jumlah');
        
        $deleted = DB::table('rekaman_stoks')
            ->where('id_produk', $dup->id_produk)
            ->where('id_penjualan', $dup->id_penjualan)
            ->where('id_rekaman_stok', '!=', $dup->keep_id)
            ->delete();
        
        $duplicateDeleted += $deleted;
        
        $existingRekaman = DB::table('rekaman_stoks')
            ->where('id_rekaman_stok', $dup->keep_id)
            ->first();
        
        if ($existingRekaman && intval($existingRekaman->stok_keluar) != $totalStokKeluar) {
            $newStokSisa = intval($existingRekaman->stok_awal) - $totalStokKeluar;
            if ($newStokSisa < 0) $newStokSisa = 0;
            
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $dup->keep_id)
                ->update([
                    'stok_keluar' => $totalStokKeluar,
                    'stok_sisa' => $newStokSisa
                ]);
        }
    }
    
    echo "   Duplikat dihapus: {$duplicateDeleted}\n\n";

    $duplicatesPembelian = DB::table('rekaman_stoks')
        ->select(
            'id_produk',
            'id_pembelian',
            DB::raw('COUNT(*) as cnt'),
            DB::raw('MIN(id_rekaman_stok) as keep_id')
        )
        ->whereNotNull('id_pembelian')
        ->groupBy('id_produk', 'id_pembelian')
        ->having('cnt', '>', 1)
        ->get();
    
    foreach ($duplicatesPembelian as $dup) {
        $totalStokMasuk = DB::table('pembelian_detail')
            ->where('id_pembelian', $dup->id_pembelian)
            ->where('id_produk', $dup->id_produk)
            ->sum('jumlah');
        
        $deleted = DB::table('rekaman_stoks')
            ->where('id_produk', $dup->id_produk)
            ->where('id_pembelian', $dup->id_pembelian)
            ->where('id_rekaman_stok', '!=', $dup->keep_id)
            ->delete();
        
        $duplicateDeleted += $deleted;
        
        $existingRekaman = DB::table('rekaman_stoks')
            ->where('id_rekaman_stok', $dup->keep_id)
            ->first();
        
        if ($existingRekaman && intval($existingRekaman->stok_masuk) != $totalStokMasuk) {
            $newStokSisa = intval($existingRekaman->stok_awal) + $totalStokMasuk;
            
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $dup->keep_id)
                ->update([
                    'stok_masuk' => $totalStokMasuk,
                    'stok_sisa' => $newStokSisa
                ]);
        }
    }
    
    echo "   Total duplikat dihapus (termasuk pembelian): {$duplicateDeleted}\n\n";

    echo "LANGKAH 2: Memperbaiki Rekaman Stok per Produk\n";
    echo "-----------------------------------------------\n";
    
    $allProducts = Produk::orderBy('nama_produk')->get();
    
    foreach ($allProducts as $produk) {
        $records = DB::table('rekaman_stoks')
            ->where('id_produk', $produk->id_produk)
            ->orderBy('waktu', 'asc')
            ->orderBy('created_at', 'asc')
            ->orderBy('id_rekaman_stok', 'asc')
            ->get();
        
        if ($records->isEmpty()) {
            continue;
        }
        
        $runningStock = 0;
        $isFirst = true;
        $needsRecalc = false;
        
        foreach ($records as $record) {
            if ($isFirst) {
                $runningStock = intval($record->stok_awal);
                $isFirst = false;
            }
            
            $calculatedSisa = $runningStock + intval($record->stok_masuk) - intval($record->stok_keluar);
            
            if (intval($record->stok_awal) != $runningStock || intval($record->stok_sisa) != $calculatedSisa) {
                $needsRecalc = true;
                
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $record->id_rekaman_stok)
                    ->update([
                        'stok_awal' => $runningStock,
                        'stok_sisa' => $calculatedSisa
                    ]);
            }
            
            $runningStock = $calculatedSisa;
        }
        
        if ($runningStock < 0) {
            $runningStock = 0;
        }
        
        if (intval($produk->stok) != $runningStock) {
            DB::table('produk')
                ->where('id_produk', $produk->id_produk)
                ->update(['stok' => $runningStock]);
            
            $repairCount++;
            echo "   [DIPERBAIKI] {$produk->nama_produk}: {$produk->stok} -> {$runningStock}\n";
        }
    }
    
    echo "\n   Total produk diperbaiki: {$repairCount}\n\n";

    echo "LANGKAH 3: Verifikasi Integritas Rekaman Stok vs Transaksi\n";
    echo "----------------------------------------------------------\n";
    
    $penjualanMismatches = 0;
    $pembelianMismatches = 0;
    
    $penjualanDetails = DB::table('penjualan_detail')
        ->select('id_penjualan', 'id_produk', DB::raw('SUM(jumlah) as total_jumlah'))
        ->groupBy('id_penjualan', 'id_produk')
        ->get();
    
    foreach ($penjualanDetails as $pd) {
        $totalKeluar = DB::table('rekaman_stoks')
            ->where('id_penjualan', $pd->id_penjualan)
            ->where('id_produk', $pd->id_produk)
            ->sum('stok_keluar');
        
        if (intval($totalKeluar) != intval($pd->total_jumlah)) {
            $penjualanMismatches++;
            
            $existingRekaman = DB::table('rekaman_stoks')
                ->where('id_penjualan', $pd->id_penjualan)
                ->where('id_produk', $pd->id_produk)
                ->first();
            
            if ($existingRekaman) {
                $newStokSisa = intval($existingRekaman->stok_awal) - intval($pd->total_jumlah);
                if ($newStokSisa < 0) $newStokSisa = 0;
                
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $existingRekaman->id_rekaman_stok)
                    ->update([
                        'stok_keluar' => intval($pd->total_jumlah),
                        'stok_sisa' => $newStokSisa
                    ]);
            }
        }
    }
    
    echo "   Mismatch penjualan diperbaiki: {$penjualanMismatches}\n";

    $pembelianDetails = DB::table('pembelian_detail')
        ->select('id_pembelian', 'id_produk', DB::raw('SUM(jumlah) as total_jumlah'))
        ->groupBy('id_pembelian', 'id_produk')
        ->get();
    
    foreach ($pembelianDetails as $pd) {
        $totalMasuk = DB::table('rekaman_stoks')
            ->where('id_pembelian', $pd->id_pembelian)
            ->where('id_produk', $pd->id_produk)
            ->sum('stok_masuk');
        
        if (intval($totalMasuk) != intval($pd->total_jumlah)) {
            $pembelianMismatches++;
            
            $existingRekaman = DB::table('rekaman_stoks')
                ->where('id_pembelian', $pd->id_pembelian)
                ->where('id_produk', $pd->id_produk)
                ->first();
            
            if ($existingRekaman) {
                $newStokSisa = intval($existingRekaman->stok_awal) + intval($pd->total_jumlah);
                
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $existingRekaman->id_rekaman_stok)
                    ->update([
                        'stok_masuk' => intval($pd->total_jumlah),
                        'stok_sisa' => $newStokSisa
                    ]);
            }
        }
    }
    
    echo "   Mismatch pembelian diperbaiki: {$pembelianMismatches}\n\n";

    echo "LANGKAH 4: Recalculate Semua Stok Produk\n";
    echo "-----------------------------------------\n";
    
    $recalcCount = 0;
    $recalcErrors = [];
    
    foreach ($allProducts as $produk) {
        try {
            RekamanStok::recalculateStock($produk->id_produk);
            $recalcCount++;
        } catch (\Exception $e) {
            $recalcErrors[] = "Produk {$produk->nama_produk}: " . $e->getMessage();
        }
    }
    
    echo "   Produk di-recalculate: {$recalcCount}\n";
    if (!empty($recalcErrors)) {
        echo "   Errors:\n";
        foreach ($recalcErrors as $err) {
            echo "     - {$err}\n";
        }
    }
    echo "\n";

    DB::commit();
    
    $endTime = microtime(true);
    $executionTime = round($endTime - $startTime, 2);
    
    echo "=======================================================\n";
    echo "   SINKRONISASI SELESAI\n";
    echo "=======================================================\n\n";
    echo "   Waktu eksekusi: {$executionTime} detik\n";
    echo "   Duplikat dihapus: {$duplicateDeleted}\n";
    echo "   Produk diperbaiki: {$repairCount}\n";
    echo "   Mismatch penjualan diperbaiki: {$penjualanMismatches}\n";
    echo "   Mismatch pembelian diperbaiki: {$pembelianMismatches}\n";
    echo "   Produk di-recalculate: {$recalcCount}\n\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "\n[ERROR FATAL] " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\nLANGKAH 5: Verifikasi Akhir\n";
echo "---------------------------\n";

$finalCheck = [];
$allProducts = Produk::orderBy('nama_produk')->get();

foreach ($allProducts as $produk) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman) {
        $stokRekaman = intval($lastRekaman->stok_sisa);
        $stokProduk = intval($produk->stok);
        
        if ($stokRekaman != $stokProduk) {
            $finalCheck[] = [
                'produk' => $produk->nama_produk,
                'stok_produk' => $stokProduk,
                'stok_rekaman' => $stokRekaman,
                'difference' => $stokRekaman - $stokProduk
            ];
        }
    }
}

if (empty($finalCheck)) {
    echo "   [OK] Semua stok produk sinkron dengan rekaman stok!\n";
} else {
    echo "   [WARNING] Masih ada " . count($finalCheck) . " produk yang tidak sinkron:\n";
    foreach ($finalCheck as $issue) {
        echo "     - {$issue['produk']}: Produk={$issue['stok_produk']}, Rekaman={$issue['stok_rekaman']}, Diff={$issue['difference']}\n";
    }
}

echo "\n=======================================================\n";
echo "   SELESAI\n";
echo "=======================================================\n";
