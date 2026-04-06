<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\BaselineStockReflowService;
use Carbon\Carbon;

$reportPath = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--report=') === 0) {
        $reportPath = substr($arg, 9);
    }
}

if (!$reportPath) {
    fwrite(STDERR, "Gunakan --report=path_ke_json_audit\n");
    exit(1);
}

$resolvedReportPath = $reportPath;
if (!preg_match('/^[A-Za-z]:\\\\|^\\\\|^\//', $resolvedReportPath)) {
    $resolvedReportPath = base_path($reportPath);
}

if (!is_file($resolvedReportPath) || !is_readable($resolvedReportPath)) {
    fwrite(STDERR, "File report tidak ditemukan atau tidak dapat dibaca: {$resolvedReportPath}\n");
    exit(1);
}

$decoded = json_decode(file_get_contents($resolvedReportPath), true);
if (!is_array($decoded)) {
    fwrite(STDERR, "Isi report tidak valid JSON.\n");
    exit(1);
}

$productIds = array_values(array_unique(array_filter(array_map('intval', array_column($decoded['products'] ?? [], 'id_produk')), function ($productId) {
    return $productId > 0;
})));

if (empty($productIds)) {
    echo json_encode([
        'report_path' => $resolvedReportPath,
        'repaired_products' => 0,
        'message' => 'Tidak ada produk anomali pada report audit.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$until = Carbon::now()->format('Y-m-d H:i:s');
$reflowSummary = app(BaselineStockReflowService::class)->rebuildProducts($productIds, $until);

$repairReport = [
    'generated_at' => Carbon::now()->format('Y-m-d H:i:s'),
    'source_audit_report' => $resolvedReportPath,
    'repaired_product_count' => count($productIds),
    'product_ids' => $productIds,
    'reflow_summary' => $reflowSummary,
];

$outputPath = storage_path('app/post_cutoff_stock_card_repair_' . Carbon::now()->format('Ymd_His') . '.json');
file_put_contents($outputPath, json_encode($repairReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'repair_report_path' => $outputPath,
    'repaired_product_count' => count($productIds),
    'reflow_summary' => $reflowSummary,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;