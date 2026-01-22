<?php
/**
 * VERIFY CSV PRODUCTS DETAILED (ONE BY ONE)
 * Verifies every CSV product matches expected stock:
 * expected = CSV baseline + pembelian after cutoff - penjualan after cutoff
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$CUTOFF_DATETIME = '2025-12-31 23:59:59';
$reportPath = __DIR__ . '/verify_csv_products_detailed_' . date('Y-m-d_His') . '.txt';

$log = function ($msg) use (&$reportPath) {
    echo $msg . "\n";
    file_put_contents($reportPath, $msg . "\n", FILE_APPEND);
};

$log("=============================================================");
$log("VERIFY CSV PRODUCTS DETAILED (ONE BY ONE)");
$log("Date: " . date('Y-m-d H:i:s'));
$log("Cutoff: $CUTOFF_DATETIME");
$log("=============================================================\n");

// Load CSV
$csvPath = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';
if (!file_exists($csvPath)) {
    $log("ERROR: CSV not found: $csvPath");
    exit(1);
}

$csvData = [];
$dupCount = 0;
$handle = fopen($csvPath, 'r');
$header = fgetcsv($handle);
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) < 3) continue;
    $id = trim($row[0]);
    $nama = trim($row[1]);
    $stok = (int) trim($row[2]);
    if ($id === '' || !is_numeric($id)) continue;
    if (isset($csvData[(int)$id])) $dupCount++;
    $csvData[(int)$id] = ['nama' => $nama, 'stok' => $stok];
}
fclose($handle);

$log("CSV products loaded: " . count($csvData));
$log("CSV duplicate IDs encountered: $dupCount\n");

// Pre-aggregate transactions after cutoff
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

$correct = 0;
$incorrect = 0;
$missingInDb = 0;

$log("ID | NAMA | CSV | +BUY | -SELL | EXPECTED | DB | STATUS");
$log(str_repeat('-', 110));

foreach ($csvData as $id => $item) {
    $dbProduct = DB::table('produk')->where('id_produk', $id)->first();
    if (!$dbProduct) {
        $missingInDb++;
        $log(sprintf("%d | %s | %d | %d | %d | %d | %s | %s",
            $id,
            substr($item['nama'], 0, 40),
            $item['stok'],
            0,
            0,
            $item['stok'],
            'N/A',
            'MISSING_DB'
        ));
        continue;
    }

    $baseline = (int) $item['stok'];
    $sold = isset($penjualanData[$id]) ? (int) $penjualanData[$id]->total_sold : 0;
    $purchased = isset($pembelianData[$id]) ? (int) $pembelianData[$id]->total_purchased : 0;
    $expected = $baseline + $purchased - $sold;
    $actual = (int) $dbProduct->stok;

    if ($actual === $expected) {
        $correct++;
        $status = 'OK';
    } else {
        $incorrect++;
        $status = 'MISMATCH';
    }

    $log(sprintf("%d | %s | %d | %d | %d | %d | %d | %s",
        $id,
        substr($item['nama'], 0, 40),
        $baseline,
        $purchased,
        $sold,
        $expected,
        $actual,
        $status
    ));
}

$log("\n=============================================================");
$log("SUMMARY");
$log("=============================================================\n");
$log("Total CSV products: " . count($csvData));
$log("Correct: $correct");
$log("Incorrect: $incorrect");
$log("Missing in DB: $missingInDb");
$log("Report saved to: $reportPath");

if ($incorrect === 0 && $missingInDb === 0) {
    $log("\n✓✓✓ ALL CSV PRODUCTS VERIFIED CORRECT ✓✓✓");
} else {
    $log("\n⚠ VERIFICATION FOUND ISSUES. See report.");
}
