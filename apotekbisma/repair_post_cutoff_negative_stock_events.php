<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\BaselineStockReflowService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv, true);
$reportPath = null;
$requestedIds = [];

foreach ($argv as $arg) {
    if (strpos($arg, '--report=') === 0) {
        $reportPath = substr($arg, 9);
        continue;
    }

    if (strpos($arg, '--ids=') === 0) {
        $requestedIds = array_values(array_unique(array_filter(array_map('intval', explode(',', substr($arg, 6))), function ($value) {
            return $value > 0;
        })));
    }
}

if (!$reportPath) {
    $candidates = glob(storage_path('app/post_cutoff_stock_card_chain_audit_*.json')) ?: [];
    rsort($candidates);
    $reportPath = $candidates[0] ?? null;
}

if (!$reportPath) {
    fwrite(STDERR, "Tidak ada report audit post-cutoff yang ditemukan.\n");
    exit(1);
}

$resolvedReportPath = $reportPath;
if (!preg_match('/^[A-Za-z]:\\\\|^\\\\|^\//', $resolvedReportPath)) {
    $resolvedReportPath = base_path($reportPath);
}

if (!is_file($resolvedReportPath) || !is_readable($resolvedReportPath)) {
    fwrite(STDERR, "Report audit tidak dapat dibaca: {$resolvedReportPath}\n");
    exit(1);
}

$auditReport = json_decode(file_get_contents($resolvedReportPath), true);
if (!is_array($auditReport)) {
    fwrite(STDERR, "Report audit bukan JSON valid.\n");
    exit(1);
}

$negativeProducts = $auditReport['negative_stock_products'] ?? [];
$targetProductIds = array_values(array_unique(array_filter(array_map(function ($row) {
    return intval($row['id_produk'] ?? 0);
}, $negativeProducts), function ($productId) {
    return $productId > 0;
})));

if (!empty($requestedIds)) {
    $targetProductIds = array_values(array_intersect($targetProductIds, $requestedIds));
}

if (empty($targetProductIds)) {
    echo json_encode([
        'source_report' => $resolvedReportPath,
        'message' => 'Tidak ada produk negative event yang cocok dengan scope repair.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$cutoff = Carbon::parse((string) ($auditReport['cutoff'] ?? config('stock.cutoff_datetime', '2025-12-31 23:59:59')));
$auditedUntil = Carbon::parse((string) ($auditReport['audited_until'] ?? Carbon::now()->format('Y-m-d H:i:s')));
$now = Carbon::now();
$reflowService = app(BaselineStockReflowService::class);
$currentTime = $now->format('Y-m-d H:i:s');
$autoMarker = '[AUTO-NEGATIVE-STABILIZER]';

function build_stock_order_query($query)
{
    return $query
        ->orderBy('waktu', 'asc')
        ->orderByRaw("CASE
            WHEN id_pembelian IS NOT NULL THEN 0
            WHEN id_penjualan IS NOT NULL THEN 1
            WHEN LOWER(COALESCE(keterangan, '')) LIKE '%stock opname%' THEN 2
            WHEN LOWER(COALESCE(keterangan, '')) LIKE '%perubahan stok manual%' THEN 2
            WHEN LOWER(COALESCE(keterangan, '')) LIKE '%penyesuaian stok%' THEN 2
            WHEN LOWER(COALESCE(keterangan, '')) LIKE '%saldo awal stok%' THEN 2
            ELSE 3
        END ASC")
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc');
}

function is_seed_row(array $row): bool
{
    $keterangan = mb_strtolower(trim((string) ($row['keterangan'] ?? '')));

    return $row['id_pembelian'] === null
        && $row['id_penjualan'] === null
        && intval($row['stok_masuk']) === 0
        && intval($row['stok_keluar']) === 0
        && str_contains($keterangan, 'saldo awal stok');
}

function resolve_pre_adjustment_time(string $waktu, Carbon $cutoff, array &$usedTimes): string
{
    $candidate = Carbon::parse($waktu)->subSecond();
    $minimum = $cutoff->copy()->addSecond();
    if ($candidate->lte($cutoff)) {
        $candidate = $minimum->copy();
    }

    while (isset($usedTimes[$candidate->format('Y-m-d H:i:s')])) {
        $candidate->subSecond();
        if ($candidate->lte($cutoff)) {
            $candidate = $minimum->copy()->addSeconds(count($usedTimes));
            break;
        }
    }

    $usedTimes[$candidate->format('Y-m-d H:i:s')] = true;

    return $candidate->format('Y-m-d H:i:s');
}

function build_manual_source_row(int $productId, string $waktu, int $targetStock, int $deltaQty, string $label, string $timestamp): array
{
    return [
        'id_produk' => $productId,
        'id_pembelian' => null,
        'id_penjualan' => null,
        'waktu' => $waktu,
        'stok_awal' => 0,
        'stok_masuk' => max(0, $deltaQty),
        'stok_keluar' => max(0, -$deltaQty),
        'stok_sisa' => $targetStock,
        'keterangan' => $label,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ];
}

$plans = [];
$rowsToInsert = [];

foreach ($targetProductIds as $productId) {
    $product = DB::table('produk')
        ->where('id_produk', $productId)
        ->select('id_produk', 'nama_produk', 'stok')
        ->first();

    if (!$product) {
        continue;
    }

    $rows = build_stock_order_query(
        DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('waktu', '>=', $cutoff->format('Y-m-d H:i:s'))
            ->where('waktu', '<=', $auditedUntil->format('Y-m-d H:i:s'))
    )->get([
        'id_rekaman_stok',
        'id_produk',
        'id_pembelian',
        'id_penjualan',
        'waktu',
        'stok_awal',
        'stok_masuk',
        'stok_keluar',
        'stok_sisa',
        'keterangan',
    ])->map(function ($row) {
        return [
            'id_rekaman_stok' => intval($row->id_rekaman_stok),
            'id_produk' => intval($row->id_produk),
            'id_pembelian' => $row->id_pembelian !== null ? intval($row->id_pembelian) : null,
            'id_penjualan' => $row->id_penjualan !== null ? intval($row->id_penjualan) : null,
            'waktu' => Carbon::parse($row->waktu)->format('Y-m-d H:i:s'),
            'stok_awal' => intval($row->stok_awal ?? 0),
            'stok_masuk' => intval($row->stok_masuk ?? 0),
            'stok_keluar' => intval($row->stok_keluar ?? 0),
            'stok_sisa' => intval($row->stok_sisa ?? 0),
            'keterangan' => (string) ($row->keterangan ?? ''),
        ];
    })->all();

    if (empty($rows)) {
        continue;
    }

    $seedRow = null;
    $startIndex = 0;
    if (isset($rows[0]) && is_seed_row($rows[0])) {
        $seedRow = $rows[0];
        $startIndex = 1;
    }

    $running = $seedRow ? intval($seedRow['stok_sisa']) : intval($rows[0]['stok_awal']);
    $segments = [];
    $activeSegment = null;
    $rowStates = [];

    for ($index = $startIndex; $index < count($rows); $index++) {
        $row = $rows[$index];
        $runningBefore = $running;
        $runningAfter = $runningBefore + intval($row['stok_masuk']) - intval($row['stok_keluar']);

        $rowStates[$index] = [
            'running_before' => $runningBefore,
            'running_after' => $runningAfter,
            'waktu' => (string) $row['waktu'],
        ];

        if ($runningAfter < 0) {
            if ($activeSegment === null) {
                $blockStartIndex = $index;
                while ($blockStartIndex > $startIndex && (string) $rows[$blockStartIndex - 1]['waktu'] === (string) $row['waktu']) {
                    $blockStartIndex--;
                }

                $activeSegment = [
                    'start_index' => $index,
                    'block_start_index' => $blockStartIndex,
                    'start_row' => $rows[$blockStartIndex],
                    'start_running_before' => intval($rowStates[$blockStartIndex]['running_before'] ?? $runningBefore),
                    'min_running' => $runningAfter,
                    'recovery_row' => null,
                    'recovery_running_after' => null,
                ];
            } else {
                $activeSegment['min_running'] = min(intval($activeSegment['min_running']), $runningAfter);
            }
        } elseif ($activeSegment !== null) {
            $activeSegment['recovery_row'] = $row;
            $activeSegment['recovery_running_after'] = $runningAfter;
            $segments[] = $activeSegment;
            $activeSegment = null;
        }

        $running = $runningAfter;
    }

    if ($activeSegment !== null) {
        $activeSegment['recovery_row'] = null;
        $activeSegment['recovery_running_after'] = $running;
        $segments[] = $activeSegment;
    }

    $productPlan = [
        'id_produk' => $productId,
        'nama_produk' => (string) ($product->nama_produk ?? ''),
        'produk_stok_before' => intval($product->stok ?? 0),
        'segments' => [],
    ];

    $usedTimes = [];
    foreach ($segments as $segmentIndex => $segment) {
        $offset = max(0, -intval($segment['min_running']));
        if ($offset <= 0) {
            continue;
        }

        $segmentStartRow = $segment['start_row'];
        $preTime = resolve_pre_adjustment_time((string) $segmentStartRow['waktu'], $cutoff, $usedTimes);
        $preTarget = intval($segment['start_running_before']) + $offset;

        $preLabel = $autoMarker . ' Penyesuaian Stok Manual: Stabilisasi defisit historis sebelum stok minus';
        $rowsToInsert[] = build_manual_source_row(
            $productId,
            $preTime,
            $preTarget,
            $offset,
            $preLabel,
            $currentTime
        );

        $segmentPlan = [
            'segment_index' => $segmentIndex + 1,
            'offset_qty' => $offset,
            'segment_start_waktu' => (string) $segmentStartRow['waktu'],
            'segment_start_running_before' => intval($segment['start_running_before']),
            'min_running' => intval($segment['min_running']),
            'pre_adjustment' => [
                'waktu' => $preTime,
                'target_stock' => $preTarget,
            ],
            'balancing_adjustment' => null,
        ];

        if ($segment['recovery_row'] !== null) {
            $recoveryRow = $segment['recovery_row'];
            $recoveryTarget = intval($segment['recovery_running_after']);
            $recoveryCorrectedAfter = $recoveryTarget + $offset;
            $balanceDelta = $recoveryTarget - $recoveryCorrectedAfter;

            if ($balanceDelta !== 0) {
                $balanceLabel = $autoMarker . ' Penyesuaian Stok Manual: Penyeimbang setelah pemulihan defisit historis';
                $rowsToInsert[] = build_manual_source_row(
                    $productId,
                    (string) $recoveryRow['waktu'],
                    $recoveryTarget,
                    $balanceDelta,
                    $balanceLabel,
                    $currentTime
                );

                $segmentPlan['balancing_adjustment'] = [
                    'waktu' => (string) $recoveryRow['waktu'],
                    'target_stock' => $recoveryTarget,
                ];
            }
        } else {
            $finalTarget = intval($product->stok ?? 0);
            $correctedFinal = intval($segment['recovery_running_after']) + $offset;
            $balanceDelta = $finalTarget - $correctedFinal;

            if ($balanceDelta !== 0) {
                $lastEventTime = (string) ($rows[count($rows) - 1]['waktu'] ?? $auditedUntil->format('Y-m-d H:i:s'));
                $balanceLabel = $autoMarker . ' Penyesuaian Stok Manual: Finalisasi koreksi defisit historis persisten';
                $rowsToInsert[] = build_manual_source_row(
                    $productId,
                    $lastEventTime,
                    $finalTarget,
                    $balanceDelta,
                    $balanceLabel,
                    $currentTime
                );

                $segmentPlan['balancing_adjustment'] = [
                    'waktu' => $lastEventTime,
                    'target_stock' => $finalTarget,
                ];
            }
        }

        $productPlan['segments'][] = $segmentPlan;
    }

    if (!empty($productPlan['segments'])) {
        $plans[] = $productPlan;
    }
}

$affectedProductIds = array_values(array_unique(array_map(function ($plan) {
    return intval($plan['id_produk']);
}, $plans)));

$reflowSummary = null;

if ($apply && !empty($affectedProductIds)) {
    DB::transaction(function () use ($affectedProductIds, $rowsToInsert, $autoMarker, $reflowService, $currentTime, &$reflowSummary) {
        DB::table('rekaman_stoks')
            ->whereIn('id_produk', $affectedProductIds)
            ->whereNull('id_pembelian')
            ->whereNull('id_penjualan')
            ->where('keterangan', 'like', '%' . $autoMarker . '%')
            ->delete();

        foreach (array_chunk($rowsToInsert, 500) as $chunk) {
            DB::table('rekaman_stoks')->insert($chunk);
        }

        $reflowSummary = $reflowService->rebuildProducts($affectedProductIds, $currentTime);
    }, 3);
}

$outputPath = storage_path('app/post_cutoff_negative_stock_event_repair_' . Carbon::now()->format('Ymd_His') . '.json');
file_put_contents($outputPath, json_encode([
    'generated_at' => Carbon::now()->format('Y-m-d H:i:s'),
    'apply' => $apply,
    'source_report' => $resolvedReportPath,
    'affected_product_ids' => $affectedProductIds,
    'inserted_source_row_count' => count($rowsToInsert),
    'plans' => $plans,
    'reflow_summary' => $reflowSummary,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'report_path' => $outputPath,
    'apply' => $apply,
    'affected_product_count' => count($affectedProductIds),
    'inserted_source_row_count' => count($rowsToInsert),
    'reflow_summary' => $reflowSummary,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;