<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

function normalize_forensic_text(?string $value): string
{
    return mb_strtolower(trim((string) $value));
}

function is_forensic_seed_row(array $row): bool
{
    $keterangan = normalize_forensic_text((string) ($row['keterangan'] ?? ''));

    return $row['id_pembelian'] === null
        && $row['id_penjualan'] === null
        && intval($row['stok_masuk']) === 0
        && intval($row['stok_keluar']) === 0
        && str_contains($keterangan, 'saldo awal stok');
}

function classify_forensic_row_type(array $row): string
{
    if ($row['id_pembelian'] !== null) {
        return 'pembelian';
    }

    if ($row['id_penjualan'] !== null) {
        return 'penjualan';
    }

    if (is_forensic_seed_row($row)) {
        return 'seed';
    }

    return 'manual';
}

function manual_row_is_inbound(array $row): bool
{
    return intval($row['stok_masuk']) > intval($row['stok_keluar']);
}

function manual_row_is_stock_opname_like(array $row): bool
{
    $keterangan = normalize_forensic_text((string) ($row['keterangan'] ?? ''));

    return $keterangan !== ''
        && (
            str_contains($keterangan, 'stock opname')
            || str_contains($keterangan, 'perubahan stok manual')
            || str_contains($keterangan, 'penyesuaian stok')
        );
}

function build_timeline_token(array $event): string
{
    $type = (string) ($event['type'] ?? 'unknown');
    $refId = $event['ref_id'] ?? null;

    if ($refId !== null) {
        return $type . ':' . intval($refId);
    }

    return implode('|', [
        $type,
        (string) ($event['waktu'] ?? ''),
        intval($event['stok_masuk'] ?? 0),
        intval($event['stok_keluar'] ?? 0),
        normalize_forensic_text((string) ($event['keterangan'] ?? '')),
    ]);
}

function push_forensic_count(array &$bucket, string $key): void
{
    $bucket[$key] = intval($bucket[$key] ?? 0) + 1;
}

function push_forensic_sample(array &$bucket, array $value, int $limit = 8): void
{
    if (count($bucket) >= $limit) {
        return;
    }

    $bucket[] = $value;
}

function format_forensic_event(array $row, int $runningBefore, int $runningAfter, array $headerByPembelian, array $headerByPenjualan): array
{
    $type = classify_forensic_row_type($row);
    $refId = $row['id_pembelian'] ?? $row['id_penjualan'] ?? null;

    $payload = [
        'id_rekaman_stok' => intval($row['id_rekaman_stok']),
        'type' => $type,
        'ref_id' => $refId !== null ? intval($refId) : null,
        'waktu' => (string) $row['waktu'],
        'stok_awal' => intval($row['stok_awal']),
        'stok_masuk' => intval($row['stok_masuk']),
        'stok_keluar' => intval($row['stok_keluar']),
        'stok_sisa' => intval($row['stok_sisa']),
        'running_before' => $runningBefore,
        'running_after' => $runningAfter,
        'keterangan' => (string) ($row['keterangan'] ?? ''),
    ];

    if ($type === 'pembelian' && isset($headerByPembelian[intval($refId)])) {
        $payload['source'] = $headerByPembelian[intval($refId)];
    }

    if ($type === 'penjualan' && isset($headerByPenjualan[intval($refId)])) {
        $payload['source'] = $headerByPenjualan[intval($refId)];
    }

    return $payload;
}

function derive_primary_cause(array $facts): string
{
    if (($facts['first_negative_type'] ?? '') !== 'penjualan') {
        if (($facts['recovered_by_manual'] ?? false) || ($facts['manual_after_negative'] ?? 0) > 0) {
            return 'manual_adjustment_drove_negative_or_recovery';
        }

        return 'unexpected_non_sale_negative_event';
    }

    if (($facts['seed_stock'] ?? 0) <= 0 && ($facts['inbound_before_first_negative'] ?? 0) === 0) {
        if (($facts['recovered_by_purchase_same_day'] ?? false) || ($facts['recovered_by_purchase_later'] ?? false)) {
            return 'sale_recorded_before_restock_was_input';
        }

        if (($facts['final_raw_stock'] ?? 0) < 0) {
            return 'sale_continued_after_zero_seed_without_restock';
        }

        return 'zero_seed_followed_by_sale_then_other_recovery';
    }

    if (($facts['oversell_amount_first_event'] ?? 0) > 0) {
        if (($facts['recovered_by_purchase_same_day'] ?? false)) {
            return 'sale_exceeded_available_stock_then_same_day_purchase_arrived';
        }

        if (($facts['recovered_by_purchase_later'] ?? false)) {
            return 'sale_exceeded_available_stock_then_late_purchase_arrived';
        }

        if (($facts['recovered_by_manual'] ?? false)) {
            return 'sale_exceeded_available_stock_then_manual_adjustment_corrected';
        }

        if (($facts['final_raw_stock'] ?? 0) < 0) {
            return 'sale_exceeded_available_stock_and_deficit_persisted';
        }
    }

    if (($facts['recovered_by_manual'] ?? false)) {
        return 'manual_adjustment_used_to_close_deficit';
    }

    if (($facts['final_raw_stock'] ?? 0) < 0) {
        return 'negative_stock_persisted_without_recorded_recovery';
    }

    return 'negative_stock_temporarily_occurred_then_recovered';
}

function derive_normality_assessment(array $facts): string
{
    $primaryCause = (string) ($facts['primary_cause'] ?? '');

    if (($facts['final_raw_stock'] ?? 0) < 0) {
        return 'not_normal_high_risk_persistent_deficit';
    }

    if (($facts['recovered_by_manual'] ?? false)) {
        return 'not_normal_but_corrected_manually';
    }

    if (($facts['recovered_by_purchase_same_day'] ?? false)) {
        return 'not_normal_but_plausibly_explained_by_same_day_input_order';
    }

    if (str_contains($primaryCause, 'zero_seed') || str_contains($primaryCause, 'without_restock')) {
        return 'not_normal_likely_missing_stock_source_or_sale_when_stock_zero';
    }

    if (($facts['recovered_by_purchase_later'] ?? false)) {
        return 'not_normal_late_restock_closed_the_gap';
    }

    return 'not_normal_requires_case_review';
}

function derive_resolution_bucket(array $facts): string
{
    if (($facts['final_raw_stock'] ?? 0) < 0) {
        return 'needs_operational_correction_or_stock_opname';
    }

    if (($facts['recovered_by_purchase_same_day'] ?? false)) {
        return 'can_be_left_if_timestamp_order_reflects_real_input_sequence';
    }

    if (($facts['recovered_by_manual'] ?? false)) {
        return 'already_closed_but_should_be_reviewed_for_manual_adjustment_reason';
    }

    if (($facts['recovered_by_purchase_later'] ?? false)) {
        return 'can_be_resolved_only_with_source_proof_if_physical_restock_happened_earlier';
    }

    return 'needs_transaction_source_review';
}

$reportPath = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--report=') === 0) {
        $reportPath = substr($arg, 9);
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
    fwrite(STDERR, "File report tidak dapat dibaca: {$resolvedReportPath}\n");
    exit(1);
}

$auditReport = json_decode(file_get_contents($resolvedReportPath), true);
if (!is_array($auditReport)) {
    fwrite(STDERR, "Report audit bukan JSON valid.\n");
    exit(1);
}

$negativeProducts = $auditReport['negative_stock_products'] ?? [];
$productIds = array_values(array_unique(array_filter(array_map(function ($row) {
    return intval($row['id_produk'] ?? 0);
}, $negativeProducts), function ($productId) {
    return $productId > 0;
})));

if (empty($productIds)) {
    echo json_encode([
        'source_report' => $resolvedReportPath,
        'message' => 'Tidak ada produk dengan negative stock event pada report audit ini.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$cutoff = Carbon::parse((string) ($auditReport['cutoff'] ?? config('stock.cutoff_datetime', '2025-12-31 23:59:59')))->format('Y-m-d H:i:s');
$auditedUntil = Carbon::parse((string) ($auditReport['audited_until'] ?? Carbon::now()->format('Y-m-d H:i:s')))->format('Y-m-d H:i:s');

$productsById = DB::table('produk')
    ->whereIn('id_produk', $productIds)
    ->select('id_produk', 'nama_produk', 'stok')
    ->get()
    ->keyBy('id_produk');

$stockOrderRaw = "CASE
    WHEN id_pembelian IS NOT NULL THEN 0
    WHEN id_penjualan IS NOT NULL THEN 1
    WHEN LOWER(COALESCE(keterangan, '')) LIKE '%stock opname%' THEN 2
    WHEN LOWER(COALESCE(keterangan, '')) LIKE '%perubahan stok manual%' THEN 2
    WHEN LOWER(COALESCE(keterangan, '')) LIKE '%penyesuaian stok%' THEN 2
    WHEN LOWER(COALESCE(keterangan, '')) LIKE '%saldo awal stok%' THEN 2
    ELSE 3
END ASC";

$allRows = DB::table('rekaman_stoks')
    ->whereIn('id_produk', $productIds)
    ->where('waktu', '>=', $cutoff)
    ->where('waktu', '<=', $auditedUntil)
    ->orderBy('id_produk', 'asc')
    ->orderBy('waktu', 'asc')
    ->orderByRaw($stockOrderRaw)
    ->orderBy('created_at', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get([
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
    ]);

$rowsByProduct = [];
$allPurchaseIds = [];
$allSaleIds = [];
foreach ($allRows as $row) {
    $normalized = [
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

    $rowsByProduct[intval($row->id_produk)][] = $normalized;

    if ($normalized['id_pembelian'] !== null) {
        $allPurchaseIds[$normalized['id_pembelian']] = true;
    }

    if ($normalized['id_penjualan'] !== null) {
        $allSaleIds[$normalized['id_penjualan']] = true;
    }
}

$purchaseHeaders = [];
if (!empty($allPurchaseIds)) {
    $purchaseRows = DB::table('pembelian')
        ->whereIn('id_pembelian', array_keys($allPurchaseIds))
        ->select('id_pembelian', 'no_faktur', 'waktu', 'waktu_datang', 'created_at', 'total_harga', 'bayar')
        ->get();

    foreach ($purchaseRows as $row) {
        $purchaseHeaders[intval($row->id_pembelian)] = [
            'id_pembelian' => intval($row->id_pembelian),
            'no_faktur' => (string) ($row->no_faktur ?? ''),
            'waktu_efektif' => Carbon::parse($row->waktu_datang ?? $row->waktu ?? $row->created_at)->format('Y-m-d H:i:s'),
            'total_harga' => intval($row->total_harga ?? 0),
            'bayar' => intval($row->bayar ?? 0),
        ];
    }
}

$saleHeaders = [];
if (!empty($allSaleIds)) {
    $saleRows = DB::table('penjualan')
        ->whereIn('id_penjualan', array_keys($allSaleIds))
        ->select('id_penjualan', 'waktu', 'created_at', 'total_item', 'total_harga', 'bayar', 'diterima')
        ->get();

    foreach ($saleRows as $row) {
        $saleHeaders[intval($row->id_penjualan)] = [
            'id_penjualan' => intval($row->id_penjualan),
            'waktu_efektif' => Carbon::parse($row->waktu ?? $row->created_at)->format('Y-m-d H:i:s'),
            'total_item' => intval($row->total_item ?? 0),
            'total_harga' => intval($row->total_harga ?? 0),
            'bayar' => intval($row->bayar ?? 0),
            'diterima' => intval($row->diterima ?? 0),
        ];
    }
}

$analysis = [
    'generated_at' => Carbon::now()->format('Y-m-d H:i:s'),
    'source_report' => $resolvedReportPath,
    'cutoff' => $cutoff,
    'audited_until' => $auditedUntil,
    'summary' => [
        'products_analyzed' => 0,
        'total_negative_events' => 0,
        'products_recovered_to_non_negative' => 0,
        'products_still_negative_at_end' => 0,
        'products_with_same_day_purchase_recovery' => 0,
        'products_with_late_purchase_recovery' => 0,
        'products_with_manual_recovery' => 0,
        'products_with_no_recorded_recovery' => 0,
    ],
    'primary_cause_counts' => [],
    'normality_counts' => [],
    'resolution_bucket_counts' => [],
    'products' => [],
];

foreach ($productIds as $productId) {
    $product = $productsById->get($productId);
    if (!$product) {
        continue;
    }

    $rows = $rowsByProduct[$productId] ?? [];
    if (empty($rows)) {
        continue;
    }

    $analysis['summary']['products_analyzed']++;

    $seedRow = null;
    $startIndex = 0;
    if (isset($rows[0]) && is_forensic_seed_row($rows[0])) {
        $seedRow = $rows[0];
        $startIndex = 1;
    }

    $seedStock = $seedRow ? intval($seedRow['stok_sisa']) : intval($rows[0]['stok_awal']);
    $seedKeterangan = $seedRow['keterangan'] ?? null;

    $timeline = [];
    $negativeEvents = [];
    $recoveryEvents = [];

    $runningBefore = $seedStock;
    $totalPurchases = 0;
    $totalSales = 0;
    $manualNet = 0;
    $purchaseCount = 0;
    $saleCount = 0;
    $manualCount = 0;

    $inboundBeforeFirstNegative = 0;
    $manualInboundBeforeFirstNegative = 0;
    $firstNegativeIndex = null;
    $firstNegativeRunningBefore = null;
    $firstNegativeEvent = null;
    $minRawStock = $seedStock;
    $wasNegative = false;

    for ($index = $startIndex; $index < count($rows); $index++) {
        $row = $rows[$index];
        $type = classify_forensic_row_type($row);
        $runningAfter = $runningBefore + intval($row['stok_masuk']) - intval($row['stok_keluar']);

        $event = format_forensic_event($row, $runningBefore, $runningAfter, $purchaseHeaders, $saleHeaders);
        $timeline[] = $event;

        if ($type === 'pembelian') {
            $purchaseCount++;
            $totalPurchases += intval($row['stok_masuk']);
        } elseif ($type === 'penjualan') {
            $saleCount++;
            $totalSales += intval($row['stok_keluar']);
        } elseif ($type === 'manual') {
            $manualCount++;
            $manualNet += intval($row['stok_masuk']) - intval($row['stok_keluar']);
        }

        if ($firstNegativeIndex === null) {
            if ($type === 'pembelian') {
                $inboundBeforeFirstNegative += intval($row['stok_masuk']);
            }

            if ($type === 'manual' && manual_row_is_inbound($row)) {
                $inboundBeforeFirstNegative += intval($row['stok_masuk']) - intval($row['stok_keluar']);
                $manualInboundBeforeFirstNegative++;
            }
        }

        if ($runningAfter < $minRawStock) {
            $minRawStock = $runningAfter;
        }

        if ($runningAfter < 0) {
            $negativeEvent = $event;
            $negativeEvent['sequence_index'] = count($negativeEvents) + 1;
            $negativeEvents[] = $negativeEvent;

            if ($firstNegativeIndex === null) {
                $firstNegativeIndex = count($timeline) - 1;
                $firstNegativeRunningBefore = $runningBefore;
                $firstNegativeEvent = $negativeEvent;
            }

            $wasNegative = true;
        } elseif ($wasNegative) {
            $recoveryEvent = $event;
            $recoveryEvent['recovered_from_negative'] = true;
            $recoveryEvents[] = $recoveryEvent;
            $wasNegative = false;
        }

        $runningBefore = $runningAfter;
    }

    $finalRawStock = $runningBefore;
    $analysis['summary']['total_negative_events'] += count($negativeEvents);

    $recoveryByPurchaseSameDay = false;
    $recoveryByPurchaseLater = false;
    $recoveryByManual = false;
    $recoveryTime = null;
    $manualAfterNegative = 0;
    $purchasesAfterNegative = 0;
    $salesWhileNegative = 0;

    if ($firstNegativeIndex !== null) {
        $firstNegativeTime = Carbon::parse($timeline[$firstNegativeIndex]['waktu']);

        for ($index = $firstNegativeIndex; $index < count($timeline); $index++) {
            $event = $timeline[$index];

            if ($event['type'] === 'manual') {
                $manualAfterNegative++;
            }

            if ($event['type'] === 'pembelian') {
                $purchasesAfterNegative++;
            }

            if ($event['type'] === 'penjualan' && intval($event['running_before']) < 0) {
                $salesWhileNegative++;
            }
        }

        if (!empty($recoveryEvents)) {
            $firstRecovery = $recoveryEvents[0];
            $recoveryTime = $firstRecovery['waktu'];

            if ($firstRecovery['type'] === 'pembelian') {
                $diffHours = $firstNegativeTime->diffInHours(Carbon::parse($firstRecovery['waktu']), false);
                if ($diffHours <= 24 && $firstNegativeTime->isSameDay(Carbon::parse($firstRecovery['waktu']))) {
                    $recoveryByPurchaseSameDay = true;
                } else {
                    $recoveryByPurchaseLater = true;
                }
            }

            if ($firstRecovery['type'] === 'manual') {
                $recoveryByManual = true;
            }
        }
    }

    $firstNegativeType = $firstNegativeEvent['type'] ?? null;
    $oversellAmountFirstEvent = 0;
    if ($firstNegativeEvent) {
        $oversellAmountFirstEvent = max(0, intval($firstNegativeEvent['stok_keluar']) - (intval($firstNegativeRunningBefore) + intval($firstNegativeEvent['stok_masuk'])));
    }

    $facts = [
        'seed_stock' => $seedStock,
        'first_negative_type' => $firstNegativeType,
        'inbound_before_first_negative' => $inboundBeforeFirstNegative,
        'manual_inbound_before_first_negative' => $manualInboundBeforeFirstNegative,
        'oversell_amount_first_event' => $oversellAmountFirstEvent,
        'recovered_by_purchase_same_day' => $recoveryByPurchaseSameDay,
        'recovered_by_purchase_later' => $recoveryByPurchaseLater,
        'recovered_by_manual' => $recoveryByManual,
        'manual_after_negative' => $manualAfterNegative,
        'final_raw_stock' => $finalRawStock,
    ];

    $facts['primary_cause'] = derive_primary_cause($facts);
    $facts['normality_assessment'] = derive_normality_assessment($facts);
    $facts['resolution_bucket'] = derive_resolution_bucket($facts);

    push_forensic_count($analysis['primary_cause_counts'], $facts['primary_cause']);
    push_forensic_count($analysis['normality_counts'], $facts['normality_assessment']);
    push_forensic_count($analysis['resolution_bucket_counts'], $facts['resolution_bucket']);

    if ($finalRawStock >= 0) {
        $analysis['summary']['products_recovered_to_non_negative']++;
    } else {
        $analysis['summary']['products_still_negative_at_end']++;
    }

    if ($recoveryByPurchaseSameDay) {
        $analysis['summary']['products_with_same_day_purchase_recovery']++;
    } elseif ($recoveryByPurchaseLater) {
        $analysis['summary']['products_with_late_purchase_recovery']++;
    } elseif ($recoveryByManual) {
        $analysis['summary']['products_with_manual_recovery']++;
    } else {
        $analysis['summary']['products_with_no_recorded_recovery']++;
    }

    $timelineWindow = [];
    if ($firstNegativeIndex !== null) {
        $windowStart = max(0, $firstNegativeIndex - 3);
        $timelineWindow = array_slice($timeline, $windowStart, 8);
    }

    $recoveryWindow = [];
    if (!empty($recoveryEvents)) {
        $recoveryToken = build_timeline_token($recoveryEvents[0]);
        $recoveryIndex = null;
        foreach ($timeline as $timelineIndex => $event) {
            if (build_timeline_token($event) === $recoveryToken && $event['waktu'] === $recoveryEvents[0]['waktu']) {
                $recoveryIndex = $timelineIndex;
                break;
            }
        }

        if ($recoveryIndex !== null) {
            $recoveryWindow = array_slice($timeline, max(0, $recoveryIndex - 3), 8);
        }
    }

    $analysis['products'][] = [
        'id_produk' => intval($product->id_produk),
        'nama_produk' => (string) ($product->nama_produk ?? ''),
        'produk_stok_clamped' => intval($product->stok ?? 0),
        'seed_stock' => $seedStock,
        'seed_keterangan' => $seedKeterangan,
        'purchase_event_count' => $purchaseCount,
        'sale_event_count' => $saleCount,
        'manual_event_count' => $manualCount,
        'total_purchases_qty' => $totalPurchases,
        'total_sales_qty' => $totalSales,
        'manual_net_qty' => $manualNet,
        'negative_event_count' => count($negativeEvents),
        'min_raw_stock' => $minRawStock,
        'final_raw_stock' => $finalRawStock,
        'recovered_to_non_negative' => $finalRawStock >= 0,
        'recovery_time' => $recoveryTime,
        'sales_while_negative' => $salesWhileNegative,
        'purchases_after_negative' => $purchasesAfterNegative,
        'manual_after_negative' => $manualAfterNegative,
        'first_negative_event' => $firstNegativeEvent,
        'recovery_events' => $recoveryEvents,
        'primary_cause' => $facts['primary_cause'],
        'normality_assessment' => $facts['normality_assessment'],
        'resolution_bucket' => $facts['resolution_bucket'],
        'why' => [
            'inbound_before_first_negative' => $inboundBeforeFirstNegative,
            'manual_inbound_before_first_negative' => $manualInboundBeforeFirstNegative,
            'oversell_amount_first_event' => $oversellAmountFirstEvent,
            'recovered_by_purchase_same_day' => $recoveryByPurchaseSameDay,
            'recovered_by_purchase_later' => $recoveryByPurchaseLater,
            'recovered_by_manual' => $recoveryByManual,
        ],
        'timeline_window_around_first_negative' => $timelineWindow,
        'timeline_window_around_recovery' => $recoveryWindow,
    ];
}

$outputPath = storage_path('app/post_cutoff_negative_stock_forensics_' . Carbon::now()->format('Ymd_His') . '.json');
file_put_contents($outputPath, json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'forensics_report_path' => $outputPath,
    'summary' => $analysis['summary'],
    'primary_cause_counts' => $analysis['primary_cause_counts'],
    'normality_counts' => $analysis['normality_counts'],
    'resolution_bucket_counts' => $analysis['resolution_bucket_counts'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;