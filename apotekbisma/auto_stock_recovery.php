<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\RekamanStok;

echo "=======================================================\n";
echo "   AUTOMATED STOCK RECOVERY 2026 (NON-INTERACTIVE)\n";
echo "   Tanggal Eksekusi: " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

set_time_limit(3600);
ini_set('memory_limit', '2G');

$cutoffDate = '2025-12-31 23:59:59';

echo "PARAMETER:\n";
echo "  - Cutoff Date (Opname Valid): {$cutoffDate}\n";
echo "  - Current Date: " . date('Y-m-d H:i:s') . "\n\n";

echo "[STEP 1/5] Collecting Stock Opname data from Dec 30-31, 2025...\n";

$stokAwalValid = [];
$sourceDetail = [];

$allProducts = Produk::all();
$totalProducts = count($allProducts);

foreach ($allProducts as $produk) {
    $opnameRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->where('waktu', '>=', '2025-12-30 00:00:00')
        ->where('waktu', '<=', $cutoffDate)
        ->whereNull('id_penjualan')
        ->whereNull('id_pembelian')
        ->where(function($q) {
            $q->where('keterangan', 'LIKE', '%Stock Opname%')
              ->orWhere('keterangan', 'LIKE', '%Update Stok Manual%')
              ->orWhere('keterangan', 'LIKE', '%Perubahan Stok Manual%')
              ->orWhere('keterangan', 'LIKE', '%Penyesuaian%')
              ->orWhere('keterangan', 'LIKE', '%Manual%');
        })
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($opnameRecord) {
        $stokAwalValid[$produk->id_produk] = intval($opnameRecord->stok_sisa);
        $sourceDetail[$produk->id_produk] = 'OPNAME';
    } else {
        $lastRecord = DB::table('rekaman_stoks')
            ->where('id_produk', $produk->id_produk)
            ->where('waktu', '<=', $cutoffDate)
            ->orderBy('waktu', 'desc')
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();
        
        if ($lastRecord) {
            $stokAwalValid[$produk->id_produk] = intval($lastRecord->stok_sisa);
            $sourceDetail[$produk->id_produk] = 'LAST_RECORD';
        } else {
            $stokAwalValid[$produk->id_produk] = 0;
            $sourceDetail[$produk->id_produk] = 'NO_RECORD';
        }
    }
}

$fromOpname = count(array_filter($sourceDetail, fn($s) => $s === 'OPNAME'));
$fromLastRecord = count(array_filter($sourceDetail, fn($s) => $s === 'LAST_RECORD'));
$noRecord = count(array_filter($sourceDetail, fn($s) => $s === 'NO_RECORD'));

echo "   - From Stock Opname: {$fromOpname}\n";
echo "   - From Last Record: {$fromLastRecord}\n";
echo "   - No Record (stok=0): {$noRecord}\n\n";

echo "[STEP 2/5] Calculating expected stock for each product...\n";

$hasilKalkulasi = [];
$produkBermasalah = [];

foreach ($allProducts as $produk) {
    $stokAwal = $stokAwalValid[$produk->id_produk] ?? 0;
    
    $totalMasuk = DB::table('pembelian_detail')
        ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
        ->where('pembelian.waktu', '>', $cutoffDate)
        ->where('pembelian_detail.id_produk', $produk->id_produk)
        ->sum('pembelian_detail.jumlah');
    
    $totalKeluar = DB::table('penjualan_detail')
        ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
        ->where('penjualan.waktu', '>', $cutoffDate)
        ->where('penjualan_detail.id_produk', $produk->id_produk)
        ->sum('penjualan_detail.jumlah');
    
    $stokAkhirSeharusnya = intval($stokAwal) + intval($totalMasuk) - intval($totalKeluar);
    if ($stokAkhirSeharusnya < 0) $stokAkhirSeharusnya = 0;
    
    $stokSekarang = intval($produk->stok);
    $selisih = $stokSekarang - $stokAkhirSeharusnya;
    
    $hasilKalkulasi[$produk->id_produk] = [
        'id' => $produk->id_produk,
        'nama' => $produk->nama_produk,
        'stok_awal' => $stokAwal,
        'masuk' => intval($totalMasuk),
        'keluar' => intval($totalKeluar),
        'seharusnya' => $stokAkhirSeharusnya,
        'sekarang' => $stokSekarang,
        'selisih' => $selisih
    ];
    
    if ($selisih !== 0) {
        $produkBermasalah[$produk->id_produk] = $hasilKalkulasi[$produk->id_produk];
    }
}

$produkSync = count($hasilKalkulasi) - count($produkBermasalah);

echo "   - Total products: " . count($hasilKalkulasi) . "\n";
echo "   - Already SYNC: {$produkSync}\n";
echo "   - Need FIX: " . count($produkBermasalah) . "\n\n";

if (count($produkBermasalah) === 0) {
    echo "[SUCCESS] All stocks are already correct! No action needed.\n";
    exit(0);
}

echo "[STEP 3/5] Fixing product stock values...\n";

DB::beginTransaction();

RekamanStok::$preventRecalculation = true;

try {
    $totalFixed = 0;
    $rekamanDeleted = 0;
    $rekamanCreated = 0;
    
    foreach ($produkBermasalah as $produkId => $h) {
        DB::table('produk')
            ->where('id_produk', $produkId)
            ->update(['stok' => $h['seharusnya']]);
        $totalFixed++;
    }
    
    echo "   - Fixed {$totalFixed} product stock values\n\n";
    
    echo "[STEP 4/5] Rebuilding stock card records (rekaman_stoks) for 2026...\n";
    
    foreach ($produkBermasalah as $produkId => $h) {
        $rekamanSetelahCutoff = DB::table('rekaman_stoks')
            ->where('id_produk', $produkId)
            ->where('waktu', '>', $cutoffDate)
            ->count();
        
        DB::table('rekaman_stoks')
            ->where('id_produk', $produkId)
            ->where('waktu', '>', $cutoffDate)
            ->delete();
        
        $rekamanDeleted += $rekamanSetelahCutoff;
        
        $stokAwal = $stokAwalValid[$produkId];
        $runningStock = $stokAwal;
        
        $allTrans = [];
        
        $pembelian2026 = DB::table('pembelian_detail')
            ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
            ->where('pembelian.waktu', '>', $cutoffDate)
            ->where('pembelian_detail.id_produk', $produkId)
            ->select(
                'pembelian_detail.id_pembelian',
                'pembelian.waktu',
                'pembelian_detail.jumlah',
                'pembelian.no_faktur',
                'pembelian.created_at'
            )
            ->orderBy('pembelian.waktu', 'asc')
            ->orderBy('pembelian.id_pembelian', 'asc')
            ->get();
        
        foreach ($pembelian2026 as $p) {
            $allTrans[] = [
                'waktu' => $p->waktu ?? $p->created_at,
                'id_pembelian' => $p->id_pembelian,
                'id_penjualan' => null,
                'stok_masuk' => intval($p->jumlah),
                'stok_keluar' => 0,
                'keterangan' => 'Pembelian - Faktur: ' . ($p->no_faktur ?: $p->id_pembelian),
                'sort_order' => 0
            ];
        }
        
        $penjualan2026 = DB::table('penjualan_detail')
            ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
            ->where('penjualan.waktu', '>', $cutoffDate)
            ->where('penjualan_detail.id_produk', $produkId)
            ->select(
                'penjualan_detail.id_penjualan',
                'penjualan.waktu',
                'penjualan_detail.jumlah',
                'penjualan.created_at'
            )
            ->orderBy('penjualan.waktu', 'asc')
            ->orderBy('penjualan.id_penjualan', 'asc')
            ->get();
        
        foreach ($penjualan2026 as $j) {
            $allTrans[] = [
                'waktu' => $j->waktu ?? $j->created_at,
                'id_penjualan' => $j->id_penjualan,
                'id_pembelian' => null,
                'stok_masuk' => 0,
                'stok_keluar' => intval($j->jumlah),
                'keterangan' => 'Penjualan - ID: ' . $j->id_penjualan,
                'sort_order' => 1
            ];
        }
        
        usort($allTrans, function($a, $b) {
            $cmp = strcmp($a['waktu'], $b['waktu']);
            if ($cmp !== 0) return $cmp;
            return $a['sort_order'] - $b['sort_order'];
        });
        
        $insertBatch = [];
        $now = now();
        
        foreach ($allTrans as $t) {
            $stokSisa = $runningStock + $t['stok_masuk'] - $t['stok_keluar'];
            if ($stokSisa < 0) $stokSisa = 0;
            
            $insertBatch[] = [
                'id_produk' => $produkId,
                'id_penjualan' => $t['id_penjualan'],
                'id_pembelian' => $t['id_pembelian'],
                'stok_awal' => $runningStock,
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
            DB::table('rekaman_stoks')->insert($insertBatch);
            $rekamanCreated += count($insertBatch);
        }
        
        if ($totalFixed % 100 === 0) {
            gc_collect_cycles();
        }
    }
    
    echo "   - Deleted {$rekamanDeleted} old records\n";
    echo "   - Created {$rekamanCreated} new records\n\n";
    
    DB::commit();
    
    RekamanStok::$preventRecalculation = false;
    
} catch (\Exception $e) {
    DB::rollBack();
    RekamanStok::$preventRecalculation = false;
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    echo "Transaction rolled back.\n";
    exit(1);
}

echo "[STEP 5/5] Final verification...\n";

$stillMismatch = 0;
$kartuStokMismatch = 0;

foreach (Produk::all() as $produk) {
    $stokAwal = $stokAwalValid[$produk->id_produk] ?? 0;
    
    $totalMasuk = DB::table('pembelian_detail')
        ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
        ->where('pembelian.waktu', '>', $cutoffDate)
        ->where('pembelian_detail.id_produk', $produk->id_produk)
        ->sum('pembelian_detail.jumlah');
    
    $totalKeluar = DB::table('penjualan_detail')
        ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
        ->where('penjualan.waktu', '>', $cutoffDate)
        ->where('penjualan_detail.id_produk', $produk->id_produk)
        ->sum('penjualan_detail.jumlah');
    
    $expected = intval($stokAwal) + intval($totalMasuk) - intval($totalKeluar);
    if ($expected < 0) $expected = 0;
    
    $actual = intval($produk->fresh()->stok);
    
    if ($expected !== $actual) {
        $stillMismatch++;
    }
    
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman && intval($lastRekaman->stok_sisa) !== intval($produk->fresh()->stok)) {
        $kartuStokMismatch++;
    }
}

echo "   - Stock calculation check: " . ($stillMismatch === 0 ? "PASSED" : "FAILED ({$stillMismatch} mismatch)") . "\n";
echo "   - Stock card sync check: " . ($kartuStokMismatch === 0 ? "PASSED" : "FAILED ({$kartuStokMismatch} mismatch)") . "\n\n";

$success = ($stillMismatch === 0 && $kartuStokMismatch === 0);

echo "=======================================================\n";
if ($success) {
    echo "   RECOVERY COMPLETED SUCCESSFULLY!\n";
    echo "   All stocks are now 100% synchronized.\n";
} else {
    echo "   RECOVERY COMPLETED WITH WARNINGS\n";
    echo "   Please check the remaining mismatches.\n";
}
echo "=======================================================\n\n";

echo "SUMMARY:\n";
echo "  - Products fixed: {$totalFixed}\n";
echo "  - Records deleted: {$rekamanDeleted}\n";
echo "  - Records created: {$rekamanCreated}\n";
echo "  - Final status: " . ($success ? "SUCCESS" : "WARNING") . "\n\n";

echo "Completed at: " . date('Y-m-d H:i:s') . "\n";

exit($success ? 0 : 1);
