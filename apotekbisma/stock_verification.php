<?php
/**
 * STOCK VERIFICATION SCRIPT
 * Verifikasi akhir setelah ultimate_stock_fix.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$CUTOFF_DATETIME = '2025-12-31 23:59:59';

echo "=============================================================\n";
echo "STOCK VERIFICATION SCRIPT\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "=============================================================\n\n";

// 1. Load CSV
$csvPath = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
$csvData = [];
$handle = fopen($csvPath, 'r');
$header = fgetcsv($handle);

while (($row = fgetcsv($handle)) !== false) {
    if (count($row) >= 3) {
        $id = (int) trim($row[0]);
        $nama = trim($row[1]);
        $stok = (int) trim($row[2]);
        $csvData[$id] = ['nama' => $nama, 'stok' => $stok];
    }
}
fclose($handle);

echo "CSV loaded: " . count($csvData) . " products\n\n";

// 2. Get transactions after cutoff
$penjualanData = DB::table('penjualan_detail as pd')
    ->join('penjualan as p', 'pd.id_penjualan', '=', 'p.id_penjualan')
    ->where('p.created_at', '>', $CUTOFF_DATETIME)
    ->select('pd.id_produk', DB::raw('SUM(pd.jumlah) as total_sold'))
    ->groupBy('pd.id_produk')
    ->get()
    ->keyBy('id_produk');

$pembelianData = DB::table('pembelian_detail as pd')
    ->join('pembelian as p', 'pd.id_pembelian', '=', 'p.id_pembelian')
    ->where('p.created_at', '>', $CUTOFF_DATETIME)
    ->select('pd.id_produk', DB::raw('SUM(pd.jumlah) as total_purchased'))
    ->groupBy('pd.id_produk')
    ->get()
    ->keyBy('id_produk');

// 3. Verify specific products mentioned by user
echo "=============================================================\n";
echo "CHECKING SPECIFIC PRODUCTS\n";
echo "=============================================================\n\n";

$checkProducts = [
    'ACTIFED',
    'ANACETIN',
    'HYPAFIX',
    'MADURASA',
    'VEGETA',
    'BYE FEVER',
    'TOLAK ANGIN',
    'B1 STRIP'
];

foreach ($checkProducts as $search) {
    echo "--- $search ---\n";
    
    // Find in CSV
    foreach ($csvData as $id => $item) {
        if (stripos($item['nama'], $search) !== false) {
            $dbProduct = DB::table('produk')->where('id_produk', $id)->first();
            
            $baseline = $item['stok'];
            $sold = isset($penjualanData[$id]) ? (int) $penjualanData[$id]->total_sold : 0;
            $purchased = isset($pembelianData[$id]) ? (int) $pembelianData[$id]->total_purchased : 0;
            $expected = $baseline + $purchased - $sold;
            
            $status = $dbProduct && (int)$dbProduct->stok === $expected ? '✓' : '✗';
            
            echo sprintf(
                "  ID %4d: %-30s | CSV=%3d +%3d -%3d = %3d | DB=%3d | %s\n",
                $id,
                substr($item['nama'], 0, 30),
                $baseline,
                $purchased,
                $sold,
                $expected,
                $dbProduct ? $dbProduct->stok : 'N/A',
                $status
            );
        }
    }
    
    // Find in DB that's NOT in CSV
    $dbProducts = DB::table('produk')
        ->where('nama_produk', 'LIKE', "%{$search}%")
        ->get();
    
    foreach ($dbProducts as $p) {
        if (!isset($csvData[$p->id_produk])) {
            echo sprintf(
                "  ID %4d: %-30s | NOT IN CSV | DB=%3d | (unchanged)\n",
                $p->id_produk,
                substr($p->nama_produk, 0, 30),
                $p->stok
            );
        }
    }
    echo "\n";
}

// 4. Full verification
echo "=============================================================\n";
echo "FULL VERIFICATION\n";
echo "=============================================================\n\n";

$correct = 0;
$incorrect = 0;
$incorrectList = [];

foreach ($csvData as $id => $item) {
    $dbProduct = DB::table('produk')->where('id_produk', $id)->first();
    if (!$dbProduct) continue;
    
    $baseline = $item['stok'];
    $sold = isset($penjualanData[$id]) ? (int) $penjualanData[$id]->total_sold : 0;
    $purchased = isset($pembelianData[$id]) ? (int) $pembelianData[$id]->total_purchased : 0;
    $expected = $baseline + $purchased - $sold;
    
    if ((int) $dbProduct->stok === $expected) {
        $correct++;
    } else {
        $incorrect++;
        $incorrectList[] = [
            'id' => $id,
            'nama' => $item['nama'],
            'csv' => $baseline,
            'purchased' => $purchased,
            'sold' => $sold,
            'expected' => $expected,
            'actual' => $dbProduct->stok
        ];
    }
}

echo "Correct: $correct\n";
echo "Incorrect: $incorrect\n\n";

if ($incorrect > 0) {
    echo "INCORRECT PRODUCTS:\n";
    foreach ($incorrectList as $item) {
        echo sprintf(
            "ID %d: %s | CSV=%d +%d -%d = Expected %d, Actual %d\n",
            $item['id'],
            $item['nama'],
            $item['csv'],
            $item['purchased'],
            $item['sold'],
            $item['expected'],
            $item['actual']
        );
    }
}

// Final summary
echo "\n=============================================================\n";
if ($incorrect === 0) {
    echo "✓✓✓ ALL " . count($csvData) . " PRODUCTS ARE CORRECTLY SYNCHRONIZED! ✓✓✓\n";
} else {
    echo "⚠ WARNING: $incorrect products have incorrect stock!\n";
}
echo "=============================================================\n";
