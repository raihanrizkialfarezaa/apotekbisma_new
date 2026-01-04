<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\RekamanStok;

echo "=======================================================\n";
echo "   STOCK RECOVERY & RECALCULATION SCRIPT 2026\n";
echo "   Tanggal Eksekusi: " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

set_time_limit(3600);
ini_set('memory_limit', '2G');

$cutoffDate = '2025-12-31 23:59:59';
$startDate2026 = '2026-01-01 00:00:00';
$endDate2026 = '2026-01-04 23:59:59';

echo "PARAMETER:\n";
echo "  - Cutoff Date (Opname Valid): {$cutoffDate}\n";
echo "  - Periode Transaksi 2026: {$startDate2026} s/d {$endDate2026}\n\n";

echo "=======================================================\n";
echo "   LANGKAH A: MENCARI ANCHOR POINT (STOK AWAL VALID)\n";
echo "=======================================================\n\n";

$stokAwalValid = [];
$detailOpname = [];

$allProducts = Produk::orderBy('nama_produk')->get();
echo "Total produk di database: " . count($allProducts) . "\n\n";

echo "Mencari rekaman 'Update Stok Manual' pada 30-31 Desember 2025...\n";

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
        $detailOpname[$produk->id_produk] = [
            'nama' => $produk->nama_produk,
            'stok_opname' => intval($opnameRecord->stok_sisa),
            'waktu_opname' => $opnameRecord->waktu,
            'keterangan' => $opnameRecord->keterangan,
            'source' => 'OPNAME'
        ];
    } else {
        $lastRecord = DB::table('rekaman_stoks')
            ->where('id_produk', $produk->id_produk)
            ->where('waktu', '<=', $cutoffDate)
            ->orderBy('waktu', 'desc')
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();
        
        if ($lastRecord) {
            $stokAwalValid[$produk->id_produk] = intval($lastRecord->stok_sisa);
            $detailOpname[$produk->id_produk] = [
                'nama' => $produk->nama_produk,
                'stok_opname' => intval($lastRecord->stok_sisa),
                'waktu_opname' => $lastRecord->waktu,
                'keterangan' => $lastRecord->keterangan,
                'source' => 'LAST_RECORD'
            ];
        } else {
            $stokAwalValid[$produk->id_produk] = 0;
            $detailOpname[$produk->id_produk] = [
                'nama' => $produk->nama_produk,
                'stok_opname' => 0,
                'waktu_opname' => null,
                'keterangan' => 'Tidak ada rekaman',
                'source' => 'NO_RECORD'
            ];
        }
    }
}

$fromOpname = count(array_filter($detailOpname, fn($d) => $d['source'] === 'OPNAME'));
$fromLastRecord = count(array_filter($detailOpname, fn($d) => $d['source'] === 'LAST_RECORD'));
$noRecord = count(array_filter($detailOpname, fn($d) => $d['source'] === 'NO_RECORD'));

echo "\n[LANGKAH A - HASIL]\n";
echo "  - Produk dengan rekaman opname 30-31 Des: {$fromOpname}\n";
echo "  - Produk dengan rekaman terakhir sebelum cutoff: {$fromLastRecord}\n";
echo "  - Produk tanpa rekaman sama sekali: {$noRecord}\n\n";

echo "Contoh stok awal valid (20 pertama):\n";
$counter = 0;
foreach ($detailOpname as $id => $d) {
    if ($counter >= 20) break;
    echo "  - [{$d['source']}] {$d['nama']}: {$d['stok_opname']} (waktu: {$d['waktu_opname']})\n";
    $counter++;
}

echo "\n=======================================================\n";
echo "   LANGKAH B: REKALKULASI TRANSAKSI 2026\n";
echo "=======================================================\n\n";

$hasilKalkulasi = [];

foreach ($allProducts as $produk) {
    $stokAwal = $stokAwalValid[$produk->id_produk] ?? 0;
    
    $totalMasuk = DB::table('pembelian_detail')
        ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
        ->where('pembelian.waktu', '>', $cutoffDate)
        ->where('pembelian.waktu', '<=', $endDate2026)
        ->where('pembelian_detail.id_produk', $produk->id_produk)
        ->sum('pembelian_detail.jumlah');
    
    $totalKeluar = DB::table('penjualan_detail')
        ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
        ->where('penjualan.waktu', '>', $cutoffDate)
        ->where('penjualan.waktu', '<=', $endDate2026)
        ->where('penjualan_detail.id_produk', $produk->id_produk)
        ->sum('penjualan_detail.jumlah');
    
    $stokAkhirSeharusnya = intval($stokAwal) + intval($totalMasuk) - intval($totalKeluar);
    if ($stokAkhirSeharusnya < 0) $stokAkhirSeharusnya = 0;
    
    $stokSekarang = intval($produk->stok);
    $selisih = $stokSekarang - $stokAkhirSeharusnya;
    
    $hasilKalkulasi[$produk->id_produk] = [
        'nama' => $produk->nama_produk,
        'stok_awal_valid' => $stokAwal,
        'total_masuk' => intval($totalMasuk),
        'total_keluar' => intval($totalKeluar),
        'stok_akhir_seharusnya' => $stokAkhirSeharusnya,
        'stok_sekarang' => $stokSekarang,
        'selisih' => $selisih
    ];
}

$produkBermasalah = array_filter($hasilKalkulasi, fn($h) => $h['selisih'] !== 0);
usort($produkBermasalah, fn($a, $b) => abs($b['selisih']) - abs($a['selisih']));

echo "[LANGKAH B - HASIL]\n";
echo "  - Total produk dikalkukasi: " . count($hasilKalkulasi) . "\n";
echo "  - Produk SYNC (selisih=0): " . (count($hasilKalkulasi) - count($produkBermasalah)) . "\n";
echo "  - Produk BERMASALAH (selisih!=0): " . count($produkBermasalah) . "\n\n";

if (count($produkBermasalah) > 0) {
    echo "DAFTAR PRODUK BERMASALAH (sorted by selisih terbesar):\n";
    echo str_repeat("-", 100) . "\n";
    printf("%-40s | %10s | %8s | %8s | %10s | %10s | %8s\n", 
        "NAMA PRODUK", "STOK AWAL", "MASUK", "KELUAR", "SEHARUSNYA", "SEKARANG", "SELISIH");
    echo str_repeat("-", 100) . "\n";
    
    foreach (array_slice($produkBermasalah, 0, 50, true) as $h) {
        $status = $h['selisih'] < 0 ? "KURANG" : "LEBIH";
        printf("%-40s | %10d | %8d | %8d | %10d | %10d | %+8d (%s)\n",
            substr($h['nama'], 0, 40),
            $h['stok_awal_valid'],
            $h['total_masuk'],
            $h['total_keluar'],
            $h['stok_akhir_seharusnya'],
            $h['stok_sekarang'],
            $h['selisih'],
            $status
        );
    }
    echo str_repeat("-", 100) . "\n";
}

echo "\n=======================================================\n";
echo "   LANGKAH C: EKSEKUSI UPDATE & SINKRONISASI\n";
echo "=======================================================\n\n";

echo "APAKAH ANDA INGIN MELANJUTKAN UPDATE DATABASE? (Y/N): ";
$input = trim(strtoupper(fgets(STDIN)));

if ($input !== 'Y') {
    echo "\n[DIBATALKAN] Script dihentikan oleh pengguna.\n";
    exit(0);
}

echo "\nMemulai proses update...\n";

DB::beginTransaction();

try {
    $totalFixed = 0;
    $totalUnchanged = 0;
    $totalRebuild = 0;
    
    foreach ($hasilKalkulasi as $produkId => $h) {
        if ($h['selisih'] !== 0) {
            DB::table('produk')
                ->where('id_produk', $produkId)
                ->update(['stok' => $h['stok_akhir_seharusnya']]);
            
            $totalFixed++;
            echo "  [FIXED] {$h['nama']}: {$h['stok_sekarang']} -> {$h['stok_akhir_seharusnya']}\n";
        } else {
            $totalUnchanged++;
        }
    }
    
    echo "\nMembangun ulang kartu stok (rekaman_stoks)...\n";
    
    $productsToRebuild = array_keys(array_filter($hasilKalkulasi, fn($h) => $h['selisih'] !== 0));
    
    foreach ($productsToRebuild as $produkId) {
        DB::table('rekaman_stoks')
            ->where('id_produk', $produkId)
            ->where('waktu', '>', $cutoffDate)
            ->delete();
        
        $stokAwal = $stokAwalValid[$produkId];
        $runningStock = $stokAwal;
        
        $pembelian2026 = DB::table('pembelian_detail')
            ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
            ->where('pembelian.waktu', '>', $cutoffDate)
            ->where('pembelian.waktu', '<=', $endDate2026)
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
        
        $penjualan2026 = DB::table('penjualan_detail')
            ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
            ->where('penjualan.waktu', '>', $cutoffDate)
            ->where('penjualan.waktu', '<=', $endDate2026)
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
        
        $allTrans = [];
        
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
        }
        
        $totalRebuild++;
        
        if ($totalRebuild % 50 === 0) {
            echo "  Rebuilt rekaman stok: {$totalRebuild}/" . count($productsToRebuild) . "\n";
        }
    }
    
    DB::commit();
    
    echo "\n=======================================================\n";
    echo "   HASIL EKSEKUSI\n";
    echo "=======================================================\n";
    echo "  - Produk FIXED (stok diupdate): {$totalFixed}\n";
    echo "  - Produk UNCHANGED: {$totalUnchanged}\n";
    echo "  - Rekaman stok REBUILT: {$totalRebuild}\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "\n[ERROR FATAL] " . $e->getMessage() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Transaksi di-rollback.\n";
    exit(1);
}

echo "\n=======================================================\n";
echo "   VERIFIKASI AKHIR\n";
echo "=======================================================\n\n";

$stillMismatch = [];
$allProducts = Produk::all();

foreach ($allProducts as $produk) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman) {
        $stokRekaman = intval($lastRekaman->stok_sisa);
        $stokProduk = intval($produk->stok);
        
        if ($stokRekaman !== $stokProduk) {
            $stillMismatch[] = [
                'nama' => $produk->nama_produk,
                'stok_produk' => $stokProduk,
                'stok_rekaman' => $stokRekaman,
                'selisih' => $stokProduk - $stokRekaman
            ];
        }
    }
}

if (empty($stillMismatch)) {
    echo "[SUCCESS] Semua stok produk 100% SINKRON dengan kartu stok!\n";
    echo "Tidak ada selisih 1 digit pun.\n";
} else {
    echo "[WARNING] Masih ada " . count($stillMismatch) . " produk tidak sinkron:\n";
    foreach ($stillMismatch as $m) {
        echo "  - {$m['nama']}: produk={$m['stok_produk']}, rekaman={$m['stok_rekaman']}, selisih={$m['selisih']}\n";
    }
}

echo "\n=======================================================\n";
echo "   PROSES SELESAI\n";
echo "=======================================================\n";
