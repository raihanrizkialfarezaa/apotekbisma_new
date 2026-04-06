<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\BaselineStockReflowService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv, true);
$cutoff = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');

function normalize_manual_adjustment_label(string $keterangan): string
{
    $trimmed = trim($keterangan);
    if ($trimmed === '') {
        return 'Penyesuaian Stok Manual';
    }

    if (stripos($trimmed, 'Stock Opname via Edit Produk:') === 0) {
        return preg_replace('/^Stock Opname via Edit Produk:/i', 'Penyesuaian Stok Manual via Edit Produk:', $trimmed) ?? $trimmed;
    }

    if (stripos($trimmed, 'Perubahan Stok Manual via Edit Produk') === 0) {
        return preg_replace('/^Perubahan Stok Manual via Edit Produk/i', 'Penyesuaian Stok Manual via Edit Produk', $trimmed) ?? $trimmed;
    }

    if (preg_match('/^Stock Opname\s*\(Penyesuaian Stok Manual\)$/i', $trimmed)) {
        return 'Penyesuaian Stok Manual';
    }

    if (stripos($trimmed, 'Stock Opname:') === 0) {
        return preg_replace('/^Stock Opname:/i', 'Penyesuaian Stok Manual:', $trimmed) ?? $trimmed;
    }

    return preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed;
}

function infer_manual_adjustment_target($row): ?int
{
    $stokMasuk = intval($row->stok_masuk ?? 0);
    $stokKeluar = intval($row->stok_keluar ?? 0);

    if ($stokMasuk > 0 && $stokKeluar === 0) {
        return $stokMasuk;
    }

    if ($stokKeluar > 0 && $stokMasuk === 0) {
        return $stokKeluar;
    }

    return null;
}

$rows = DB::table('rekaman_stoks')
    ->whereNull('id_pembelian')
    ->whereNull('id_penjualan')
    ->where('waktu', '>', $cutoff)
    ->where('stok_sisa', '<', 0)
    ->where(function ($query) {
        $query->whereRaw("LOWER(COALESCE(keterangan, '')) LIKE '%stock opname%'")
            ->orWhereRaw("LOWER(COALESCE(keterangan, '')) LIKE '%perubahan stok manual%'")
            ->orWhereRaw("LOWER(COALESCE(keterangan, '')) LIKE '%penyesuaian stok%'");
    })
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get([
        'id_rekaman_stok',
        'id_produk',
        'waktu',
        'stok_awal',
        'stok_masuk',
        'stok_keluar',
        'stok_sisa',
        'keterangan',
    ]);

$repairs = [];
$skipped = [];
$affectedProductIds = [];

foreach ($rows as $row) {
    $targetStock = infer_manual_adjustment_target($row);
    if ($targetStock === null || $targetStock < 0) {
        $skipped[] = [
            'id_rekaman_stok' => intval($row->id_rekaman_stok),
            'id_produk' => intval($row->id_produk),
            'reason' => 'ambiguous_target',
        ];
        continue;
    }

    $stokAwal = intval($row->stok_awal ?? 0);
    $correctedMasuk = max(0, $targetStock - $stokAwal);
    $correctedKeluar = max(0, $stokAwal - $targetStock);
    $correctedSisa = $targetStock;

    if ($correctedSisa < 0) {
        $skipped[] = [
            'id_rekaman_stok' => intval($row->id_rekaman_stok),
            'id_produk' => intval($row->id_produk),
            'reason' => 'negative_corrected_target',
        ];
        continue;
    }

    $affectedProductIds[] = intval($row->id_produk);
    $repairs[] = [
        'id_rekaman_stok' => intval($row->id_rekaman_stok),
        'id_produk' => intval($row->id_produk),
        'waktu' => Carbon::parse($row->waktu)->format('Y-m-d H:i:s'),
        'before' => [
            'stok_awal' => $stokAwal,
            'stok_masuk' => intval($row->stok_masuk ?? 0),
            'stok_keluar' => intval($row->stok_keluar ?? 0),
            'stok_sisa' => intval($row->stok_sisa ?? 0),
            'keterangan' => (string) ($row->keterangan ?? ''),
        ],
        'after' => [
            'stok_awal' => $stokAwal,
            'stok_masuk' => $correctedMasuk,
            'stok_keluar' => $correctedKeluar,
            'stok_sisa' => $correctedSisa,
            'keterangan' => normalize_manual_adjustment_label((string) ($row->keterangan ?? '')),
        ],
    ];
}

$affectedProductIds = array_values(array_unique($affectedProductIds));
$reflowSummary = null;

if ($apply && !empty($repairs)) {
    DB::transaction(function () use ($repairs, $affectedProductIds, &$reflowSummary) {
        foreach ($repairs as $repair) {
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $repair['id_rekaman_stok'])
                ->update([
                    'waktu' => $repair['waktu'],
                    'stok_awal' => $repair['after']['stok_awal'],
                    'stok_masuk' => $repair['after']['stok_masuk'],
                    'stok_keluar' => $repair['after']['stok_keluar'],
                    'stok_sisa' => $repair['after']['stok_sisa'],
                    'keterangan' => $repair['after']['keterangan'],
                    'updated_at' => Carbon::now(),
                ]);
        }

        $reflowSummary = app(BaselineStockReflowService::class)
            ->rebuildProducts($affectedProductIds, Carbon::now()->format('Y-m-d H:i:s'));
    }, 3);
}

$report = [
    'generated_at' => Carbon::now()->format('Y-m-d H:i:s'),
    'apply' => $apply,
    'cutoff' => $cutoff,
    'rows_scanned' => $rows->count(),
    'repairs_planned' => count($repairs),
    'skipped' => $skipped,
    'affected_product_ids' => $affectedProductIds,
    'repairs' => $repairs,
    'reflow_summary' => $reflowSummary,
];

$outputPath = storage_path('app/manual_stock_adjustment_repair_' . Carbon::now()->format('Ymd_His') . '.json');
file_put_contents($outputPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'report_path' => $outputPath,
    'apply' => $apply,
    'rows_scanned' => $rows->count(),
    'repairs_planned' => count($repairs),
    'affected_product_ids' => $affectedProductIds,
    'reflow_summary' => $reflowSummary,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;