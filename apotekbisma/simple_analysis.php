<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\DB;

$productId = 204;
$product = DB::table('produk')->where('id_produk', $productId)->first();

echo "=== DEMACOLIN TAB (ID: 204) ===\n";
echo "Current produk.stok: {$product->stok}\n\n";

$rekaman = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->orderBy('waktu', 'asc')
    ->orderBy('created_at', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

echo "Total rekaman records: {$rekaman->count()}\n\n";
echo "TIMELINE (showing all records with issues):\n";
echo str_repeat("-", 120) . "\n";

$no = 1;
$prevSisa = null;
$issues = [];

foreach ($rekaman as $r) {
    $hasIssue = false;
    $gap = 0;
    
    if ($prevSisa !== null && intval($r->stok_awal) != intval($prevSisa)) {
        $gap = intval($r->stok_awal) - intval($prevSisa);
        $hasIssue = true;
        $issues[] = [
            'no' => $no,
            'id' => $r->id_rekaman_stok,
            'waktu' => $r->waktu,
            'expected_awal' => $prevSisa,
            'actual_awal' => $r->stok_awal,
            'gap' => $gap,
            'keterangan' => $r->keterangan
        ];
    }
    
    $gapStr = $hasIssue ? " [GAP: {$gap}]" : "";
    $marker = $hasIssue ? ">>>" : "   ";
    
    echo "{$marker} {$no}. [{$r->id_rekaman_stok}] {$r->waktu} | Awal:{$r->stok_awal} +{$r->stok_masuk} -{$r->stok_keluar} = Sisa:{$r->stok_sisa}{$gapStr}\n";
    echo "       Keterangan: " . substr($r->keterangan ?? '-', 0, 80) . "\n";
    
    $prevSisa = $r->stok_sisa;
    $no++;
}

echo str_repeat("-", 120) . "\n\n";

$lastRekaman = $rekaman->last();
echo "Last rekaman stok_sisa: {$lastRekaman->stok_sisa}\n";
echo "Current produk.stok: {$product->stok}\n";
echo "MISMATCH: " . (intval($product->stok) - intval($lastRekaman->stok_sisa)) . "\n\n";

echo "STOCK OPNAME CHECK:\n";
$csvData = [];
$handle = fopen(__DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv', 'r');
fgetcsv($handle);
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) >= 3 && intval($row[0]) == $productId) {
        $csvData = ['nama' => $row[1], 'stok' => intval($row[2])];
        break;
    }
}
fclose($handle);

if (!empty($csvData)) {
    echo "Stock Opname 31 Dec 2025: {$csvData['stok']}\n";
    
    $afterCutoff = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->where('waktu', '>', '2025-12-31 23:59:59')
        ->get();
    
    $totalMasuk = $afterCutoff->sum('stok_masuk');
    $totalKeluar = $afterCutoff->sum('stok_keluar');
    
    echo "Transactions after cutoff: +{$totalMasuk} masuk, -{$totalKeluar} keluar\n";
    $expected = $csvData['stok'] + $totalMasuk - $totalKeluar;
    echo "Expected: {$csvData['stok']} + {$totalMasuk} - {$totalKeluar} = {$expected}\n";
    echo "Actual produk.stok: {$product->stok}\n";
    echo "Discrepancy: " . (intval($product->stok) - $expected) . "\n";
}

echo "\n\n";

echo "=== ISSUES FOUND ===\n";
foreach ($issues as $i) {
    echo "Record #{$i['id']} at {$i['waktu']}\n";
    echo "  Expected stok_awal: {$i['expected_awal']}, Actual: {$i['actual_awal']}, GAP: {$i['gap']}\n";
    echo "  Keterangan: {$i['keterangan']}\n\n";
}
