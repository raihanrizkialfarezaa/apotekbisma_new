<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\RekamanStok;
use App\Models\Produk;
use Illuminate\Support\Facades\DB;

$output = [];
$output[] = "============================================";
$output[] = "ANALISIS DETAIL KARTU STOK PRODUK ID: 48";
$output[] = "============================================";
$output[] = "";

$p = Produk::find(48);
if ($p) {
    $output[] = "INFORMASI PRODUK:";
    $output[] = "- Nama: " . $p->nama_produk;
    $output[] = "- Stok Aktual (produk.stok): " . $p->stok;
    $output[] = "";
} else {
    $output[] = "PRODUK TIDAK DITEMUKAN!";
    file_put_contents('analyze_result.txt', implode("\n", $output));
    exit;
}

$output[] = "============================================";
$output[] = "SEMUA REKAMAN STOK (URUTAN KRONOLOGIS ASC)";
$output[] = "============================================";
$output[] = "";

$recs = RekamanStok::where('id_produk', 48)
    ->orderBy('waktu', 'asc')
    ->orderBy('created_at', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

$output[] = "Total rekaman_stoks: " . count($recs);
$output[] = "";

$no = 1;
foreach($recs as $r) {
    $calculatedSisa = intval($r->stok_awal ?? 0) + intval($r->stok_masuk ?? 0) - intval($r->stok_keluar ?? 0);
    $status = ($calculatedSisa != intval($r->stok_sisa)) ? " ***ANOMALI***" : "";
    
    $output[] = "#{$no} | ID:{$r->id_rekaman_stok} | {$r->waktu} | Awal:{$r->stok_awal} | +{$r->stok_masuk} | -{$r->stok_keluar} | Sisa:{$r->stok_sisa}{$status}";
    $output[] = "   Ket: " . ($r->keterangan ?? '-') . " | Jual:" . ($r->id_penjualan ?? '-') . " | Beli:" . ($r->id_pembelian ?? '-');
    $no++;
}

$output[] = "";
$output[] = "============================================";
$output[] = "SIMULASI KALKULASI STOK BENAR";
$output[] = "============================================";
$output[] = "";

$runningStock = 0;
$isFirst = true;
$no = 1;
$problems = [];

foreach($recs as $r) {
    if ($isFirst) {
        $runningStock = intval($r->stok_awal ?? 0);
        $isFirst = false;
    }
    
    $stokMasuk = intval($r->stok_masuk ?? 0);
    $stokKeluar = intval($r->stok_keluar ?? 0);
    $expectedSisa = $runningStock + $stokMasuk - $stokKeluar;
    
    $status = '';
    if ($runningStock != intval($r->stok_awal ?? 0)) {
        $status .= " [AWAL SALAH: harusnya {$runningStock}]";
        $problems[] = "Record #{$no} ID:{$r->id_rekaman_stok}: stok_awal salah ({$r->stok_awal} vs {$runningStock})";
    }
    if ($expectedSisa != intval($r->stok_sisa ?? 0)) {
        $status .= " [SISA SALAH: harusnya {$expectedSisa}]";
        $problems[] = "Record #{$no} ID:{$r->id_rekaman_stok}: stok_sisa salah ({$r->stok_sisa} vs {$expectedSisa})";
    }
    
    $output[] = "#{$no}: {$r->waktu} | Awal={$runningStock} | +{$stokMasuk} | -{$stokKeluar} | Sisa={$expectedSisa}{$status}";
    
    $runningStock = $expectedSisa;
    $no++;
}

$output[] = "";
$output[] = "STOK AKHIR SEHARUSNYA: " . $runningStock;
$output[] = "STOK AKTUAL DI PRODUK: " . $p->stok;
$output[] = "SELISIH: " . ($runningStock - $p->stok);

$output[] = "";
$output[] = "============================================";
$output[] = "CEK DUPLIKAT TRANSAKSI";
$output[] = "============================================";
$output[] = "";

$dupPenjualan = DB::table('rekaman_stoks')
    ->select('id_penjualan', DB::raw('COUNT(*) as cnt'))
    ->where('id_produk', 48)
    ->whereNotNull('id_penjualan')
    ->groupBy('id_penjualan')
    ->having('cnt', '>', 1)
    ->get();

if (count($dupPenjualan) > 0) {
    $output[] = "DUPLIKAT PENJUALAN:";
    foreach($dupPenjualan as $d) {
        $output[] = "- ID Penjualan: " . $d->id_penjualan . " muncul " . $d->cnt . " kali";
    }
} else {
    $output[] = "Tidak ada duplikat penjualan.";
}

$dupPembelian = DB::table('rekaman_stoks')
    ->select('id_pembelian', DB::raw('COUNT(*) as cnt'))
    ->where('id_produk', 48)
    ->whereNotNull('id_pembelian')
    ->groupBy('id_pembelian')
    ->having('cnt', '>', 1)
    ->get();

if (count($dupPembelian) > 0) {
    $output[] = "DUPLIKAT PEMBELIAN:";
    foreach($dupPembelian as $d) {
        $output[] = "- ID Pembelian: " . $d->id_pembelian . " muncul " . $d->cnt . " kali";
    }
} else {
    $output[] = "Tidak ada duplikat pembelian.";
}

$output[] = "";
$output[] = "============================================";
$output[] = "MASALAH TERDETEKSI";
$output[] = "============================================";
$output[] = "";

foreach($problems as $prob) {
    $output[] = $prob;
}

if ($runningStock != $p->stok) {
    $output[] = "Stok produk tidak sinkron: harusnya {$runningStock}, aktual {$p->stok}";
}

$output[] = "";
$output[] = "Selesai.";

file_put_contents('analyze_result.txt', implode("\n", $output));
echo "Output tersimpan di analyze_result.txt\n";
