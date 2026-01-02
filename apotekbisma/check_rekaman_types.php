<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Checking all unique keterangan in rekaman_stoks:\n";
echo "================================================\n\n";

$keterangans = DB::table('rekaman_stoks')
    ->select('keterangan', DB::raw('count(*) as cnt'))
    ->groupBy('keterangan')
    ->orderBy('cnt', 'desc')
    ->get();

foreach ($keterangans as $k) {
    echo "  [{$k->cnt}] " . ($k->keterangan ?? '(null)') . "\n";
}

echo "\n\nChecking waktu range:\n";
echo "=====================\n";

$min = DB::table('rekaman_stoks')->min('waktu');
$max = DB::table('rekaman_stoks')->max('waktu');

echo "Earliest: {$min}\n";
echo "Latest: {$max}\n";

echo "\n\nTransaksi per bulan:\n";
echo "====================\n";

$perBulan = DB::table('rekaman_stoks')
    ->select(DB::raw("DATE_FORMAT(waktu, '%Y-%m') as bulan"), DB::raw('count(*) as cnt'))
    ->groupBy(DB::raw("DATE_FORMAT(waktu, '%Y-%m')"))
    ->orderBy('bulan', 'desc')
    ->limit(12)
    ->get();

foreach ($perBulan as $p) {
    echo "  {$p->bulan}: {$p->cnt} rekaman\n";
}
