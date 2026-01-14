<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$f = fopen('analysis_48.md', 'w');

fwrite($f, "# ANALISIS KARTU STOK PRODUK 48\n\n");

$produk = DB::table('produk')->where('id_produk', 48)->first();
fwrite($f, "## Informasi Produk\n");
fwrite($f, "- **Nama**: {$produk->nama_produk}\n");
fwrite($f, "- **Stok di DB**: {$produk->stok}\n\n");

fwrite($f, "## 15 Rekaman Terakhir (Descending)\n\n");
fwrite($f, "| ID | Waktu | Awal | Masuk | Keluar | Sisa | JualID | BeliID |\n");
fwrite($f, "|---|---|---|---|---|---|---|---|\n");

$recs = DB::table('rekaman_stoks')
    ->where('id_produk', 48)
    ->orderBy('waktu', 'desc')
    ->orderBy('id_rekaman_stok', 'desc')
    ->limit(15)
    ->get();

foreach($recs as $r) {
    $jualId = $r->id_penjualan ?? '-';
    $beliId = $r->id_pembelian ?? '-';
    fwrite($f, "| {$r->id_rekaman_stok} | {$r->waktu} | {$r->stok_awal} | {$r->stok_masuk} | {$r->stok_keluar} | {$r->stok_sisa} | {$jualId} | {$beliId} |\n");
}

fwrite($f, "\n## Duplikat Penjualan\n\n");

$dups = DB::table('rekaman_stoks')
    ->select('id_penjualan', DB::raw('COUNT(*) as cnt'))
    ->where('id_produk', 48)
    ->whereNotNull('id_penjualan')
    ->groupBy('id_penjualan')
    ->having('cnt', '>', 1)
    ->get();

fwrite($f, "Total duplikat: " . count($dups) . " penjualan\n\n");
fwrite($f, "| ID Penjualan | Jumlah Record |\n");
fwrite($f, "|---|---|\n");

$totalDupRecords = 0;
foreach($dups as $d) {
    fwrite($f, "| {$d->id_penjualan} | {$d->cnt} |\n");
    $totalDupRecords += ($d->cnt - 1);
}

fwrite($f, "\n**Total record duplikat yang berlebih**: {$totalDupRecords}\n\n");

fwrite($f, "## Analisis Konsistensi\n\n");

$allRecs = DB::table('rekaman_stoks')
    ->where('id_produk', 48)
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

fwrite($f, "Total rekaman: " . count($allRecs) . "\n\n");

$runningStock = 0;
$isFirst = true;

foreach($allRecs as $r) {
    if ($isFirst) {
        $runningStock = $r->stok_awal;
        $isFirst = false;
    }
    $runningStock = $runningStock + $r->stok_masuk - $r->stok_keluar;
}

fwrite($f, "- **Stok akhir dari kalkulasi rekaman**: {$runningStock}\n");
fwrite($f, "- **Stok di tabel produk**: {$produk->stok}\n");
fwrite($f, "- **Selisih**: " . ($runningStock - $produk->stok) . "\n\n");

fwrite($f, "## Detail Masalah Data Tampilan\n\n");

fwrite($f, "Dari gambar yang diberikan user, terlihat:\n\n");
fwrite($f, "1. No.3 tanggal 13 Jan 2026 stok keluar 2, stok akhir **4**\n");
fwrite($f, "2. No.4 tanggal 13 Jan 2026 stok keluar 1, stok akhir **3**\n"); 
fwrite($f, "3. No.2 tanggal 14 Jan 2026 stok keluar 2, stok akhir **-2** (aneh!)\n");
fwrite($f, "4. No.1 tanggal 14 Jan 2026 stok masuk 30, stok akhir **28**\n\n");

fwrite($f, "### Mengapa hal ini terjadi:\n\n");
fwrite($f, "Tampilan menunjukkan urutan **DESCENDING** (terbaru di atas), tapi **stok_sisa (stok akhir)** dihitung berdasarkan urutan **ASCENDING** (dari transaksi pertama).\n\n");

fwrite($f, "Mari verifikasi urutan ascending:\n\n");

$recsAsc = DB::table('rekaman_stoks')
    ->where('id_produk', 48)
    ->orderBy('waktu', 'desc')
    ->orderBy('id_rekaman_stok', 'desc')
    ->limit(8)
    ->get();

fwrite($f, "| No (tampilan) | Waktu | Masuk | Keluar | Stok Akhir |\n");
fwrite($f, "|---|---|---|---|---|\n");

$no = 1;
foreach($recsAsc as $r) {
    $masuk = $r->stok_masuk > 0 ? $r->stok_masuk : '-';
    $keluar = $r->stok_keluar > 0 ? $r->stok_keluar : '-';
    fwrite($f, "| {$no} | {$r->waktu} | {$masuk} | {$keluar} | {$r->stok_sisa} |\n");
    $no++;
}

fclose($f);
echo "Analisis tersimpan di analysis_48.md\n";
