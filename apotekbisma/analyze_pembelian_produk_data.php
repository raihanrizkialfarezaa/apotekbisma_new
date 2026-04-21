<?php

use App\Http\Controllers\PembelianDetailController;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$controller = app(PembelianDetailController::class);

$options = [
    'draw' => 1,
    'start' => 0,
    'length' => 10,
    'search' => '',
    'order_column' => 2,
    'order_dir' => 'asc',
];

foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
        continue;
    }

    [$key, $value] = explode('=', substr($argument, 2), 2);
    if (array_key_exists($key, $options)) {
        $options[$key] = $value;
    }
}

$request = Request::create('/pembelian_detail/produk-data', 'GET', [
    'draw' => intval($options['draw']),
    'start' => intval($options['start']),
    'length' => intval($options['length']),
    'search' => ['value' => (string) $options['search'], 'regex' => 'false'],
    'order' => [[
        'column' => intval($options['order_column']),
        'dir' => strtolower((string) $options['order_dir']) === 'desc' ? 'desc' : 'asc',
    ]],
]);
app()->instance('request', $request);

DB::flushQueryLog();
DB::enableQueryLog();

$startedAt = microtime(true);

try {
    $response = $controller->getProdukData($request);
    $duration = microtime(true) - $startedAt;
    $queryCount = count(DB::getQueryLog());

    $statusCode = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
    $content = method_exists($response, 'getContent') ? $response->getContent() : (string) $response;
    $decoded = json_decode($content, true);

    fwrite(STDOUT, "Analisis endpoint /pembelian_detail/produk-data\n");
    fwrite(STDOUT, str_repeat('=', 90) . "\n");
    fwrite(STDOUT, 'Global search               : ' . ((string) $options['search'] === '' ? '-' : $options['search']) . "\n");
    fwrite(STDOUT, 'Status response             : ' . $statusCode . "\n");
    fwrite(STDOUT, 'Durasi eksekusi             : ' . number_format($duration, 3) . " detik\n");
    fwrite(STDOUT, 'Jumlah query DB             : ' . $queryCount . "\n");
    fwrite(STDOUT, 'JSON valid?                 : ' . (json_last_error() === JSON_ERROR_NONE ? 'YA' : 'TIDAK') . "\n");
    fwrite(STDOUT, 'Payload bytes               : ' . strlen($content) . "\n");

    if (is_array($decoded)) {
        $rows = [];

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            fwrite(STDOUT, 'recordsTotal               : ' . intval($decoded['recordsTotal'] ?? 0) . "\n");
            fwrite(STDOUT, 'recordsFiltered            : ' . intval($decoded['recordsFiltered'] ?? 0) . "\n");
            $rows = $decoded['data'];
        } else {
            $rows = $decoded;
        }

        fwrite(STDOUT, 'Row count payload           : ' . count($rows) . "\n");

        if (!empty($rows[0]) && is_array($rows[0])) {
            fwrite(STDOUT, 'Kolom row pertama           : ' . implode(', ', array_keys($rows[0])) . "\n");
            fwrite(STDOUT, 'Contoh row pertama          : ' . json_encode($rows[0], JSON_UNESCAPED_UNICODE) . "\n");
        }
    }
} catch (Throwable $throwable) {
    $duration = microtime(true) - $startedAt;
    fwrite(STDOUT, "Analisis endpoint /pembelian_detail/produk-data\n");
    fwrite(STDOUT, str_repeat('=', 90) . "\n");
    fwrite(STDOUT, 'Status response             : EXCEPTION' . "\n");
    fwrite(STDOUT, 'Durasi eksekusi             : ' . number_format($duration, 3) . " detik\n");
    fwrite(STDOUT, 'Exception class             : ' . get_class($throwable) . "\n");
    fwrite(STDOUT, 'Exception message           : ' . $throwable->getMessage() . "\n");
    fwrite(STDOUT, 'Exception file              : ' . $throwable->getFile() . ':' . $throwable->getLine() . "\n");
    exit(1);
}
