<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "\n";
echo "=============================================================\n";
echo "  ANALISIS MENDALAM: KENAPA 497 PRODUK TIDAK SINKRON?\n";
echo "=============================================================\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// Load report untuk analisis
$reportFile = 'stock_sync_report_20260123_104119.json';
if (!file_exists($reportFile)) {
    echo "❌ Report file tidak ditemukan!\n\n";
    exit(1);
}

$report = json_decode(file_get_contents($reportFile), true);
$tidakSinkron = $report['detail'];

echo "Total produk yang tidak sinkron: " . count($tidakSinkron) . "\n\n";

echo "═══════════════════════════════════════════════════════════\n";
echo "ANALISIS 1: KAPAN STOCK OPNAME TERAKHIR DIBUAT?\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// Group by waktu rekaman
$byWaktu = [];
foreach ($tidakSinkron as $item) {
    $date = substr($item['waktu_rekaman'], 0, 10); // ambil tanggal saja
    if (!isset($byWaktu[$date])) {
        $byWaktu[$date] = 0;
    }
    $byWaktu[$date]++;
}

ksort($byWaktu);

echo "Distribusi waktu rekaman terakhir:\n\n";
foreach ($byWaktu as $date => $count) {
    echo "  {$date}: {$count} produk\n";
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "ANALISIS 2: JAM BERAPA STOCK OPNAME DIBUAT?\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// Check for stock opname records created today
$stockOpnameToday = DB::table('rekaman_stoks')
    ->where('keterangan', 'LIKE', '%Stock Opname Cutoff%')
    ->where('waktu', '>=', '2026-01-23 00:00:00')
    ->where('waktu', '<=', '2026-01-23 23:59:59')
    ->orderBy('waktu', 'asc')
    ->get(['id_rekaman_stok', 'id_produk', 'waktu', 'created_at', 'stok_masuk', 'stok_keluar', 'stok_awal', 'stok_sisa']);

echo "Stock Opname yang dibuat hari ini (23 Jan 2026): {$stockOpnameToday->count()} records\n\n";

if ($stockOpnameToday->count() > 0) {
    $first = $stockOpnameToday->first();
    $last = $stockOpnameToday->last();
    
    echo "Record pertama:\n";
    echo "  Waktu       : {$first->waktu}\n";
    echo "  Created At  : {$first->created_at}\n";
    echo "  ID Produk   : {$first->id_produk}\n\n";
    
    echo "Record terakhir:\n";
    echo "  Waktu       : {$last->waktu}\n";
    echo "  Created At  : {$last->created_at}\n";
    echo "  ID Produk   : {$last->id_produk}\n\n";
    
    // Distribusi per menit
    $byMinute = [];
    foreach ($stockOpnameToday as $r) {
        $minute = substr($r->waktu, 0, 16); // YYYY-MM-DD HH:MM
        if (!isset($byMinute[$minute])) {
            $byMinute[$minute] = 0;
        }
        $byMinute[$minute]++;
    }
    
    echo "Distribusi per menit (top 10):\n\n";
    arsort($byMinute);
    $top10 = array_slice($byMinute, 0, 10, true);
    
    foreach ($top10 as $minute => $count) {
        echo "  {$minute}: {$count} records\n";
    }
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "ANALISIS 3: APAKAH INI HASIL SCRIPT ATAU MANUAL INPUT?\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// Check created_at vs waktu
$createdAtAnalysis = DB::select("
    SELECT 
        CASE 
            WHEN created_at = waktu THEN 'Same (Real-time)'
            WHEN created_at = '2025-12-31 23:59:59' THEN 'Created at Cutoff Date'
            ELSE 'Different'
        END as pattern,
        COUNT(*) as total
    FROM rekaman_stoks
    WHERE keterangan LIKE '%Stock Opname Cutoff%'
    AND waktu >= '2026-01-23 00:00:00'
    GROUP BY pattern
");

echo "Pola created_at vs waktu:\n\n";
foreach ($createdAtAnalysis as $row) {
    echo "  {$row->pattern}: {$row->total} records\n";
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "ANALISIS 4: CEK APAKAH ADA SCRIPT create_so_baseline.php?\n";
echo "═══════════════════════════════════════════════════════════\n\n";

$scriptFiles = [
    'create_so_baseline.php',
    'create_baseline.php',
    'import_baseline.php',
    'restore_stock.php'
];

echo "Mencari script yang mungkin terkait...\n\n";
foreach ($scriptFiles as $file) {
    if (file_exists(__DIR__ . '/' . $file)) {
        echo "  ✅ FOUND: {$file}\n";
        $mtime = filemtime(__DIR__ . '/' . $file);
        echo "     Last Modified: " . date('Y-m-d H:i:s', $mtime) . "\n\n";
    } else {
        echo "  ❌ NOT FOUND: {$file}\n";
    }
}

echo "═══════════════════════════════════════════════════════════\n";
echo "ANALISIS 5: CEK BASELINE ASLI (31 DES 2025)\n";
echo "═══════════════════════════════════════════════════════════\n\n";

$baselineAsli = DB::table('rekaman_stoks')
    ->where('keterangan', 'LIKE', '%BASELINE_OPNAME_31DES2025%')
    ->count();

echo "Total baseline asli (BASELINE_OPNAME_31DES2025_V3): {$baselineAsli} records\n\n";

$duplikatBaseline = DB::table('rekaman_stoks')
    ->where('keterangan', 'LIKE', '%Stock Opname Cutoff 31 Desember 2025%')
    ->where('waktu', '>=', '2026-01-23 00:00:00')
    ->count();

echo "Total duplikat baseline (hari ini): {$duplikatBaseline} records\n\n";

echo "═══════════════════════════════════════════════════════════\n";
echo "ANALISIS 6: SAMPLE PRODUK DENGAN 2 BASELINE\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// Get sample produk dengan 2 baseline
$sampleProduk = DB::select("
    SELECT id_produk, COUNT(*) as total_baseline
    FROM rekaman_stoks
    WHERE (keterangan LIKE '%BASELINE_OPNAME_31DES2025%' 
           OR keterangan LIKE '%Stock Opname Cutoff 31 Desember 2025%')
    GROUP BY id_produk
    HAVING COUNT(*) >= 2
    LIMIT 5
");

if (count($sampleProduk) > 0) {
    echo "Produk dengan 2+ baseline: " . count($sampleProduk) . " (showing 5 samples)\n\n";
    
    foreach ($sampleProduk as $row) {
        $produk = DB::table('produk')->where('id_produk', $row->id_produk)->first();
        echo "Produk ID {$row->id_produk} ({$produk->nama_produk}):\n";
        
        $baselines = DB::table('rekaman_stoks')
            ->where('id_produk', $row->id_produk)
            ->where(function($q) {
                $q->where('keterangan', 'LIKE', '%BASELINE_OPNAME_31DES2025%')
                  ->orWhere('keterangan', 'LIKE', '%Stock Opname Cutoff 31 Desember 2025%');
            })
            ->orderBy('waktu', 'asc')
            ->get(['waktu', 'created_at', 'stok_awal', 'stok_sisa', 'keterangan']);
        
        foreach ($baselines as $idx => $b) {
            echo "  Baseline #" . ($idx + 1) . ":\n";
            echo "    Waktu      : {$b->waktu}\n";
            echo "    Created At : {$b->created_at}\n";
            echo "    Stok       : {$b->stok_awal} → {$b->stok_sisa}\n";
            echo "    Keterangan : " . substr($b->keterangan, 0, 50) . "\n\n";
        }
    }
}

echo "═══════════════════════════════════════════════════════════\n";
echo "KESIMPULAN:\n";
echo "═══════════════════════════════════════════════════════════\n\n";

if ($duplikatBaseline > 0) {
    echo "🔴 DITEMUKAN DUPLIKASI BASELINE!\n\n";
    echo "Ada {$duplikatBaseline} Stock Opname yang dibuat hari ini (23 Jan 2026)\n";
    echo "dengan keterangan 'Stock Opname Cutoff 31 Desember 2025'\n\n";
    
    echo "Kemungkinan penyebab:\n";
    echo "1. Ada seseorang yang manual input Stock Opname massal via UI\n";
    echo "2. Ada script yang jalan untuk restore/import baseline\n";
    echo "3. Ada proses otomatis yang ter-trigger\n\n";
    
    echo "Yang perlu dilakukan:\n";
    echo "1. ✅ Stok sudah di-fix (497 produk sudah sinkron)\n";
    echo "2. ⚠️  Cek apakah duplikasi baseline perlu dihapus atau dibiarkan\n";
    echo "3. ⚠️  Investigasi siapa/apa yang membuat baseline duplikat\n";
    echo "4. ⚠️  Pastikan tidak terjadi lagi di masa depan\n\n";
}

echo "=============================================================\n";
echo "Analisis selesai: " . date('Y-m-d H:i:s') . "\n";
echo "=============================================================\n\n";
