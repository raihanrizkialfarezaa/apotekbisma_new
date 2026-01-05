<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(300);

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$output = [];
$output[] = "================================================================";
$output[] = "   VERIFIKASI KELENGKAPAN STOCK OPNAME - 31 DESEMBER 2025";
$output[] = "   Waktu Analisis: " . date('Y-m-d H:i:s');
$output[] = "================================================================";
$output[] = "";

$output[] = "HIPOTESIS YANG AKAN DIUJI:";
$output[] = "1. Apakah klien benar-benar melakukan stock opname untuk SEMUA produk?";
$output[] = "2. Apakah ada bug di kode yang menyebabkan rekaman tidak tercatat?";
$output[] = "";

$START_OPNAME = '2025-12-31 00:00:00';
$END_OPNAME = '2025-12-31 23:59:59';

$output[] = "================================================================";
$output[] = "   BAGIAN 1: STATISTIK REKAMAN STOCK OPNAME";
$output[] = "================================================================";
$output[] = "";

$totalProduk = DB::table('produk')->count();
$output[] = "Total Produk di Database: {$totalProduk}";

$produkDenganRekOpname = DB::table('rekaman_stoks')
    ->where('waktu', '>=', $START_OPNAME)
    ->where('waktu', '<=', $END_OPNAME)
    ->distinct()
    ->pluck('id_produk')
    ->toArray();

$jumlahProdukOpname = count($produkDenganRekOpname);
$output[] = "Produk dengan rekaman pada 31 Des 2025: {$jumlahProdukOpname}";
$output[] = "Produk TANPA rekaman pada 31 Des 2025: " . ($totalProduk - $jumlahProdukOpname);
$output[] = "";

$rekamanOpname31Des = DB::table('rekaman_stoks')
    ->where('waktu', '>=', $START_OPNAME)
    ->where('waktu', '<=', $END_OPNAME)
    ->count();
$output[] = "Total rekaman stok pada 31 Des 2025: {$rekamanOpname31Des}";

$rekamanManual = DB::table('rekaman_stoks')
    ->where('waktu', '>=', $START_OPNAME)
    ->where('waktu', '<=', $END_OPNAME)
    ->where(function($q) {
        $q->whereNull('id_penjualan')
          ->whereNull('id_pembelian');
    })
    ->orWhere(function($q) use ($START_OPNAME, $END_OPNAME) {
        $q->where('waktu', '>=', $START_OPNAME)
          ->where('waktu', '<=', $END_OPNAME)
          ->where('id_penjualan', 0)
          ->where('id_pembelian', 0);
    })
    ->count();
$output[] = "Rekaman MANUAL (stock opname) pada 31 Des 2025: {$rekamanManual}";
$output[] = "";

$output[] = "================================================================";
$output[] = "   BAGIAN 2: ANALISIS WAKTU REKAMAN";
$output[] = "================================================================";
$output[] = "";

$waktuRekaman = DB::table('rekaman_stoks')
    ->where('waktu', '>=', $START_OPNAME)
    ->where('waktu', '<=', $END_OPNAME)
    ->selectRaw('HOUR(waktu) as jam, COUNT(*) as jumlah')
    ->groupBy(DB::raw('HOUR(waktu)'))
    ->orderBy('jam')
    ->get();

$output[] = "Distribusi rekaman per jam pada 31 Des 2025:";
foreach ($waktuRekaman as $w) {
    $output[] = "  Jam {$w->jam}:00 - {$w->jam}:59 : {$w->jumlah} rekaman";
}
$output[] = "";

$output[] = "================================================================";
$output[] = "   BAGIAN 3: CEK PRODUK AMLODIPIN SECARA DETAIL";
$output[] = "================================================================";
$output[] = "";

$amlodipin = DB::table('produk')
    ->where('nama_produk', 'LIKE', '%amlodipin%')
    ->get();

foreach ($amlodipin as $p) {
    $output[] = "PRODUK: {$p->nama_produk} (ID: {$p->id_produk})";
    $output[] = "  Stok Saat Ini: {$p->stok}";
    
    $rekamanOpname = DB::table('rekaman_stoks')
        ->where('id_produk', $p->id_produk)
        ->where('waktu', '>=', $START_OPNAME)
        ->where('waktu', '<=', $END_OPNAME)
        ->get();
    
    $output[] = "  Rekaman pada 31 Des 2025: " . $rekamanOpname->count();
    
    if ($rekamanOpname->count() > 0) {
        foreach ($rekamanOpname as $r) {
            $output[] = "    [{$r->waktu}] awal={$r->stok_awal} +{$r->stok_masuk} -{$r->stok_keluar} = {$r->stok_sisa}";
            $output[] = "    Keterangan: " . ($r->keterangan ?? '-');
        }
    } else {
        $output[] = "    *** TIDAK ADA REKAMAN PADA TANGGAL INI! ***";
        
        $rekamanTerakhir = DB::table('rekaman_stoks')
            ->where('id_produk', $p->id_produk)
            ->orderBy('waktu', 'desc')
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();
        
        if ($rekamanTerakhir) {
            $output[] = "    Rekaman terakhir: {$rekamanTerakhir->waktu}, stok_sisa={$rekamanTerakhir->stok_sisa}";
        }
    }
    $output[] = "";
}

$output[] = "================================================================";
$output[] = "   BAGIAN 4: PRODUK DENGAN STOK > 0 TAPI TIDAK DI-OPNAME";
$output[] = "================================================================";
$output[] = "";

$produkTidakDiOpname = DB::table('produk as p')
    ->whereNotIn('p.id_produk', $produkDenganRekOpname)
    ->where('p.stok', '>', 0)
    ->orderBy('p.stok', 'desc')
    ->limit(50)
    ->get();

$output[] = "50 Produk dengan stok > 0 yang TIDAK memiliki rekaman pada 31 Des 2025:";
$output[] = "(Diurutkan berdasarkan stok terbesar)";
$output[] = "";

$nomor = 0;
foreach ($produkTidakDiOpname as $p) {
    $nomor++;
    $rekamanTerakhir = DB::table('rekaman_stoks')
        ->where('id_produk', $p->id_produk)
        ->orderBy('waktu', 'desc')
        ->first();
    
    $tglTerakhir = $rekamanTerakhir ? $rekamanTerakhir->waktu : 'TIDAK ADA';
    
    $output[] = "{$nomor}. {$p->nama_produk}";
    $output[] = "   ID: {$p->id_produk} | Stok: {$p->stok} | Rekaman terakhir: {$tglTerakhir}";
}

$totalTidakDiOpname = DB::table('produk')
    ->whereNotIn('id_produk', $produkDenganRekOpname)
    ->where('stok', '>', 0)
    ->count();

$output[] = "";
$output[] = "Total produk dengan stok > 0 yang TIDAK di-opname: {$totalTidakDiOpname}";

$output[] = "";
$output[] = "================================================================";
$output[] = "   BAGIAN 5: CEK KETERANGAN REKAMAN STOCK OPNAME";
$output[] = "================================================================";
$output[] = "";

$keteranganUnik = DB::table('rekaman_stoks')
    ->where('waktu', '>=', $START_OPNAME)
    ->where('waktu', '<=', $END_OPNAME)
    ->selectRaw('keterangan, COUNT(*) as jumlah')
    ->groupBy('keterangan')
    ->orderBy('jumlah', 'desc')
    ->get();

$output[] = "Keterangan unik pada rekaman 31 Des 2025:";
foreach ($keteranganUnik as $k) {
    $ket = $k->keterangan ?? '(null)';
    if (strlen($ket) > 60) $ket = substr($ket, 0, 60) . '...';
    $output[] = "  [{$k->jumlah}x] {$ket}";
}

$output[] = "";
$output[] = "================================================================";
$output[] = "   BAGIAN 6: KESIMPULAN";
$output[] = "================================================================";
$output[] = "";

$persenDiOpname = round(($jumlahProdukOpname / $totalProduk) * 100, 2);

if ($jumlahProdukOpname == $totalProduk) {
    $output[] = "KESIMPULAN: Semua produk ({$totalProduk}) memiliki rekaman pada 31 Des 2025.";
    $output[] = "Tidak ada indikasi bug pada sistem pencatatan rekaman stok.";
} else {
    $output[] = "KESIMPULAN: HANYA {$jumlahProdukOpname} dari {$totalProduk} produk ({$persenDiOpname}%)";
    $output[] = "yang memiliki rekaman pada 31 Des 2025.";
    $output[] = "";
    $output[] = "KEMUNGKINAN PENYEBAB:";
    $output[] = "1. Klien TIDAK melakukan stock opname untuk semua produk";
    $output[] = "2. Ada bug pada sistem yang menyebabkan rekaman tidak tercatat";
    $output[] = "";
    $output[] = "REKOMENDASI:";
    $output[] = "- Verifikasi dengan klien apakah benar-benar melakukan stock opname untuk SEMUA produk";
    $output[] = "- Periksa log Laravel untuk error pada tanggal 31 Des 2025";
}

$output[] = "";
$output[] = "================================================================";

$content = implode("\n", $output);
$outputFile = __DIR__ . '/opname_verification_result.txt';
file_put_contents($outputFile, $content);

echo $content;
echo "\n\nHasil disimpan ke: {$outputFile}\n";
