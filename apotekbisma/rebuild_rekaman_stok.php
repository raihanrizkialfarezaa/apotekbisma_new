<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== COMPLETE REKAMAN STOK REBUILD ===\n\n";

DB::connection()->disableQueryLog();

echo "STEP 1: Backing up current rekaman_stoks count...\n";
$oldCount = DB::table('rekaman_stoks')->count();
echo "   Current records: {$oldCount}\n";

echo "\nSTEP 2: Collecting ALL transactions from penjualan_detail and pembelian_detail...\n";

$allTransactions = [];

$penjualanData = DB::select("
    SELECT 
        pd.id_penjualan,
        pd.id_produk,
        p.waktu,
        pd.jumlah as qty,
        'penjualan' as tipe,
        CONCAT('Penjualan - ID: ', pd.id_penjualan) as keterangan
    FROM penjualan_detail pd
    JOIN penjualan p ON pd.id_penjualan = p.id_penjualan
    ORDER BY p.waktu ASC, pd.id_penjualan ASC, pd.id_produk ASC
");
echo "   Found " . count($penjualanData) . " penjualan detail records\n";

foreach ($penjualanData as $p) {
    $key = $p->id_produk;
    if (!isset($allTransactions[$key])) {
        $allTransactions[$key] = [];
    }
    $allTransactions[$key][] = [
        'waktu' => $p->waktu,
        'id_penjualan' => $p->id_penjualan,
        'id_pembelian' => null,
        'stok_masuk' => 0,
        'stok_keluar' => $p->qty,
        'keterangan' => $p->keterangan,
        'tipe' => 'penjualan'
    ];
}

$pembelianData = DB::select("
    SELECT 
        pd.id_pembelian,
        pd.id_produk,
        b.waktu,
        pd.jumlah as qty,
        'pembelian' as tipe,
        CONCAT('Pembelian - Faktur: ', COALESCE(b.no_faktur, b.id_pembelian)) as keterangan
    FROM pembelian_detail pd
    JOIN pembelian b ON pd.id_pembelian = b.id_pembelian
    ORDER BY b.waktu ASC, pd.id_pembelian ASC, pd.id_produk ASC
");
echo "   Found " . count($pembelianData) . " pembelian detail records\n";

foreach ($pembelianData as $b) {
    $key = $b->id_produk;
    if (!isset($allTransactions[$key])) {
        $allTransactions[$key] = [];
    }
    $allTransactions[$key][] = [
        'waktu' => $b->waktu,
        'id_penjualan' => null,
        'id_pembelian' => $b->id_pembelian,
        'stok_masuk' => $b->qty,
        'stok_keluar' => 0,
        'keterangan' => $b->keterangan,
        'tipe' => 'pembelian'
    ];
}

echo "\nSTEP 3: Clearing old rekaman_stoks...\n";
DB::table('rekaman_stoks')->truncate();
echo "   Cleared.\n";

echo "\nSTEP 4: Rebuilding rekaman_stoks with Zero-Dip Logic...\n";

$totalProducts = count($allTransactions);
$processedProducts = 0;
$totalRecordsCreated = 0;

foreach ($allTransactions as $produkId => $transactions) {
    $processedProducts++;
    
    usort($transactions, function($a, $b) {
        $cmp = strcmp($a['waktu'], $b['waktu']);
        if ($cmp !== 0) return $cmp;
        if ($a['id_pembelian'] && !$b['id_pembelian']) return -1;
        if (!$a['id_pembelian'] && $b['id_pembelian']) return 1;
        return 0;
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
    $isFirst = true;
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
    
    if ($processedProducts % 100 == 0) {
        echo "   Processed {$processedProducts}/{$totalProducts} products...\n";
    }
}

echo "   Total records created: {$totalRecordsCreated}\n";

echo "\nSTEP 5: Updating products without transactions...\n";
$productsWithoutTrans = DB::table('produk')
    ->whereNotIn('id_produk', array_keys($allTransactions))
    ->get();

foreach ($productsWithoutTrans as $p) {
    if ($p->stok != 0) {
        DB::table('produk')
            ->where('id_produk', $p->id_produk)
            ->update(['stok' => 0]);
    }
}
echo "   Updated " . $productsWithoutTrans->count() . " products without transactions\n";

echo "\n=== REBUILD COMPLETE ===\n";
echo "Old count: {$oldCount} | New count: {$totalRecordsCreated}\n";
