<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Http\Request;

echo "=== Testing data() endpoint with MANUAL PAGINATION ===\n\n";

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
    if (is_array($row)) {
        echo ($i+1) . ". DT_RowIndex: " . ($row['DT_RowIndex'] ?? 'N/A') . " | waktu_raw: " . ($row['waktu_raw'] ?? 'N/A') . "\n";
    } else {
        echo ($i+1) . ". Object row\n";
    }
}

echo "\n=== Looking for Dec 31 in Page 1 ===\n";
$found = false;
foreach ($json['data'] as $row) {
    $wr = is_array($row) ? ($row['waktu_raw'] ?? '') : ($row->waktu_raw ?? '');
    if (str_contains($wr, '2025-12-31')) {
        echo "FOUND: $wr at DT_RowIndex " . (is_array($row) ? $row['DT_RowIndex'] : $row->DT_RowIndex) . "\n";
        $found = true;
    }
}
if (!$found) {
    echo "Dec 31 NOT in result (maybe pushed to next page?)\n";
}
