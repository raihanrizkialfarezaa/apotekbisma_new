<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\KartuStokController;
use Illuminate\Http\Request;

$productId = isset($argv[1]) ? max(0, intval($argv[1])) : 862;
$request = new Request(['date_filter' => 'all']);
$controller = app(KartuStokController::class);
$rows = $controller->getDataFiltered($productId, $request);

$signatures = [];
foreach ($rows as $row) {
    $signature = implode('|', [
        (string) ($row['waktu_raw'] ?? ''),
        strip_tags((string) ($row['keterangan'] ?? '')),
        (string) ($row['stok_masuk'] ?? ''),
        (string) ($row['stok_keluar'] ?? ''),
        strip_tags((string) ($row['stok_sisa'] ?? '')),
    ]);
    $signatures[$signature] = ($signatures[$signature] ?? 0) + 1;
}

$duplicates = array_filter($signatures, function (int $count) {
    return $count > 1;
});

echo 'product_id=' . $productId . PHP_EOL;
echo 'row_count=' . count($rows) . PHP_EOL;
echo 'duplicate_signature_count=' . count($duplicates) . PHP_EOL;

echo PHP_EOL . 'first_rows:' . PHP_EOL;
foreach (array_slice($rows, 0, 5) as $index => $row) {
    echo sprintf(
        "%d. %s | masuk=%s | keluar=%s | sisa=%s | %s\n",
        $index + 1,
        (string) ($row['waktu_raw'] ?? ''),
        (string) ($row['stok_masuk'] ?? '-'),
        (string) ($row['stok_keluar'] ?? '-'),
        strip_tags((string) ($row['stok_sisa'] ?? '-')),
        trim(strip_tags((string) ($row['keterangan'] ?? '')))
    );
}

echo PHP_EOL . 'last_rows:' . PHP_EOL;
foreach (array_slice($rows, -5) as $index => $row) {
    echo sprintf(
        "%d. %s | masuk=%s | keluar=%s | sisa=%s | %s\n",
        count($rows) - 4 + $index,
        (string) ($row['waktu_raw'] ?? ''),
        (string) ($row['stok_masuk'] ?? '-'),
        (string) ($row['stok_keluar'] ?? '-'),
        strip_tags((string) ($row['stok_sisa'] ?? '-')),
        trim(strip_tags((string) ($row['keterangan'] ?? '')))
    );
}
