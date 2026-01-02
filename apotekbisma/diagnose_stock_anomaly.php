<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\RekamanStok;

echo "=======================================================\n";
echo "   DIAGNOSA ANOMALI STOK - APOTEK BISMA\n";
echo "   Tanggal: " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

$issues = [];
$criticalIssues = [];

echo "1. ANALISIS INTEGRITAS REKAMAN STOK\n";
echo "-----------------------------------\n\n";

$allProducts = Produk::orderBy('nama_produk')->get();
$productsWithIssues = [];

foreach ($allProducts as $produk) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();

    if ($records->isEmpty()) {
        continue;
    }

    $runningStock = 0;
    $firstRecord = true;
    $hasIssue = false;
    $productIssues = [];

    foreach ($records as $index => $record) {
        if ($firstRecord) {
            $runningStock = $record->stok_awal;
            $firstRecord = false;
        }

        $calculatedSisa = $runningStock + $record->stok_masuk - $record->stok_keluar;

        if ($record->stok_awal != $runningStock && !$firstRecord) {
            $hasIssue = true;
            $productIssues[] = [
                'type' => 'STOK_AWAL_MISMATCH',
                'record_id' => $record->id_rekaman_stok,
                'expected_awal' => $runningStock,
                'actual_awal' => $record->stok_awal,
                'waktu' => $record->waktu,
                'keterangan' => $record->keterangan
            ];
        }

        if ($record->stok_sisa != $calculatedSisa) {
            $hasIssue = true;
            $productIssues[] = [
                'type' => 'STOK_SISA_CALCULATION_ERROR',
                'record_id' => $record->id_rekaman_stok,
                'expected_sisa' => $calculatedSisa,
                'actual_sisa' => $record->stok_sisa,
                'formula' => "({$runningStock} + {$record->stok_masuk} - {$record->stok_keluar})",
                'waktu' => $record->waktu,
                'keterangan' => $record->keterangan
            ];
        }

        $runningStock = $calculatedSisa;
    }

    $lastRecordSisa = $records->last()->stok_sisa;
    if ($produk->stok != $lastRecordSisa) {
        $hasIssue = true;
        $productIssues[] = [
            'type' => 'PRODUCT_STOCK_MISMATCH',
            'produk_stok' => $produk->stok,
            'rekaman_sisa' => $lastRecordSisa,
            'difference' => $produk->stok - $lastRecordSisa
        ];
        
        $criticalIssues[] = [
            'produk' => $produk->nama_produk,
            'id_produk' => $produk->id_produk,
            'stok_produk' => $produk->stok,
            'stok_rekaman' => $lastRecordSisa,
            'selisih' => $produk->stok - $lastRecordSisa
        ];
    }

    if ($hasIssue) {
        $productsWithIssues[$produk->id_produk] = [
            'nama_produk' => $produk->nama_produk,
            'issues' => $productIssues
        ];
    }
}

if (empty($criticalIssues)) {
    echo "   [OK] Tidak ada diskrepansi antara stok produk dan rekaman stok.\n\n";
} else {
    echo "   [WARNING] Ditemukan " . count($criticalIssues) . " produk dengan diskrepansi stok:\n\n";
    
    foreach ($criticalIssues as $issue) {
        echo "   - {$issue['produk']} (ID: {$issue['id_produk']})\n";
        echo "     Stok Produk: {$issue['stok_produk']}, Stok Rekaman: {$issue['stok_rekaman']}, Selisih: {$issue['selisih']}\n\n";
    }
}

echo "\n2. ANALISIS DUPLICAT REKAMAN STOK (KEMUNGKINAN DOUBLE DEDUCTION)\n";
echo "-----------------------------------------------------------------\n\n";

$duplicates = DB::table('rekaman_stoks')
    ->select(
        'id_produk',
        'id_penjualan',
        'stok_keluar',
        DB::raw('COUNT(*) as jumlah'),
        DB::raw('MIN(id_rekaman_stok) as first_id'),
        DB::raw('MAX(id_rekaman_stok) as last_id')
    )
    ->whereNotNull('id_penjualan')
    ->where('stok_keluar', '>', 0)
    ->groupBy('id_produk', 'id_penjualan', 'stok_keluar')
    ->having('jumlah', '>', 1)
    ->get();

if ($duplicates->isEmpty()) {
    echo "   [OK] Tidak ditemukan duplikat rekaman penjualan.\n\n";
} else {
    echo "   [CRITICAL] Ditemukan " . count($duplicates) . " kasus duplikat rekaman penjualan!\n\n";
    
    foreach ($duplicates as $dup) {
        $produk = Produk::find($dup->id_produk);
        echo "   - Produk: " . ($produk ? $produk->nama_produk : 'ID ' . $dup->id_produk) . "\n";
        echo "     ID Penjualan: {$dup->id_penjualan}, Stok Keluar: {$dup->stok_keluar}\n";
        echo "     Duplikat: {$dup->jumlah}x (ID Rekaman: {$dup->first_id} - {$dup->last_id})\n";
        echo "     DAMPAK: Stok terpotong " . ($dup->stok_keluar * ($dup->jumlah - 1)) . " lebih banyak!\n\n";
    }
}

echo "\n3. ANALISIS TRANSAKSI DENGAN MULTIPLE REKAMAN STOK PADA SATU PENJUALAN\n";
echo "-----------------------------------------------------------------------\n\n";

$multipleRecords = DB::table('rekaman_stoks')
    ->select(
        'id_produk',
        'id_penjualan',
        DB::raw('COUNT(*) as jumlah_rekaman'),
        DB::raw('SUM(stok_keluar) as total_stok_keluar')
    )
    ->whereNotNull('id_penjualan')
    ->groupBy('id_produk', 'id_penjualan')
    ->having('jumlah_rekaman', '>', 1)
    ->get();

$problematicSales = [];

foreach ($multipleRecords as $record) {
    $penjualanDetail = DB::table('penjualan_detail')
        ->where('id_penjualan', $record->id_penjualan)
        ->where('id_produk', $record->id_produk)
        ->first();
    
    $expectedQty = $penjualanDetail ? $penjualanDetail->jumlah : 0;
    
    if ($record->total_stok_keluar != $expectedQty) {
        $problematicSales[] = [
            'id_produk' => $record->id_produk,
            'id_penjualan' => $record->id_penjualan,
            'jumlah_rekaman' => $record->jumlah_rekaman,
            'total_stok_keluar' => $record->total_stok_keluar,
            'expected_qty' => $expectedQty,
            'difference' => $record->total_stok_keluar - $expectedQty
        ];
    }
}

if (empty($problematicSales)) {
    echo "   [OK] Semua transaksi penjualan memiliki rekaman stok yang konsisten.\n\n";
} else {
    echo "   [CRITICAL] Ditemukan " . count($problematicSales) . " transaksi dengan rekaman stok tidak konsisten!\n\n";
    
    foreach ($problematicSales as $sale) {
        $produk = Produk::find($sale['id_produk']);
        echo "   - Produk: " . ($produk ? $produk->nama_produk : 'ID ' . $sale['id_produk']) . "\n";
        echo "     ID Penjualan: {$sale['id_penjualan']}\n";
        echo "     Jumlah Rekaman: {$sale['jumlah_rekaman']}\n";
        echo "     Total Stok Keluar: {$sale['total_stok_keluar']}\n";
        echo "     Jumlah Seharusnya: {$sale['expected_qty']}\n";
        echo "     Selisih: {$sale['difference']} (lebih {$sale['difference']} item terpotong)\n\n";
    }
}

echo "\n4. ANALISIS PERBEDAAN STOK PRODUK VS PERHITUNGAN REKAMAN\n";
echo "--------------------------------------------------------\n\n";

$stockComparison = [];

foreach ($allProducts as $produk) {
    $rekamanData = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->selectRaw('SUM(stok_masuk) as total_masuk, SUM(stok_keluar) as total_keluar')
        ->first();
    
    $totalMasuk = $rekamanData->total_masuk ?? 0;
    $totalKeluar = $rekamanData->total_keluar ?? 0;
    
    $firstRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->first();
    
    $stokAwal = $firstRecord ? $firstRecord->stok_awal : 0;
    $calculatedStock = $stokAwal + $totalMasuk - $totalKeluar;
    
    if ($produk->stok != $calculatedStock) {
        $stockComparison[] = [
            'nama_produk' => $produk->nama_produk,
            'id_produk' => $produk->id_produk,
            'stok_produk' => $produk->stok,
            'calculated_stock' => $calculatedStock,
            'stok_awal' => $stokAwal,
            'total_masuk' => $totalMasuk,
            'total_keluar' => $totalKeluar,
            'difference' => $produk->stok - $calculatedStock
        ];
    }
}

if (empty($stockComparison)) {
    echo "   [OK] Semua stok produk konsisten dengan rekaman.\n\n";
} else {
    echo "   [CRITICAL] Ditemukan " . count($stockComparison) . " produk dengan kalkulasi tidak konsisten!\n\n";
    
    foreach ($stockComparison as $comp) {
        echo "   - {$comp['nama_produk']} (ID: {$comp['id_produk']})\n";
        echo "     Stok Produk: {$comp['stok_produk']}\n";
        echo "     Kalkulasi: {$comp['stok_awal']} + {$comp['total_masuk']} - {$comp['total_keluar']} = {$comp['calculated_stock']}\n";
        echo "     Selisih: {$comp['difference']}\n\n";
    }
}

echo "\n5. ANALISIS TRANSAKSI HARI INI (KEMUNGKINAN DOUBLE PROCESSING)\n";
echo "---------------------------------------------------------------\n\n";

$today = date('Y-m-d');
$todayStart = $today . ' 00:00:00';
$todayEnd = $today . ' 23:59:59';

$todaysRecords = DB::table('rekaman_stoks')
    ->whereBetween('waktu', [$todayStart, $todayEnd])
    ->orderBy('id_produk')
    ->orderBy('waktu', 'asc')
    ->get();

$todaysByProduct = [];
foreach ($todaysRecords as $record) {
    if (!isset($todaysByProduct[$record->id_produk])) {
        $todaysByProduct[$record->id_produk] = [];
    }
    $todaysByProduct[$record->id_produk][] = $record;
}

echo "   Rekaman stok hari ini (" . count($todaysRecords) . " total):\n\n";

foreach ($todaysByProduct as $produkId => $records) {
    $produk = Produk::find($produkId);
    if (!$produk) continue;
    
    $totalKeluar = 0;
    $totalMasuk = 0;
    
    foreach ($records as $record) {
        $totalKeluar += $record->stok_keluar;
        $totalMasuk += $record->stok_masuk;
    }
    
    if ($totalKeluar > 0 || $totalMasuk > 0) {
        echo "   - {$produk->nama_produk}\n";
        echo "     Jumlah Rekaman: " . count($records) . "\n";
        echo "     Total Masuk: {$totalMasuk}, Total Keluar: {$totalKeluar}\n";
        echo "     Stok Saat Ini: {$produk->stok}\n\n";
    }
}

echo "\n6. VERIFIKASI REKAMAN STOK PENJUALAN VS PENJUALAN_DETAIL\n";
echo "---------------------------------------------------------\n\n";

$penjualanVerification = DB::table('penjualan_detail')
    ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
    ->select(
        'penjualan_detail.id_penjualan',
        'penjualan_detail.id_produk',
        'penjualan_detail.jumlah',
        'penjualan.waktu'
    )
    ->get();

$mismatches = [];

foreach ($penjualanVerification as $pDetail) {
    $rekamanKeluar = DB::table('rekaman_stoks')
        ->where('id_penjualan', $pDetail->id_penjualan)
        ->where('id_produk', $pDetail->id_produk)
        ->sum('stok_keluar');
    
    if ($rekamanKeluar != $pDetail->jumlah) {
        $produk = Produk::find($pDetail->id_produk);
        $mismatches[] = [
            'produk' => $produk ? $produk->nama_produk : 'ID ' . $pDetail->id_produk,
            'id_penjualan' => $pDetail->id_penjualan,
            'jumlah_detail' => $pDetail->jumlah,
            'rekaman_keluar' => $rekamanKeluar,
            'difference' => $rekamanKeluar - $pDetail->jumlah
        ];
    }
}

if (empty($mismatches)) {
    echo "   [OK] Semua rekaman stok konsisten dengan penjualan_detail.\n\n";
} else {
    echo "   [CRITICAL] Ditemukan " . count($mismatches) . " ketidaksesuaian!\n\n";
    
    foreach ($mismatches as $m) {
        echo "   - {$m['produk']}\n";
        echo "     ID Penjualan: {$m['id_penjualan']}\n";
        echo "     Jumlah di Detail: {$m['jumlah_detail']}\n";
        echo "     Stok Keluar di Rekaman: {$m['rekaman_keluar']}\n";
        echo "     Selisih: {$m['difference']} (OVER-DEDUCTION!)\n\n";
    }
}

echo "\n7. RINGKASAN MASALAH\n";
echo "---------------------\n\n";

$totalProblems = count($criticalIssues) + count($duplicates) + count($problematicSales) + count($stockComparison) + count($mismatches);

if ($totalProblems == 0) {
    echo "   [OK] Tidak ditemukan masalah kritis pada sistem stok.\n";
} else {
    echo "   [CRITICAL] Total masalah ditemukan: {$totalProblems}\n\n";
    echo "   Breakdown:\n";
    echo "   - Diskrepansi Stok Produk vs Rekaman: " . count($criticalIssues) . "\n";
    echo "   - Duplikat Rekaman Penjualan: " . count($duplicates) . "\n";
    echo "   - Transaksi dengan Multiple Rekaman: " . count($problematicSales) . "\n";
    echo "   - Kalkulasi Stok Tidak Konsisten: " . count($stockComparison) . "\n";
    echo "   - Mismatch Detail vs Rekaman: " . count($mismatches) . "\n";
}

echo "\n\n=======================================================\n";
echo "   DIAGNOSA SELESAI\n";
echo "=======================================================\n";
