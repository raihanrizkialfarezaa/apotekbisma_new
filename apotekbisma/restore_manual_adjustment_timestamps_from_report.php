<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\BaselineStockReflowService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$reportPath = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--report=') === 0) {
        $reportPath = substr($arg, 9);
    }
}

if (!$reportPath) {
    fwrite(STDERR, "Gunakan --report=path_ke_report_repair\n");
    exit(1);
}

$resolvedReportPath = $reportPath;
if (!preg_match('/^[A-Za-z]:\\\\|^\\\\|^\//', $resolvedReportPath)) {
    $resolvedReportPath = base_path($reportPath);
}

if (!is_file($resolvedReportPath) || !is_readable($resolvedReportPath)) {
    fwrite(STDERR, "Report repair tidak dapat dibaca: {$resolvedReportPath}\n");
    exit(1);
}

$decoded = json_decode(file_get_contents($resolvedReportPath), true);
if (!is_array($decoded)) {
    fwrite(STDERR, "Report repair bukan JSON valid.\n");
    exit(1);
}

$repairs = $decoded['repairs'] ?? [];
if (empty($repairs)) {
    echo json_encode([
        'report_path' => $resolvedReportPath,
        'restored_rows' => 0,
        'message' => 'Tidak ada repair rows di report.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$updatedRows = [];
$missingRows = [];
$affectedProductIds = [];
$reflowSummary = null;

function is_manual_adjustment_label(string $keterangan): bool
{
    $normalized = strtolower(trim($keterangan));
    if ($normalized === '') {
        return false;
    }

    return strpos($normalized, 'stock opname') !== false
        || strpos($normalized, 'perubahan stok manual') !== false
        || strpos($normalized, 'penyesuaian stok manual') !== false
        || strpos($normalized, 'penyesuaian stok') !== false;
}

DB::transaction(function () use ($repairs, &$updatedRows, &$missingRows, &$affectedProductIds, &$reflowSummary) {
    $repairsByProduct = [];
    foreach ($repairs as $repair) {
        $repairsByProduct[intval($repair['id_produk'])][] = $repair;
    }

    foreach ($repairsByProduct as $productId => $productRepairs) {
        $currentRows = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->whereNull('id_pembelian')
            ->whereNull('id_penjualan')
            ->get(['id_rekaman_stok', 'keterangan']);

        $currentRows = $currentRows
            ->filter(function ($row) {
                return is_manual_adjustment_label((string) ($row->keterangan ?? ''));
            })
            ->values();

        if ($currentRows->isNotEmpty()) {
            DB::table('rekaman_stoks')
                ->whereIn('id_rekaman_stok', $currentRows->pluck('id_rekaman_stok')->all())
                ->delete();
        }

        usort($productRepairs, function (array $left, array $right) {
            $timeCompare = strcmp((string) $left['waktu'], (string) $right['waktu']);
            if ($timeCompare !== 0) {
                return $timeCompare;
            }

            return intval($left['id_rekaman_stok']) <=> intval($right['id_rekaman_stok']);
        });

        foreach ($productRepairs as $repair) {
            DB::table('rekaman_stoks')->insert([
                'id_produk' => $productId,
                'id_pembelian' => null,
                'id_penjualan' => null,
                'waktu' => (string) $repair['waktu'],
                'stok_awal' => intval($repair['after']['stok_awal']),
                'stok_masuk' => intval($repair['after']['stok_masuk']),
                'stok_keluar' => intval($repair['after']['stok_keluar']),
                'stok_sisa' => intval($repair['after']['stok_sisa']),
                'keterangan' => (string) $repair['after']['keterangan'],
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $updatedRows[] = [
                'id_produk' => $productId,
                'restored_waktu' => (string) $repair['waktu'],
                'restored_target_stock' => intval($repair['after']['stok_sisa']),
            ];
            $affectedProductIds[] = $productId;
        }
    }

    $affectedProductIds = array_values(array_unique($affectedProductIds));
    if (!empty($affectedProductIds)) {
        $reflowSummary = app(BaselineStockReflowService::class)
            ->rebuildProducts($affectedProductIds, Carbon::now()->format('Y-m-d H:i:s'));
    }
}, 3);

$outputPath = storage_path('app/manual_stock_adjustment_timestamp_restore_' . Carbon::now()->format('Ymd_His') . '.json');
file_put_contents($outputPath, json_encode([
    'generated_at' => Carbon::now()->format('Y-m-d H:i:s'),
    'source_report' => $resolvedReportPath,
    'updated_rows' => $updatedRows,
    'missing_rows' => $missingRows,
    'affected_product_ids' => array_values(array_unique($affectedProductIds)),
    'reflow_summary' => $reflowSummary,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'report_path' => $outputPath,
    'updated_row_count' => count($updatedRows),
    'missing_row_count' => count($missingRows),
    'affected_product_ids' => array_values(array_unique($affectedProductIds)),
    'reflow_summary' => $reflowSummary,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;