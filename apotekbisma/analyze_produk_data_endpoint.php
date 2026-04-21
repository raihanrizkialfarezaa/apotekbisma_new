<?php

use App\Http\Controllers\ProdukController;
use App\Models\Produk;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$controller = app(ProdukController::class);

$options = [
    'draw' => 1,
    'start' => 0,
    'length' => 10,
    'search' => '',
    'filter_stok' => '',
    'order_column' => 2,
    'order_dir' => 'asc',
    'column_id_search' => '',
    'column_name_search' => '',
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

$request = Request::create('/produk/data', 'GET', [
    'draw' => intval($options['draw']),
    'start' => intval($options['start']),
    'length' => intval($options['length']),
    'filter_stok' => (string) $options['filter_stok'],
    'search' => ['value' => (string) $options['search'], 'regex' => 'false'],
    'order' => [[
        'column' => intval($options['order_column']),
        'dir' => strtolower((string) $options['order_dir']) === 'desc' ? 'desc' : 'asc',
    ]],
    'columns' => [
        ['data' => 'select_all', 'search' => ['value' => '']],
        ['data' => 'DT_RowIndex', 'search' => ['value' => '']],
        ['data' => 'id_produk', 'search' => ['value' => (string) $options['column_id_search']]],
        ['data' => 'nama_produk', 'search' => ['value' => (string) $options['column_name_search']]],
        ['data' => 'nama_kategori', 'search' => ['value' => '']],
        ['data' => 'merk', 'search' => ['value' => '']],
        ['data' => 'harga_beli', 'search' => ['value' => '']],
        ['data' => 'harga_jual', 'search' => ['value' => '']],
        ['data' => 'expired_date', 'search' => ['value' => '']],
        ['data' => 'batch', 'search' => ['value' => '']],
        ['data' => 'stok', 'search' => ['value' => '']],
        ['data' => 'aksi', 'search' => ['value' => '']],
    ],
]);

$totalProducts = Produk::count();
$lowStockProducts = Produk::where('stok', '<=', 20)->count();

$start = microtime(true);
$queryCount = 0;

DB::listen(function () use (&$queryCount) {
    $queryCount++;
});

fwrite(STDOUT, "Analisis endpoint /produk/data\n");
fwrite(STDOUT, str_repeat('=', 90) . "\n");
fwrite(STDOUT, 'Total produk                : ' . $totalProducts . "\n");
fwrite(STDOUT, 'Produk stok <= 20           : ' . $lowStockProducts . "\n");
fwrite(STDOUT, 'Filter stok                 : ' . ((string) $options['filter_stok'] === '' ? '-' : $options['filter_stok']) . "\n");
fwrite(STDOUT, 'Global search               : ' . ((string) $options['search'] === '' ? '-' : $options['search']) . "\n");
fwrite(STDOUT, 'ID column search            : ' . ((string) $options['column_id_search'] === '' ? '-' : $options['column_id_search']) . "\n");
fwrite(STDOUT, 'Nama column search          : ' . ((string) $options['column_name_search'] === '' ? '-' : $options['column_name_search']) . "\n");

try {
    $response = $controller->data($request);
    $elapsed = microtime(true) - $start;
    $content = $response->getContent();
    $decoded = json_decode($content, true);
    $statusCode = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;

    fwrite(STDOUT, 'Status response             : ' . $statusCode . "\n");
    fwrite(STDOUT, 'Durasi eksekusi             : ' . number_format($elapsed, 3) . " detik\n");
    fwrite(STDOUT, 'Jumlah query DB             : ' . $queryCount . "\n");
    fwrite(STDOUT, 'JSON valid?                 : ' . (json_last_error() === JSON_ERROR_NONE ? 'YA' : 'TIDAK') . "\n");

    if (is_array($decoded)) {
        fwrite(STDOUT, 'recordsTotal               : ' . intval($decoded['recordsTotal'] ?? 0) . "\n");
        fwrite(STDOUT, 'recordsFiltered            : ' . intval($decoded['recordsFiltered'] ?? 0) . "\n");
        fwrite(STDOUT, 'Row count payload          : ' . count($decoded['data'] ?? []) . "\n");
    } else {
        fwrite(STDOUT, "Response bukan JSON array. Potongan awal:\n");
        fwrite(STDOUT, substr($content, 0, 1000) . "\n");
    }
} catch (Throwable $throwable) {
    $elapsed = microtime(true) - $start;
    fwrite(STDOUT, 'Durasi sebelum exception    : ' . number_format($elapsed, 3) . " detik\n");
    fwrite(STDOUT, 'Jumlah query DB             : ' . $queryCount . "\n");
    fwrite(STDOUT, 'Exception class            : ' . get_class($throwable) . "\n");
    fwrite(STDOUT, 'Exception message          : ' . $throwable->getMessage() . "\n");
    fwrite(STDOUT, 'Exception file             : ' . $throwable->getFile() . ':' . $throwable->getLine() . "\n");
    fwrite(STDOUT, "Trace:\n" . $throwable->getTraceAsString() . "\n");
    exit(1);
}