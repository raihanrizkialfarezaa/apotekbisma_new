<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = new App\Http\Controllers\StockSyncController();
$analysis = $controller->getStockAnalysis();

echo "Inconsistent products count: " . count($analysis['inconsistent_products']) . "\n";
echo "Summary:\n";
print_r($analysis['summary']);

if (count($analysis['inconsistent_products']) > 0) {
    echo "\nFirst inconsistent product:\n";
    print_r($analysis['inconsistent_products'][0]);
}
?>
