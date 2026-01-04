<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\RekamanStok;

echo "=======================================================\n";
echo "   DATA RECOVERY & STOCK RECALCULATION 2026 - FINAL\n";
echo "   Tanggal Eksekusi: " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

set_time_limit(3600);
ini_set('memory_limit', '2G');

$cutoffDate = '2025-12-31 23:59:59';
$startDate2026 = '2026-01-01 00:00:00';
$endDate2026 = date('Y-m-d 23:59:59');

echo "PARAMETER:\n";
echo "  - Cutoff Date (Opname Valid): {$cutoffDate}\n";
echo "  - Start 2026: {$startDate2026}\n";
echo "  - End Date: {$endDate2026}\n\n";

echo "=======================================================\n";
echo "   LANGKAH A: MENCARI ANCHOR POINT (STOK AWAL VALID)\n";
echo "   Mencari rekaman Stock Opname/Update Manual pada 30-31 Des 2025\n";
echo "=======================================================\n\n";

$stokAwalValid = [];
$sourceDetail = [];

$allProducts = Produk::orderBy('nama_produk')->get();
$totalProducts = count($allProducts);
echo "Total produk di database: {$totalProducts}\n\n";

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

echo "[LANGKAH A - HASIL]\n";
echo "  - Dari Stock Opname 30-31 Des: {$fromOpname} produk\n";
echo "  - Dari rekaman terakhir sebelum cutoff: {$fromLastRecord} produk\n";
echo "  - Tanpa rekaman (stok awal = 0): {$noRecord} produk\n\n";

echo "=======================================================\n";
echo "   LANGKAH B: REKALKULASI TRANSAKSI 2026\n";
echo "   Formula: Stok Akhir = Stok Awal + Masuk - Keluar\n";
echo "=======================================================\n\n";

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
        'source' => $sourceDetail[$produk->id_produk],
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

echo "[LANGKAH B - HASIL]\n";
echo "  - Total produk: " . count($hasilKalkulasi) . "\n";
echo "  - Produk SYNC (selisih=0): {$produkSync}\n";
echo "  - Produk BERMASALAH: " . count($produkBermasalah) . "\n\n";

if (count($produkBermasalah) === 0) {
    echo "[SUCCESS] Tidak ada produk yang perlu diperbaiki!\n";
    echo "Semua stok sudah sesuai dengan kalkulasi.\n";
    exit(0);
}

uasort($produkBermasalah, fn($a, $b) => abs($b['selisih']) - abs($a['selisih']));

echo "10 PRODUK DENGAN SELISIH TERBESAR:\n";
echo str_repeat("-", 90) . "\n";
printf("%-35s | %8s | %6s | %6s | %10s | %10s | %8s\n", 
    "NAMA PRODUK", "STOK AWL", "MASUK", "KELUAR", "SEHARUSNYA", "SEKARANG", "SELISIH");
echo str_repeat("-", 90) . "\n";

$counter = 0;
foreach ($produkBermasalah as $h) {
    if ($counter >= 10) break;
    printf("%-35s | %8d | %6d | %6d | %10d | %10d | %+8d\n",
        substr($h['nama'], 0, 35),
        $h['stok_awal'],
        $h['masuk'],
        $h['keluar'],
        $h['seharusnya'],
        $h['sekarang'],
        $h['selisih']
    );
    $counter++;
}
echo str_repeat("-", 90) . "\n\n";

echo "=======================================================\n";
echo "   LANGKAH C: EKSEKUSI UPDATE & SINKRONISASI\n";
echo "=======================================================\n\n";

echo "PERINGATAN: Ini akan mengubah " . count($produkBermasalah) . " produk!\n\n";
echo "Ketik 'YES' untuk melanjutkan: ";
$input = trim(strtoupper(fgets(STDIN)));

if ($input !== 'YES') {
    echo "\n[DIBATALKAN] Script dihentikan oleh pengguna.\n";
    exit(0);
}

echo "\nMemulai proses recovery...\n\n";

DB::beginTransaction();

RekamanStok::$preventRecalculation = true;

try {
    $totalFixed = 0;
    $rekamanDeleted = 0;
    $rekamanCreated = 0;
    
    foreach ($produkBermasalah as $produkId => $h) {
        echo "[PROCESSING] {$h['nama']} (ID: {$produkId})\n";
        echo "  Stok lama: {$h['sekarang']} -> Stok baru: {$h['seharusnya']}\n";
        
        DB::table('produk')
            ->where('id_produk', $produkId)
            ->update(['stok' => $h['seharusnya']]);
        
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
        
        $totalFixed++;
        
        echo "  [OK] Stok diperbarui, " . count($insertBatch) . " rekaman dibuat\n\n";
        
        if ($totalFixed % 50 === 0) {
            gc_collect_cycles();
        }
    }
    
    DB::commit();
    
    RekamanStok::$preventRecalculation = false;
    
    echo "\n=======================================================\n";
    echo "   HASIL EKSEKUSI\n";
    echo "=======================================================\n";
    echo "  - Produk FIXED: {$totalFixed}\n";
    echo "  - Rekaman DIHAPUS: {$rekamanDeleted}\n";
    echo "  - Rekaman DIBUAT: {$rekamanCreated}\n\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    RekamanStok::$preventRecalculation = false;
    echo "\n[ERROR FATAL] " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Transaksi di-rollback.\n";
    exit(1);
}

echo "=======================================================\n";
echo "   VERIFIKASI AKHIR (ROBUSTNESS CHECK)\n";
echo "=======================================================\n\n";

$stillMismatch = [];
$kartuStokMismatch = [];
$allProducts = Produk::all();

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
    
    $expected = intval($stokAwal) + intval($totalMasuk) - intval($totalKeluar);
    if ($expected < 0) $expected = 0;
    
    $actual = intval($produk->fresh()->stok);
    
    if ($expected !== $actual) {
        $stillMismatch[] = [
            'nama' => $produk->nama_produk,
            'expected' => $expected,
            'actual' => $actual,
            'diff' => $actual - $expected
        ];
    }
    
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman) {
        if (intval($lastRekaman->stok_sisa) !== intval($produk->fresh()->stok)) {
            $kartuStokMismatch[] = [
                'nama' => $produk->nama_produk,
                'stok_produk' => intval($produk->fresh()->stok),
                'stok_rekaman' => intval($lastRekaman->stok_sisa),
                'diff' => intval($produk->fresh()->stok) - intval($lastRekaman->stok_sisa)
            ];
        }
    }
}

echo "1. VERIFIKASI PERHITUNGAN STOK:\n";
if (empty($stillMismatch)) {
    echo "   [SUCCESS] Semua stok produk 100% sesuai dengan formula:\n";
    echo "             Stok Awal (31 Des) + Masuk - Keluar = Stok Sekarang\n";
    echo "   Tidak ada selisih 1 digit pun!\n\n";
} else {
    echo "   [WARNING] " . count($stillMismatch) . " produk masih tidak sesuai perhitungan:\n";
    foreach (array_slice($stillMismatch, 0, 10) as $m) {
        echo "   - {$m['nama']}: expected={$m['expected']}, actual={$m['actual']}, diff={$m['diff']}\n";
    }
    echo "\n";
}

echo "2. VERIFIKASI SINKRONISASI KARTU STOK:\n";
if (empty($kartuStokMismatch)) {
    echo "   [SUCCESS] Semua stok produk 100% sinkron dengan kartu stok!\n";
    echo "   produk.stok = rekaman_stoks.stok_sisa (terakhir)\n\n";
} else {
    echo "   [WARNING] " . count($kartuStokMismatch) . " produk kartu stok tidak sinkron:\n";
    foreach (array_slice($kartuStokMismatch, 0, 10) as $m) {
        echo "   - {$m['nama']}: produk={$m['stok_produk']}, rekaman={$m['stok_rekaman']}, diff={$m['diff']}\n";
    }
    echo "\n";
}

$overallSuccess = empty($stillMismatch) && empty($kartuStokMismatch);

echo "=======================================================\n";
if ($overallSuccess) {
    echo "   RECOVERY BERHASIL 100% - SISTEM ROBUST\n";
} else {
    echo "   RECOVERY SELESAI DENGAN PERINGATAN\n";
}
echo "=======================================================\n\n";

echo "RINGKASAN AKHIR:\n";
echo "  - Total produk: " . count($allProducts) . "\n";
echo "  - Produk diperbaiki: {$totalFixed}\n";
echo "  - Verifikasi kalkulasi: " . (empty($stillMismatch) ? "LULUS" : "GAGAL") . "\n";
echo "  - Verifikasi kartu stok: " . (empty($kartuStokMismatch) ? "LULUS" : "GAGAL") . "\n\n";

echo "SELESAI pada: " . date('Y-m-d H:i:s') . "\n";
