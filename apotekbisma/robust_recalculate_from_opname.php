<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '1G');
set_time_limit(600);

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\RekamanStok;

$OPNAME_START = '2025-12-31 00:00:00';
$OPNAME_END = '2025-12-31 23:59:59';
$TRANSACTION_START = '2026-01-01 00:00:00';

$dryRun = true;
if (in_array('--execute', $argv ?? [])) {
    $dryRun = false;
}

$output = [];
$output[] = "================================================================";
$output[] = "   ROBUST STOCK RECALCULATION - APOTEK BISMA";
$output[] = "   Waktu Eksekusi: " . date('Y-m-d H:i:s');
$output[] = "   Cutoff Opname: {$OPNAME_END}";
$output[] = "   Transaksi Mulai: {$TRANSACTION_START}";
$output[] = "================================================================";
$output[] = "";

if ($dryRun) {
    $output[] = "MODE: DRY RUN (simulasi, tidak ada perubahan database)";
    $output[] = "Untuk eksekusi: php " . basename(__FILE__) . " --execute";
} else {
    $output[] = "MODE: EXECUTE - Perubahan akan diterapkan!";
}
$output[] = "";

$produkDenganOpname = DB::table('rekaman_stoks as rs')
    ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
    ->where('rs.waktu', '>=', $OPNAME_START)
    ->where('rs.waktu', '<=', $OPNAME_END)
    ->select('p.id_produk', 'p.nama_produk', 'p.stok as stok_sistem')
    ->distinct()
    ->get();

$output[] = "================================================================";
$output[] = "   FASE 1: IDENTIFIKASI PRODUK DENGAN STOCK OPNAME";
$output[] = "================================================================";
$output[] = "";
$output[] = "Produk dengan stock opname pada 31 Des 2025: " . $produkDenganOpname->count();
$output[] = "";

$recalcResults = [];
$totalFixed = 0;
$totalAlreadyOk = 0;
$totalErrors = 0;

$output[] = "================================================================";
$output[] = "   FASE 2: RECALCULATE STOK BERDASARKAN TRANSAKSI";
$output[] = "================================================================";
$output[] = "";

foreach ($produkDenganOpname as $produk) {
    $result = recalculateProductStock($produk, $OPNAME_START, $OPNAME_END, $TRANSACTION_START, $dryRun);
    $recalcResults[] = $result;
    
    if ($result['error']) {
        $totalErrors++;
    } elseif ($result['fixed']) {
        $totalFixed++;
    } else {
        $totalAlreadyOk++;
    }
}

$output[] = "Hasil Recalculation:";
$output[] = "  - Produk sudah OK: {$totalAlreadyOk}";
$output[] = "  - Produk diperbaiki: {$totalFixed}";
$output[] = "  - Error: {$totalErrors}";
$output[] = "";

$output[] = "================================================================";
$output[] = "   FASE 3: DETAIL PRODUK YANG DIPERBAIKI";
$output[] = "================================================================";
$output[] = "";

$fixedProducts = array_filter($recalcResults, function($r) { return $r['fixed']; });
usort($fixedProducts, function($a, $b) {
    return abs($b['difference']) - abs($a['difference']);
});

$count = 0;
foreach ($fixedProducts as $r) {
    $count++;
    if ($count > 50) {
        $remaining = count($fixedProducts) - 50;
        $output[] = "... dan {$remaining} produk lainnya ...";
        break;
    }
    $sign = $r['difference'] > 0 ? '+' : '';
    $output[] = "{$r['nama_produk']} (ID:{$r['id_produk']})";
    $output[] = "  Stok Opname: {$r['stok_opname']} | +Beli: {$r['total_beli']} | -Jual: {$r['total_jual']}";
    $output[] = "  Stok Lama: {$r['stok_lama']} -> Stok Baru: {$r['stok_baru']} (selisih: {$sign}{$r['difference']})";
    $output[] = "";
}

if ($totalErrors > 0) {
    $output[] = "================================================================";
    $output[] = "   ERRORS";
    $output[] = "================================================================";
    $output[] = "";
    
    $errorProducts = array_filter($recalcResults, function($r) { return $r['error']; });
    foreach ($errorProducts as $r) {
        $output[] = "{$r['nama_produk']} (ID:{$r['id_produk']}): {$r['error_message']}";
    }
    $output[] = "";
}

$output[] = "================================================================";
$output[] = "   FASE 4: VERIFIKASI AKHIR";
$output[] = "================================================================";
$output[] = "";

if (!$dryRun && $totalFixed > 0) {
    $output[] = "Menjalankan recalculateStock untuk setiap produk yang diperbaiki...";
    
    RekamanStok::$preventRecalculation = false;
    
    $recalcSuccess = 0;
    $recalcFail = 0;
    
    foreach ($fixedProducts as $r) {
        try {
            RekamanStok::recalculateStock($r['id_produk']);
            $recalcSuccess++;
        } catch (\Exception $e) {
            $recalcFail++;
            Log::error("Recalculate error for product {$r['id_produk']}: " . $e->getMessage());
        }
    }
    
    $output[] = "Recalculate berhasil: {$recalcSuccess}";
    $output[] = "Recalculate gagal: {$recalcFail}";
} else {
    $output[] = "Verifikasi dilewati (dry run atau tidak ada perubahan)";
}

$output[] = "";
$output[] = "================================================================";
$output[] = "   RINGKASAN AKHIR";
$output[] = "================================================================";
$output[] = "";
$output[] = "Total produk diproses: " . count($recalcResults);
$output[] = "Produk sudah OK: {$totalAlreadyOk}";
$output[] = "Produk diperbaiki: {$totalFixed}";
$output[] = "Errors: {$totalErrors}";
$output[] = "";

if ($dryRun) {
    $output[] = "UNTUK MENERAPKAN PERBAIKAN:";
    $output[] = "php " . basename(__FILE__) . " --execute";
} else {
    $output[] = "PERBAIKAN TELAH DITERAPKAN!";
}

$output[] = "";
$output[] = "================================================================";

$content = implode("\n", $output);
$outputFile = __DIR__ . '/recalc_result_' . date('Y-m-d_His') . '.txt';
file_put_contents($outputFile, $content);

echo $content;
echo "\n\nHasil disimpan ke: {$outputFile}\n";


function recalculateProductStock($produk, $opnameStart, $opnameEnd, $txStart, $dryRun) {
    $result = [
        'id_produk' => $produk->id_produk,
        'nama_produk' => $produk->nama_produk,
        'stok_lama' => intval($produk->stok_sistem),
        'stok_opname' => 0,
        'total_beli' => 0,
        'total_jual' => 0,
        'stok_baru' => 0,
        'difference' => 0,
        'fixed' => false,
        'error' => false,
        'error_message' => '',
    ];
    
    try {
        $rekamanOpname = DB::table('rekaman_stoks')
            ->where('id_produk', $produk->id_produk)
            ->where('waktu', '>=', $opnameStart)
            ->where('waktu', '<=', $opnameEnd)
            ->orderBy('waktu', 'desc')
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();
        
        if (!$rekamanOpname) {
            $result['error'] = true;
            $result['error_message'] = 'Tidak ada rekaman opname ditemukan';
            return $result;
        }
        
        $result['stok_opname'] = intval($rekamanOpname->stok_sisa);
        
        $totalPembelian = DB::table('pembelian_detail as pd')
            ->join('pembelian as pb', 'pd.id_pembelian', '=', 'pb.id_pembelian')
            ->where('pd.id_produk', $produk->id_produk)
            ->where('pb.waktu', '>=', $txStart)
            ->sum('pd.jumlah');
        
        $result['total_beli'] = intval($totalPembelian);
        
        $totalPenjualan = DB::table('penjualan_detail as pd')
            ->join('penjualan as pj', 'pd.id_penjualan', '=', 'pj.id_penjualan')
            ->where('pd.id_produk', $produk->id_produk)
            ->where('pj.waktu', '>=', $txStart)
            ->sum('pd.jumlah');
        
        $result['total_jual'] = intval($totalPenjualan);
        
        $stokSeharusnya = $result['stok_opname'] + $result['total_beli'] - $result['total_jual'];
        
        if ($stokSeharusnya < 0) {
            $stokSeharusnya = 0;
        }
        
        $result['stok_baru'] = $stokSeharusnya;
        $result['difference'] = $result['stok_lama'] - $stokSeharusnya;
        
        if ($result['stok_lama'] != $stokSeharusnya) {
            $result['fixed'] = true;
            
            if (!$dryRun) {
                DB::beginTransaction();
                try {
                    DB::table('produk')
                        ->where('id_produk', $produk->id_produk)
                        ->update(['stok' => $stokSeharusnya]);
                    
                    rebuildRekamanStoksForProduct(
                        $produk->id_produk,
                        $result['stok_opname'],
                        $opnameEnd,
                        $txStart
                    );
                    
                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    $result['error'] = true;
                    $result['error_message'] = $e->getMessage();
                    $result['fixed'] = false;
                }
            }
        }
        
    } catch (\Exception $e) {
        $result['error'] = true;
        $result['error_message'] = $e->getMessage();
    }
    
    return $result;
}

function rebuildRekamanStoksForProduct($idProduk, $stokOpname, $opnameEnd, $txStart) {
    DB::table('rekaman_stoks')
        ->where('id_produk', $idProduk)
        ->where('waktu', '>=', $txStart)
        ->delete();
    
    $penjualanDetails = DB::table('penjualan_detail as pd')
        ->join('penjualan as pj', 'pd.id_penjualan', '=', 'pj.id_penjualan')
        ->where('pd.id_produk', $idProduk)
        ->where('pj.waktu', '>=', $txStart)
        ->select(
            'pj.id_penjualan',
            'pj.waktu',
            DB::raw('SUM(pd.jumlah) as total_jumlah')
        )
        ->groupBy('pj.id_penjualan', 'pj.waktu')
        ->orderBy('pj.waktu', 'asc')
        ->orderBy('pj.id_penjualan', 'asc')
        ->get();
    
    $pembelianDetails = DB::table('pembelian_detail as pd')
        ->join('pembelian as pb', 'pd.id_pembelian', '=', 'pb.id_pembelian')
        ->where('pd.id_produk', $idProduk)
        ->where('pb.waktu', '>=', $txStart)
        ->select(
            'pb.id_pembelian',
            'pb.waktu',
            DB::raw('SUM(pd.jumlah) as total_jumlah')
        )
        ->groupBy('pb.id_pembelian', 'pb.waktu')
        ->orderBy('pb.waktu', 'asc')
        ->orderBy('pb.id_pembelian', 'asc')
        ->get();
    
    $allTransactions = [];
    
    foreach ($penjualanDetails as $pj) {
        $allTransactions[] = [
            'type' => 'penjualan',
            'id' => $pj->id_penjualan,
            'waktu' => $pj->waktu,
            'jumlah' => intval($pj->total_jumlah),
        ];
    }
    
    foreach ($pembelianDetails as $pb) {
        $allTransactions[] = [
            'type' => 'pembelian',
            'id' => $pb->id_pembelian,
            'waktu' => $pb->waktu,
            'jumlah' => intval($pb->total_jumlah),
        ];
    }
    
    usort($allTransactions, function($a, $b) {
        $cmp = strcmp($a['waktu'], $b['waktu']);
        if ($cmp !== 0) return $cmp;
        return $a['id'] - $b['id'];
    });
    
    $runningStock = $stokOpname;
    
    foreach ($allTransactions as $tx) {
        $stokAwal = $runningStock;
        $stokMasuk = 0;
        $stokKeluar = 0;
        
        if ($tx['type'] === 'pembelian') {
            $stokMasuk = $tx['jumlah'];
            $runningStock += $tx['jumlah'];
            $keterangan = 'Pembelian: Penambahan stok dari supplier';
            $idPenjualan = null;
            $idPembelian = $tx['id'];
        } else {
            $stokKeluar = $tx['jumlah'];
            $runningStock -= $tx['jumlah'];
            if ($runningStock < 0) $runningStock = 0;
            $keterangan = 'Penjualan: Transaksi penjualan produk';
            $idPenjualan = $tx['id'];
            $idPembelian = null;
        }
        
        $stokSisa = $runningStock;
        
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $idProduk,
            'id_penjualan' => $idPenjualan,
            'id_pembelian' => $idPembelian,
            'waktu' => $tx['waktu'],
            'stok_awal' => $stokAwal,
            'stok_masuk' => $stokMasuk,
            'stok_keluar' => $stokKeluar,
            'stok_sisa' => $stokSisa,
            'keterangan' => $keterangan,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
