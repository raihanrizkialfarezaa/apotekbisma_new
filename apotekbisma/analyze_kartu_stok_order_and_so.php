<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\KartuStokController;
use App\Services\BaselineStockReflowService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$productId = 862;
$cutoff = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');
$excludedPatterns = array_map(static function ($pattern): string {
    return mb_strtolower(trim((string) $pattern));
}, config('stock.excluded_manual_keterangan_patterns', []));

function section(string $title): void
{
    echo "\n=== {$title} ===\n";
}

function plain(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? '');
}

$product = DB::table('produk')
    ->where('id_produk', $productId)
    ->first(['id_produk', 'nama_produk', 'stok']);

if (!$product) {
    echo "Produk {$productId} tidak ditemukan.\n";
    exit(1);
}

echo "Inspecting stock-card order and SO inclusion for #{$productId} ({$product->nama_produk})\n";
echo 'Database: ' . DB::connection()->getDatabaseName() . "\n";
echo 'Cutoff: ' . $cutoff . "\n";

$controller = app(KartuStokController::class);
$allRows = $controller->getDataFiltered($productId, Request::create('/debug', 'GET', ['date_filter' => 'all']));

section('Final Kartu Stok Dataset');
echo 'Total rows: ' . count($allRows) . "\n";
echo "First 8 rows:\n";
foreach (array_slice($allRows, 0, 8) as $index => $row) {
    echo sprintf(
        "  %d. %s | id=%d | %s\n",
        $index + 1,
        (string) ($row['waktu_raw'] ?? '-'),
        (int) ($row['id'] ?? 0),
        plain((string) ($row['keterangan'] ?? '-'))
    );
}

echo "Last 8 rows:\n";
$tail = array_slice($allRows, -8);
foreach ($tail as $index => $row) {
    echo sprintf(
        "  %d. %s | id=%d | %s\n",
        count($allRows) - count($tail) + $index + 1,
        (string) ($row['waktu_raw'] ?? '-'),
        (int) ($row['id'] ?? 0),
        plain((string) ($row['keterangan'] ?? '-'))
    );
}

$manualRows = array_values(array_filter($allRows, static function (array $row): bool {
    $keterangan = plain((string) ($row['keterangan'] ?? ''));
    return stripos($keterangan, 'Perubahan Stok Manual') !== false
        || stripos($keterangan, 'Stock Opname') !== false
        || stripos($keterangan, 'Penyesuaian Stok') !== false;
}));

section('Manual SO Rows In Final Dataset');
if ($manualRows === []) {
    echo "No manual SO row detected in final kartu stok dataset.\n";
} else {
    foreach ($manualRows as $row) {
        echo sprintf(
            "  waktu=%s | id=%d | masuk=%s | keluar=%s | sisa=%s | %s\n",
            (string) ($row['waktu_raw'] ?? '-'),
            (int) ($row['id'] ?? 0),
            plain((string) ($row['stok_masuk'] ?? '-')),
            plain((string) ($row['stok_keluar'] ?? '-')),
            plain((string) ($row['stok_sisa'] ?? '-')),
            plain((string) ($row['keterangan'] ?? '-'))
        );
    }
}

$duplicateBuckets = [];
foreach ($allRows as $row) {
    $signature = implode('|', [
        (string) ($row['waktu_raw'] ?? ''),
        plain((string) ($row['stok_masuk'] ?? '')),
        plain((string) ($row['stok_keluar'] ?? '')),
        plain((string) ($row['stok_sisa'] ?? '')),
        plain((string) ($row['keterangan'] ?? '')),
    ]);
    $duplicateBuckets[$signature][] = $row;
}

section('Potential Duplicate Display Rows');
$hasDuplicates = false;
foreach ($duplicateBuckets as $signature => $rows) {
    if (count($rows) < 2) {
        continue;
    }

    $hasDuplicates = true;
    echo 'count=' . count($rows) . ' | signature=' . $signature . "\n";
}

if (!$hasDuplicates) {
    echo "No duplicate display rows detected in final kartu stok dataset.\n";
}

$rawManualRecords = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->whereNull('id_penjualan')
    ->whereNull('id_pembelian')
    ->where('waktu', '>', $cutoff)
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get(['id_rekaman_stok', 'waktu', 'stok_awal', 'stok_masuk', 'stok_keluar', 'stok_sisa', 'keterangan']);

section('Raw Post-cutoff Manual Rekaman');
if ($rawManualRecords->isEmpty()) {
    echo "No raw manual rekaman found after cutoff.\n";
} else {
    foreach ($rawManualRecords as $record) {
        $normalizedKeterangan = mb_strtolower(trim((string) ($record->keterangan ?? '')));
        $matchedPatterns = array_values(array_filter($excludedPatterns, static function (string $pattern) use ($normalizedKeterangan): bool {
            return $pattern !== '' && str_contains($normalizedKeterangan, $pattern);
        }));
        echo sprintf(
            "  id=%d | waktu=%s | awal=%d | +%d | -%d | sisa=%d | excluded=%s | %s\n",
            (int) $record->id_rekaman_stok,
            (string) $record->waktu,
            (int) $record->stok_awal,
            (int) $record->stok_masuk,
            (int) $record->stok_keluar,
            (int) $record->stok_sisa,
            $matchedPatterns === [] ? 'NO' : ('YES [' . implode(', ', $matchedPatterns) . ']'),
            (string) ($record->keterangan ?? '-')
        );
    }
}

$ledger = app(BaselineStockReflowService::class)
    ->previewProductLedgers([$productId], Carbon::now()->format('Y-m-d H:i:s'))[$productId] ?? null;

section('Ledger Rows Around Cutoff');
if ($ledger === null) {
    echo "Ledger unavailable.\n";
} else {
    $rows = array_values(array_filter($ledger['rows'] ?? [], static function (array $row): bool {
        return (string) ($row['waktu'] ?? '') <= '2026-01-15 23:59:59';
    }));

    foreach ($rows as $row) {
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
