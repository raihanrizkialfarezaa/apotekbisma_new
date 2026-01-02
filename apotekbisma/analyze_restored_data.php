<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;

echo "==============================================\n";
echo "ANALISIS DATA STOCK OPNAME 31 DESEMBER 2025\n";
echo "==============================================\n\n";

$cutoffDate = '2025-12-31 23:00:00';

$allRecords = DB::table('rekaman_stoks')->count();
echo "Total rekaman stok: {$allRecords}\n\n";

echo "Jenis keterangan:\n";
$keterangans = DB::table('rekaman_stoks')
    ->select('keterangan', DB::raw('count(*) as cnt'))
    ->groupBy('keterangan')
    ->orderBy('cnt', 'desc')
    ->limit(15)
    ->get();

foreach ($keterangans as $k) {
    echo "  [{$k->cnt}] " . substr($k->keterangan ?? '(null)', 0, 50) . "\n";
}

echo "\nWaktu range:\n";
$min = DB::table('rekaman_stoks')->min('waktu');
$max = DB::table('rekaman_stoks')->max('waktu');
echo "  Earliest: {$min}\n";
echo "  Latest: {$max}\n";

echo "\nRekaman pada 31 Des 2025:\n";
$on31Dec = DB::table('rekaman_stoks')
    ->whereDate('waktu', '2025-12-31')
    ->count();
echo "  Total pada 31 Des: {$on31Dec}\n";

$stockOpname31 = DB::table('rekaman_stoks')
    ->whereDate('waktu', '2025-12-31')
    ->where(function($q) {
        $q->where('keterangan', 'LIKE', '%Stock Opname%')
          ->orWhere('keterangan', 'LIKE', '%Perubahan Stok Manual%')
          ->orWhere('keterangan', 'LIKE', '%Manual%')
          ->orWhere('keterangan', 'LIKE', '%Penyesuaian%');
    })
    ->count();
echo "  Stock Opname/Manual pada 31 Des: {$stockOpname31}\n";

echo "\nRekaman SETELAH 31 Des 2025 23:00:\n";
$afterCutoff = DB::table('rekaman_stoks')
    ->where('waktu', '>', $cutoffDate)
    ->count();
echo "  Total setelah cutoff: {$afterCutoff}\n";

if ($afterCutoff > 0) {
    $afterDetails = DB::table('rekaman_stoks')
        ->where('waktu', '>', $cutoffDate)
        ->select('keterangan', DB::raw('count(*) as cnt'))
        ->groupBy('keterangan')
        ->get();
    
    foreach ($afterDetails as $d) {
        echo "    - [{$d->cnt}] " . substr($d->keterangan ?? '(null)', 0, 40) . "\n";
    }
}

echo "\n==============================================\n";
echo "CONTOH PRODUK DAN STOKNYA\n";
echo "==============================================\n";

$sampleProducts = ['Amoxicillin', 'Asam Mefenamat', 'Cetirizin', 'Dextem'];

foreach ($sampleProducts as $name) {
    $produk = Produk::where('nama_produk', 'LIKE', "%{$name}%")->first();
    if ($produk) {
        echo "\n{$produk->nama_produk}:\n";
        echo "  Stok di tabel produk: {$produk->stok}\n";
        
        $lastRekaman = DB::table('rekaman_stoks')
            ->where('id_produk', $produk->id_produk)
            ->orderBy('waktu', 'desc')
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();
        
        if ($lastRekaman) {
            echo "  Stok di rekaman terakhir: {$lastRekaman->stok_sisa}\n";
            echo "  Waktu rekaman terakhir: {$lastRekaman->waktu}\n";
            echo "  Keterangan: {$lastRekaman->keterangan}\n";
        }
        
        $rekamanCount = DB::table('rekaman_stoks')
            ->where('id_produk', $produk->id_produk)
            ->count();
        echo "  Total rekaman: {$rekamanCount}\n";
    }
}

echo "\n==============================================\n";
