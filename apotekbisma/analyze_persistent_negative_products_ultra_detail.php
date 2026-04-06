<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

function ultra_normalize_text(?string $value): string
{
    return mb_strtolower(trim((string) $value));
}

function ultra_is_seed_row(array $row): bool
{
    return $row['id_pembelian'] === null
        && $row['id_penjualan'] === null
        && intval($row['stok_masuk']) === 0
        && intval($row['stok_keluar']) === 0
        && str_contains(ultra_normalize_text((string) ($row['keterangan'] ?? '')), 'saldo awal stok');
}

function ultra_row_type(array $row): string
{
    if ($row['id_pembelian'] !== null) {
        return 'pembelian';
    }

    if ($row['id_penjualan'] !== null) {
        return 'penjualan';
    }

    if (ultra_is_seed_row($row)) {
        return 'seed';
    }

    return 'manual';
}

function ultra_build_recommendation(array $productAnalysis): string
{
    if (($productAnalysis['root_trigger_type'] ?? '') === 'manual') {
        return 'Review manual adjustment source and operator intent. Defisit lahir dari edit stok/manual opname, jadi perbaikan harus lewat koreksi opname terkontrol, bukan sekadar reflow.';
    }

    if (($productAnalysis['purchase_after_negative_count'] ?? 0) > 0) {
        return 'Ada pembelian setelah mulai minus, tetapi tidak cukup menutup gap atau tidak ada sama sekali pada kasus ini. Validasi invoice pembelian dan waktu fisik barang datang sebelum mengubah timestamp.';
    }

    if (($productAnalysis['seed_stock'] ?? 0) <= 0) {
        return 'Produk mulai post-cutoff dari seed nol lalu dijual. Verifikasi baseline/seed dan cek apakah ada stok fisik awal atau pembelian yang belum tercatat.';
    }

    return 'Tidak ada recovery tercatat setelah minus. Perlu audit sumber transaksi penjualan penyebab dan, bila stok fisik memang ada selisih, lakukan stock opname korektif dengan alasan yang jelas.';
}

function ultra_push_count(array &$bucket, string $key): void
{
    $bucket[$key] = intval($bucket[$key] ?? 0) + 1;
}

function ultra_window(array $timeline, int $centerIndex, int $before = 3, int $after = 4): array
{
    return array_slice($timeline, max(0, $centerIndex - $before), $before + $after + 1);
}

$forensicsReportPath = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--report=') === 0) {
        $forensicsReportPath = substr($arg, 9);
    }
}

if (!$forensicsReportPath) {
    $candidates = glob(storage_path('app/post_cutoff_negative_stock_forensics_*.json')) ?: [];
    rsort($candidates);
    $forensicsReportPath = $candidates[0] ?? null;
}

if (!$forensicsReportPath) {
    fwrite(STDERR, "Tidak ada report forensik negatif yang ditemukan.\n");
    exit(1);
}

$resolvedReportPath = $forensicsReportPath;
if (!preg_match('/^[A-Za-z]:\\\\|^\\\\|^\//', $resolvedReportPath)) {
    $resolvedReportPath = base_path($forensicsReportPath);
}

if (!is_file($resolvedReportPath) || !is_readable($resolvedReportPath)) {
    fwrite(STDERR, "Report forensik tidak dapat dibaca: {$resolvedReportPath}\n");
    exit(1);
}

$forensicsReport = json_decode(file_get_contents($resolvedReportPath), true);
if (!is_array($forensicsReport)) {
    fwrite(STDERR, "Report forensik bukan JSON valid.\n");
    exit(1);
}

$persistentProducts = array_values(array_filter($forensicsReport['products'] ?? [], function ($product) {
    return intval($product['final_raw_stock'] ?? 0) < 0;
}));

if (empty($persistentProducts)) {
    echo json_encode([
        'source_report' => $resolvedReportPath,
        'message' => 'Tidak ada produk persistent negative pada report ini.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$productIds = array_values(array_unique(array_map(function ($product) {
    return intval($product['id_produk']);
}, $persistentProducts)));

$cutoff = Carbon::parse((string) ($forensicsReport['cutoff'] ?? config('stock.cutoff_datetime', '2025-12-31 23:59:59')))->format('Y-m-d H:i:s');
$auditedUntil = Carbon::parse((string) ($forensicsReport['audited_until'] ?? Carbon::now()->format('Y-m-d H:i:s')))->format('Y-m-d H:i:s');

$stockOrderRaw = "CASE
    WHEN id_pembelian IS NOT NULL THEN 0
    WHEN id_penjualan IS NOT NULL THEN 1
    WHEN LOWER(COALESCE(keterangan, '')) LIKE '%stock opname%' THEN 2
    WHEN LOWER(COALESCE(keterangan, '')) LIKE '%perubahan stok manual%' THEN 2
    WHEN LOWER(COALESCE(keterangan, '')) LIKE '%penyesuaian stok%' THEN 2
    WHEN LOWER(COALESCE(keterangan, '')) LIKE '%saldo awal stok%' THEN 2
    ELSE 3
END ASC";

$rows = DB::table('rekaman_stoks')
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
$purchaseIds = [];
$saleIds = [];

foreach ($rows as $row) {
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
        $purchaseIds[$normalized['id_pembelian']] = true;
    }
    if ($normalized['id_penjualan'] !== null) {
        $saleIds[$normalized['id_penjualan']] = true;
    }
}

$purchaseHeaders = [];
if (!empty($purchaseIds)) {
    $purchaseRows = DB::table('pembelian as p')
        ->leftJoin('supplier as s', 's.id_supplier', '=', 'p.id_supplier')
        ->whereIn('p.id_pembelian', array_keys($purchaseIds))
        ->select('p.id_pembelian', 'p.no_faktur', 'p.waktu', 'p.waktu_datang', 'p.created_at', 'p.total_harga', 'p.bayar', 's.nama as supplier_nama')
        ->get();

    foreach ($purchaseRows as $row) {
        $purchaseHeaders[intval($row->id_pembelian)] = [
            'id_pembelian' => intval($row->id_pembelian),
            'no_faktur' => (string) ($row->no_faktur ?? ''),
            'waktu_efektif' => Carbon::parse($row->waktu_datang ?? $row->waktu ?? $row->created_at)->format('Y-m-d H:i:s'),
            'total_harga' => intval($row->total_harga ?? 0),
            'bayar' => intval($row->bayar ?? 0),
            'supplier_nama' => (string) ($row->supplier_nama ?? ''),
        ];
    }
}

$purchaseDetails = [];
if (!empty($purchaseIds)) {
    $purchaseDetailRows = DB::table('pembelian_detail')
        ->whereIn('id_pembelian', array_keys($purchaseIds))
        ->whereIn('id_produk', $productIds)
        ->orderBy('id_pembelian_detail', 'asc')
        ->get();

    foreach ($purchaseDetailRows as $row) {
        $key = intval($row->id_pembelian) . ':' . intval($row->id_produk);
        if (!isset($purchaseDetails[$key])) {
            $purchaseDetails[$key] = [
                'total_qty' => 0,
                'row_count' => 0,
                'rows' => [],
            ];
        }

        $purchaseDetails[$key]['total_qty'] += intval($row->jumlah ?? 0);
        $purchaseDetails[$key]['row_count']++;
        $purchaseDetails[$key]['rows'][] = [
            'id_pembelian_detail' => intval($row->id_pembelian_detail),
            'jumlah' => intval($row->jumlah ?? 0),
            'harga_beli' => intval($row->harga_beli ?? 0),
            'subtotal' => intval($row->subtotal ?? 0),
        ];
    }
}

$saleHeaders = [];
if (!empty($saleIds)) {
    $saleRows = DB::table('penjualan')
        ->whereIn('id_penjualan', array_keys($saleIds))
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

$saleDetails = [];
if (!empty($saleIds)) {
    $saleDetailRows = DB::table('penjualan_detail')
        ->whereIn('id_penjualan', array_keys($saleIds))
        ->whereIn('id_produk', $productIds)
        ->orderBy('id_penjualan_detail', 'asc')
        ->get();

    foreach ($saleDetailRows as $row) {
        $key = intval($row->id_penjualan) . ':' . intval($row->id_produk);
        if (!isset($saleDetails[$key])) {
            $saleDetails[$key] = [
                'total_qty' => 0,
                'row_count' => 0,
                'rows' => [],
            ];
        }

        $saleDetails[$key]['total_qty'] += intval($row->jumlah ?? 0);
        $saleDetails[$key]['row_count']++;
        $saleDetails[$key]['rows'][] = [
            'id_penjualan_detail' => intval($row->id_penjualan_detail),
            'jumlah' => intval($row->jumlah ?? 0),
            'harga_jual' => intval($row->harga_jual ?? 0),
            'subtotal' => intval($row->subtotal ?? 0),
        ];
    }
}

$ultraReport = [
    'generated_at' => Carbon::now()->format('Y-m-d H:i:s'),
    'source_forensics_report' => $resolvedReportPath,
    'cutoff' => $cutoff,
    'audited_until' => $auditedUntil,
    'summary' => [
        'products_analyzed' => 0,
        'total_negative_events' => 0,
        'manual_root_trigger_products' => 0,
        'sale_root_trigger_products' => 0,
        'products_with_any_purchase_after_negative' => 0,
        'products_with_no_purchase_after_negative' => 0,
    ],
    'root_trigger_counts' => [],
    'products' => [],
];

foreach ($persistentProducts as $baseProduct) {
    $productId = intval($baseProduct['id_produk']);
    $rowsForProduct = $rowsByProduct[$productId] ?? [];
    if (empty($rowsForProduct)) {
        continue;
    }

    $ultraReport['summary']['products_analyzed']++;
    $ultraReport['summary']['total_negative_events'] += intval($baseProduct['negative_event_count'] ?? 0);

    $seedRow = null;
    $startIndex = 0;
    if (isset($rowsForProduct[0]) && ultra_is_seed_row($rowsForProduct[0])) {
        $seedRow = $rowsForProduct[0];
        $startIndex = 1;
    }

    $timeline = [];
    $runningBefore = $seedRow ? intval($seedRow['stok_sisa']) : intval($rowsForProduct[0]['stok_awal']);
    $firstNegativeIndex = null;
    $firstNegativeEvent = null;
    $lastZeroOrPositiveEvent = null;
    $transactionsAfterNegative = [];
    $purchaseAfterNegative = [];
    $saleAfterNegative = [];
    $manualAfterNegative = [];

    foreach (array_slice($rowsForProduct, $startIndex) as $row) {
        $type = ultra_row_type($row);
        $runningAfter = $runningBefore + intval($row['stok_masuk']) - intval($row['stok_keluar']);

        $entry = [
            'id_rekaman_stok' => intval($row['id_rekaman_stok']),
            'type' => $type,
            'waktu' => $row['waktu'],
            'stok_awal' => intval($row['stok_awal']),
            'stok_masuk' => intval($row['stok_masuk']),
            'stok_keluar' => intval($row['stok_keluar']),
            'stok_sisa' => intval($row['stok_sisa']),
            'running_before' => $runningBefore,
            'running_after' => $runningAfter,
            'keterangan' => $row['keterangan'],
            'id_pembelian' => $row['id_pembelian'],
            'id_penjualan' => $row['id_penjualan'],
        ];

        if ($type === 'pembelian' && $row['id_pembelian'] !== null) {
            $key = intval($row['id_pembelian']) . ':' . $productId;
            $entry['source'] = $purchaseHeaders[intval($row['id_pembelian'])] ?? null;
            $entry['detail'] = $purchaseDetails[$key] ?? null;
        }

        if ($type === 'penjualan' && $row['id_penjualan'] !== null) {
            $key = intval($row['id_penjualan']) . ':' . $productId;
            $entry['source'] = $saleHeaders[intval($row['id_penjualan'])] ?? null;
            $entry['detail'] = $saleDetails[$key] ?? null;
        }

        $timeline[] = $entry;

        if ($runningAfter >= 0) {
            $lastZeroOrPositiveEvent = $entry;
        }

        if ($runningAfter < 0 && $firstNegativeIndex === null) {
            $firstNegativeIndex = count($timeline) - 1;
            $firstNegativeEvent = $entry;
        }

        if ($firstNegativeIndex !== null) {
            $transactionsAfterNegative[] = $entry;

            if ($type === 'pembelian') {
                $purchaseAfterNegative[] = $entry;
            } elseif ($type === 'penjualan') {
                $saleAfterNegative[] = $entry;
            } elseif ($type === 'manual') {
                $manualAfterNegative[] = $entry;
            }
        }

        $runningBefore = $runningAfter;
    }

    $zeroDepletionEvent = null;
    if ($firstNegativeIndex !== null) {
        for ($index = $firstNegativeIndex - 1; $index >= 0; $index--) {
            if (intval($timeline[$index]['running_after']) === 0) {
                $zeroDepletionEvent = $timeline[$index];
                break;
            }
        }
    }

    $rootTriggerType = (string) ($firstNegativeEvent['type'] ?? 'unknown');
    $rootTriggerCode = $rootTriggerType === 'manual' ? 'manual_adjustment' : 'sale_event';
    ultra_push_count($ultraReport['root_trigger_counts'], $rootTriggerCode);

    if ($rootTriggerType === 'manual') {
        $ultraReport['summary']['manual_root_trigger_products']++;
    } else {
        $ultraReport['summary']['sale_root_trigger_products']++;
    }

    if (!empty($purchaseAfterNegative)) {
        $ultraReport['summary']['products_with_any_purchase_after_negative']++;
    } else {
        $ultraReport['summary']['products_with_no_purchase_after_negative']++;
    }

    $deepeningEvents = array_values(array_filter($transactionsAfterNegative, function ($entry) {
        return intval($entry['running_after']) < intval($entry['running_before']);
    }));

    $negativeSalesOnly = array_values(array_filter($saleAfterNegative, function ($entry) {
        return intval($entry['running_after']) < intval($entry['running_before']);
    }));

    $ultraReport['products'][] = [
        'id_produk' => $productId,
        'nama_produk' => (string) ($baseProduct['nama_produk'] ?? ''),
        'seed_stock' => intval($baseProduct['seed_stock'] ?? 0),
        'seed_keterangan' => $baseProduct['seed_keterangan'] ?? null,
        'final_raw_stock' => intval($baseProduct['final_raw_stock'] ?? 0),
        'produk_stok_clamped' => intval($baseProduct['produk_stok_clamped'] ?? 0),
        'root_trigger_type' => $rootTriggerType,
        'root_trigger_code' => $rootTriggerCode,
        'root_trigger_event' => $firstNegativeEvent,
        'last_zero_or_positive_event_before_negative' => $lastZeroOrPositiveEvent,
        'event_that_depleted_stock_to_zero' => $zeroDepletionEvent,
        'purchase_after_negative_count' => count($purchaseAfterNegative),
        'sale_after_negative_count' => count($saleAfterNegative),
        'manual_after_negative_count' => count($manualAfterNegative),
        'negative_sales_only' => $negativeSalesOnly,
        'deepening_events_after_negative' => $deepeningEvents,
        'timeline_window_around_first_negative' => $firstNegativeIndex !== null ? ultra_window($timeline, $firstNegativeIndex, 4, 5) : [],
        'full_negative_segment' => $transactionsAfterNegative,
        'recommendation' => ultra_build_recommendation([
            'root_trigger_type' => $rootTriggerType,
            'purchase_after_negative_count' => count($purchaseAfterNegative),
            'seed_stock' => intval($baseProduct['seed_stock'] ?? 0),
        ]),
        'base_forensics_summary' => [
            'primary_cause' => $baseProduct['primary_cause'] ?? null,
            'normality_assessment' => $baseProduct['normality_assessment'] ?? null,
            'resolution_bucket' => $baseProduct['resolution_bucket'] ?? null,
        ],
    ];
}

$outputPath = storage_path('app/persistent_negative_products_ultra_detail_' . Carbon::now()->format('Ymd_His') . '.json');
file_put_contents($outputPath, json_encode($ultraReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'ultra_report_path' => $outputPath,
    'summary' => $ultraReport['summary'],
    'root_trigger_counts' => $ultraReport['root_trigger_counts'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;