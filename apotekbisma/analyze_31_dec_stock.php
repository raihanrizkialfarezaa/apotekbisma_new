<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;

echo "=======================================================\n";
echo "   ANALISIS DATA STOCK OPNAME 31 DESEMBER 2025\n";
echo "   Tanggal: " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

$cutoffDate = '2025-12-31 23:00:00';

echo "CUTOFF DATE: {$cutoffDate}\n\n";

echo "1. REKAMAN STOCK OPNAME/MANUAL PADA 31 DES 2025:\n";
echo "------------------------------------------------\n";

$stockOpnameRecords = DB::table('rekaman_stoks')
    ->where('waktu', '<=', $cutoffDate)
    ->where('waktu', '>=', '2025-12-31 00:00:00')
    ->where(function($q) {
        $q->where('keterangan', 'LIKE', '%Stock Opname%')
          ->orWhere('keterangan', 'LIKE', '%Perubahan Stok Manual%')
          ->orWhere('keterangan', 'LIKE', '%Penyesuaian%')
          ->orWhere('keterangan', 'LIKE', '%Manual%');
    })
    ->orderBy('id_produk')
    ->get();

echo "Jumlah rekaman stock opname pada 31 Des 2025: " . count($stockOpnameRecords) . "\n\n";

if (count($stockOpnameRecords) > 0) {
    echo "Contoh rekaman (10 pertama):\n";
    foreach ($stockOpnameRecords->take(10) as $r) {
        $produk = Produk::find($r->id_produk);
        echo "  - " . ($produk ? $produk->nama_produk : "ID {$r->id_produk}") . "\n";
        echo "    Waktu: {$r->waktu}, Stok Sisa: {$r->stok_sisa}\n";
        echo "    Keterangan: {$r->keterangan}\n\n";
    }
}

echo "\n2. SEMUA REKAMAN STOK PADA 31 DES 2025 (per jenis):\n";
echo "---------------------------------------------------\n";

$allRecords31Dec = DB::table('rekaman_stoks')
    ->where('waktu', '<=', $cutoffDate)
    ->where('waktu', '>=', '2025-12-31 00:00:00')
    ->get();

$penjualan31 = $allRecords31Dec->whereNotNull('id_penjualan')->count();
$pembelian31 = $allRecords31Dec->whereNotNull('id_pembelian')->count();
$manual31 = $allRecords31Dec->whereNull('id_penjualan')->whereNull('id_pembelian')->count();

echo "  - Total rekaman: " . count($allRecords31Dec) . "\n";
echo "  - Penjualan: {$penjualan31}\n";
echo "  - Pembelian: {$pembelian31}\n";
echo "  - Manual/Adjustment: {$manual31}\n";

echo "\n3. TRANSAKSI SETELAH CUTOFF (1 Jan 2026 dst):\n";
echo "----------------------------------------------\n";

$afterCutoff = DB::table('rekaman_stoks')
    ->where('waktu', '>', $cutoffDate)
    ->get();

$penjualanAfter = $afterCutoff->whereNotNull('id_penjualan')->count();
$pembelianAfter = $afterCutoff->whereNotNull('id_pembelian')->count();
$manualAfter = $afterCutoff->whereNull('id_penjualan')->whereNull('id_pembelian')->count();

echo "  - Total rekaman: " . count($afterCutoff) . "\n";
echo "  - Penjualan: {$penjualanAfter}\n";
echo "  - Pembelian: {$pembelianAfter}\n";
echo "  - Manual/Adjustment: {$manualAfter}\n";

echo "\n4. STOK TERAKHIR PADA 31 DES 2025 PER PRODUK:\n";
echo "----------------------------------------------\n";
echo "(Ini adalah stok yang VALID setelah stock opname)\n\n";

$produkList = Produk::orderBy('nama_produk')->get();
$stokPer31Des = [];
$produkTampil = 0;

foreach ($produkList as $produk) {
    $lastRecordBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->where('waktu', '<=', $cutoffDate)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if ($lastRecordBefore) {
        $stokPer31Des[$produk->id_produk] = [
            'nama' => $produk->nama_produk,
            'stok_31_des' => intval($lastRecordBefore->stok_sisa),
            'stok_sekarang' => intval($produk->stok),
            'waktu_terakhir' => $lastRecordBefore->waktu
        ];
        
        if ($produkTampil < 20) {
            echo "  - {$produk->nama_produk}\n";
            echo "    Stok 31 Des: {$lastRecordBefore->stok_sisa}, Stok Sekarang: {$produk->stok}\n";
            $produkTampil++;
        }
    }
}

echo "\n  ... dan " . (count($stokPer31Des) - 20) . " produk lainnya\n";

echo "\n5. PRODUK YANG STOKNYA BERBEDA (31 Des vs Sekarang):\n";
echo "----------------------------------------------------\n";

$berbeda = [];
foreach ($stokPer31Des as $id => $data) {
    if ($data['stok_31_des'] != $data['stok_sekarang']) {
        $transaksiSetelah = DB::table('rekaman_stoks')
            ->where('id_produk', $id)
            ->where('waktu', '>', $cutoffDate)
            ->get();
        
        $masuk = $transaksiSetelah->sum('stok_masuk');
        $keluar = $transaksiSetelah->sum('stok_keluar');
        $expectedNow = $data['stok_31_des'] + $masuk - $keluar;
        
        if ($expectedNow < 0) $expectedNow = 0;
        
        $berbeda[] = [
            'nama' => $data['nama'],
            'stok_31_des' => $data['stok_31_des'],
            'masuk_setelah' => $masuk,
            'keluar_setelah' => $keluar,
            'expected' => $expectedNow,
            'stok_sekarang' => $data['stok_sekarang'],
            'selisih' => $data['stok_sekarang'] - $expectedNow
        ];
    }
}

echo "Jumlah produk dengan stok berbeda: " . count($berbeda) . "\n\n";

if (count($berbeda) > 0) {
    echo "Detail (20 pertama, sorted by selisih):\n";
    usort($berbeda, function($a, $b) {
        return abs($b['selisih']) - abs($a['selisih']);
    });
    
    foreach (array_slice($berbeda, 0, 20) as $b) {
        echo "  - {$b['nama']}\n";
        echo "    Stok 31 Des: {$b['stok_31_des']}\n";
        echo "    + Masuk setelah: {$b['masuk_setelah']}\n";
        echo "    - Keluar setelah: {$b['keluar_setelah']}\n";
        echo "    = Expected: {$b['expected']}\n";
        echo "    Stok Sekarang: {$b['stok_sekarang']}\n";
        echo "    SELISIH: {$b['selisih']} " . ($b['selisih'] < 0 ? "(KURANG)" : ($b['selisih'] > 0 ? "(LEBIH)" : "")) . "\n\n";
    }
}

echo "\n=======================================================\n";
echo "   RINGKASAN\n";
echo "=======================================================\n";
echo "Total produk dengan rekaman: " . count($stokPer31Des) . "\n";
echo "Produk dengan stok berbeda dari expected: " . count($berbeda) . "\n";
echo "\nJika Anda ingin restore ke nilai 31 Des + transaksi setelahnya,\n";
echo "jalankan: php restore_to_31_dec.php\n";
