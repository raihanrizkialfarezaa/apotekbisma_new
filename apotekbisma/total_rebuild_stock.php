<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\RekamanStok;

echo "=======================================================\n";
echo "   TOTAL STOCK REBUILD - APOTEK BISMA\n";
echo "   Tanggal: " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

set_time_limit(1800);
ini_set('memory_limit', '1G');

$startTime = microtime(true);

DB::beginTransaction();

try {
    echo "LANGKAH 1: Mengumpulkan semua transaksi...\n";
    echo "-------------------------------------------\n";
    
    $allTransactions = [];
    
    $penjualanDetails = DB::table('penjualan_detail')
        ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
        ->select(
            'penjualan_detail.id_penjualan_detail',
            'penjualan_detail.id_penjualan',
            'penjualan_detail.id_produk',
            'penjualan.waktu',
            'penjualan_detail.jumlah as qty',
            'penjualan.created_at'
        )
        ->orderBy('penjualan.waktu', 'asc')
        ->orderBy('penjualan.id_penjualan', 'asc')
        ->get();
    
    foreach ($penjualanDetails as $p) {
        $key = $p->id_produk;
        if (!isset($allTransactions[$key])) {
            $allTransactions[$key] = [];
        }
        $waktu = $p->waktu ?? $p->created_at ?? now();
        $allTransactions[$key][] = [
            'waktu' => $waktu,
            'id_penjualan' => $p->id_penjualan,
            'id_pembelian' => null,
            'stok_masuk' => 0,
            'stok_keluar' => $p->qty,
            'keterangan' => 'Penjualan - ID: ' . $p->id_penjualan,
            'sort_order' => 1
        ];
    }
    
    echo "   Transaksi penjualan: " . count($penjualanDetails) . "\n";
    
    $pembelianDetails = DB::table('pembelian_detail')
        ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
        ->select(
            'pembelian_detail.id_pembelian_detail',
            'pembelian_detail.id_pembelian',
            'pembelian_detail.id_produk',
            'pembelian.waktu',
            'pembelian_detail.jumlah as qty',
            'pembelian.no_faktur',
            'pembelian.created_at'
        )
        ->orderBy('pembelian.waktu', 'asc')
        ->orderBy('pembelian.id_pembelian', 'asc')
        ->get();
    
    foreach ($pembelianDetails as $b) {
        $key = $b->id_produk;
        if (!isset($allTransactions[$key])) {
            $allTransactions[$key] = [];
        }
        $waktu = $b->waktu ?? $b->created_at ?? now();
        $allTransactions[$key][] = [
            'waktu' => $waktu,
            'id_penjualan' => null,
            'id_pembelian' => $b->id_pembelian,
            'stok_masuk' => $b->qty,
            'stok_keluar' => 0,
            'keterangan' => 'Pembelian - Faktur: ' . ($b->no_faktur ?: $b->id_pembelian),
            'sort_order' => 0
        ];
    }
    
    echo "   Transaksi pembelian: " . count($pembelianDetails) . "\n";
    
    $stockOpnames = DB::table('rekaman_stoks')
        ->whereNull('id_penjualan')
        ->whereNull('id_pembelian')
        ->where('keterangan', 'LIKE', '%Stock Opname%')
        ->orWhere('keterangan', 'LIKE', '%Perubahan Stok Manual%')
        ->orWhere('keterangan', 'LIKE', '%Penyesuaian%')
        ->get();
    
    foreach ($stockOpnames as $so) {
        $key = $so->id_produk;
        if (!isset($allTransactions[$key])) {
            $allTransactions[$key] = [];
        }
        $allTransactions[$key][] = [
            'waktu' => $so->waktu,
            'id_penjualan' => null,
            'id_pembelian' => null,
            'stok_masuk' => $so->stok_masuk,
            'stok_keluar' => $so->stok_keluar,
            'keterangan' => $so->keterangan ?? 'Penyesuaian Stok',
            'sort_order' => 0,
            'is_adjustment' => true,
            'original_stok_awal' => $so->stok_awal
        ];
    }
    
    echo "   Stock opname/adjustments: " . count($stockOpnames) . "\n\n";

    echo "LANGKAH 2: Menghapus semua rekaman stok lama...\n";
    echo "-----------------------------------------------\n";
    
    DB::table('rekaman_stoks')->delete();
    echo "   Rekaman stok dihapus.\n\n";

    echo "LANGKAH 3: Rebuild kartu stok per produk...\n";
    echo "--------------------------------------------\n";
    
    $totalProducts = count($allTransactions);
    $totalRecordsCreated = 0;
    $processedProducts = 0;
    
    foreach ($allTransactions as $produkId => $transactions) {
        usort($transactions, function($a, $b) {
            $cmp = strcmp($a['waktu'], $b['waktu']);
            if ($cmp !== 0) return $cmp;
            $cmp = $a['sort_order'] - $b['sort_order'];
            return $cmp;
        });
        
        $simStock = 0;
        $minStock = 0;
        
        foreach ($transactions as $t) {
            if (isset($t['is_adjustment']) && $t['is_adjustment']) {
                $simStock = $t['original_stok_awal'] + $t['stok_masuk'] - $t['stok_keluar'];
            } else {
                $simStock = $simStock + $t['stok_masuk'] - $t['stok_keluar'];
            }
            if ($simStock < $minStock) {
                $minStock = $simStock;
            }
        }
        
        $initialStock = ($minStock < 0) ? abs($minStock) : 0;
        
        $runningStock = $initialStock;
        $insertBatch = [];
        $now = now();
        
        foreach ($transactions as $t) {
            $stokAwal = $runningStock;
            
            if (isset($t['is_adjustment']) && $t['is_adjustment']) {
                $stokAwal = $runningStock;
            }
            
            $stokSisa = $stokAwal + $t['stok_masuk'] - $t['stok_keluar'];
            
            $insertBatch[] = [
                'id_produk' => $produkId,
                'id_penjualan' => $t['id_penjualan'],
                'id_pembelian' => $t['id_pembelian'],
                'stok_awal' => $stokAwal,
                'stok_masuk' => $t['stok_masuk'],
                'stok_keluar' => $t['stok_keluar'],
                'stok_sisa' => $stokSisa,
                'waktu' => $t['waktu'],
                'keterangan' => $t['keterangan'],
                'created_at' => $now,
                'updated_at' => $now
            ];
            
            $runningStock = $stokSisa;
        }
        
        if (!empty($insertBatch)) {
            foreach (array_chunk($insertBatch, 500) as $chunk) {
                DB::table('rekaman_stoks')->insert($chunk);
            }
            $totalRecordsCreated += count($insertBatch);
        }
        
        if ($runningStock < 0) $runningStock = 0;
        
        DB::table('produk')
            ->where('id_produk', $produkId)
            ->update(['stok' => $runningStock]);
        
        $processedProducts++;
        
        if ($processedProducts % 100 === 0) {
            echo "   Processed: {$processedProducts}/{$totalProducts} products\n";
            gc_collect_cycles();
        }
    }
    
    echo "   Processed: {$processedProducts}/{$totalProducts} products - DONE\n\n";

    echo "LANGKAH 4: Produk tanpa transaksi set stok = 0...\n";
    echo "-------------------------------------------------\n";
    
    $productsWithoutTrans = DB::table('produk')
        ->whereNotIn('id_produk', array_keys($allTransactions))
        ->count();
    
    DB::table('produk')
        ->whereNotIn('id_produk', array_keys($allTransactions))
        ->update(['stok' => 0]);
    
    echo "   Produk tanpa transaksi: {$productsWithoutTrans}\n\n";

    DB::commit();
    
    $endTime = microtime(true);
    $executionTime = round($endTime - $startTime, 2);
    
    echo "=======================================================\n";
    echo "   REBUILD SELESAI\n";
    echo "=======================================================\n";
    echo "   Waktu eksekusi: {$executionTime} detik\n";
    echo "   Total produk: {$totalProducts}\n";
    echo "   Total rekaman dibuat: {$totalRecordsCreated}\n";
    echo "   Produk tanpa transaksi: {$productsWithoutTrans}\n\n";

} catch (\Exception $e) {
    DB::rollBack();
    echo "\n[ERROR FATAL] " . $e->getMessage() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}

echo "VERIFIKASI akhir...\n";
echo "-------------------\n";

$outOfSync = [];
$allProducts = Produk::all();

foreach ($allProducts as $produk) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman && intval($produk->stok) != intval($lastRekaman->stok_sisa)) {
        $outOfSync[] = $produk->nama_produk;
    }
}

if (empty($outOfSync)) {
    echo "   [OK] Semua stok produk sinkron!\n";
} else {
    echo "   [WARNING] " . count($outOfSync) . " produk masih tidak sinkron.\n";
}

echo "\n=======================================================\n";
echo "   SELESAI\n";
echo "=======================================================\n";
