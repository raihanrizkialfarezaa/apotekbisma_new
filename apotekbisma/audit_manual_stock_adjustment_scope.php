<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\BaselineStockReflowService;
use Illuminate\Support\Facades\DB;

$cutoff = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');
$batchSize = 200;
$sampleLimit = 25;

function writeLine(string $text = ''): void
{
    echo $text . PHP_EOL;
}

function formatSampleList(array $rows, int $sampleLimit): array
{
    $sample = array_slice($rows, 0, $sampleLimit);

    return array_map(function (array $row) {
        return sprintf(
            '#%d | %s | refs=%s',
            intval($row['id_produk'] ?? 0),
            (string) ($row['nama_produk'] ?? ''),
            (string) ($row['refs'] ?? '-')
        );
    }, $sample);
}

$products = DB::table('produk')
    ->orderBy('id_produk')
    ->get(['id_produk', 'nama_produk']);

$productLabels = [];
$allProductIds = [];

foreach ($products as $product) {
    $productId = intval($product->id_produk ?? 0);
    if ($productId <= 0) {
        continue;
    }

    $allProductIds[] = $productId;
    $productLabels[$productId] = (string) ($product->nama_produk ?? '');
}

$historicalNegativeIds = [];
$reflowService = app(BaselineStockReflowService::class);

foreach (array_chunk($allProductIds, $batchSize) as $chunk) {
    $summary = $reflowService->previewRebuildSummary($chunk);
    foreach (($summary['negative_event_product_ids'] ?? []) as $productId) {
        $productId = intval($productId);
        if ($productId > 0) {
            $historicalNegativeIds[$productId] = true;
        }
    }
}

$penjualanDraftRows = DB::table('penjualan_detail as pd')
    ->join('penjualan as p', 'pd.id_penjualan', '=', 'p.id_penjualan')
    ->join('produk as pr', 'pd.id_produk', '=', 'pr.id_produk')
    ->where('pd.jumlah', '>', 0)
    ->whereRaw('COALESCE(p.waktu, p.created_at) > ?', [$cutoff])
    ->where(function ($query) {
        $query->where('p.total_item', '<=', 0)
            ->orWhere('p.total_harga', '<=', 0)
            ->orWhere('p.bayar', '<=', 0)
            ->orWhere('p.diterima', '<=', 0);
    })
    ->groupBy('pd.id_produk', 'pr.nama_produk')
    ->selectRaw('pd.id_produk, pr.nama_produk, GROUP_CONCAT(DISTINCT p.id_penjualan ORDER BY p.id_penjualan SEPARATOR ",") as refs')
    ->get();

$pembelianDraftRows = DB::table('pembelian_detail as pd')
    ->join('pembelian as p', 'pd.id_pembelian', '=', 'p.id_pembelian')
    ->join('produk as pr', 'pd.id_produk', '=', 'pr.id_produk')
    ->where('pd.jumlah', '>', 0)
    ->whereRaw('COALESCE(p.waktu_datang, p.waktu, p.created_at) > ?', [$cutoff])
    ->where(function ($query) {
        $query->where('p.no_faktur', 'o')
            ->orWhere('p.no_faktur', '')
            ->orWhereNull('p.no_faktur')
            ->orWhere('p.total_harga', '<=', 0)
            ->orWhere('p.bayar', '<=', 0);
    })
    ->groupBy('pd.id_produk', 'pr.nama_produk')
    ->selectRaw('pd.id_produk, pr.nama_produk, GROUP_CONCAT(DISTINCT p.id_pembelian ORDER BY p.id_pembelian SEPARATOR ",") as refs')
    ->get();

$draftBlockedProducts = [];

foreach ($penjualanDraftRows as $row) {
    $productId = intval($row->id_produk ?? 0);
    if ($productId <= 0) {
        continue;
    }

    $draftBlockedProducts[$productId] = [
        'id_produk' => $productId,
        'nama_produk' => (string) ($row->nama_produk ?? ($productLabels[$productId] ?? '')),
        'refs' => 'penjualan:' . (string) ($row->refs ?? ''),
    ];
}

foreach ($pembelianDraftRows as $row) {
    $productId = intval($row->id_produk ?? 0);
    if ($productId <= 0) {
        continue;
    }

    if (!isset($draftBlockedProducts[$productId])) {
        $draftBlockedProducts[$productId] = [
            'id_produk' => $productId,
            'nama_produk' => (string) ($row->nama_produk ?? ($productLabels[$productId] ?? '')),
            'refs' => 'pembelian:' . (string) ($row->refs ?? ''),
        ];
        continue;
    }

    $draftBlockedProducts[$productId]['refs'] .= ' | pembelian:' . (string) ($row->refs ?? '');
}

$historicalOnlyProducts = [];
foreach (array_keys($historicalNegativeIds) as $productId) {
    if (isset($draftBlockedProducts[$productId])) {
        continue;
    }

    $historicalOnlyProducts[] = [
        'id_produk' => $productId,
        'nama_produk' => (string) ($productLabels[$productId] ?? ''),
        'refs' => 'minus_historis',
    ];
}

usort($historicalOnlyProducts, function (array $left, array $right) {
    return [$left['nama_produk'], $left['id_produk']] <=> [$right['nama_produk'], $right['id_produk']];
});

$legacyBlockedCount = count($historicalNegativeIds + array_fill_keys(array_keys($draftBlockedProducts), true));
$currentBlockedCount = count($draftBlockedProducts);

writeLine('Database: ' . DB::connection()->getDatabaseName());
writeLine('Cutoff: ' . $cutoff);
writeLine('Total produk: ' . count($allProductIds));
writeLine('Produk dengan minus historis: ' . count($historicalNegativeIds));
writeLine('Produk dengan draft post-cutoff aktif: ' . $currentBlockedCount);
writeLine('Produk yang diblokir rule lama manual stock: ' . $legacyBlockedCount);
writeLine('Produk yang diblokir rule sekarang: ' . $currentBlockedCount);
writeLine('Produk yang dulu terblokir hanya karena minus historis dan sekarang bebas: ' . count($historicalOnlyProducts));

writeLine();
writeLine('=== Sample Historis-Only Now Allowed ===');
foreach (formatSampleList($historicalOnlyProducts, $sampleLimit) as $line) {
    writeLine($line);
}

writeLine();
writeLine('=== Sample Still Blocked By Real Drafts ===');
foreach (formatSampleList(array_values($draftBlockedProducts), $sampleLimit) as $line) {
    writeLine($line);
}