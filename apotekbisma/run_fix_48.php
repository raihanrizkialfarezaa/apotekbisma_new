<?php
// run_fix_48.php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

use App\Http\Controllers\KartuStokController;

echo "=== MEMPERBAIKI STOK PRODUK ID 48 ===\n";

try {
    $controller = new KartuStokController();
    $response = $controller->fixRecordsForProduct(48);
    
    // Extract JSON from response
    $content = $response->getContent();
    $result = json_decode($content, true);
    
    if ($result['success']) {
        echo "SUKSES!\n";
        echo "Total Records: " . $result['stats']['total_records'] . "\n"; // Assuming key structure based on similar methods
        echo implode("\n", $result['steps']) . "\n";
    } else {
        echo "GAGAL: " . ($result['error'] ?? 'Unknown error') . "\n";
    }
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
