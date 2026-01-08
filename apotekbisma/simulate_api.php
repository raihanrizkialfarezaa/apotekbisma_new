<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$controller = new \App\Http\Controllers\KartuStokController();

$request = new \Illuminate\Http\Request();
$request->merge(['date_filter' => 'all']);

$data = $controller->getDataFiltered(63, $request);

echo "Total rows returned: " . count($data) . "\n\n";

$found = false;
foreach ($data as $index => $row) {
    if (isset($row['waktu_raw']) && str_contains($row['waktu_raw'], '2025-12-31')) {
        echo "FOUND Dec 31 at index $index:\n";
        echo "  tanggal: " . ($row['tanggal'] ?? 'N/A') . "\n";
        echo "  waktu_raw: " . ($row['waktu_raw'] ?? 'N/A') . "\n";
        echo "  DT_RowIndex: " . ($row['DT_RowIndex'] ?? 'N/A') . "\n";
        $found = true;
    }
}

if (!$found) {
    echo "Dec 31 NOT FOUND in API response!\n";
    echo "Checking last 10 rows by waktu_raw:\n";
    
    usort($data, function($a, $b) {
        return strcmp($b['waktu_raw'] ?? '', $a['waktu_raw'] ?? '');
    });
    
    for ($i = 0; $i < min(10, count($data)); $i++) {
        echo ($i+1) . ". " . ($data[$i]['waktu_raw'] ?? 'N/A') . " | " . ($data[$i]['tanggal'] ?? 'N/A') . "\n";
    }
}
