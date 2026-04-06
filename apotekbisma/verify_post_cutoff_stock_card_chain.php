<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

function push_sample(array &$bucket, string $key, array $value, int $limit = 10): void
{
    if (!isset($bucket[$key])) {
        $bucket[$key] = [];
    }

    if (count($bucket[$key]) >= $limit) {
        return;
    }

    $bucket[$key][] = $value;
}

function increment_counter(array &$bucket, string $key): void
{
    $bucket[$key] = intval($bucket[$key] ?? 0) + 1;
}

function normalize_manual_text(?string $value): string
{
    return mb_strtolower(trim((string) $value));
}

function is_pembelian_finalized($row): bool
{
    $noFaktur = trim((string) ($row->no_faktur ?? ''));

    return $noFaktur !== ''
        && strtolower($noFaktur) !== 'o'
        && intval($row->total_harga ?? 0) > 0
        && intval($row->bayar ?? 0) > 0;
}

function is_penjualan_finalized($row): bool
{
    return intval($row->total_item ?? 0) > 0
        && intval($row->total_harga ?? 0) > 0
        && intval($row->bayar ?? 0) > 0
        && intval($row->diterima ?? 0) > 0;
}

function is_excluded_manual_record(?string $keterangan, array $patterns): bool
{
    $needle = normalize_manual_text($keterangan);
    if ($needle === '') {
        return false;
    }

    foreach ($patterns as $pattern) {
        $normalizedPattern = normalize_manual_text((string) $pattern);
        if ($normalizedPattern !== '' && str_contains($needle, $normalizedPattern)) {
            return true;
        }
    }

    return false;
}

function is_manual_stock_opname_like(?string $keterangan): bool
{
    $needle = normalize_manual_text($keterangan);

    return $needle !== ''
        && (
            str_contains($needle, 'stock opname')
            || str_contains($needle, 'perubahan stok manual')
            || str_contains($needle, 'penyesuaian stok')
        );
}

function stock_event_compare(array $left, array $right): int
{
    $timeComparison = strcmp($left['waktu'], $right['waktu']);
    if ($timeComparison !== 0) {
        return $timeComparison;
    }

    if ($left['type_priority'] !== $right['type_priority']) {
        return $left['type_priority'] <=> $right['type_priority'];
    }

    return $left['sort_key'] <=> $right['sort_key'];
}

function build_manual_signature(string $waktu, int $stokMasuk, int $stokKeluar, string $keterangan): string
{
    return implode('|', [
        'manual',
        $waktu,
        $stokMasuk,
        $stokKeluar,
        normalize_manual_text($keterangan),
    ]);
}

function normalize_csv_cell($value): string
{
    return trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $value));
}

function detect_csv_delimiter(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        return ',';
    }

    $sample = @file_get_contents($path, false, null, 0, 2048);
    if ($sample === false) {
        return ',';
    }

    $sample = preg_replace('/^\xEF\xBB\xBF/', '', $sample);
    $firstLine = strtok($sample, "\r\n") ?: '';

    $delimiterScores = [
        ';' => substr_count($firstLine, ';'),
        ',' => substr_count($firstLine, ','),
        "\t" => substr_count($firstLine, "\t"),
    ];

    arsort($delimiterScores);
    $delimiter = array_key_first($delimiterScores);

    return $delimiter ?: ',';
}

function load_baseline_csv_map(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $delimiter = detect_csv_delimiter($path);
    $handle = fopen($path, 'r');
    if ($handle === false) {
        return [];
    }

    $header = fgetcsv($handle, 0, $delimiter);
    if (!$header || count($header) < 3) {
        fclose($handle);
        return [];
    }

    $baselineMap = [];
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count($row) < 3) {
            continue;
        }

        $productId = intval(normalize_csv_cell($row[0]));
        if ($productId <= 0) {
            continue;
        }

        $baselineMap[$productId] = [
            'id_produk' => $productId,
            'nama_produk' => trim((string) normalize_csv_cell($row[1])),
            'stok' => is_numeric(normalize_csv_cell($row[2])) ? intval(normalize_csv_cell($row[2])) : 0,
        ];
    }

    fclose($handle);

    return $baselineMap;
}

function resolve_seed_for_product(int $productId, string $cutoff, array $baselineMap): array
{
    if (isset($baselineMap[$productId])) {
        return [
            'stok' => intval($baselineMap[$productId]['stok']),
            'keterangan' => 'Saldo Awal Stok per 31-12-2025',
            'source' => 'baseline_csv',
        ];
    }

    $lastPreCutoffRecord = DB::table('rekaman_stoks')
        ->select('stok_sisa', 'stok_awal', 'stok_masuk', 'stok_keluar')
        ->where('id_produk', $productId)
        ->where('waktu', '<=', $cutoff)
        ->orderBy('waktu', 'desc')
        ->orderBy('created_at', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();

    if ($lastPreCutoffRecord) {
        $seedStock = $lastPreCutoffRecord->stok_sisa !== null
            ? intval($lastPreCutoffRecord->stok_sisa)
            : intval($lastPreCutoffRecord->stok_awal) + intval($lastPreCutoffRecord->stok_masuk) - intval($lastPreCutoffRecord->stok_keluar);

        return [
            'stok' => $seedStock,
            'keterangan' => 'Saldo Awal Stok dari histori sebelum cutoff',
            'source' => 'pre_cutoff_rekaman',
        ];
    }

    return [
        'stok' => 0,
        'keterangan' => 'Saldo Awal Stok Produk Baru',
        'source' => 'zero_default',
    ];
}

function is_seed_like_row(array $row): bool
{
    $keterangan = normalize_manual_text((string) ($row['keterangan'] ?? ''));

    return $row['id_pembelian'] === null
        && $row['id_penjualan'] === null
        && $row['stok_masuk'] === 0
    && $row['stok_keluar'] === 0
        && str_contains($keterangan, 'saldo awal stok');
}

$now = Carbon::now();
$cutoff = Carbon::parse((string) config('stock.cutoff_datetime', '2025-12-31 23:59:59'));
$auditedUntil = $now->copy();
$maxFutureMinutes = max(0, (int) config('stock.max_future_transaction_minutes', 5));
$latestAllowed = $now->copy()->addMinutes($maxFutureMinutes);
$cutoffString = $cutoff->format('Y-m-d H:i:s');
$auditedUntilString = $auditedUntil->format('Y-m-d H:i:s');
$latestAllowedString = $latestAllowed->format('Y-m-d H:i:s');
$excludedManualPatterns = config('stock.excluded_manual_keterangan_patterns', []);
$sampleLimit = 10;

$requestedProductIds = [];
foreach ($argv as $arg) {
    if (strpos($arg, '--ids=') === 0) {
        $requestedProductIds = array_values(array_unique(array_filter(array_map('intval', explode(',', substr($arg, 6))), function ($productId) {
            return $productId > 0;
        })));
    }
}

$baselineMap = load_baseline_csv_map(base_path((string) config('stock.baseline_csv', 'REKAMAN STOK FINAL 31 DESEMBER 2025_2.csv')));

$purchaseSourceRows = DB::table('pembelian_detail as pd')
    ->join('pembelian as p', 'pd.id_pembelian', '=', 'p.id_pembelian')
    ->where('pd.jumlah', '>', 0)
    ->whereRaw('COALESCE(p.waktu_datang, p.waktu, p.created_at) > ?', [$cutoffString])
    ->when(!empty($requestedProductIds), function ($query) use ($requestedProductIds) {
        $query->whereIn('pd.id_produk', $requestedProductIds);
    })
    ->groupBy('pd.id_produk', 'pd.id_pembelian', DB::raw('COALESCE(p.waktu_datang, p.waktu, p.created_at)'))
    ->selectRaw('pd.id_produk, pd.id_pembelian as ref_id, COALESCE(p.waktu_datang, p.waktu, p.created_at) as waktu_event, SUM(pd.jumlah) as qty, MAX(pd.id_pembelian_detail) as sort_key')
    ->selectRaw('MAX(p.no_faktur) as no_faktur, MAX(p.total_harga) as total_harga, MAX(p.bayar) as bayar')
    ->get();

$saleSourceRows = DB::table('penjualan_detail as pd')
    ->join('penjualan as p', 'pd.id_penjualan', '=', 'p.id_penjualan')
    ->where('pd.jumlah', '>', 0)
    ->whereRaw('COALESCE(p.waktu, p.created_at) > ?', [$cutoffString])
    ->when(!empty($requestedProductIds), function ($query) use ($requestedProductIds) {
        $query->whereIn('pd.id_produk', $requestedProductIds);
    })
    ->groupBy('pd.id_produk', 'pd.id_penjualan', DB::raw('COALESCE(p.waktu, p.created_at)'))
    ->selectRaw('pd.id_produk, pd.id_penjualan as ref_id, COALESCE(p.waktu, p.created_at) as waktu_event, SUM(pd.jumlah) as qty, MAX(pd.id_penjualan_detail) as sort_key')
    ->selectRaw('MAX(p.total_item) as total_item, MAX(p.total_harga) as total_harga, MAX(p.bayar) as bayar, MAX(p.diterima) as diterima')
    ->get();

$actualProductIds = DB::table('rekaman_stoks')
    ->where('waktu', '>=', $cutoffString)
    ->when(!empty($requestedProductIds), function ($query) use ($requestedProductIds) {
        $query->whereIn('id_produk', $requestedProductIds);
    })
    ->distinct()
    ->pluck('id_produk')
    ->map(function ($productId) {
        return intval($productId);
    })
    ->all();

$relevantProductIds = array_values(array_unique(array_merge(
    $requestedProductIds,
    $actualProductIds,
    $purchaseSourceRows->pluck('id_produk')->map(function ($productId) {
        return intval($productId);
    })->all(),
    $saleSourceRows->pluck('id_produk')->map(function ($productId) {
        return intval($productId);
    })->all()
)));
sort($relevantProductIds);

$productsById = DB::table('produk')
    ->when(!empty($relevantProductIds), function ($query) use ($relevantProductIds) {
        $query->whereIn('id_produk', $relevantProductIds);
    }, function ($query) {
        $query->whereRaw('1 = 0');
    })
    ->select('id_produk', 'nama_produk', 'stok')
    ->get()
    ->keyBy('id_produk');

$purchaseSourcesByProduct = [];
foreach ($purchaseSourceRows as $row) {
    $event = [
        'type' => 'pembelian',
        'type_priority' => 10,
        'sort_key' => intval($row->sort_key ?? 0),
        'waktu' => Carbon::parse($row->waktu_event)->format('Y-m-d H:i:s'),
        'ref_id' => intval($row->ref_id),
        'stok_masuk' => intval($row->qty ?? 0),
        'stok_keluar' => 0,
        'no_faktur' => (string) ($row->no_faktur ?? ''),
        'total_harga' => intval($row->total_harga ?? 0),
        'bayar' => intval($row->bayar ?? 0),
        'is_finalized' => is_pembelian_finalized($row),
    ];

    $purchaseSourcesByProduct[intval($row->id_produk)][intval($row->ref_id)] = $event;
}

$saleSourcesByProduct = [];
foreach ($saleSourceRows as $row) {
    $event = [
        'type' => 'penjualan',
        'type_priority' => 20,
        'sort_key' => intval($row->sort_key ?? 0),
        'waktu' => Carbon::parse($row->waktu_event)->format('Y-m-d H:i:s'),
        'ref_id' => intval($row->ref_id),
        'stok_masuk' => 0,
        'stok_keluar' => intval($row->qty ?? 0),
        'total_item' => intval($row->total_item ?? 0),
        'total_harga' => intval($row->total_harga ?? 0),
        'bayar' => intval($row->bayar ?? 0),
        'diterima' => intval($row->diterima ?? 0),
        'is_finalized' => is_penjualan_finalized($row),
    ];

    $saleSourcesByProduct[intval($row->id_produk)][intval($row->ref_id)] = $event;
}

$stockOrderRaw = "CASE
    WHEN id_pembelian IS NOT NULL THEN 0
    WHEN id_penjualan IS NOT NULL THEN 1
    WHEN LOWER(COALESCE(keterangan, '')) LIKE '%stock opname%' THEN 2
    WHEN LOWER(COALESCE(keterangan, '')) LIKE '%perubahan stok manual%' THEN 2
    WHEN LOWER(COALESCE(keterangan, '')) LIKE '%penyesuaian stok%' THEN 2
    WHEN LOWER(COALESCE(keterangan, '')) LIKE '%saldo awal stok%' THEN 2
    ELSE 3
END ASC";

$actualRowsByProduct = [];
if (!empty($relevantProductIds)) {
    $actualRows = DB::table('rekaman_stoks')
        ->whereIn('id_produk', $relevantProductIds)
        ->where('waktu', '>=', $cutoffString)
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

    foreach ($actualRows as $row) {
        $actualRowsByProduct[intval($row->id_produk)][] = [
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
    }
}

$report = [
    'generated_at' => $now->format('Y-m-d H:i:s'),
    'scope_product_ids' => $requestedProductIds,
    'cutoff' => $cutoffString,
    'audited_until' => $auditedUntilString,
    'latest_allowed' => $latestAllowedString,
    'summary' => [
        'products_checked' => 0,
        'products_with_expected_activity' => 0,
        'products_with_actual_post_cutoff_rows' => 0,
        'products_with_manual_post_cutoff_rows' => 0,
        'products_with_manual_stock_opname_like_rows' => 0,
        'products_with_negative_stock_events' => 0,
        'products_with_anomalies' => 0,
        'clean_products' => 0,
    ],
    'anomaly_counts' => [],
    'negative_stock_products' => [],
    'products' => [],
];

foreach ($relevantProductIds as $index => $productId) {
    $product = $productsById->get($productId);
    if (!$product) {
        continue;
    }

    $report['summary']['products_checked']++;

    $seed = resolve_seed_for_product($productId, $cutoffString, $baselineMap);
    $actualRows = $actualRowsByProduct[$productId] ?? [];
    $purchaseSources = $purchaseSourcesByProduct[$productId] ?? [];
    $saleSources = $saleSourcesByProduct[$productId] ?? [];

    $expectedEvents = [];
    $expectedPurchaseIds = [];
    $expectedSaleIds = [];
    $futureSourceCount = 0;

    foreach ($purchaseSources as $refId => $event) {
        if (!$event['is_finalized']) {
            continue;
        }

        if ($event['waktu'] > $auditedUntilString) {
            $futureSourceCount++;
        } else {
            $expectedEvents[] = $event;
            $expectedPurchaseIds[$refId] = true;
        }
    }

    foreach ($saleSources as $refId => $event) {
        if (!$event['is_finalized']) {
            continue;
        }

        if ($event['waktu'] > $auditedUntilString) {
            $futureSourceCount++;
        } else {
            $expectedEvents[] = $event;
            $expectedSaleIds[$refId] = true;
        }
    }

    $expectedManualCounts = [];
    $manualRowsCount = 0;
    $manualStockOpnameCount = 0;
    $excludedManualRows = [];

    foreach ($actualRows as $row) {
        if ($row['id_pembelian'] !== null || $row['id_penjualan'] !== null) {
            continue;
        }

        if (is_seed_like_row($row)) {
            continue;
        }

        if ($row['waktu'] <= $cutoffString || $row['waktu'] > $auditedUntilString) {
            continue;
        }

        if ($row['stok_masuk'] === 0 && $row['stok_keluar'] === 0) {
            continue;
        }

        if (is_excluded_manual_record($row['keterangan'], $excludedManualPatterns)) {
            $excludedManualRows[] = $row;
            continue;
        }

        $manualRowsCount++;
        if (is_manual_stock_opname_like($row['keterangan'])) {
            $manualStockOpnameCount++;
        }

        $expectedEvents[] = [
            'type' => 'manual',
            'type_priority' => 30,
            'sort_key' => intval($row['id_rekaman_stok']),
            'waktu' => $row['waktu'],
            'ref_id' => null,
            'stok_masuk' => intval($row['stok_masuk']),
            'stok_keluar' => intval($row['stok_keluar']),
            'keterangan' => $row['keterangan'],
        ];

        $signature = build_manual_signature($row['waktu'], intval($row['stok_masuk']), intval($row['stok_keluar']), $row['keterangan']);
        $expectedManualCounts[$signature] = intval($expectedManualCounts[$signature] ?? 0) + 1;
    }

    usort($expectedEvents, 'stock_event_compare');

    if (!empty($expectedEvents) || $futureSourceCount > 0) {
        $report['summary']['products_with_expected_activity']++;
    }

    if (!empty($actualRows)) {
        $report['summary']['products_with_actual_post_cutoff_rows']++;
    }

    if ($manualRowsCount > 0) {
        $report['summary']['products_with_manual_post_cutoff_rows']++;
    }

    if ($manualStockOpnameCount > 0) {
        $report['summary']['products_with_manual_stock_opname_like_rows']++;
    }

    $expectedNetDelta = 0;
    foreach ($expectedEvents as $event) {
        $expectedNetDelta += intval($event['stok_masuk']) - intval($event['stok_keluar']);
    }
    $expectedFinalStock = max(0, intval($seed['stok']) + $expectedNetDelta);

    $anomalies = [];
    $samples = [];
    $notes = [];

    if ($futureSourceCount > 0) {
        $anomalies['finalized_source_after_now'] = true;
        foreach ($purchaseSources as $event) {
            if ($event['is_finalized'] && $event['waktu'] > $auditedUntilString) {
                push_sample($samples, 'finalized_source_after_now', [
                    'type' => 'pembelian',
                    'ref_id' => $event['ref_id'],
                    'waktu' => $event['waktu'],
                    'qty' => $event['stok_masuk'],
                    'beyond_latest_allowed' => $event['waktu'] > $latestAllowedString,
                ], $sampleLimit);
            }
        }
        foreach ($saleSources as $event) {
            if ($event['is_finalized'] && $event['waktu'] > $auditedUntilString) {
                push_sample($samples, 'finalized_source_after_now', [
                    'type' => 'penjualan',
                    'ref_id' => $event['ref_id'],
                    'waktu' => $event['waktu'],
                    'qty' => $event['stok_keluar'],
                    'beyond_latest_allowed' => $event['waktu'] > $latestAllowedString,
                ], $sampleLimit);
            }
        }
    }

    if (!empty($excludedManualRows)) {
        $anomalies['excluded_manual_post_cutoff_row'] = true;
        foreach ($excludedManualRows as $row) {
            push_sample($samples, 'excluded_manual_post_cutoff_row', [
                'id_rekaman_stok' => $row['id_rekaman_stok'],
                'waktu' => $row['waktu'],
                'stok_masuk' => $row['stok_masuk'],
                'stok_keluar' => $row['stok_keluar'],
                'keterangan' => $row['keterangan'],
            ], $sampleLimit);
        }
    }

    if ($manualRowsCount > 0) {
        $notes['manual_post_cutoff_rows'] = $manualRowsCount;
    }

    if ($manualStockOpnameCount > 0) {
        $notes['manual_stock_opname_like_rows'] = $manualStockOpnameCount;
    }

    $actualEventRows = $actualRows;
    if (empty($actualRows)) {
        if (!empty($expectedEvents)) {
            $anomalies['missing_seed_row'] = true;
        }
    } else {
        $firstRow = $actualRows[0];

        if (!is_seed_like_row($firstRow)) {
            $anomalies['missing_or_invalid_seed_row'] = true;
            push_sample($samples, 'missing_or_invalid_seed_row', $firstRow, $sampleLimit);
        } else {
            if ($firstRow['stok_awal'] !== intval($seed['stok']) || $firstRow['stok_sisa'] !== intval($seed['stok'])) {
                $anomalies['seed_stock_mismatch'] = true;
                push_sample($samples, 'seed_stock_mismatch', [
                    'id_rekaman_stok' => $firstRow['id_rekaman_stok'],
                    'actual_stok_awal' => $firstRow['stok_awal'],
                    'actual_stok_sisa' => $firstRow['stok_sisa'],
                    'expected_seed_stock' => intval($seed['stok']),
                    'seed_source' => $seed['source'],
                ], $sampleLimit);
            }

            $actualEventRows = array_slice($actualRows, 1);
        }

        $duplicateSeedRows = 0;
        foreach ($actualEventRows as $row) {
            if (is_seed_like_row($row)) {
                $duplicateSeedRows++;
                push_sample($samples, 'duplicate_seed_row', $row, $sampleLimit);
            }
        }

        if ($duplicateSeedRows > 0) {
            $anomalies['duplicate_seed_row'] = true;
        }
    }

    $matchedExpectedPurchaseIds = [];
    $matchedExpectedSaleIds = [];
    $matchedManualCounts = [];
    $actualOrderTokens = [];

    foreach ($actualEventRows as $row) {
        if ($row['waktu'] > $auditedUntilString) {
            $anomalies['future_rekaman_row'] = true;
            push_sample($samples, 'future_rekaman_row', [
                'id_rekaman_stok' => $row['id_rekaman_stok'],
                'waktu' => $row['waktu'],
                'id_pembelian' => $row['id_pembelian'],
                'id_penjualan' => $row['id_penjualan'],
            ], $sampleLimit);
        }

        if ($row['id_pembelian'] !== null) {
            $refId = intval($row['id_pembelian']);
            $source = $purchaseSources[$refId] ?? null;

            if (!$source) {
                $anomalies['orphan_pembelian_rekaman_row'] = true;
                push_sample($samples, 'orphan_pembelian_rekaman_row', $row, $sampleLimit);
                continue;
            }

            if (!$source['is_finalized']) {
                $anomalies['non_finalized_pembelian_rekaman_row'] = true;
                push_sample($samples, 'non_finalized_pembelian_rekaman_row', [
                    'id_rekaman_stok' => $row['id_rekaman_stok'],
                    'id_pembelian' => $refId,
                    'waktu_rekaman' => $row['waktu'],
                    'waktu_sumber' => $source['waktu'],
                    'qty' => $row['stok_masuk'],
                ], $sampleLimit);
                continue;
            }

            if ($source['waktu'] > $auditedUntilString) {
                $anomalies['future_pembelian_source_row_present'] = true;
                push_sample($samples, 'future_pembelian_source_row_present', [
                    'id_rekaman_stok' => $row['id_rekaman_stok'],
                    'id_pembelian' => $refId,
                    'waktu_rekaman' => $row['waktu'],
                    'waktu_sumber' => $source['waktu'],
                ], $sampleLimit);
                continue;
            }

            $matchedExpectedPurchaseIds[$refId] = true;
            $actualOrderTokens[] = 'pembelian:' . $refId;

            if ($row['waktu'] !== $source['waktu']) {
                $anomalies['pembelian_waktu_mismatch'] = true;
                push_sample($samples, 'pembelian_waktu_mismatch', [
                    'id_rekaman_stok' => $row['id_rekaman_stok'],
                    'id_pembelian' => $refId,
                    'actual_waktu' => $row['waktu'],
                    'expected_waktu' => $source['waktu'],
                ], $sampleLimit);
            }

            if ($row['stok_masuk'] !== intval($source['stok_masuk']) || $row['stok_keluar'] !== 0) {
                $anomalies['pembelian_qty_mismatch'] = true;
                push_sample($samples, 'pembelian_qty_mismatch', [
                    'id_rekaman_stok' => $row['id_rekaman_stok'],
                    'id_pembelian' => $refId,
                    'actual_stok_masuk' => $row['stok_masuk'],
                    'actual_stok_keluar' => $row['stok_keluar'],
                    'expected_stok_masuk' => intval($source['stok_masuk']),
                ], $sampleLimit);
            }

            continue;
        }

        if ($row['id_penjualan'] !== null) {
            $refId = intval($row['id_penjualan']);
            $source = $saleSources[$refId] ?? null;

            if (!$source) {
                $anomalies['orphan_penjualan_rekaman_row'] = true;
                push_sample($samples, 'orphan_penjualan_rekaman_row', $row, $sampleLimit);
                continue;
            }

            if (!$source['is_finalized']) {
                $anomalies['non_finalized_penjualan_rekaman_row'] = true;
                push_sample($samples, 'non_finalized_penjualan_rekaman_row', [
                    'id_rekaman_stok' => $row['id_rekaman_stok'],
                    'id_penjualan' => $refId,
                    'waktu_rekaman' => $row['waktu'],
                    'waktu_sumber' => $source['waktu'],
                    'qty' => $row['stok_keluar'],
                ], $sampleLimit);
                continue;
            }

            if ($source['waktu'] > $auditedUntilString) {
                $anomalies['future_penjualan_source_row_present'] = true;
                push_sample($samples, 'future_penjualan_source_row_present', [
                    'id_rekaman_stok' => $row['id_rekaman_stok'],
                    'id_penjualan' => $refId,
                    'waktu_rekaman' => $row['waktu'],
                    'waktu_sumber' => $source['waktu'],
                ], $sampleLimit);
                continue;
            }

            $matchedExpectedSaleIds[$refId] = true;
            $actualOrderTokens[] = 'penjualan:' . $refId;

            if ($row['waktu'] !== $source['waktu']) {
                $anomalies['penjualan_waktu_mismatch'] = true;
                push_sample($samples, 'penjualan_waktu_mismatch', [
                    'id_rekaman_stok' => $row['id_rekaman_stok'],
                    'id_penjualan' => $refId,
                    'actual_waktu' => $row['waktu'],
                    'expected_waktu' => $source['waktu'],
                ], $sampleLimit);
            }

            if ($row['stok_keluar'] !== intval($source['stok_keluar']) || $row['stok_masuk'] !== 0) {
                $anomalies['penjualan_qty_mismatch'] = true;
                push_sample($samples, 'penjualan_qty_mismatch', [
                    'id_rekaman_stok' => $row['id_rekaman_stok'],
                    'id_penjualan' => $refId,
                    'actual_stok_masuk' => $row['stok_masuk'],
                    'actual_stok_keluar' => $row['stok_keluar'],
                    'expected_stok_keluar' => intval($source['stok_keluar']),
                ], $sampleLimit);
            }

            continue;
        }

        if ($row['stok_masuk'] === 0 && $row['stok_keluar'] === 0) {
            $anomalies['zero_delta_manual_row'] = true;
            push_sample($samples, 'zero_delta_manual_row', $row, $sampleLimit);
            continue;
        }

        if (is_excluded_manual_record($row['keterangan'], $excludedManualPatterns)) {
            $anomalies['excluded_manual_post_cutoff_row'] = true;
            push_sample($samples, 'excluded_manual_post_cutoff_row', $row, $sampleLimit);
            continue;
        }

        $signature = build_manual_signature($row['waktu'], $row['stok_masuk'], $row['stok_keluar'], $row['keterangan']);
        $matchedManualCounts[$signature] = intval($matchedManualCounts[$signature] ?? 0) + 1;
        $actualOrderTokens[] = $signature . '#' . $matchedManualCounts[$signature];
    }

    foreach (array_keys($expectedPurchaseIds) as $refId) {
        if (!isset($matchedExpectedPurchaseIds[$refId])) {
            $anomalies['missing_rekaman_for_finalized_pembelian'] = true;
            $source = $purchaseSources[$refId];
            push_sample($samples, 'missing_rekaman_for_finalized_pembelian', [
                'id_pembelian' => $refId,
                'waktu' => $source['waktu'],
                'stok_masuk' => $source['stok_masuk'],
            ], $sampleLimit);
        }
    }

    foreach (array_keys($expectedSaleIds) as $refId) {
        if (!isset($matchedExpectedSaleIds[$refId])) {
            $anomalies['missing_rekaman_for_finalized_penjualan'] = true;
            $source = $saleSources[$refId];
            push_sample($samples, 'missing_rekaman_for_finalized_penjualan', [
                'id_penjualan' => $refId,
                'waktu' => $source['waktu'],
                'stok_keluar' => $source['stok_keluar'],
            ], $sampleLimit);
        }
    }

    foreach ($expectedManualCounts as $signature => $count) {
        if (intval($matchedManualCounts[$signature] ?? 0) !== $count) {
            $anomalies['manual_row_count_mismatch'] = true;
            push_sample($samples, 'manual_row_count_mismatch', [
                'signature' => $signature,
                'expected_count' => $count,
                'actual_count' => intval($matchedManualCounts[$signature] ?? 0),
            ], $sampleLimit);
        }
    }

    if (empty(array_intersect(array_keys($anomalies), [
        'missing_rekaman_for_finalized_pembelian',
        'missing_rekaman_for_finalized_penjualan',
        'orphan_pembelian_rekaman_row',
        'orphan_penjualan_rekaman_row',
        'non_finalized_pembelian_rekaman_row',
        'non_finalized_penjualan_rekaman_row',
        'pembelian_waktu_mismatch',
        'penjualan_waktu_mismatch',
        'pembelian_qty_mismatch',
        'penjualan_qty_mismatch',
        'future_pembelian_source_row_present',
        'future_penjualan_source_row_present',
        'manual_row_count_mismatch',
    ]))) {
        $expectedOrderTokens = [];
        $expectedManualCounters = [];
        foreach ($expectedEvents as $event) {
            if ($event['type'] === 'pembelian') {
                $expectedOrderTokens[] = 'pembelian:' . $event['ref_id'];
                continue;
            }

            if ($event['type'] === 'penjualan') {
                $expectedOrderTokens[] = 'penjualan:' . $event['ref_id'];
                continue;
            }

            $signature = build_manual_signature($event['waktu'], intval($event['stok_masuk']), intval($event['stok_keluar']), (string) ($event['keterangan'] ?? ''));
            $expectedManualCounters[$signature] = intval($expectedManualCounters[$signature] ?? 0) + 1;
            $expectedOrderTokens[] = $signature . '#' . $expectedManualCounters[$signature];
        }

        if ($expectedOrderTokens !== $actualOrderTokens) {
            $anomalies['event_order_mismatch'] = true;
            push_sample($samples, 'event_order_mismatch', [
                'expected_first_20' => array_slice($expectedOrderTokens, 0, 20),
                'actual_first_20' => array_slice($actualOrderTokens, 0, 20),
            ], 1);
        }
    }

    $runningStock = intval($seed['stok']);
    $chainAwalErrors = 0;
    $chainSisaErrors = 0;
    $negativeEventCount = 0;
    $negativeEventSamples = [];

    foreach ($actualEventRows as $row) {
        if ($row['stok_awal'] !== $runningStock) {
            $chainAwalErrors++;
            push_sample($samples, 'chain_stok_awal_mismatch', [
                'id_rekaman_stok' => $row['id_rekaman_stok'],
                'waktu' => $row['waktu'],
                'actual_stok_awal' => $row['stok_awal'],
                'expected_stok_awal' => $runningStock,
            ], $sampleLimit);
        }

        $calculatedSisa = $runningStock + intval($row['stok_masuk']) - intval($row['stok_keluar']);
        if ($calculatedSisa < 0) {
            $negativeEventCount++;
            push_sample($negativeEventSamples, 'negative_stock_event', [
                'id_rekaman_stok' => $row['id_rekaman_stok'],
                'waktu' => $row['waktu'],
                'stok_awal' => $runningStock,
                'stok_masuk' => $row['stok_masuk'],
                'stok_keluar' => $row['stok_keluar'],
                'calculated_stok_sisa' => $calculatedSisa,
            ], $sampleLimit);
        }

        if ($row['stok_sisa'] !== $calculatedSisa) {
            $chainSisaErrors++;
            push_sample($samples, 'chain_stok_sisa_mismatch', [
                'id_rekaman_stok' => $row['id_rekaman_stok'],
                'waktu' => $row['waktu'],
                'stok_awal' => $runningStock,
                'stok_masuk' => $row['stok_masuk'],
                'stok_keluar' => $row['stok_keluar'],
                'actual_stok_sisa' => $row['stok_sisa'],
                'expected_stok_sisa' => $calculatedSisa,
            ], $sampleLimit);
        }

        $runningStock = $calculatedSisa;
    }

    if ($negativeEventCount > 0) {
        $report['summary']['products_with_negative_stock_events']++;
        $notes['negative_stock_event_count'] = $negativeEventCount;
        $report['negative_stock_products'][] = [
            'id_produk' => intval($product->id_produk),
            'nama_produk' => (string) ($product->nama_produk ?? ''),
            'negative_stock_event_count' => $negativeEventCount,
            'samples' => $negativeEventSamples['negative_stock_event'] ?? [],
        ];
    }

    if ($chainAwalErrors > 0) {
        $anomalies['chain_stok_awal_mismatch'] = true;
    }

    if ($chainSisaErrors > 0) {
        $anomalies['chain_stok_sisa_mismatch'] = true;
    }

    $actualFinalStockFromChain = max(0, $runningStock);
    if ($actualFinalStockFromChain !== $expectedFinalStock) {
        $anomalies['final_stock_from_source_mismatch'] = true;
        push_sample($samples, 'final_stock_from_source_mismatch', [
            'actual_final_stock_from_chain' => $actualFinalStockFromChain,
            'expected_final_stock_from_source' => $expectedFinalStock,
            'seed_stock' => intval($seed['stok']),
        ], $sampleLimit);
    }

    if (intval($product->stok) !== $expectedFinalStock) {
        $anomalies['produk_stock_mismatch'] = true;
        push_sample($samples, 'produk_stock_mismatch', [
            'produk_stok' => intval($product->stok),
            'expected_final_stock_from_source' => $expectedFinalStock,
        ], $sampleLimit);
    }

    if (!empty($anomalies)) {
        $report['summary']['products_with_anomalies']++;

        foreach (array_keys($anomalies) as $anomalyKey) {
            increment_counter($report['anomaly_counts'], $anomalyKey);
        }

        $report['products'][] = [
            'id_produk' => intval($product->id_produk),
            'nama_produk' => (string) ($product->nama_produk ?? ''),
            'produk_stok' => intval($product->stok ?? 0),
            'seed_stock' => intval($seed['stok']),
            'seed_source' => $seed['source'],
            'actual_post_cutoff_row_count' => count($actualRows),
            'expected_event_count' => count($expectedEvents),
            'manual_post_cutoff_row_count' => $manualRowsCount,
            'manual_stock_opname_like_row_count' => $manualStockOpnameCount,
            'anomalies' => array_keys($anomalies),
            'notes' => $notes,
            'samples' => $samples,
        ];
    } else {
        $report['summary']['clean_products']++;
    }

    if (($index + 1) % 50 === 0) {
        echo 'Processed ' . ($index + 1) . ' / ' . count($relevantProductIds) . " products\n";
    }
}

$reportPath = storage_path('app/post_cutoff_stock_card_chain_audit_' . $now->format('Ymd_His') . '.json');
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'report_path' => $reportPath,
    'summary' => $report['summary'],
    'anomaly_counts' => $report['anomaly_counts'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;