<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$startTime = microtime(true);

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    DB::connection()->disableQueryLog();
    
    $result = [
        'success' => true,
        'steps' => [],
        'stats' => []
    ];
    
    $result['steps'][] = 'Mengumpulkan data transaksi...';
    
    $allTransactions = [];
    
    $penjualanData = DB::select("
        SELECT 
            pd.id_penjualan_detail,
            pd.id_penjualan,
            pd.id_produk,
            p.waktu,
            pd.jumlah as qty
        FROM penjualan_detail pd
        JOIN penjualan p ON pd.id_penjualan = p.id_penjualan
        ORDER BY p.waktu ASC, pd.id_penjualan ASC, pd.id_penjualan_detail ASC
    ");
    
    $result['stats']['penjualan_records'] = count($penjualanData);
    
    foreach ($penjualanData as $p) {
        $key = $p->id_produk;
        if (!isset($allTransactions[$key])) {
            $allTransactions[$key] = [];
        }
        $allTransactions[$key][] = [
            'detail_id' => 'P' . $p->id_penjualan_detail,
            'waktu' => $p->waktu,
            'id_penjualan' => $p->id_penjualan,
            'id_pembelian' => null,
            'stok_masuk' => 0,
            'stok_keluar' => $p->qty,
            'keterangan' => 'Penjualan - ID: ' . $p->id_penjualan,
            'tipe' => 'penjualan',
            'sort_order' => 1
        ];
    }
    
    $pembelianData = DB::select("
        SELECT 
            pd.id_pembelian_detail,
            pd.id_pembelian,
            pd.id_produk,
            b.waktu,
            pd.jumlah as qty,
            b.no_faktur
        FROM pembelian_detail pd
        JOIN pembelian b ON pd.id_pembelian = b.id_pembelian
        ORDER BY b.waktu ASC, pd.id_pembelian ASC, pd.id_pembelian_detail ASC
    ");
    
    $result['stats']['pembelian_records'] = count($pembelianData);
    
    foreach ($pembelianData as $b) {
        $key = $b->id_produk;
        if (!isset($allTransactions[$key])) {
            $allTransactions[$key] = [];
        }
        $allTransactions[$key][] = [
            'detail_id' => 'B' . $b->id_pembelian_detail,
            'waktu' => $b->waktu,
            'id_penjualan' => null,
            'id_pembelian' => $b->id_pembelian,
            'stok_masuk' => $b->qty,
            'stok_keluar' => 0,
            'keterangan' => 'Pembelian - Faktur: ' . ($b->no_faktur ?: $b->id_pembelian),
            'tipe' => 'pembelian',
            'sort_order' => 0
        ];
    }
    
    $result['steps'][] = 'Membersihkan data lama...';
    DB::table('rekaman_stoks')->truncate();
    
    $result['steps'][] = 'Membangun ulang kartu stok...';
    
    $totalProducts = count($allTransactions);
    $totalRecordsCreated = 0;
    
    foreach ($allTransactions as $produkId => $transactions) {
        usort($transactions, function($a, $b) {
            $cmp = strcmp($a['waktu'], $b['waktu']);
            if ($cmp !== 0) return $cmp;
            $cmp = $a['sort_order'] - $b['sort_order'];
            if ($cmp !== 0) return $cmp;
            return strcmp($a['detail_id'], $b['detail_id']);
        });
        
        $simStock = 0;
        $minStock = 0;
        
        foreach ($transactions as $t) {
            $simStock = $simStock + $t['stok_masuk'] - $t['stok_keluar'];
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
            $stokSisa = $runningStock + $t['stok_masuk'] - $t['stok_keluar'];
            
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
        
        DB::table('produk')
            ->where('id_produk', $produkId)
            ->update(['stok' => $runningStock]);
    }
    
    $result['steps'][] = 'Memperbarui produk tanpa transaksi...';
    $productsWithoutTrans = DB::table('produk')
        ->whereNotIn('id_produk', array_keys($allTransactions))
        ->count();
    DB::table('produk')
        ->whereNotIn('id_produk', array_keys($allTransactions))
        ->update(['stok' => 0]);
    
    $endTime = microtime(true);
    $executionTime = round($endTime - $startTime, 2);
    
    $result['stats']['total_products'] = $totalProducts;
    $result['stats']['total_records_created'] = $totalRecordsCreated;
    $result['stats']['products_without_transactions'] = $productsWithoutTrans;
    $result['stats']['execution_time'] = $executionTime . ' detik';
    $result['steps'][] = 'Selesai!';
    $result['message'] = "Berhasil memperbaiki kartu stok untuk {$totalProducts} produk dengan {$totalRecordsCreated} record dalam {$executionTime} detik";
    
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}
