<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== VERIFIKASI POST-FIX ===\n\n";

// 1. Cek Adjustment Demacolin
$demacolinAdj = DB::table('rekaman_stoks')
    ->where('id_produk', 204)
    ->where('keterangan', 'LIKE', '%Stock Opname%')
    ->first();

echo "1. Adjustment Demacolin (204): " . ($demacolinAdj ? "FOUND" : "MISSING") . "\n";
if ($demacolinAdj) {
    echo "   Time: {$demacolinAdj->waktu}\n";
    echo "   Ket: {$demacolinAdj->keterangan}\n";
    echo "   Flow: {$demacolinAdj->stok_awal} -> {$demacolinAdj->stok_sisa}\n";
}

// 2. Cek Remaining Gaps
$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$opnameData = [];
$handle = fopen($csvFile, 'r');
fgetcsv($handle);
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) >= 3 && !empty($row[0])) {
        $opnameData[intval($row[0])] = intval($row[2]);
    }
}
fclose($handle);

$gaps = 0;
foreach ($opnameData as $pid => $opname) {
    $last2025 = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->where('waktu', '<=', '2025-12-31 23:59:59')
        ->orderBy('waktu', 'desc')
        ->first();
        
    $first2026 = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->where('waktu', '>', '2025-12-31 23:59:59')
        ->orderBy('waktu', 'asc')
        ->first();
        
    if ($last2025 && $first2026 && intval($last2025->stok_sisa) != intval($first2026->stok_awal)) {
        $gaps++;
        echo "   GAP FOUND: Product {$pid} ({$last2025->stok_sisa} -> {$first2026->stok_awal})\n";
    }
}
echo "\n2. Gaps Remaining: {$gaps}\n";

// 3. Cek Continuity Errors Total
$continuityErrors = 0;
$productIds = DB::table('rekaman_stoks')->distinct()->pluck('id_produk');
foreach ($productIds as $pid) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
        
    $prev = null;
    foreach ($records as $r) {
        if ($prev !== null && intval($r->stok_awal) != intval($prev)) {
            $continuityErrors++;
            break; 
        }
        $prev = $r->stok_sisa;
    }
}
echo "3. Products with Continuity Errors: {$continuityErrors}\n";

// 4. Cek Sync produk.stok
$mismatch = 0;
$products = DB::table('produk')->get();
foreach ($products as $p) {
    $last = DB::table('rekaman_stoks')->where('id_produk', $p->id_produk)->orderBy('waktu', 'desc')->first();
    if ($last && intval($p->stok) != intval($last->stok_sisa)) {
        $mismatch++;
    }
}
echo "4. Mismatched produk.stok: {$mismatch}\n";

if ($gaps == 0 && $continuityErrors == 0 && $mismatch == 0) {
    echo "\nRESULT: ALL GREEN. SYSTEM IS HEALTHY.\n";
} else {
    echo "\nRESULT: ISSUES REMAIN.\n";
}
