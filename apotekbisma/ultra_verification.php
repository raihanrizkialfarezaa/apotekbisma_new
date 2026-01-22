<?php
/**
 * ULTRA COMPREHENSIVE STOCK VERIFICATION
 * Verifies CSV sync + post-cutoff transactions correctness
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$CUTOFF_DATETIME = '2025-12-31 23:59:59';
$CUTOFF_DATE = '2025-12-31';

$reportPath = __DIR__ . '/ultra_verification_report_' . date('Y-m-d_His') . '.txt';

$log = function ($msg) use (&$reportPath) {
    echo $msg . "\n";
    file_put_contents($reportPath, $msg . "\n", FILE_APPEND);
};

$log("=============================================================");
$log("ULTRA COMPREHENSIVE STOCK VERIFICATION");
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
$csvDuplicates = [];
$handle = fopen($csvPath, 'r');
$header = fgetcsv($handle);
$line = 1;
while (($row = fgetcsv($handle)) !== false) {
    $line++;
    if (count($row) < 3) {
        $log("WARNING: CSV line $line has < 3 columns, skipped.");
        continue;
    }
    $id = trim($row[0]);
    $nama = trim($row[1]);
    $stok = (int) trim($row[2]);
    if ($id === '' || !is_numeric($id)) {
        $log("WARNING: CSV line $line invalid ID '$id', skipped.");
        continue;
    }
    if (isset($csvData[(int)$id])) {
        $csvDuplicates[] = [
            'id' => (int)$id,
            'prev' => $csvData[(int)$id],
            'new' => ['nama' => $nama, 'stok' => $stok, 'line' => $line]
        ];
    }
    $csvData[(int)$id] = ['nama' => $nama, 'stok' => $stok, 'line' => $line];
}
fclose($handle);

$log("CSV products loaded: " . count($csvData));
if (count($csvDuplicates) > 0) {
    $log("CSV duplicate IDs: " . count($csvDuplicates));
}
$log("");

// Database counts
$totalDb = DB::table('produk')->count();
$log("Database products: $totalDb");
$log("CSV-only scope: " . count($csvData));
$log("\n=============================================================");
$log("STEP 1: TRANSACTION AGGREGATES AFTER CUTOFF");
$log("=============================================================\n");

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

$log("Products with sales after cutoff: " . count($penjualanData));
$log("Products with purchases after cutoff: " . count($pembelianData));
$log("");

$log("=============================================================");
$log("STEP 2: FULL VERIFICATION AGAINST CSV + TRANSACTIONS");
$log("=============================================================\n");

$correct = 0;
$incorrect = 0;
$missingInDb = 0;
$incorrectList = [];

foreach ($csvData as $id => $item) {
    $dbProduct = DB::table('produk')->where('id_produk', $id)->first();
    if (!$dbProduct) {
        $missingInDb++;
        continue;
    }

    $baseline = (int) $item['stok'];
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
            'actual' => (int) $dbProduct->stok,
        ];
    }
}

$log("Correct: $correct");
$log("Incorrect: $incorrect");
$log("Missing in DB (CSV scope): $missingInDb\n");

if ($incorrect > 0) {
    $log("INCORRECT PRODUCTS (first 100):");
    $count = 0;
    foreach ($incorrectList as $it) {
        if ($count >= 100) break;
        $log(sprintf(
            "ID %d: %s | CSV=%d +%d -%d = %d | DB=%d",
            $it['id'],
            $it['nama'],
            $it['csv'],
            $it['purchased'],
            $it['sold'],
            $it['expected'],
            $it['actual']
        ));
        $count++;
    }
    if (count($incorrectList) > 100) {
        $log("... and " . (count($incorrectList) - 100) . " more.");
    }
    $log("");
}

$log("=============================================================");
$log("STEP 3: BASELINE REKAMAN STOKS CHECK");
$log("=============================================================\n");

$baselineCount = DB::table('rekaman_stoks')
    ->whereDate('created_at', $CUTOFF_DATE)
    ->where('keterangan', 'LIKE', '%Stock Opname%')
    ->count();

$baselineUniqueProducts = DB::table('rekaman_stoks')
    ->whereDate('created_at', $CUTOFF_DATE)
    ->where('keterangan', 'LIKE', '%Stock Opname%')
    ->distinct('id_produk')
    ->count('id_produk');

$log("Baseline records on $CUTOFF_DATE: $baselineCount");
$log("Unique products with baseline: $baselineUniqueProducts");
$log("");

$log("=============================================================");
$log("STEP 4: DB PRODUCTS NOT IN CSV (UNCHANGED SCOPE)");
$log("=============================================================\n");

$notInCsv = DB::table('produk')
    ->whereNotIn('id_produk', array_keys($csvData))
    ->count();

$log("Products in DB but NOT in CSV: $notInCsv");
$log("(These are intentionally NOT synced)\n");

$log("=============================================================");
$log("FINAL SUMMARY");
$log("=============================================================\n");

$log("CSV scope products: " . count($csvData));
$log("Correct within CSV scope: $correct");
$log("Incorrect within CSV scope: $incorrect");
$log("Missing CSV IDs in DB: $missingInDb");
$log("Baseline records count: $baselineCount");
$log("Baseline unique products: $baselineUniqueProducts");
$log("Report saved to: $reportPath");

if ($incorrect === 0 && $missingInDb === 0) {
    $log("\n✓✓✓ VERIFICATION PASSED: 100% SYNC WITH CSV + TRANSACTIONS ✓✓✓");
} else {
    $log("\n⚠ VERIFICATION FAILED: Issues found. See report.");
}
