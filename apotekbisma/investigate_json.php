<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$result = [];

$productId = 204;
$result['demacolin'] = [
    'id' => $productId,
    'records' => []
];

$records = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->whereBetween('waktu', ['2025-12-28 00:00:00', '2026-01-03 23:59:59'])
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

foreach ($records as $r) {
    $result['demacolin']['records'][] = [
        'id' => $r->id_rekaman_stok,
        'waktu' => $r->waktu,
        'created_at' => $r->created_at,
        'stok_awal' => $r->stok_awal,
        'stok_masuk' => $r->stok_masuk,
        'stok_keluar' => $r->stok_keluar,
        'stok_sisa' => $r->stok_sisa,
        'id_penjualan' => $r->id_penjualan,
        'id_pembelian' => $r->id_pembelian,
        'keterangan' => $r->keterangan
    ];
}

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

$problems = [];
foreach ($opnameData as $pid => $opnameStock) {
    $lastBefore = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->where('waktu', '<=', '2025-12-31 23:59:59')
        ->orderBy('waktu', 'desc')
        ->first();
    
    $firstAfter = DB::table('rekaman_stoks')
        ->where('id_produk', $pid)
        ->where('waktu', '>', '2025-12-31 23:59:59')
        ->orderBy('waktu', 'asc')
        ->first();
    
    if ($lastBefore && $firstAfter && intval($firstAfter->stok_awal) != intval($lastBefore->stok_sisa)) {
        $prod = DB::table('produk')->where('id_produk', $pid)->first();
        $problems[] = [
            'id' => $pid,
            'nama' => $prod ? $prod->nama_produk : 'Unknown',
            'opname' => $opnameStock,
            'last_sisa' => intval($lastBefore->stok_sisa),
            'first_awal' => intval($firstAfter->stok_awal),
            'gap' => intval($firstAfter->stok_awal) - intval($lastBefore->stok_sisa)
        ];
    }
}

usort($problems, function($a, $b) { return abs($b['gap']) - abs($a['gap']); });
$result['problems_count'] = count($problems);
$result['problems'] = array_slice($problems, 0, 20);

file_put_contents(__DIR__ . '/investigation_result.json', json_encode($result, JSON_PRETTY_PRINT));
echo "Saved to investigation_result.json\n";
echo "Problems found: " . count($problems) . "\n";
