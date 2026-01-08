<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Http\Request;

echo "=== Testing data() endpoint ===\n\n";

$controller = new \App\Http\Controllers\KartuStokController();

$request = new Request();
$request->merge([
    'draw' => 1,
    'start' => 0,
    'length' => 10,
    'order' => [['column' => 1, 'dir' => 'desc']],
    'date_filter' => 'all'
]);

$response = $controller->data(63, $request);
$json = json_decode($response->getContent(), true);

echo "recordsTotal: " . $json['recordsTotal'] . "\n";
echo "recordsFiltered: " . $json['recordsFiltered'] . "\n";
echo "data count: " . count($json['data']) . "\n\n";

echo "First 10 rows:\n";
foreach ($json['data'] as $i => $row) {
    echo ($i+1) . ". DT_RowIndex: " . $row['DT_RowIndex'] . " | waktu_raw: " . $row['waktu_raw'] . "\n";
}

echo "\n=== Looking for Dec 31 ===\n";
$found = false;
foreach ($json['data'] as $row) {
    if (isset($row['waktu_raw']) && str_contains($row['waktu_raw'], '2025-12-31')) {
        echo "FOUND: " . $row['waktu_raw'] . " at DT_RowIndex " . $row['DT_RowIndex'] . "\n";
        $found = true;
    }
}
if (!$found) {
    echo "Dec 31 NOT in first page (expected if pagination works)\n";
}
