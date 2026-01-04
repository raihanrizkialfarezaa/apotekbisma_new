<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;

echo "=======================================================\n";
echo "   ANALYSIS: STOCK DISCREPANCY REPORT 2026\n";
echo "   Tanggal: " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

set_time_limit(600);
ini_set('memory_limit', '1G');

$cutoffDate = '2025-12-31 23:59:59';
$endDate2026 = '2026-01-04 23:59:59';

echo "PARAMETER:\n";
echo "  - Cutoff Date: {$cutoffDate}\n";
echo "  - End Date: {$endDate2026}\n\n";

echo "=======================================================\n";
echo "   1. STATISTIK REKAMAN STOK\n";
echo "=======================================================\n\n";

$totalRekaman = DB::table('rekaman_stoks')->count();
$rekamanSebelumCutoff = DB::table('rekaman_stoks')->where('waktu', '<=', $cutoffDate)->count();
$rekamanSetelahCutoff = DB::table('rekaman_stoks')->where('waktu', '>', $cutoffDate)->count();

echo "Total rekaman stok: {$totalRekaman}\n";
echo "Rekaman sebelum/= cutoff: {$rekamanSebelumCutoff}\n";
echo "Rekaman setelah cutoff: {$rekamanSetelahCutoff}\n\n";

$opnameRecords = DB::table('rekaman_stoks')
    ->where('waktu', '>=', '2025-12-30 00:00:00')
    ->where('waktu', '<=', $cutoffDate)
    ->whereNull('id_penjualan')
    ->whereNull('id_pembelian')
    ->where(function($q) {
        $q->where('keterangan', 'LIKE', '%Stock Opname%')
          ->orWhere('keterangan', 'LIKE', '%Update Stok Manual%')
          ->orWhere('keterangan', 'LIKE', '%Perubahan Stok Manual%')
          ->orWhere('keterangan', 'LIKE', '%Penyesuaian%');
    })
    ->count();

echo "Rekaman opname pada 30-31 Des 2025: {$opnameRecords}\n\n";

echo "=======================================================\n";
echo "   2. STATISTIK TRANSAKSI 2026\n";
echo "=======================================================\n\n";

$penjualanCount = DB::table('penjualan')
    ->where('waktu', '>', $cutoffDate)
    ->where('waktu', '<=', $endDate2026)
    ->count();

$penjualanDetailCount = DB::table('penjualan_detail')
    ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
    ->where('penjualan.waktu', '>', $cutoffDate)
    ->where('penjualan.waktu', '<=', $endDate2026)
    ->count();

$totalItemKeluar = DB::table('penjualan_detail')
    ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
    ->where('penjualan.waktu', '>', $cutoffDate)
    ->where('penjualan.waktu', '<=', $endDate2026)
    ->sum('penjualan_detail.jumlah');

echo "Transaksi penjualan: {$penjualanCount}\n";
echo "Detail penjualan: {$penjualanDetailCount}\n";
echo "Total item keluar: {$totalItemKeluar}\n\n";

$pembelianCount = DB::table('pembelian')
    ->where('waktu', '>', $cutoffDate)
    ->where('waktu', '<=', $endDate2026)
    ->count();

$pembelianDetailCount = DB::table('pembelian_detail')
    ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
    ->where('pembelian.waktu', '>', $cutoffDate)
    ->where('pembelian.waktu', '<=', $endDate2026)
    ->count();

$totalItemMasuk = DB::table('pembelian_detail')
    ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
    ->where('pembelian.waktu', '>', $cutoffDate)
    ->where('pembelian.waktu', '<=', $endDate2026)
    ->sum('pembelian_detail.jumlah');

echo "Transaksi pembelian: {$pembelianCount}\n";
echo "Detail pembelian: {$pembelianDetailCount}\n";
echo "Total item masuk: {$totalItemMasuk}\n\n";

echo "=======================================================\n";
echo "   3. ANALISIS DISCREPANCY PER PRODUK\n";
echo "=======================================================\n\n";

$allProducts = Produk::orderBy('nama_produk')->get();
$hasilKalkulasi = [];

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
        $stokAwal = intval($opnameRecord->stok_sisa);
        $source = 'OPNAME';
    } else {
        $lastRecord = DB::table('rekaman_stoks')
            ->where('id_produk', $produk->id_produk)
            ->where('waktu', '<=', $cutoffDate)
            ->orderBy('waktu', 'desc')
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();
        
        if ($lastRecord) {
            $stokAwal = intval($lastRecord->stok_sisa);
            $source = 'LAST_RECORD';
        } else {
            $stokAwal = 0;
            $source = 'NO_RECORD';
        }
    }
    
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
    
    $stokSeharusnya = intval($stokAwal) + intval($totalMasuk) - intval($totalKeluar);
    if ($stokSeharusnya < 0) $stokSeharusnya = 0;
    
    $stokSekarang = intval($produk->stok);
    $selisih = $stokSekarang - $stokSeharusnya;
    
    $hasilKalkulasi[$produk->id_produk] = [
        'id' => $produk->id_produk,
        'nama' => $produk->nama_produk,
        'source' => $source,
        'stok_awal' => $stokAwal,
        'masuk' => intval($totalMasuk),
        'keluar' => intval($totalKeluar),
        'seharusnya' => $stokSeharusnya,
        'sekarang' => $stokSekarang,
        'selisih' => $selisih
    ];
}

$produkBermasalah = array_filter($hasilKalkulasi, fn($h) => $h['selisih'] !== 0);
$produkSync = count($hasilKalkulasi) - count($produkBermasalah);

usort($produkBermasalah, fn($a, $b) => abs($b['selisih']) - abs($a['selisih']));

echo "RINGKASAN:\n";
echo "  - Total produk: " . count($hasilKalkulasi) . "\n";
echo "  - Produk SYNC (selisih=0): {$produkSync}\n";
echo "  - Produk BERMASALAH: " . count($produkBermasalah) . "\n\n";

if (count($produkBermasalah) > 0) {
    echo "=======================================================\n";
    echo "   DAFTAR PRODUK BERMASALAH (sorted by selisih terbesar)\n";
    echo "=======================================================\n\n";
    
    echo str_repeat("-", 120) . "\n";
    printf("%-5s | %-35s | %-10s | %8s | %6s | %6s | %10s | %10s | %8s\n", 
        "ID", "NAMA PRODUK", "SOURCE", "STOK AWL", "MASUK", "KELUAR", "SEHARUSNYA", "SEKARANG", "SELISIH");
    echo str_repeat("-", 120) . "\n";
    
    foreach ($produkBermasalah as $h) {
        $status = $h['selisih'] < 0 ? "KURANG" : "LEBIH";
        printf("%-5d | %-35s | %-10s | %8d | %6d | %6d | %10d | %10d | %+8d\n",
            $h['id'],
            substr($h['nama'], 0, 35),
            $h['source'],
            $h['stok_awal'],
            $h['masuk'],
            $h['keluar'],
            $h['seharusnya'],
            $h['sekarang'],
            $h['selisih']
        );
    }
    echo str_repeat("-", 120) . "\n";
    
    $totalSelisihLebih = array_sum(array_map(fn($h) => $h['selisih'] > 0 ? $h['selisih'] : 0, $produkBermasalah));
    $totalSelisihKurang = abs(array_sum(array_map(fn($h) => $h['selisih'] < 0 ? $h['selisih'] : 0, $produkBermasalah)));
    
    echo "\nRINGKASAN SELISIH:\n";
    echo "  - Total stok LEBIH: +" . $totalSelisihLebih . "\n";
    echo "  - Total stok KURANG: -" . $totalSelisihKurang . "\n";
}

echo "\n=======================================================\n";
echo "   4. ANALISIS KARTU STOK (REKAMAN_STOKS)\n";
echo "=======================================================\n\n";

$kartuStokMismatch = [];

foreach ($allProducts as $produk) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman) {
        if (intval($lastRekaman->stok_sisa) !== intval($produk->stok)) {
            $kartuStokMismatch[] = [
                'nama' => $produk->nama_produk,
                'stok_produk' => intval($produk->stok),
                'stok_rekaman' => intval($lastRekaman->stok_sisa),
                'selisih' => intval($produk->stok) - intval($lastRekaman->stok_sisa)
            ];
        }
    }
}

echo "Produk dengan kartu stok tidak sinkron: " . count($kartuStokMismatch) . "\n\n";

if (count($kartuStokMismatch) > 0 && count($kartuStokMismatch) <= 30) {
    foreach ($kartuStokMismatch as $m) {
        echo "  - {$m['nama']}: produk={$m['stok_produk']}, rekaman={$m['stok_rekaman']}, selisih={$m['selisih']}\n";
    }
}

echo "\n=======================================================\n";
echo "   5. REKOMENDASI\n";
echo "=======================================================\n\n";

if (count($produkBermasalah) > 0) {
    echo "TINDAKAN YANG DIPERLUKAN:\n";
    echo "1. Jalankan script recovery: php stock_recovery_2026.php\n";
    echo "2. Script akan meminta konfirmasi sebelum melakukan update.\n";
    echo "3. Backup database terlebih dahulu sebelum menjalankan recovery.\n\n";
    
    echo "COMMAND BACKUP (MySQL):\n";
    echo "  mysqldump -u [user] -p [database] > backup_" . date('Ymd_His') . ".sql\n";
} else {
    echo "TIDAK ADA TINDAKAN DIPERLUKAN.\n";
    echo "Semua stok sudah sesuai perhitungan.\n";
}

echo "\n=======================================================\n";
echo "   ANALISIS SELESAI\n";
echo "=======================================================\n";
