<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\KartuStokController;
use App\Services\BaselineStockReflowService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$productId = 862;
$pageLength = 25;

function printHeader(string $title): void
{
    echo "\n=== {$title} ===\n";
}

function toPlainText(string $html): string
{
    return trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
}

$product = DB::table('produk')
    ->where('id_produk', $productId)
    ->first(['id_produk', 'nama_produk', 'stok']);

if (!$product) {
    echo "Produk {$productId} tidak ditemukan.\n";
    exit(1);
}

echo "Inspecting kartu stok visibility for product #{$productId} ({$product->nama_produk})\n";
echo 'Current produk.stok: ' . (int) $product->stok . "\n";

$controller = app(KartuStokController::class);
$request = Request::create('/debug/kartu-stok', 'GET', ['date_filter' => 'all']);
$rows = $controller->getDataFiltered($productId, $request);

usort($rows, static function (array $left, array $right): int {
    $timeCompare = strcmp((string) ($right['waktu_raw'] ?? ''), (string) ($left['waktu_raw'] ?? ''));
    if ($timeCompare !== 0) {
        return $timeCompare;
    }

    return ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0));
});

printHeader('Dataset Summary');
echo 'Total rows in dataStokLengkap: ' . count($rows) . "\n";
echo 'UI page length: ' . $pageLength . "\n";
echo 'Estimated last page: ' . (int) ceil(max(count($rows), 1) / $pageLength) . "\n";

$baselineRows = [];
foreach ($rows as $index => $row) {
    $plainKeterangan = toPlainText((string) ($row['keterangan'] ?? ''));
    if (stripos($plainKeterangan, 'Saldo Awal Stok') === false) {
        continue;
    }

    $baselineRows[] = [
        'position' => $index + 1,
        'page' => (int) floor($index / $pageLength) + 1,
        'waktu_raw' => (string) ($row['waktu_raw'] ?? ''),
        'tanggal' => toPlainText((string) ($row['tanggal'] ?? '')),
        'stok_masuk' => toPlainText((string) ($row['stok_masuk'] ?? '')),
        'stok_keluar' => toPlainText((string) ($row['stok_keluar'] ?? '')),
        'stok_sisa' => toPlainText((string) ($row['stok_sisa'] ?? '')),
        'keterangan' => $plainKeterangan,
    ];
}

printHeader('Baseline Rows In Final Card Dataset');
if ($baselineRows === []) {
    echo "No row containing 'Saldo Awal Stok' was found in the final kartu stok dataset.\n";
} else {
    foreach ($baselineRows as $row) {
        echo sprintf(
            "position=%d | page=%d | waktu=%s | masuk=%s | keluar=%s | sisa=%s | ket=%s\n",
            $row['position'],
            $row['page'],
            $row['waktu_raw'],
            $row['stok_masuk'],
            $row['stok_keluar'],
            $row['stok_sisa'],
            $row['keterangan']
        );
    }
}

$ledger = app(BaselineStockReflowService::class)
    ->previewProductLedgers([$productId], Carbon::now()->format('Y-m-d H:i:s'))[$productId] ?? null;

printHeader('Ledger Seed Row');
if ($ledger === null) {
    echo "Ledger not available.\n";
} else {
    $seedRow = ($ledger['rows'] ?? [])[0] ?? null;
    if ($seedRow === null) {
        echo "Ledger has no rows.\n";
    } else {
        echo json_encode($seedRow, JSON_UNESCAPED_UNICODE) . "\n";
    }
    echo 'seed_source: ' . (string) ($ledger['seed_source'] ?? '-') . "\n";
    echo 'final_stock: ' . (int) ($ledger['final_stock'] ?? 0) . "\n";
}

printHeader('First 5 Rows In UI Order');
foreach (array_slice($rows, 0, 5) as $index => $row) {
    echo sprintf(
        "%d. %s | %s\n",
        $index + 1,
        (string) ($row['waktu_raw'] ?? '-'),
        toPlainText((string) ($row['keterangan'] ?? '-'))
    );
}

printHeader('Last 5 Rows In UI Order');
$lastRows = array_slice($rows, -5);
foreach ($lastRows as $index => $row) {
    echo sprintf(
        "%d. %s | %s\n",
        count($rows) - count($lastRows) + $index + 1,
        (string) ($row['waktu_raw'] ?? '-'),
        toPlainText((string) ($row['keterangan'] ?? '-'))
    );
}
