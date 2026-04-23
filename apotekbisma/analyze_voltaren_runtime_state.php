<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\BaselineStockReflowService;
use App\Services\StockRuntimeIntegrityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$productId = 862;
$productName = 'VOLTAREN 50mg';
$now = Carbon::now()->format('Y-m-d H:i:s');
$cutoff = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');
$baselineConfigPath = (string) config('stock.baseline_csv', '');
$baselineFullPath = base_path($baselineConfigPath);

function printSection(string $title): void
{
    echo "\n=== {$title} ===\n";
}

function formatBool(bool $value): string
{
    return $value ? 'YES' : 'NO';
}

function readCsvBaselineRow(string $path, int $targetId): ?array
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $sample = @file_get_contents($path, false, null, 0, 2048);
    if ($sample === false) {
        return null;
    }

    $sample = preg_replace('/^\xEF\xBB\xBF/', '', $sample);
    $firstLine = strtok($sample, "\r\n") ?: '';
    $scores = [
        ';' => substr_count($firstLine, ';'),
        ',' => substr_count($firstLine, ','),
        "\t" => substr_count($firstLine, "\t"),
    ];
    arsort($scores);
    $delimiter = (string) array_key_first($scores);
    if ($delimiter === '') {
        $delimiter = ',';
    }

    $handle = fopen($path, 'r');
    if ($handle === false) {
        return null;
    }

    fgetcsv($handle, 0, $delimiter);

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count($row) < 3) {
            continue;
        }

        $id = (int) trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]));
        if ($id !== $targetId) {
            continue;
        }

        fclose($handle);

        return [
            'delimiter' => $delimiter,
            'id_produk' => $id,
            'nama_produk' => trim((string) $row[1]),
            'stok' => is_numeric(trim((string) $row[2])) ? (int) trim((string) $row[2]) : null,
        ];
    }

    fclose($handle);

    return [
        'delimiter' => $delimiter,
        'id_produk' => $targetId,
        'nama_produk' => null,
        'stok' => null,
    ];
}

echo "Inspecting product #{$productId} ({$productName}) at {$now}\n";

printSection('Environment');
echo 'APP_ENV: ' . app()->environment() . "\n";
echo 'Database: ' . DB::connection()->getDatabaseName() . "\n";
echo 'Cutoff: ' . $cutoff . "\n";
echo 'Baseline config: ' . $baselineConfigPath . "\n";
echo 'Baseline full path: ' . $baselineFullPath . "\n";
echo 'Baseline readable: ' . formatBool(is_file($baselineFullPath) && is_readable($baselineFullPath)) . "\n";
if (is_file($baselineFullPath) && is_readable($baselineFullPath)) {
    echo 'Baseline SHA1: ' . sha1_file($baselineFullPath) . "\n";
}

$baselineRow = readCsvBaselineRow($baselineFullPath, $productId);
echo 'Baseline row found: ' . formatBool($baselineRow !== null && $baselineRow['stok'] !== null) . "\n";
if ($baselineRow !== null) {
    echo 'Baseline delimiter: ' . $baselineRow['delimiter'] . "\n";
    echo 'Baseline name: ' . (($baselineRow['nama_produk'] ?? '') === '' ? '-' : (string) $baselineRow['nama_produk']) . "\n";
    echo 'Baseline stock: ' . ($baselineRow['stok'] === null ? 'NULL' : (string) $baselineRow['stok']) . "\n";
}

$product = DB::table('produk')
    ->where('id_produk', $productId)
    ->first([
        'id_produk',
        'nama_produk',
        'stok',
        'batch',
        'expired_date',
        'updated_at',
    ]);

printSection('Produk Table');
if (!$product) {
    echo "Product not found in produk table.\n";
    exit(1);
}

echo 'Nama: ' . (string) $product->nama_produk . "\n";
echo 'stok column: ' . (int) $product->stok . "\n";
echo 'produk list overlay candidate (stok <= 20): ' . formatBool((int) $product->stok <= 20) . "\n";
echo 'batch: ' . (string) ($product->batch ?? '-') . "\n";
echo 'expired_date: ' . (string) ($product->expired_date ?? '-') . "\n";
echo 'updated_at: ' . (string) ($product->updated_at ?? '-') . "\n";

$runtimeService = app(StockRuntimeIntegrityService::class);
$runtimeMap = $runtimeService->previewCurrentSellableStockMap([$productId], null, true);
$runtime = $runtimeMap[$productId] ?? null;

printSection('Runtime Overlay');
if ($runtime === null) {
    echo "Runtime stock map returned no row.\n";
} else {
    echo 'committed_stock: ' . (int) ($runtime['committed_stock'] ?? 0) . "\n";
    echo 'draft_pembelian_qty: ' . (int) ($runtime['draft_pembelian_qty'] ?? 0) . "\n";
    echo 'draft_penjualan_qty: ' . (int) ($runtime['draft_penjualan_qty'] ?? 0) . "\n";
    echo 'raw_current_stock: ' . (int) ($runtime['raw_current_stock'] ?? 0) . "\n";
    echo 'display_stock: ' . (int) ($runtime['display_stock'] ?? 0) . "\n";
    echo 'has_negative_projection: ' . formatBool((bool) ($runtime['has_negative_projection'] ?? false)) . "\n";
}

$reflowService = app(BaselineStockReflowService::class);
$ledger = $reflowService->previewProductLedgers([$productId], $now)[$productId] ?? null;

printSection('Baseline Reflow Ledger');
if ($ledger === null) {
    echo "Ledger not available.\n";
} else {
    echo 'seed_source: ' . (string) ($ledger['seed_source'] ?? '-') . "\n";
    echo 'final_stock: ' . (int) ($ledger['final_stock'] ?? 0) . "\n";
    echo 'applied_stock: ' . (int) ($ledger['applied_stock'] ?? 0) . "\n";
    echo 'negative_event_count: ' . (int) ($ledger['negative_event_count'] ?? 0) . "\n";

    $rows = collect($ledger['rows'] ?? []);
    echo 'ledger_rows: ' . $rows->count() . "\n";

    $seedRow = $rows->first();
    if ($seedRow !== null) {
        echo 'seed_row: ' . json_encode($seedRow, JSON_UNESCAPED_UNICODE) . "\n";
    }

    $lastRows = $rows->take(-8)->values();
    echo "last_rows:\n";
    foreach ($lastRows as $row) {
        echo sprintf(
            "  %s | jual=%s | beli=%s | awal=%d | +%d | -%d | sisa=%d | %s\n",
            (string) ($row['waktu'] ?? '-'),
            $row['id_penjualan'] === null ? '-' : (string) $row['id_penjualan'],
            $row['id_pembelian'] === null ? '-' : (string) $row['id_pembelian'],
            (int) ($row['stok_awal'] ?? 0),
            (int) ($row['stok_masuk'] ?? 0),
            (int) ($row['stok_keluar'] ?? 0),
            (int) ($row['stok_sisa'] ?? 0),
            (string) ($row['keterangan'] ?? '-')
        );
    }
}

$dbSeedCandidates = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->whereNull('id_penjualan')
    ->whereNull('id_pembelian')
    ->where(function ($query) {
        $query->where('keterangan', 'like', '%Saldo Awal Stok%')
            ->orWhere('keterangan', 'like', '%histori sebelum cutoff%');
    })
    ->where('waktu', '>=', Carbon::parse($cutoff)->copy()->startOfDay()->format('Y-m-d H:i:s'))
    ->where('waktu', '<=', Carbon::parse($cutoff)->copy()->addDay()->endOfDay()->format('Y-m-d H:i:s'))
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get([
        'id_rekaman_stok',
        'waktu',
        'stok_awal',
        'stok_masuk',
        'stok_keluar',
        'stok_sisa',
        'keterangan',
    ]);

printSection('Database Seed Candidates Around Cutoff');
echo 'count: ' . $dbSeedCandidates->count() . "\n";
foreach ($dbSeedCandidates as $row) {
    echo sprintf(
        "  id=%d | waktu=%s | awal=%d | +%d | -%d | sisa=%d | %s\n",
        (int) $row->id_rekaman_stok,
        (string) $row->waktu,
        (int) $row->stok_awal,
        (int) $row->stok_masuk,
        (int) $row->stok_keluar,
        (int) $row->stok_sisa,
        (string) ($row->keterangan ?? '-')
    );
}

$manualRecords = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->whereNull('id_penjualan')
    ->whereNull('id_pembelian')
    ->where('waktu', '>', $cutoff)
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get([
        'id_rekaman_stok',
        'waktu',
        'stok_awal',
        'stok_masuk',
        'stok_keluar',
        'stok_sisa',
        'keterangan',
    ]);

printSection('Post-cutoff Manual Rekaman');
echo 'count: ' . $manualRecords->count() . "\n";
foreach ($manualRecords as $row) {
    echo sprintf(
        "  id=%d | waktu=%s | awal=%d | +%d | -%d | sisa=%d | %s\n",
        (int) $row->id_rekaman_stok,
        (string) $row->waktu,
        (int) $row->stok_awal,
        (int) $row->stok_masuk,
        (int) $row->stok_keluar,
        (int) $row->stok_sisa,
        (string) ($row->keterangan ?? '-')
    );
}

$purchaseTotals = DB::table('pembelian_detail as pd')
    ->join('pembelian as p', 'pd.id_pembelian', '=', 'p.id_pembelian')
    ->where('pd.id_produk', $productId)
    ->where('pd.jumlah', '>', 0)
    ->whereRaw('COALESCE(p.waktu_datang, p.waktu, p.created_at) > ?', [$cutoff])
    ->whereRaw('COALESCE(p.waktu_datang, p.waktu, p.created_at) <= ?', [$now])
    ->whereNotNull('p.no_faktur')
    ->whereRaw("TRIM(p.no_faktur) != ''")
    ->whereRaw("LOWER(TRIM(p.no_faktur)) != 'o'")
    ->where('p.total_harga', '>', 0)
    ->where('p.bayar', '>', 0)
    ->selectRaw('COUNT(DISTINCT p.id_pembelian) as transaksi, COALESCE(SUM(pd.jumlah), 0) as qty')
    ->first();

$salesTotals = DB::table('penjualan_detail as pd')
    ->join('penjualan as p', 'pd.id_penjualan', '=', 'p.id_penjualan')
    ->where('pd.id_produk', $productId)
    ->where('pd.jumlah', '>', 0)
    ->whereRaw('COALESCE(p.waktu, p.created_at) > ?', [$cutoff])
    ->whereRaw('COALESCE(p.waktu, p.created_at) <= ?', [$now])
    ->where('p.total_item', '>', 0)
    ->where('p.total_harga', '>', 0)
    ->where('p.bayar', '>', 0)
    ->where('p.diterima', '>', 0)
    ->selectRaw('COUNT(DISTINCT p.id_penjualan) as transaksi, COALESCE(SUM(pd.jumlah), 0) as qty')
    ->first();

$recentSales = DB::table('penjualan_detail as pd')
    ->join('penjualan as p', 'pd.id_penjualan', '=', 'p.id_penjualan')
    ->where('pd.id_produk', $productId)
    ->where('pd.jumlah', '>', 0)
    ->whereRaw('COALESCE(p.waktu, p.created_at) > ?', [$cutoff])
    ->whereRaw('COALESCE(p.waktu, p.created_at) <= ?', [$now])
    ->where('p.total_item', '>', 0)
    ->where('p.total_harga', '>', 0)
    ->where('p.bayar', '>', 0)
    ->where('p.diterima', '>', 0)
    ->orderByRaw('COALESCE(p.waktu, p.created_at) desc')
    ->orderBy('pd.id_penjualan_detail', 'desc')
    ->limit(8)
    ->get([
        'p.id_penjualan',
        DB::raw('COALESCE(p.waktu, p.created_at) as waktu_event'),
        'pd.jumlah',
        'pd.id_penjualan_detail',
    ]);

printSection('Finalized Post-cutoff Transactions');
echo 'Pembelian transaksi: ' . (int) ($purchaseTotals->transaksi ?? 0) . "\n";
echo 'Pembelian qty: ' . (int) ($purchaseTotals->qty ?? 0) . "\n";
echo 'Penjualan transaksi: ' . (int) ($salesTotals->transaksi ?? 0) . "\n";
echo 'Penjualan qty: ' . (int) ($salesTotals->qty ?? 0) . "\n";

printSection('Recent Finalized Sales');
$recentSalesQty = 0;
foreach ($recentSales as $sale) {
    $recentSalesQty += (int) $sale->jumlah;
    echo sprintf(
        "  jual=%d | detail=%d | waktu=%s | qty=%d\n",
        (int) $sale->id_penjualan,
        (int) $sale->id_penjualan_detail,
        (string) $sale->waktu_event,
        (int) $sale->jumlah
    );
}

if ($runtime !== null && $recentSales->count() >= 3) {
    $lastThreeQty = (int) $recentSales->take(3)->sum('jumlah');
    echo 'Qty 3 penjualan terbaru: ' . $lastThreeQty . "\n";
    echo 'Stok sebelum 3 penjualan terbaru: ' . ((int) ($runtime['raw_current_stock'] ?? 0) + $lastThreeQty) . "\n";
}

printSection('Conclusion Hints');
echo 'If DB is identical across environments but any of baseline config, baseline path, baseline readability, or baseline SHA1 differs, runtime stock can differ too.' . "\n";
echo 'If runtime display_stock matches produk.stok here, then the current local code path is internally consistent.' . "\n";