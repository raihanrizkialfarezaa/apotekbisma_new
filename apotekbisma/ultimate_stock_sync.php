<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '1G');
set_time_limit(900);

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\RekamanStok;

$dryRun = true;
if (in_array('--execute', $argv ?? [])) {
    $dryRun = false;
}

$output = [];
$output[] = "================================================================";
$output[] = "   ULTIMATE STOCK SYNC - ALL PRODUCTS";
$output[] = "   Waktu: " . date('Y-m-d H:i:s');
$output[] = "================================================================";
$output[] = "";

if ($dryRun) {
    $output[] = "MODE: DRY RUN";
    $output[] = "Untuk eksekusi: php " . basename(__FILE__) . " --execute";
} else {
    $output[] = "MODE: EXECUTE";
}
$output[] = "";

$stats = [
    'synced' => 0,
    'already_ok' => 0,
    'errors' => 0,
];

$allProducts = DB::table('produk')->get();
$output[] = "Total produk: " . $allProducts->count();
$output[] = "";

$mismatchProducts = [];

foreach ($allProducts as $produk) {
    $stokProduk = intval($produk->stok);
    
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if (!$lastRekaman) {
        if ($stokProduk > 0) {
            $mismatchProducts[] = [
                'produk' => $produk,
                'stok_produk' => $stokProduk,
                'stok_rekaman' => 0,
                'last_rekaman' => null,
                'action' => 'create_initial',
            ];
        }
        continue;
    }
    
    $stokRekaman = intval($lastRekaman->stok_sisa);
    
    if ($stokProduk !== $stokRekaman) {
        $mismatchProducts[] = [
            'produk' => $produk,
            'stok_produk' => $stokProduk,
            'stok_rekaman' => $stokRekaman,
            'last_rekaman' => $lastRekaman,
            'action' => 'sync_to_rekaman',
        ];
    } else {
        $stats['already_ok']++;
    }
}

$output[] = "Produk sudah OK: {$stats['already_ok']}";
$output[] = "Produk perlu sinkronisasi: " . count($mismatchProducts);
$output[] = "";

if (count($mismatchProducts) > 0) {
    $output[] = "DAFTAR PRODUK MISMATCH:";
    foreach (array_slice($mismatchProducts, 0, 30) as $m) {
        $output[] = "  {$m['produk']->nama_produk}: produk={$m['stok_produk']}, rekaman={$m['stok_rekaman']}";
    }
    if (count($mismatchProducts) > 30) {
        $output[] = "  ... dan " . (count($mismatchProducts) - 30) . " lainnya";
    }
    $output[] = "";
}

if (!$dryRun && count($mismatchProducts) > 0) {
    $output[] = "MELAKUKAN SINKRONISASI...";
    $output[] = "";
    
    foreach ($mismatchProducts as $m) {
        try {
            $produk = $m['produk'];
            $stokTarget = $m['stok_rekaman'];
            
            if ($m['action'] === 'create_initial') {
                DB::table('rekaman_stoks')->insert([
                    'id_produk' => $produk->id_produk,
                    'waktu' => now(),
                    'stok_awal' => 0,
                    'stok_masuk' => $m['stok_produk'],
                    'stok_keluar' => 0,
                    'stok_sisa' => $m['stok_produk'],
                    'keterangan' => 'Auto-created: Stok awal produk',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $stats['synced']++;
            } else {
                DB::table('produk')
                    ->where('id_produk', $produk->id_produk)
                    ->update(['stok' => $stokTarget]);
                $stats['synced']++;
            }
            
        } catch (\Exception $e) {
            $stats['errors']++;
        }
    }
    
    $output[] = "Produk disinkronkan: {$stats['synced']}";
    $output[] = "Errors: {$stats['errors']}";
}

$output[] = "";
$output[] = "================================================================";
$output[] = "   RINGKASAN";
$output[] = "================================================================";
$output[] = "";
$output[] = "Produk sudah OK: {$stats['already_ok']}";
$output[] = "Produk disinkronkan: {$stats['synced']}";
$output[] = "Errors: {$stats['errors']}";
$output[] = "";

if (!$dryRun) {
    $output[] = "SINKRONISASI SELESAI!";
} else {
    $output[] = "UNTUK MENERAPKAN: php " . basename(__FILE__) . " --execute";
}

$output[] = "";
$output[] = "================================================================";

$content = implode("\n", $output);
$outputFile = __DIR__ . '/ultimate_sync_' . date('Y-m-d_His') . '.txt';
file_put_contents($outputFile, $content);

echo $content;
echo "\n\nHasil disimpan ke: {$outputFile}\n";
