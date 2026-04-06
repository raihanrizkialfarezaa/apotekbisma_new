<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Penjualan;
use App\Services\BaselineStockReflowService;
use App\Services\TransactionDateMutationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv, true);
$forceExistingNegative = in_array('--force-existing-negative', $argv, true);
$defaultIds = [1443, 1444, 1445, 1446, 1447];
$targetIds = $defaultIds;

foreach ($argv as $arg) {
    if (strpos($arg, '--ids=') === 0) {
        $targetIds = array_values(array_unique(array_filter(array_map('intval', explode(',', substr($arg, 6))), function ($value) {
            return $value > 0;
        })));
    }
}

if (empty($targetIds)) {
    fwrite(STDERR, "Tidak ada id penjualan target. Gunakan --ids=1443,1444,...\n");
    exit(1);
}

$now = Carbon::now();
$cutoff = Carbon::parse((string) config('stock.cutoff_datetime', '2025-12-31 23:59:59'));
$maxFutureMinutes = max(0, (int) config('stock.max_future_transaction_minutes', 5));
$latestAllowed = $now->copy()->addMinutes($maxFutureMinutes);
$mutationService = app(TransactionDateMutationService::class);
$reflowService = app(BaselineStockReflowService::class);

function resolve_normalized_penjualan_waktu(string $rawWaktu, Carbon $latestAllowed): array
{
    $original = Carbon::parse($rawWaktu);
    $candidate = $original->copy();
    $daysShifted = 0;

    while ($candidate->gt($latestAllowed)) {
        $candidate->subDay();
        $daysShifted++;

        if ($daysShifted > 31) {
            throw new RuntimeException('Gagal menormalkan waktu penjualan ke rentang yang aman.');
        }
    }

    return [
        'old_waktu' => $original->format('Y-m-d H:i:s'),
        'new_waktu' => $candidate->format('Y-m-d H:i:s'),
        'days_shifted' => $daysShifted,
    ];
}

function shift_penjualan_waktu_by_days(string $rawWaktu, int $daysShifted): string
{
    return Carbon::parse($rawWaktu)->subDays($daysShifted)->format('Y-m-d H:i:s');
}

$sales = Penjualan::query()
    ->whereIn('id_penjualan', $targetIds)
    ->orderBy('waktu')
    ->orderBy('id_penjualan')
    ->get(['id_penjualan', 'waktu', 'created_at', 'updated_at', 'total_item', 'total_harga', 'bayar', 'diterima']);

if ($sales->count() !== count($targetIds)) {
    $foundIds = $sales->pluck('id_penjualan')->map(function ($value) {
        return intval($value);
    })->all();
    $missingIds = array_values(array_diff($targetIds, $foundIds));
    fwrite(STDERR, 'Penjualan target tidak lengkap. Missing IDs: ' . implode(',', $missingIds) . "\n");
    exit(1);
}

$plans = [];
$previousNewWaktu = null;
$clusterDaysShifted = 0;

foreach ($sales as $sale) {
    if (!($sale->total_item > 0 && $sale->total_harga > 0 && $sale->bayar > 0 && $sale->diterima > 0)) {
        continue;
    }

    $resolved = resolve_normalized_penjualan_waktu((string) $sale->waktu, $latestAllowed);
    $clusterDaysShifted = max($clusterDaysShifted, intval($resolved['days_shifted']));
}

foreach ($sales as $sale) {
    if (!($sale->total_item > 0 && $sale->total_harga > 0 && $sale->bayar > 0 && $sale->diterima > 0)) {
        $plans[] = [
            'id_penjualan' => intval($sale->id_penjualan),
            'status' => 'skipped_non_finalized',
            'message' => 'Transaksi belum final.',
        ];
        continue;
    }

    $resolved = resolve_normalized_penjualan_waktu((string) $sale->waktu, $latestAllowed);
    $newWaktu = Carbon::parse(shift_penjualan_waktu_by_days((string) $sale->waktu, $clusterDaysShifted));

    if ($newWaktu->lte($cutoff)) {
        throw new RuntimeException('Waktu baru jatuh pada atau sebelum cutoff untuk penjualan #' . $sale->id_penjualan);
    }

    if ($previousNewWaktu !== null && $newWaktu->lte($previousNewWaktu)) {
        $newWaktu = $previousNewWaktu->copy()->addSecond();
    }

    if ($newWaktu->gt($latestAllowed)) {
        throw new RuntimeException('Waktu baru masih melewati batas future untuk penjualan #' . $sale->id_penjualan);
    }

    $previousNewWaktu = $newWaktu->copy();

    $plans[] = [
        'id_penjualan' => intval($sale->id_penjualan),
        'status' => 'planned',
        'old_waktu' => $resolved['old_waktu'],
        'new_waktu' => $newWaktu->format('Y-m-d H:i:s'),
        'days_shifted' => $clusterDaysShifted,
        'created_at' => optional($sale->created_at)->format('Y-m-d H:i:s'),
        'updated_at' => optional($sale->updated_at)->format('Y-m-d H:i:s'),
        'total_item' => intval($sale->total_item),
        'total_harga' => intval($sale->total_harga),
    ];
}

$results = [];

if ($apply) {
    foreach ($plans as $plan) {
        if (($plan['status'] ?? null) !== 'planned') {
            $results[] = $plan;
            continue;
        }

        try {
            DB::transaction(function () use ($plan, $mutationService, &$results) {
                $penjualan = Penjualan::findOrFail($plan['id_penjualan']);

                DB::table('penjualan')
                    ->where('id_penjualan', $plan['id_penjualan'])
                    ->update([
                        'waktu' => $plan['new_waktu'],
                        'updated_at' => Carbon::now(),
                    ]);

                $penjualan->refresh();
                $reflow = $mutationService->handlePenjualanFinalDateChange(
                    $penjualan,
                    $plan['old_waktu'],
                    $plan['new_waktu']
                );

                $results[] = array_merge($plan, [
                    'status' => 'applied',
                    'reflow' => $reflow['reflow'] ?? null,
                ]);
            }, 3);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $canForceApply = $forceExistingNegative && stripos($message, 'stok minus historis') !== false;

            if (!$canForceApply) {
                $results[] = array_merge($plan, [
                    'status' => 'failed',
                    'message' => $message,
                ]);
                continue;
            }

            try {
                DB::transaction(function () use ($plan, $reflowService, &$results) {
                    DB::table('penjualan')
                        ->where('id_penjualan', $plan['id_penjualan'])
                        ->update([
                            'waktu' => $plan['new_waktu'],
                            'updated_at' => Carbon::now(),
                        ]);

                    $productIds = DB::table('penjualan_detail')
                        ->where('id_penjualan', $plan['id_penjualan'])
                        ->pluck('id_produk')
                        ->map(function ($productId) {
                            return intval($productId);
                        })
                        ->filter(function ($productId) {
                            return $productId > 0;
                        })
                        ->unique()
                        ->values()
                        ->all();

                    $reflow = $reflowService->rebuildProducts($productIds, Carbon::now()->format('Y-m-d H:i:s'));

                    $results[] = array_merge($plan, [
                        'status' => 'applied_with_force_reflow',
                        'message' => 'Guard tanggal transaksi dilewati karena negative event terkait sudah eksis sebelumnya; header diperbarui dan stok direflow ulang.',
                        'reflow' => $reflow,
                    ]);
                }, 3);
            } catch (Throwable $forceException) {
                $results[] = array_merge($plan, [
                    'status' => 'failed',
                    'message' => $forceException->getMessage(),
                ]);
            }
        }
    }
} else {
    $results = $plans;
}

$report = [
    'generated_at' => Carbon::now()->format('Y-m-d H:i:s'),
    'apply' => $apply,
    'force_existing_negative' => $forceExistingNegative,
    'cutoff' => $cutoff->format('Y-m-d H:i:s'),
    'latest_allowed' => $latestAllowed->format('Y-m-d H:i:s'),
    'target_ids' => $targetIds,
    'results' => $results,
];

$outputPath = storage_path('app/future_penjualan_header_repair_' . Carbon::now()->format('Ymd_His') . '.json');
file_put_contents($outputPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'report_path' => $outputPath,
    'apply' => $apply,
    'force_existing_negative' => $forceExistingNegative,
    'planned_count' => count(array_filter($results, function ($row) {
        return ($row['status'] ?? null) === 'planned';
    })),
    'applied_count' => count(array_filter($results, function ($row) {
        return in_array(($row['status'] ?? null), ['applied', 'applied_with_force_reflow'], true);
    })),
    'failed_count' => count(array_filter($results, function ($row) {
        return ($row['status'] ?? null) === 'failed';
    })),
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;