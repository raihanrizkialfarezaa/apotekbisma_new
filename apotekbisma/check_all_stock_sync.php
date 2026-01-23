<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "\n";
echo "=============================================================\n";
echo "  CEK SINKRONISASI STOK: PRODUK vs REKAMAN_STOKS\n";
echo "=============================================================\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

echo "Mengambil semua produk...\n";
$produkList = DB::table('produk')->get();
echo "Total produk: {$produkList->count()}\n\n";

echo "Memeriksa sinkronisasi stok...\n\n";

$tidakSinkron = [];
$sinkron = 0;
$tidakAdaRekaman = 0;

foreach ($produkList as $produk) {
    // Get rekaman terakhir
    $rekamanTerakhir = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if (!$rekamanTerakhir) {
        $tidakAdaRekaman++;
        continue;
    }
    
    // Bandingkan stok
    if ($produk->stok != $rekamanTerakhir->stok_sisa) {
        $tidakSinkron[] = [
            'id_produk' => $produk->id_produk,
            'kode_produk' => $produk->kode_produk,
            'nama_produk' => $produk->nama_produk,
            'stok_produk' => $produk->stok,
            'stok_rekaman' => $rekamanTerakhir->stok_sisa,
            'selisih' => $rekamanTerakhir->stok_sisa - $produk->stok,
            'waktu_rekaman' => $rekamanTerakhir->waktu,
            'keterangan' => $rekamanTerakhir->keterangan,
        ];
    } else {
        $sinkron++;
    }
}

echo "═══════════════════════════════════════════════════════════\n";
echo "HASIL PEMERIKSAAN:\n";
echo "═══════════════════════════════════════════════════════════\n\n";

echo "✅ Produk SINKRON         : {$sinkron}\n";
echo "❌ Produk TIDAK SINKRON   : " . count($tidakSinkron) . "\n";
echo "⚠️  Produk tanpa rekaman  : {$tidakAdaRekaman}\n\n";

if (count($tidakSinkron) > 0) {
    echo "═══════════════════════════════════════════════════════════\n";
    echo "PRODUK YANG TIDAK SINKRON:\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    echo str_pad("ID", 6) . " | " . 
         str_pad("KODE", 12) . " | " . 
         str_pad("NAMA PRODUK", 35) . " | " . 
         str_pad("STOK", 6) . " | " . 
         str_pad("REKAMAN", 7) . " | " . 
         str_pad("SELISIH", 8) . "\n";
    echo str_repeat("-", 95) . "\n";
    
    foreach ($tidakSinkron as $item) {
        $kode = $item['kode_produk'] ?: '-';
        $nama = strlen($item['nama_produk']) > 35 ? substr($item['nama_produk'], 0, 32) . '...' : $item['nama_produk'];
        
        echo str_pad($item['id_produk'], 6) . " | " . 
             str_pad($kode, 12) . " | " . 
             str_pad($nama, 35) . " | " . 
             str_pad($item['stok_produk'], 6) . " | " . 
             str_pad($item['stok_rekaman'], 7) . " | " . 
             str_pad($item['selisih'] > 0 ? "+{$item['selisih']}" : $item['selisih'], 8) . "\n";
    }
    
    echo "\n═══════════════════════════════════════════════════════════\n";
    echo "DETAIL 10 TERATAS:\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    $top10 = array_slice($tidakSinkron, 0, 10);
    
    foreach ($top10 as $idx => $item) {
        echo "─────────────────────────────────────────────────────────\n";
        echo "#{$idx}. {$item['nama_produk']}\n";
        echo "─────────────────────────────────────────────────────────\n";
        echo "  ID Produk       : {$item['id_produk']}\n";
        echo "  Kode Produk     : " . ($item['kode_produk'] ?: '(kosong)') . "\n";
        echo "  🔴 Stok Produk  : {$item['stok_produk']}\n";
        echo "  🟢 Stok Rekaman : {$item['stok_rekaman']}\n";
        echo "  ⚠️  Selisih     : " . ($item['selisih'] > 0 ? "+{$item['selisih']}" : $item['selisih']) . "\n";
        echo "  Waktu Rekaman   : {$item['waktu_rekaman']}\n";
        echo "  Keterangan      : " . (strlen($item['keterangan']) > 60 ? substr($item['keterangan'], 0, 57) . '...' : $item['keterangan']) . "\n\n";
    }
    
    // Analisis pola
    echo "═══════════════════════════════════════════════════════════\n";
    echo "ANALISIS POLA:\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    $stokOpname = 0;
    $penjualan = 0;
    $pembelian = 0;
    $lainnya = 0;
    
    foreach ($tidakSinkron as $item) {
        if (stripos($item['keterangan'], 'opname') !== false || 
            stripos($item['keterangan'], 'baseline') !== false) {
            $stokOpname++;
        } elseif (stripos($item['keterangan'], 'penjualan') !== false) {
            $penjualan++;
        } elseif (stripos($item['keterangan'], 'pembelian') !== false) {
            $pembelian++;
        } else {
            $lainnya++;
        }
    }
    
    echo "Rekaman terakhir berdasarkan jenis:\n";
    echo "  - Stock Opname    : {$stokOpname}\n";
    echo "  - Penjualan       : {$penjualan}\n";
    echo "  - Pembelian       : {$pembelian}\n";
    echo "  - Lainnya         : {$lainnya}\n\n";
    
    echo "═══════════════════════════════════════════════════════════\n";
    echo "REKOMENDASI FIX:\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    if ($stokOpname > 0) {
        echo "⚠️  PERHATIAN: Ada {$stokOpname} produk dengan rekaman terakhir Stock Opname\n";
        echo "   yang tidak sinkron. Ini mirip kasus BODREX!\n\n";
    }
    
    echo "Untuk fix semua produk yang tidak sinkron, buat script:\n";
    echo "  php fix_all_stock_sync.php\n\n";
    
    echo "Atau lihat detail per produk dulu untuk verifikasi manual.\n\n";
    
    // Save to file
    $logFile = 'stock_sync_report_' . date('Ymd_His') . '.json';
    file_put_contents($logFile, json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'total_produk' => $produkList->count(),
        'sinkron' => $sinkron,
        'tidak_sinkron' => count($tidakSinkron),
        'tanpa_rekaman' => $tidakAdaRekaman,
        'detail' => $tidakSinkron
    ], JSON_PRETTY_PRINT));
    
    echo "📄 Laporan detail disimpan ke: {$logFile}\n\n";
    
} else {
    echo "🎉 SEMUA PRODUK SUDAH SINKRON!\n";
    echo "   Tidak ada masalah seperti BODREX di produk lain.\n\n";
}

echo "=============================================================\n";
echo "Pemeriksaan selesai: " . date('Y-m-d H:i:s') . "\n";
echo "=============================================================\n\n";
