<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\RekamanStok;

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║         LAPORAN STATUS SISTEM STOK - APOTEK BISMA            ║\n";
echo "║                    " . date('d F Y H:i:s') . "                   ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$errors = 0;
$warnings = 0;

echo "┌──────────────────────────────────────────────────────────────┐\n";
echo "│ 1. CEK DUPLIKAT REKAMAN STOK                                 │\n";
echo "└──────────────────────────────────────────────────────────────┘\n";

$dupPenjualan = DB::table('rekaman_stoks')
    ->select('id_produk', 'id_penjualan', DB::raw('COUNT(*) as cnt'))
    ->whereNotNull('id_penjualan')
    ->groupBy('id_produk', 'id_penjualan')
    ->having('cnt', '>', 1)
    ->count();

$dupPembelian = DB::table('rekaman_stoks')
    ->select('id_produk', 'id_pembelian', DB::raw('COUNT(*) as cnt'))
    ->whereNotNull('id_pembelian')
    ->groupBy('id_produk', 'id_pembelian')
    ->having('cnt', '>', 1)
    ->count();

if ($dupPenjualan == 0 && $dupPembelian == 0) {
    echo "  ✓ Tidak ada duplikat rekaman stok\n";
} else {
    echo "  ✗ Ditemukan duplikat: Penjualan={$dupPenjualan}, Pembelian={$dupPembelian}\n";
    $errors += $dupPenjualan + $dupPembelian;
}

echo "\n┌──────────────────────────────────────────────────────────────┐\n";
echo "│ 2. CEK SINKRONISASI STOK PRODUK VS REKAMAN                   │\n";
echo "└──────────────────────────────────────────────────────────────┘\n";

$outOfSync = [];
$allProducts = Produk::orderBy('nama_produk')->get();

foreach ($allProducts as $produk) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRekaman && intval($produk->stok) != intval($lastRekaman->stok_sisa)) {
        $outOfSync[] = [
            'nama' => $produk->nama_produk,
            'stok_produk' => $produk->stok,
            'stok_rekaman' => $lastRekaman->stok_sisa
        ];
    }
}

if (empty($outOfSync)) {
    echo "  ✓ Semua stok produk sinkron dengan rekaman\n";
} else {
    echo "  ✗ " . count($outOfSync) . " produk tidak sinkron:\n";
    foreach (array_slice($outOfSync, 0, 5) as $item) {
        echo "    - {$item['nama']}: Produk={$item['stok_produk']}, Rekaman={$item['stok_rekaman']}\n";
    }
    if (count($outOfSync) > 5) {
        echo "    ... dan " . (count($outOfSync) - 5) . " lainnya\n";
    }
    $errors += count($outOfSync);
}

echo "\n┌──────────────────────────────────────────────────────────────┐\n";
echo "│ 3. CEK STOK NEGATIF                                          │\n";
echo "└──────────────────────────────────────────────────────────────┘\n";

$negativeStock = Produk::where('stok', '<', 0)->count();
$negativeRekaman = DB::table('rekaman_stoks')->where('stok_sisa', '<', 0)->orWhere('stok_awal', '<', 0)->count();

if ($negativeStock == 0 && $negativeRekaman == 0) {
    echo "  ✓ Tidak ada stok negatif\n";
} else {
    echo "  ✗ Stok negatif: Produk={$negativeStock}, Rekaman={$negativeRekaman}\n";
    $errors += $negativeStock + $negativeRekaman;
}

echo "\n┌──────────────────────────────────────────────────────────────┐\n";
echo "│ 4. CEK FORMULA KALKULASI REKAMAN                             │\n";
echo "└──────────────────────────────────────────────────────────────┘\n";

$formulaErrors = 0;
$allRekaman = DB::table('rekaman_stoks')->get();

foreach ($allRekaman as $r) {
    $calc = intval($r->stok_awal) + intval($r->stok_masuk) - intval($r->stok_keluar);
    if ($calc != intval($r->stok_sisa)) {
        $formulaErrors++;
    }
}

if ($formulaErrors == 0) {
    echo "  ✓ Semua formula kalkulasi benar\n";
} else {
    echo "  ✗ Ditemukan {$formulaErrors} rekaman dengan formula salah\n";
    $errors += $formulaErrors;
}

echo "\n┌──────────────────────────────────────────────────────────────┐\n";
echo "│ 5. CEK MISMATCH REKAMAN VS DETAIL TRANSAKSI                  │\n";
echo "└──────────────────────────────────────────────────────────────┘\n";

$salesMismatch = 0;
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
        $salesMismatch++;
    }
}

$purchaseMismatch = 0;
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
        $purchaseMismatch++;
    }
}

if ($salesMismatch == 0 && $purchaseMismatch == 0) {
    echo "  ✓ Semua rekaman sesuai dengan detail transaksi\n";
} else {
    echo "  ⚠ Mismatch: Penjualan={$salesMismatch}, Pembelian={$purchaseMismatch}\n";
    $warnings += $salesMismatch + $purchaseMismatch;
}

echo "\n┌──────────────────────────────────────────────────────────────┐\n";
echo "│ 6. STATISTIK SISTEM                                          │\n";
echo "└──────────────────────────────────────────────────────────────┘\n";

$totalProduk = Produk::count();
$totalRekaman = RekamanStok::count();
$totalPenjualan = DB::table('penjualan')->count();
$totalPembelian = DB::table('pembelian')->count();
$produkDenganStok = Produk::where('stok', '>', 0)->count();
$produkStokHabis = Produk::where('stok', '<=', 0)->count();

echo "  Total Produk      : {$totalProduk}\n";
echo "  - Dengan Stok     : {$produkDenganStok}\n";
echo "  - Stok Habis      : {$produkStokHabis}\n";
echo "  Total Rekaman     : {$totalRekaman}\n";
echo "  Total Penjualan   : {$totalPenjualan}\n";
echo "  Total Pembelian   : {$totalPembelian}\n";

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo "║                      KESIMPULAN                              ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

if ($errors == 0 && $warnings == 0) {
    echo "  ╔════════════════════════════════════════════════════════╗\n";
    echo "  ║  ✓ SISTEM STOK DALAM KONDISI SEHAT DAN SINKRON         ║\n";
    echo "  ╚════════════════════════════════════════════════════════╝\n";
} elseif ($errors == 0 && $warnings > 0) {
    echo "  ╔════════════════════════════════════════════════════════╗\n";
    echo "  ║  ⚠ SISTEM STOK MEMILIKI {$warnings} PERINGATAN            ║\n";
    echo "  ║    Jalankan repair_stock_sync.php untuk memperbaiki    ║\n";
    echo "  ╚════════════════════════════════════════════════════════╝\n";
} else {
    echo "  ╔════════════════════════════════════════════════════════╗\n";
    echo "  ║  ✗ SISTEM STOK MEMILIKI {$errors} MASALAH                 ║\n";
    echo "  ║    SEGERA jalankan repair_stock_sync.php               ║\n";
    echo "  ╚════════════════════════════════════════════════════════╝\n";
}

echo "\n";
