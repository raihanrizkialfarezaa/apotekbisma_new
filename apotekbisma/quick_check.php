<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$cutoffDate = '2025-12-31 23:00:00';

echo "CUTOFF: {$cutoffDate}\n\n";

$stockOpnameRecords = DB::table('rekaman_stoks')
    ->where('waktu', '<=', $cutoffDate)
    ->where('waktu', '>=', '2025-12-31 00:00:00')
    ->where(function($q) {
        $q->where('keterangan', 'LIKE', '%Stock Opname%')
          ->orWhere('keterangan', 'LIKE', '%Perubahan Stok Manual%')
          ->orWhere('keterangan', 'LIKE', '%Manual%');
    })
    ->count();

echo "Stock Opname records pada 31 Des: {$stockOpnameRecords}\n";

$afterCutoff = DB::table('rekaman_stoks')->where('waktu', '>', $cutoffDate)->count();
echo "Transaksi setelah cutoff: {$afterCutoff}\n";

$allRecords = DB::table('rekaman_stoks')->count();
echo "Total rekaman stok: {$allRecords}\n\n";

echo "Contoh keterangan rekaman:\n";
$samples = DB::table('rekaman_stoks')
    ->select('keterangan')
    ->distinct()
    ->limit(20)
    ->get();

foreach ($samples as $s) {
    echo "  - " . substr($s->keterangan ?? '(null)', 0, 60) . "\n";
}
