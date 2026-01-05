<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '1G');
set_time_limit(600);

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\RekamanStok;

$dryRun = true;
if (in_array('--execute', $argv ?? [])) {
    $dryRun = false;
}

$output = [];
$output[] = "================================================================";
$output[] = "   COMPREHENSIVE STOCK FIX - APOTEK BISMA";
$output[] = "   Waktu: " . date('Y-m-d H:i:s');
$output[] = "================================================================";
$output[] = "";

if ($dryRun) {
    $output[] = "MODE: DRY RUN (simulasi)";
    $output[] = "Untuk eksekusi: php " . basename(__FILE__) . " --execute";
} else {
    $output[] = "MODE: EXECUTE - Perubahan akan diterapkan!";
}
$output[] = "";

$OPNAME_END = '2025-12-31 23:59:59';
$TX_START = '2026-01-01 00:00:00';

$stats = [
    'duplicates_fixed' => 0,
    'missing_rekaman_created' => 0,
    'products_recalculated' => 0,
    'errors' => 0,
];

$output[] = "================================================================";
$output[] = "   FASE 1: HAPUS DUPLIKAT REKAMAN PENJUALAN";
$output[] = "================================================================";
$output[] = "";

$duplicatePenjualan = DB::select("
    SELECT id_produk, id_penjualan, COUNT(*) as cnt, MIN(id_rekaman_stok) as keep_id
    FROM rekaman_stoks
    WHERE id_penjualan IS NOT NULL AND id_penjualan > 0
    GROUP BY id_produk, id_penjualan
    HAVING cnt > 1
");

$output[] = "Duplikat grup ditemukan: " . count($duplicatePenjualan);

if (!$dryRun && count($duplicatePenjualan) > 0) {
    foreach ($duplicatePenjualan as $dup) {
        $deleteCount = DB::table('rekaman_stoks')
            ->where('id_produk', $dup->id_produk)
            ->where('id_penjualan', $dup->id_penjualan)
            ->where('id_rekaman_stok', '!=', $dup->keep_id)
            ->delete();
        
        $stats['duplicates_fixed'] += $deleteCount;
    }
    $output[] = "Duplikat dihapus: {$stats['duplicates_fixed']}";
} elseif ($dryRun && count($duplicatePenjualan) > 0) {
    $output[] = "(Dry run - tidak ada perubahan)";
}
$output[] = "";

$output[] = "================================================================";
$output[] = "   FASE 2: BUAT REKAMAN UNTUK PEMBELIAN YANG HILANG";
$output[] = "================================================================";
$output[] = "";

$pembelianTanpaRekaman = DB::select("
    SELECT pd.id_pembelian, pd.id_produk, SUM(pd.jumlah) as total_jumlah, pb.waktu
    FROM pembelian_detail pd
    JOIN pembelian pb ON pd.id_pembelian = pb.id_pembelian
    WHERE pb.waktu >= ?
    AND NOT EXISTS (
        SELECT 1 FROM rekaman_stoks rs 
        WHERE rs.id_pembelian = pd.id_pembelian 
        AND rs.id_produk = pd.id_produk
    )
    GROUP BY pd.id_pembelian, pd.id_produk, pb.waktu
", [$TX_START]);

$output[] = "Pembelian tanpa rekaman: " . count($pembelianTanpaRekaman);

if (!$dryRun && count($pembelianTanpaRekaman) > 0) {
    foreach ($pembelianTanpaRekaman as $pb) {
        $produk = DB::table('produk')->where('id_produk', $pb->id_produk)->first();
        if (!$produk) continue;
        
        $stokSekarang = intval($produk->stok);
        $stokAwal = $stokSekarang - intval($pb->total_jumlah);
        if ($stokAwal < 0) $stokAwal = 0;
        
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $pb->id_produk,
            'id_pembelian' => $pb->id_pembelian,
            'waktu' => $pb->waktu,
            'stok_awal' => $stokAwal,
            'stok_masuk' => intval($pb->total_jumlah),
            'stok_keluar' => 0,
            'stok_sisa' => $stokSekarang,
            'keterangan' => 'Pembelian: Auto-created rekaman yang hilang',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        $stats['missing_rekaman_created']++;
    }
    $output[] = "Rekaman dibuat: {$stats['missing_rekaman_created']}";
} elseif ($dryRun && count($pembelianTanpaRekaman) > 0) {
    $output[] = "(Dry run - tidak ada perubahan)";
}
$output[] = "";

$output[] = "================================================================";
$output[] = "   FASE 3: RECALCULATE SEMUA PRODUK DARI OPNAME";
$output[] = "================================================================";
$output[] = "";

$produkOpname = DB::table('rekaman_stoks as rs')
    ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
    ->where('rs.waktu', '>=', '2025-12-31 00:00:00')
    ->where('rs.waktu', '<=', $OPNAME_END)
    ->select('p.id_produk', 'p.nama_produk', 'p.stok as stok_sistem')
    ->distinct()
    ->get();

$output[] = "Produk dengan opname: " . $produkOpname->count();

$fixedProducts = [];

if (!$dryRun) {
    RekamanStok::$preventRecalculation = true;
    
    foreach ($produkOpname as $produk) {
        try {
            $opnameRec = DB::table('rekaman_stoks')
                ->where('id_produk', $produk->id_produk)
                ->where('waktu', '>=', '2025-12-31 00:00:00')
                ->where('waktu', '<=', $OPNAME_END)
                ->orderBy('waktu', 'desc')
                ->orderBy('id_rekaman_stok', 'desc')
                ->first();
            
            if (!$opnameRec) continue;
            
            $stokOpname = intval($opnameRec->stok_sisa);
            
            $totalBeli = DB::table('pembelian_detail as pd')
                ->join('pembelian as pb', 'pd.id_pembelian', '=', 'pb.id_pembelian')
                ->where('pd.id_produk', $produk->id_produk)
                ->where('pb.waktu', '>=', $TX_START)
                ->sum('pd.jumlah');
            
            $totalJual = DB::table('penjualan_detail as pd')
                ->join('penjualan as pj', 'pd.id_penjualan', '=', 'pj.id_penjualan')
                ->where('pd.id_produk', $produk->id_produk)
                ->where('pj.waktu', '>=', $TX_START)
                ->sum('pd.jumlah');
            
            $stokSeharusnya = $stokOpname + intval($totalBeli) - intval($totalJual);
            if ($stokSeharusnya < 0) $stokSeharusnya = 0;
            
            if (intval($produk->stok_sistem) != $stokSeharusnya) {
                DB::table('produk')
                    ->where('id_produk', $produk->id_produk)
                    ->update(['stok' => $stokSeharusnya]);
                
                $fixedProducts[] = [
                    'nama' => $produk->nama_produk,
                    'lama' => intval($produk->stok_sistem),
                    'baru' => $stokSeharusnya,
                ];
            }
            
            $stats['products_recalculated']++;
            
        } catch (\Exception $e) {
            $stats['errors']++;
        }
    }
    
    RekamanStok::$preventRecalculation = false;
    
    $output[] = "Produk diproses: {$stats['products_recalculated']}";
    $output[] = "Produk diperbaiki: " . count($fixedProducts);
    
    if (count($fixedProducts) > 0) {
        $output[] = "";
        $output[] = "Detail perbaikan:";
        foreach (array_slice($fixedProducts, 0, 20) as $fp) {
            $output[] = "  - {$fp['nama']}: {$fp['lama']} -> {$fp['baru']}";
        }
        if (count($fixedProducts) > 20) {
            $output[] = "  ... dan " . (count($fixedProducts) - 20) . " lainnya";
        }
    }
} else {
    $output[] = "(Dry run - tidak ada perubahan)";
}
$output[] = "";

$output[] = "================================================================";
$output[] = "   FASE 4: REBUILD REKAMAN STOK SETELAH OPNAME";
$output[] = "================================================================";
$output[] = "";

if (!$dryRun) {
    $rebuildCount = 0;
    
    foreach ($produkOpname as $produk) {
        try {
            DB::table('rekaman_stoks')
                ->where('id_produk', $produk->id_produk)
                ->where('waktu', '>=', $TX_START)
                ->delete();
            
            $opnameRec = DB::table('rekaman_stoks')
                ->where('id_produk', $produk->id_produk)
                ->where('waktu', '>=', '2025-12-31 00:00:00')
                ->where('waktu', '<=', $OPNAME_END)
                ->orderBy('waktu', 'desc')
                ->orderBy('id_rekaman_stok', 'desc')
                ->first();
            
            if (!$opnameRec) continue;
            
            $stokOpname = intval($opnameRec->stok_sisa);
            
            $allTransactions = [];
            
            $penjualanDetails = DB::table('penjualan_detail as pd')
                ->join('penjualan as pj', 'pd.id_penjualan', '=', 'pj.id_penjualan')
                ->where('pd.id_produk', $produk->id_produk)
                ->where('pj.waktu', '>=', $TX_START)
                ->select(
                    'pj.id_penjualan',
                    'pj.waktu',
                    DB::raw('SUM(pd.jumlah) as total_jumlah')
                )
                ->groupBy('pj.id_penjualan', 'pj.waktu')
                ->get();
            
            foreach ($penjualanDetails as $pj) {
                $allTransactions[] = [
                    'type' => 'penjualan',
                    'id' => $pj->id_penjualan,
                    'waktu' => $pj->waktu,
                    'jumlah' => intval($pj->total_jumlah),
                ];
            }
            
            $pembelianDetails = DB::table('pembelian_detail as pd')
                ->join('pembelian as pb', 'pd.id_pembelian', '=', 'pb.id_pembelian')
                ->where('pd.id_produk', $produk->id_produk)
                ->where('pb.waktu', '>=', $TX_START)
                ->select(
                    'pb.id_pembelian',
                    'pb.waktu',
                    DB::raw('SUM(pd.jumlah) as total_jumlah')
                )
                ->groupBy('pb.id_pembelian', 'pb.waktu')
                ->get();
            
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
                
                if ($tx['type'] === 'pembelian') {
                    $stokMasuk = $tx['jumlah'];
                    $stokKeluar = 0;
                    $runningStock += $tx['jumlah'];
                    $keterangan = 'Pembelian: Penambahan stok dari supplier';
                    $idPenjualan = null;
                    $idPembelian = $tx['id'];
                } else {
                    $stokMasuk = 0;
                    $stokKeluar = $tx['jumlah'];
                    $runningStock -= $tx['jumlah'];
                    if ($runningStock < 0) $runningStock = 0;
                    $keterangan = 'Penjualan: Transaksi penjualan produk';
                    $idPenjualan = $tx['id'];
                    $idPembelian = null;
                }
                
                DB::table('rekaman_stoks')->insert([
                    'id_produk' => $produk->id_produk,
                    'id_penjualan' => $idPenjualan,
                    'id_pembelian' => $idPembelian,
                    'waktu' => $tx['waktu'],
                    'stok_awal' => $stokAwal,
                    'stok_masuk' => $stokMasuk,
                    'stok_keluar' => $stokKeluar,
                    'stok_sisa' => $runningStock,
                    'keterangan' => $keterangan,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            
            $rebuildCount++;
            
        } catch (\Exception $e) {
            $stats['errors']++;
        }
    }
    
    $output[] = "Produk dengan rekaman di-rebuild: {$rebuildCount}";
} else {
    $output[] = "(Dry run - tidak ada perubahan)";
}
$output[] = "";

$output[] = "================================================================";
$output[] = "   RINGKASAN";
$output[] = "================================================================";
$output[] = "";
$output[] = "Duplikat dihapus: {$stats['duplicates_fixed']}";
$output[] = "Rekaman hilang dibuat: {$stats['missing_rekaman_created']}";
$output[] = "Produk diproses: {$stats['products_recalculated']}";
$output[] = "Produk diperbaiki: " . count($fixedProducts);
$output[] = "Errors: {$stats['errors']}";
$output[] = "";

if (!$dryRun) {
    $output[] = "PERBAIKAN SELESAI!";
    $output[] = "Jalankan php stock_health_check.php untuk verifikasi.";
} else {
    $output[] = "UNTUK MENERAPKAN: php " . basename(__FILE__) . " --execute";
}

$output[] = "";
$output[] = "================================================================";

$content = implode("\n", $output);
$outputFile = __DIR__ . '/comprehensive_fix_' . date('Y-m-d_His') . '.txt';
file_put_contents($outputFile, $content);

echo $content;
echo "\n\nHasil disimpan ke: {$outputFile}\n";
