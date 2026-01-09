<?php
require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║         ROOT CAUSE ANALYSIS - STOCK DISCREPANCY                               ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";

$stockOpnameData = [];
$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
if (file_exists($csvFile)) {
    $handle = fopen($csvFile, 'r');
    $header = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) >= 3) {
            $stockOpnameData[intval($row[0])] = [
                'nama' => $row[1],
                'stok_opname' => intval($row[2])
            ];
        }
    }
    fclose($handle);
}

function analyzeProduct($productId, $stockOpnameData) {
    $product = DB::table('produk')->where('id_produk', $productId)->first();
    
    if (!$product) {
        echo "Product ID {$productId} not found!\n";
        return;
    }
    
    echo "═══════════════════════════════════════════════════════════════════════════════\n";
    echo "ANALISIS PRODUK: [{$product->id_produk}] {$product->nama_produk}\n";
    echo "═══════════════════════════════════════════════════════════════════════════════\n\n";
    
    $opnameStock = isset($stockOpnameData[$productId]) ? $stockOpnameData[$productId]['stok_opname'] : null;
    echo "Stok di produk.stok saat ini: {$product->stok}\n";
    echo "Stok Opname 31 Des 2025: " . ($opnameStock !== null ? $opnameStock : 'N/A') . "\n\n";
    
    $allRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    echo "Total record di rekaman_stoks: {$allRekaman->count()}\n\n";
    
    echo "┌─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┐\n";
    echo "│ NO │ ID_REKAMAN │      WAKTU           │ AWAL  │ MASUK │ KELUAR│ SISA  │ STATUS   │ KETERANGAN                         │\n";
    echo "├─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┤\n";
    
    $no = 1;
    $prevSisa = null;
    $issues = [];
    
    foreach ($allRekaman as $r) {
        $expectedAwal = ($prevSisa !== null) ? $prevSisa : $r->stok_awal;
        $expectedSisa = intval($r->stok_awal) + intval($r->stok_masuk) - intval($r->stok_keluar);
        
        $status = 'OK';
        $issue = null;
        
        if ($prevSisa !== null && intval($r->stok_awal) != intval($prevSisa)) {
            $gap = intval($r->stok_awal) - intval($prevSisa);
            $status = "GAP:{$gap}";
            $issue = [
                'type' => 'gap',
                'id_rekaman' => $r->id_rekaman_stok,
                'expected_awal' => $prevSisa,
                'actual_awal' => $r->stok_awal,
                'gap' => $gap,
                'waktu' => $r->waktu,
                'keterangan' => $r->keterangan
            ];
            $issues[] = $issue;
        }
        
        if (intval($r->stok_sisa) != $expectedSisa) {
            $status .= ($status != 'OK' ? ',' : '') . "CALC_ERR";
            $issue = [
                'type' => 'calc_error',
                'id_rekaman' => $r->id_rekaman_stok,
                'expected_sisa' => $expectedSisa,
                'actual_sisa' => $r->stok_sisa
            ];
            $issues[] = $issue;
        }
        
        $keteranganShort = substr($r->keterangan ?? '-', 0, 35);
        if (strlen($r->keterangan ?? '') > 35) $keteranganShort .= '...';
        
        printf("│%3d │ %10d │ %-20s │ %5d │ %5s │ %5s │ %5d │ %-8s │ %-35s │\n",
            $no,
            $r->id_rekaman_stok,
            $r->waktu,
            $r->stok_awal,
            $r->stok_masuk ?: '-',
            $r->stok_keluar ?: '-',
            $r->stok_sisa,
            $status,
            $keteranganShort
        );
        
        $prevSisa = $r->stok_sisa;
        $no++;
    }
    
    echo "└─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┘\n\n";
    
    if ($allRekaman->isNotEmpty()) {
        $lastRekaman = $allRekaman->last();
        echo "VERIFIKASI AKHIR:\n";
        echo "  - Stok_sisa terakhir di rekaman_stoks: {$lastRekaman->stok_sisa}\n";
        echo "  - Stok di produk.stok: {$product->stok}\n";
        echo "  - Selisih: " . (intval($product->stok) - intval($lastRekaman->stok_sisa)) . "\n\n";
    }
    
    if ($opnameStock !== null) {
        $rekamanBeforeCutoff = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('waktu', '<=', '2025-12-31 23:59:59')
            ->orderBy('waktu', 'desc')
            ->first();
        
        $rekamanAfterCutoff = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('waktu', '>', '2025-12-31 23:59:59')
            ->orderBy('waktu', 'asc')
            ->get();
        
        echo "ANALISIS BERDASARKAN STOCK OPNAME:\n";
        
        if ($rekamanBeforeCutoff) {
            echo "  - Rekaman terakhir sebelum/saat cutoff (31 Des 2025):\n";
            echo "    ID: {$rekamanBeforeCutoff->id_rekaman_stok}\n";
            echo "    Waktu: {$rekamanBeforeCutoff->waktu}\n";
            echo "    Stok_sisa: {$rekamanBeforeCutoff->stok_sisa}\n";
            echo "    Keterangan: {$rekamanBeforeCutoff->keterangan}\n";
            
            if (intval($rekamanBeforeCutoff->stok_sisa) != $opnameStock) {
                echo "  >>> DISCREPANCY: Stok opname ({$opnameStock}) != stok_sisa rekaman ({$rekamanBeforeCutoff->stok_sisa})\n";
            }
        } else {
            echo "  - Tidak ada rekaman pada/sebelum cutoff\n";
        }
        
        echo "\n  - Transaksi setelah cutoff (2026):\n";
        $totalMasukAfter = 0;
        $totalKeluarAfter = 0;
        
        foreach ($rekamanAfterCutoff as $ra) {
            $totalMasukAfter += intval($ra->stok_masuk);
            $totalKeluarAfter += intval($ra->stok_keluar);
            echo "    [{$ra->id_rekaman_stok}] {$ra->waktu} | +{$ra->stok_masuk} / -{$ra->stok_keluar} | Sisa: {$ra->stok_sisa} | {$ra->keterangan}\n";
        }
        
        echo "\n  - Total transaksi setelah cutoff:\n";
        echo "    Masuk: +{$totalMasukAfter}\n";
        echo "    Keluar: -{$totalKeluarAfter}\n";
        echo "    Net: " . ($totalMasukAfter - $totalKeluarAfter) . "\n\n";
        
        $expectedCurrentStock = $opnameStock + $totalMasukAfter - $totalKeluarAfter;
        echo "  - EXPECTED current stock = Opname ({$opnameStock}) + Masuk ({$totalMasukAfter}) - Keluar ({$totalKeluarAfter}) = {$expectedCurrentStock}\n";
        echo "  - ACTUAL produk.stok: {$product->stok}\n";
        echo "  - SELISIH: " . (intval($product->stok) - $expectedCurrentStock) . "\n\n";
    }
    
    if (!empty($issues)) {
        echo "═══════════════════════════════════════════════════════════════════════════════\n";
        echo "MASALAH YANG DITEMUKAN:\n";
        echo "═══════════════════════════════════════════════════════════════════════════════\n\n";
        
        foreach ($issues as $issue) {
            if ($issue['type'] == 'gap') {
                echo "  [GAP] Rekaman #{$issue['id_rekaman']}\n";
                echo "    Waktu: {$issue['waktu']}\n";
                echo "    Expected stok_awal: {$issue['expected_awal']}\n";
                echo "    Actual stok_awal: {$issue['actual_awal']}\n";
                echo "    Gap: {$issue['gap']}\n";
                echo "    Keterangan: {$issue['keterangan']}\n\n";
            }
        }
    }
    
    $penjualan = DB::table('penjualan_detail')
        ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
        ->where('penjualan_detail.id_produk', $productId)
        ->select('penjualan_detail.*', 'penjualan.waktu as penjualan_waktu')
        ->orderBy('penjualan.waktu', 'asc')
        ->get();
    
    $pembelian = DB::table('pembelian_detail')
        ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
        ->where('pembelian_detail.id_produk', $productId)
        ->select('pembelian_detail.*', 'pembelian.waktu as pembelian_waktu')
        ->orderBy('pembelian.waktu', 'asc')
        ->get();
    
    echo "═══════════════════════════════════════════════════════════════════════════════\n";
    echo "CROSS-CHECK TRANSAKSI:\n";
    echo "═══════════════════════════════════════════════════════════════════════════════\n\n";
    
    echo "PEMBELIAN (stok masuk):\n";
    $totalPembelian = 0;
    foreach ($pembelian as $p) {
        echo "  [{$p->id_pembelian_detail}] {$p->pembelian_waktu} | Qty: {$p->jumlah} | ID_Pembelian: {$p->id_pembelian}\n";
        $totalPembelian += $p->jumlah;
    }
    echo "  TOTAL PEMBELIAN: {$totalPembelian}\n\n";
    
    echo "PENJUALAN (stok keluar):\n";
    $totalPenjualan = 0;
    foreach ($penjualan as $p) {
        echo "  [{$p->id_penjualan_detail}] {$p->penjualan_waktu} | Qty: {$p->jumlah} | ID_Penjualan: {$p->id_penjualan}\n";
        $totalPenjualan += $p->jumlah;
    }
    echo "  TOTAL PENJUALAN: {$totalPenjualan}\n\n";
    
    $rekamanTotalMasuk = $allRekaman->sum('stok_masuk');
    $rekamanTotalKeluar = $allRekaman->sum('stok_keluar');
    
    echo "PERBANDINGAN:\n";
    echo "  - Total Pembelian (dari pembelian_detail): {$totalPembelian}\n";
    echo "  - Total Masuk (dari rekaman_stoks): {$rekamanTotalMasuk}\n";
    echo "    Selisih: " . ($totalPembelian - $rekamanTotalMasuk) . "\n\n";
    
    echo "  - Total Penjualan (dari penjualan_detail): {$totalPenjualan}\n";
    echo "  - Total Keluar (dari rekaman_stoks): {$rekamanTotalKeluar}\n";
    echo "    Selisih: " . ($totalPenjualan - $rekamanTotalKeluar) . "\n\n";
    
    return $issues;
}

$problemProducts = [204, 994];

foreach ($problemProducts as $productId) {
    analyzeProduct($productId, $stockOpnameData);
    echo "\n\n";
}

echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                      ROOT CAUSE IDENTIFICATION                                ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";

echo "Berdasarkan analisis di atas, ROOT CAUSE anomali stok adalah:\n\n";

echo "1. TIMELINE ANOMALY (Urutan Waktu yang Salah)\n";
echo "   - Beberapa record rekaman_stoks memiliki waktu (timestamp) yang tidak\n";
echo "     mengikuti urutan kronologis yang benar.\n";
echo "   - Contoh: Record 'Perubahan Stok Manual' atau 'Update jumlah transaksi'\n";
echo "     dengan timestamp yang menyebabkan lompatan stok.\n\n";

echo "2. STOCK OPNAME TIDAK TERINTEGRASI DENGAN BENAR\n";
echo "   - Data stock opname 31 Desember 2025 tidak di-sync dengan benar\n";
echo "     ke dalam sistem rekaman_stoks.\n";
echo "   - Akibatnya, stok_awal transaksi pertama setelah cutoff tidak\n";
echo "     sesuai dengan stok opname.\n\n";

echo "3. MULTIPLE UPDATE PADA TRANSAKSI YANG SAMA\n";
echo "   - Beberapa record memiliki keterangan 'Update jumlah transaksi'\n";
echo "     yang menyebabkan duplikasi atau perubahan stok yang tidak terkalkulasi\n";
echo "     ulang dengan benar.\n\n";

echo "4. RECALCULATION TIDAK BERJALAN SAAT ADA PERUBAHAN\n";
echo "   - Ketika ada update pada transaksi, sistem tidak melakukan\n";
echo "     recalculation pada semua record setelahnya.\n\n";

echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                             SOLUSI                                            ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";

echo "Untuk memperbaiki semua anomali stok, perlu dilakukan:\n\n";

echo "1. REBUILD SELURUH REKAMAN_STOKS\n";
echo "   - Hapus semua data rekaman_stoks\n";
echo "   - Rebuild dari awal berdasarkan:\n";
echo "     a. Stok Opname 31 Desember 2025 sebagai stok_awal\n";
echo "     b. Semua transaksi pembelian dan penjualan (terurut kronologis)\n\n";

echo "2. SYNC PRODUK.STOK\n";
echo "   - Update produk.stok dengan stok_sisa terakhir dari rekaman_stoks\n\n";

echo "Script perbaikan akan dibuat selanjutnya.\n";
