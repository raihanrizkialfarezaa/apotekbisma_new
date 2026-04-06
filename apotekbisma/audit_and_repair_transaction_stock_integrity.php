<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Pembelian;
use App\Models\Penjualan;
use App\Services\TransactionDateMutationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$dryRun = in_array('--dry-run', $argv, true);
$now = Carbon::now();
$cutoff = Carbon::parse((string) config('stock.cutoff_datetime', '2025-12-31 23:59:59'));
$maxFutureMinutes = max(0, (int) config('stock.max_future_transaction_minutes', 5));
$latestAllowed = $now->copy()->addMinutes($maxFutureMinutes);
$auditTableAvailable = Schema::hasTable('transaction_date_change_audits');
$mutationService = app(TransactionDateMutationService::class);

$report = [
    'generated_at' => $now->format('Y-m-d H:i:s'),
    'dry_run' => $dryRun,
    'cutoff' => $cutoff->format('Y-m-d H:i:s'),
    'latest_allowed' => $latestAllowed->format('Y-m-d H:i:s'),
    'audit_table_available' => $auditTableAvailable,
    'summary' => [
        'pembelian' => [
            'checked' => 0,
            'finalized_checked' => 0,
            'anomalies_found' => 0,
            'repaired' => 0,
            'failed_repairs' => 0,
            'unresolved_after_repair' => 0,
        ],
        'penjualan' => [
            'checked' => 0,
            'finalized_checked' => 0,
            'anomalies_found' => 0,
            'repaired' => 0,
            'failed_repairs' => 0,
            'unresolved_after_repair' => 0,
        ],
    ],
    'pembelian' => [],
    'penjualan' => [],
];

$parseCarbon = static function ($value): ?Carbon {
    if ($value === null || $value === '') {
        return null;
    }

    try {
        return Carbon::parse($value);
    } catch (Throwable $e) {
        return null;
    }
};

$formatCarbon = static function (?Carbon $value): ?string {
    return $value ? $value->format('Y-m-d H:i:s') : null;
};

$isFinalizedPembelian = static function ($row): bool {
    $noFaktur = trim((string) ($row->no_faktur ?? ''));

    return $noFaktur !== ''
        && strtolower($noFaktur) !== 'o'
        && intval($row->total_harga ?? 0) > 0
        && intval($row->bayar ?? 0) > 0;
};

$isFinalizedPenjualan = static function ($row): bool {
    return intval($row->total_item ?? 0) > 0
        && intval($row->total_harga ?? 0) > 0
        && intval($row->bayar ?? 0) > 0
        && intval($row->diterima ?? 0) > 0;
};

$resolvePembelianWaktu = static function ($row) use ($parseCarbon): ?Carbon {
    return $parseCarbon($row->waktu_datang ?? $row->waktu ?? $row->created_at ?? null);
};

$resolvePenjualanWaktu = static function ($row) use ($parseCarbon): ?Carbon {
    return $parseCarbon($row->waktu ?? $row->created_at ?? null);
};

$resolveRepairAnchor = static function ($row) use ($parseCarbon, $cutoff, $latestAllowed): ?Carbon {
    foreach ([$row->created_at ?? null, $row->updated_at ?? null] as $candidate) {
        $parsed = $parseCarbon($candidate);
        if (!$parsed) {
            continue;
        }

        if ($parsed->gt($cutoff) && $parsed->lte($latestAllowed)) {
            return $parsed;
        }
    }

    return null;
};

$getPembelianStats = static function (int $idPembelian): array {
    $detail = DB::table('pembelian_detail')
        ->where('id_pembelian', $idPembelian)
        ->where('jumlah', '>', 0)
        ->selectRaw('COUNT(*) as row_count, COUNT(DISTINCT id_produk) as distinct_produk, COALESCE(SUM(jumlah), 0) as total_qty')
        ->first();

    $rekaman = DB::table('rekaman_stoks')
        ->where('id_pembelian', $idPembelian)
        ->selectRaw('COUNT(*) as row_count, COUNT(DISTINCT id_produk) as distinct_produk, COALESCE(SUM(stok_masuk), 0) as total_qty')
        ->first();

    return [
        'detail_row_count' => intval($detail->row_count ?? 0),
        'detail_distinct_produk' => intval($detail->distinct_produk ?? 0),
        'detail_total_qty' => intval($detail->total_qty ?? 0),
        'rekaman_row_count' => intval($rekaman->row_count ?? 0),
        'rekaman_distinct_produk' => intval($rekaman->distinct_produk ?? 0),
        'rekaman_total_qty' => intval($rekaman->total_qty ?? 0),
    ];
};

$getPenjualanStats = static function (int $idPenjualan): array {
    $detail = DB::table('penjualan_detail')
        ->where('id_penjualan', $idPenjualan)
        ->where('jumlah', '>', 0)
        ->selectRaw('COUNT(*) as row_count, COUNT(DISTINCT id_produk) as distinct_produk, COALESCE(SUM(jumlah), 0) as total_qty')
        ->first();

    $rekaman = DB::table('rekaman_stoks')
        ->where('id_penjualan', $idPenjualan)
        ->selectRaw('COUNT(*) as row_count, COUNT(DISTINCT id_produk) as distinct_produk, COALESCE(SUM(stok_keluar), 0) as total_qty')
        ->first();

    return [
        'detail_row_count' => intval($detail->row_count ?? 0),
        'detail_distinct_produk' => intval($detail->distinct_produk ?? 0),
        'detail_total_qty' => intval($detail->total_qty ?? 0),
        'rekaman_row_count' => intval($rekaman->row_count ?? 0),
        'rekaman_distinct_produk' => intval($rekaman->distinct_produk ?? 0),
        'rekaman_total_qty' => intval($rekaman->total_qty ?? 0),
    ];
};

$detectPembelianAnomalies = static function ($row, array $stats) use ($resolvePembelianWaktu, $cutoff, $latestAllowed): array {
    $anomalies = [];
    $resolvedWaktu = $resolvePembelianWaktu($row);
    $createdAt = Carbon::parse($row->created_at);

    if (!$resolvedWaktu) {
        $anomalies[] = 'invalid_header_waktu';
    } else {
        if ($resolvedWaktu->lte($cutoff) && $createdAt->gt($cutoff)) {
            $anomalies[] = 'header_at_or_before_cutoff';
        }

        if ($resolvedWaktu->gt($latestAllowed)) {
            $anomalies[] = 'header_in_future';
        }
    }

    if ($stats['detail_distinct_produk'] <= 0) {
        $anomalies[] = 'finalized_without_detail';
    } else {
        if ($stats['rekaman_distinct_produk'] !== $stats['detail_distinct_produk']) {
            $anomalies[] = 'rekaman_distinct_product_mismatch';
        }

        if ($stats['rekaman_row_count'] < $stats['detail_distinct_produk']) {
            $anomalies[] = 'rekaman_row_count_mismatch';
        }

        if ($stats['rekaman_total_qty'] !== $stats['detail_total_qty']) {
            $anomalies[] = 'rekaman_qty_mismatch';
        }
    }

    return array_values(array_unique($anomalies));
};

$detectPenjualanAnomalies = static function ($row, array $stats) use ($resolvePenjualanWaktu, $cutoff, $latestAllowed): array {
    $anomalies = [];
    $resolvedWaktu = $resolvePenjualanWaktu($row);
    $createdAt = Carbon::parse($row->created_at);

    if (!$resolvedWaktu) {
        $anomalies[] = 'invalid_header_waktu';
    } else {
        if ($resolvedWaktu->lte($cutoff) && $createdAt->gt($cutoff)) {
            $anomalies[] = 'header_at_or_before_cutoff';
        }

        if ($resolvedWaktu->gt($latestAllowed)) {
            $anomalies[] = 'header_in_future';
        }
    }

    if ($stats['detail_distinct_produk'] <= 0) {
        $anomalies[] = 'finalized_without_detail';
    } else {
        if ($stats['rekaman_distinct_produk'] !== $stats['detail_distinct_produk']) {
            $anomalies[] = 'rekaman_distinct_product_mismatch';
        }

        if ($stats['rekaman_row_count'] < $stats['detail_distinct_produk']) {
            $anomalies[] = 'rekaman_row_count_mismatch';
        }

        if ($stats['rekaman_total_qty'] !== $stats['detail_total_qty']) {
            $anomalies[] = 'rekaman_qty_mismatch';
        }
    }

    return array_values(array_unique($anomalies));
};

$repairPembelian = static function ($row, array $anomalies) use (
    $dryRun,
    $resolveRepairAnchor,
    $resolvePembelianWaktu,
    $mutationService,
    $auditTableAvailable,
    $cutoff,
    $formatCarbon
): array {
    $result = [
        'status' => 'skipped',
        'actions' => [],
        'message' => null,
    ];

    $needsHeaderRepair = count(array_intersect($anomalies, [
        'invalid_header_waktu',
        'header_at_or_before_cutoff',
        'header_in_future',
    ])) > 0;

    $resolvedWaktu = $resolvePembelianWaktu($row);
    if (!$needsHeaderRepair && $resolvedWaktu && $resolvedWaktu->lte($cutoff)) {
        $result['message'] = 'Mismatch hanya terjadi pada histori pre-cutoff. Dibiarkan report-only agar tidak menimpa baseline source of truth.';
        return $result;
    }

    $repairAnchor = $needsHeaderRepair ? $resolveRepairAnchor($row) : null;
    if ($needsHeaderRepair && !$repairAnchor) {
        $result['message'] = 'Tidak ada anchor waktu server yang aman untuk repair otomatis.';
        return $result;
    }

    if ($dryRun) {
        $result['status'] = 'dry_run';
        $result['actions'] = $needsHeaderRepair
            ? ['would_update_header_waktu', 'would_rebuild_stock_history']
            : ['would_rebuild_stock_history'];
        $result['message'] = $repairAnchor ? ('Anchor repair: ' . $formatCarbon($repairAnchor)) : 'Rebuild histori tanpa ubah header.';
        return $result;
    }

    try {
        DB::transaction(function () use ($row, $needsHeaderRepair, $repairAnchor, $mutationService, $auditTableAvailable, $cutoff, &$result) {
            $pembelian = Pembelian::findOrFail($row->id_pembelian);
            $oldWaktu = ($pembelian->waktu_datang ?: $pembelian->waktu ?: $pembelian->created_at);
            $oldWaktuFormatted = Carbon::parse($oldWaktu)->format('Y-m-d H:i:s');

            if ($needsHeaderRepair) {
                $newWaktu = $repairAnchor->format('Y-m-d H:i:s');
                DB::table('pembelian')
                    ->where('id_pembelian', $row->id_pembelian)
                    ->update([
                        'waktu' => $newWaktu,
                        'waktu_datang' => $newWaktu,
                        'updated_at' => now(),
                    ]);

                $pembelian->refresh();
                $result['actions'][] = 'update_header_waktu';

                if ($auditTableAvailable && $oldWaktuFormatted > $cutoff->format('Y-m-d H:i:s')) {
                    $mutationService->handlePembelianFinalDateChange($pembelian, $oldWaktuFormatted, $newWaktu);
                    $result['actions'][] = 'handle_date_change_with_reflow';
                } else {
                    $mutationService->synchronizeFinalizedPembelian($pembelian);
                    $result['actions'][] = 'rebuild_stock_history';
                }
            } else {
                $mutationService->synchronizeFinalizedPembelian($pembelian);
                $result['actions'][] = 'rebuild_stock_history';
            }
        }, 3);

        $result['status'] = 'repaired';
    } catch (Throwable $e) {
        $result['status'] = 'failed';
        $result['message'] = $e->getMessage();
    }

    return $result;
};

$repairPenjualan = static function ($row, array $anomalies) use (
    $dryRun,
    $resolveRepairAnchor,
    $resolvePenjualanWaktu,
    $mutationService,
    $auditTableAvailable,
    $cutoff,
    $formatCarbon
): array {
    $result = [
        'status' => 'skipped',
        'actions' => [],
        'message' => null,
    ];

    $needsHeaderRepair = count(array_intersect($anomalies, [
        'invalid_header_waktu',
        'header_at_or_before_cutoff',
        'header_in_future',
    ])) > 0;

    $resolvedWaktu = $resolvePenjualanWaktu($row);
    if (!$needsHeaderRepair && $resolvedWaktu && $resolvedWaktu->lte($cutoff)) {
        $result['message'] = 'Mismatch hanya terjadi pada histori pre-cutoff. Dibiarkan report-only agar tidak menimpa baseline source of truth.';
        return $result;
    }

    $repairAnchor = $needsHeaderRepair ? $resolveRepairAnchor($row) : null;
    if ($needsHeaderRepair && !$repairAnchor) {
        $result['message'] = 'Tidak ada anchor waktu server yang aman untuk repair otomatis.';
        return $result;
    }

    if ($dryRun) {
        $result['status'] = 'dry_run';
        $result['actions'] = $needsHeaderRepair
            ? ['would_update_header_waktu', 'would_rebuild_stock_history']
            : ['would_rebuild_stock_history'];
        $result['message'] = $repairAnchor ? ('Anchor repair: ' . $formatCarbon($repairAnchor)) : 'Rebuild histori tanpa ubah header.';
        return $result;
    }

    try {
        DB::transaction(function () use ($row, $needsHeaderRepair, $repairAnchor, $mutationService, $auditTableAvailable, $cutoff, &$result) {
            $penjualan = Penjualan::findOrFail($row->id_penjualan);
            $oldWaktu = ($penjualan->waktu ?: $penjualan->created_at);
            $oldWaktuFormatted = Carbon::parse($oldWaktu)->format('Y-m-d H:i:s');

            if ($needsHeaderRepair) {
                $newWaktu = $repairAnchor->format('Y-m-d H:i:s');
                DB::table('penjualan')
                    ->where('id_penjualan', $row->id_penjualan)
                    ->update([
                        'waktu' => $newWaktu,
                        'updated_at' => now(),
                    ]);

                $penjualan->refresh();
                $result['actions'][] = 'update_header_waktu';

                if ($auditTableAvailable && $oldWaktuFormatted > $cutoff->format('Y-m-d H:i:s')) {
                    $mutationService->handlePenjualanFinalDateChange($penjualan, $oldWaktuFormatted, $newWaktu);
                    $result['actions'][] = 'handle_date_change_with_reflow';
                } else {
                    $mutationService->synchronizeFinalizedPenjualan($penjualan);
                    $result['actions'][] = 'rebuild_stock_history';
                }
            } else {
                $mutationService->synchronizeFinalizedPenjualan($penjualan);
                $result['actions'][] = 'rebuild_stock_history';
            }
        }, 3);

        $result['status'] = 'repaired';
    } catch (Throwable $e) {
        $result['status'] = 'failed';
        $result['message'] = $e->getMessage();
    }

    return $result;
};

DB::table('pembelian')
    ->select('id_pembelian', 'no_faktur', 'total_item', 'total_harga', 'bayar', 'created_at', 'updated_at', 'waktu', 'waktu_datang')
    ->orderBy('id_pembelian')
    ->chunk(200, function ($rows) use (
        &$report,
        $isFinalizedPembelian,
        $getPembelianStats,
        $detectPembelianAnomalies,
        $repairPembelian,
        $resolvePembelianWaktu,
        $formatCarbon,
        $resolveRepairAnchor
    ) {
        foreach ($rows as $row) {
            $report['summary']['pembelian']['checked']++;

            if (!$isFinalizedPembelian($row)) {
                continue;
            }

            $report['summary']['pembelian']['finalized_checked']++;
            $stats = $getPembelianStats(intval($row->id_pembelian));
            $anomalies = $detectPembelianAnomalies($row, $stats);

            if (empty($anomalies)) {
                continue;
            }

            $report['summary']['pembelian']['anomalies_found']++;
            $repair = $repairPembelian($row, $anomalies);
            $afterStats = $getPembelianStats(intval($row->id_pembelian));
            $freshRow = DB::table('pembelian')
                ->select('id_pembelian', 'no_faktur', 'total_item', 'total_harga', 'bayar', 'created_at', 'updated_at', 'waktu', 'waktu_datang')
                ->where('id_pembelian', $row->id_pembelian)
                ->first();
            $remainingAnomalies = $detectPembelianAnomalies($freshRow, $afterStats);

            if ($repair['status'] === 'repaired') {
                $report['summary']['pembelian']['repaired']++;
            } elseif ($repair['status'] === 'failed') {
                $report['summary']['pembelian']['failed_repairs']++;
            }

            if (!empty($remainingAnomalies)) {
                $report['summary']['pembelian']['unresolved_after_repair']++;
            }

            $report['pembelian'][] = [
                'id_pembelian' => intval($row->id_pembelian),
                'no_faktur' => $row->no_faktur,
                'before_waktu' => $formatCarbon($resolvePembelianWaktu($row)),
                'repair_anchor' => $formatCarbon($resolveRepairAnchor($row)),
                'anomalies' => $anomalies,
                'before_stats' => $stats,
                'repair' => $repair,
                'after_waktu' => $formatCarbon($resolvePembelianWaktu($freshRow)),
                'after_stats' => $afterStats,
                'remaining_anomalies' => $remainingAnomalies,
            ];
        }
    });

DB::table('penjualan')
    ->select('id_penjualan', 'total_item', 'total_harga', 'bayar', 'diterima', 'created_at', 'updated_at', 'waktu')
    ->orderBy('id_penjualan')
    ->chunk(200, function ($rows) use (
        &$report,
        $isFinalizedPenjualan,
        $getPenjualanStats,
        $detectPenjualanAnomalies,
        $repairPenjualan,
        $resolvePenjualanWaktu,
        $formatCarbon,
        $resolveRepairAnchor
    ) {
        foreach ($rows as $row) {
            $report['summary']['penjualan']['checked']++;

            if (!$isFinalizedPenjualan($row)) {
                continue;
            }

            $report['summary']['penjualan']['finalized_checked']++;
            $stats = $getPenjualanStats(intval($row->id_penjualan));
            $anomalies = $detectPenjualanAnomalies($row, $stats);

            if (empty($anomalies)) {
                continue;
            }

            $report['summary']['penjualan']['anomalies_found']++;
            $repair = $repairPenjualan($row, $anomalies);
            $afterStats = $getPenjualanStats(intval($row->id_penjualan));
            $freshRow = DB::table('penjualan')
                ->select('id_penjualan', 'total_item', 'total_harga', 'bayar', 'diterima', 'created_at', 'updated_at', 'waktu')
                ->where('id_penjualan', $row->id_penjualan)
                ->first();
            $remainingAnomalies = $detectPenjualanAnomalies($freshRow, $afterStats);

            if ($repair['status'] === 'repaired') {
                $report['summary']['penjualan']['repaired']++;
            } elseif ($repair['status'] === 'failed') {
                $report['summary']['penjualan']['failed_repairs']++;
            }

            if (!empty($remainingAnomalies)) {
                $report['summary']['penjualan']['unresolved_after_repair']++;
            }

            $report['penjualan'][] = [
                'id_penjualan' => intval($row->id_penjualan),
                'before_waktu' => $formatCarbon($resolvePenjualanWaktu($row)),
                'repair_anchor' => $formatCarbon($resolveRepairAnchor($row)),
                'anomalies' => $anomalies,
                'before_stats' => $stats,
                'repair' => $repair,
                'after_waktu' => $formatCarbon($resolvePenjualanWaktu($freshRow)),
                'after_stats' => $afterStats,
                'remaining_anomalies' => $remainingAnomalies,
            ];
        }
    });

$reportName = 'transaction_stock_audit_repair_report_' . $now->format('Ymd_His') . '.json';
$reportPath = storage_path('app/' . $reportName);
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'report_path' => $reportPath,
    'summary' => $report['summary'],
    'pembelian_cases' => count($report['pembelian']),
    'penjualan_cases' => count($report['penjualan']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;