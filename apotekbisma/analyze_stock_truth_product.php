<?php

use App\Services\BaselineStockReflowService;
use App\Services\StockRuntimeIntegrityService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$productId = intval($argv[1] ?? 0);

if ($productId <= 0) {
    fwrite(STDERR, "Usage: php analyze_stock_truth_product.php <id_produk>\n");
    exit(1);
}

$product = DB::table('produk')
    ->where('id_produk', $productId)
    ->first();

if (!$product) {
    fwrite(STDERR, "Produk #{$productId} tidak ditemukan.\n");
    exit(1);
}

$cutoff = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');
$baselinePath = base_path(config('stock.baseline_csv'));
$baselineRow = findBaselineRow($baselinePath, $productId);

$reflowSummary = app(BaselineStockReflowService::class)
    ->previewRebuildSummary([$productId], Carbon::now()->format('Y-m-d H:i:s'));

$finalRow = collect($reflowSummary['final_stock_by_product'] ?? [])->firstWhere('id_produk', $productId);

$latestRecord = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->orderBy('waktu', 'desc')
    ->orderBy('id_rekaman_stok', 'desc')
    ->first();

$latestCommittedRecord = DB::table('rekaman_stoks as rs')
    ->leftJoin('penjualan as pj', 'pj.id_penjualan', '=', 'rs.id_penjualan')
    ->leftJoin('pembelian as pb', 'pb.id_pembelian', '=', 'rs.id_pembelian')
    ->where('rs.id_produk', $productId)
    ->where(function ($query) {
        $query->where(function ($nested) {
            $nested->whereNull('rs.id_penjualan')
                ->whereNull('rs.id_pembelian');
        })->orWhere(function ($nested) {
            $nested->whereNotNull('rs.id_penjualan')
                ->whereRaw('COALESCE(pj.total_item, 0) > 0')
                ->whereRaw('COALESCE(pj.total_harga, 0) > 0')
                ->whereRaw('COALESCE(pj.bayar, 0) > 0')
                ->whereRaw('COALESCE(pj.diterima, 0) > 0');
        })->orWhere(function ($nested) {
            $nested->whereNotNull('rs.id_pembelian')
                ->whereNotNull('pb.no_faktur')
                ->whereRaw('TRIM(pb.no_faktur) != ?', [''])
                ->whereRaw('LOWER(TRIM(pb.no_faktur)) != ?', ['o'])
                ->whereRaw('COALESCE(pb.total_harga, 0) > 0')
                ->whereRaw('COALESCE(pb.bayar, 0) > 0');
        });
    })
    ->orderBy('rs.waktu', 'desc')
    ->orderBy('rs.id_rekaman_stok', 'desc')
    ->first(['rs.*']);

$integrityService = app(StockRuntimeIntegrityService::class);
$reflection = new ReflectionClass($integrityService);
$method = $reflection->getMethod('buildDraftAwareStockSnapshot');
$method->setAccessible(true);
$snapshot = $method->invoke($integrityService, $productId, [$productId => intval($finalRow['final_stock'] ?? 0)]);

$postCutoffRows = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->where('waktu', '>', $cutoff)
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get([
        'id_rekaman_stok',
        'waktu',
        'id_penjualan',
        'id_pembelian',
        'stok_awal',
        'stok_masuk',
        'stok_keluar',
        'stok_sisa',
        'keterangan',
    ]);

fwrite(STDOUT, "Analisis truth stok produk #{$productId} - {$product->nama_produk}\n");
fwrite(STDOUT, str_repeat('=', 90) . "\n");
fwrite(STDOUT, 'Cutoff baseline              : ' . $cutoff . "\n");
fwrite(STDOUT, 'Produk.stok saat ini         : ' . intval($product->stok ?? 0) . "\n");
fwrite(STDOUT, 'Baseline row ditemukan?      : ' . ($baselineRow ? 'YA' : 'TIDAK') . "\n");
if ($baselineRow) {
    fwrite(STDOUT, 'Baseline stok                : ' . intval($baselineRow['stok']) . "\n");
    fwrite(STDOUT, 'Baseline nama                : ' . $baselineRow['nama_produk'] . "\n");
}
fwrite(STDOUT, 'Latest record stok_sisa      : ' . intval($latestRecord->stok_sisa ?? 0) . "\n");
fwrite(STDOUT, 'Latest committed stok_sisa   : ' . intval($latestCommittedRecord->stok_sisa ?? 0) . "\n");
fwrite(STDOUT, 'Reflow final stok committed  : ' . intval($finalRow['final_stock'] ?? 0) . "\n");
fwrite(STDOUT, 'Draft beli terbuka           : ' . intval($snapshot['draft_pembelian_qty'] ?? 0) . "\n");
fwrite(STDOUT, 'Draft jual terbuka           : ' . intval($snapshot['draft_penjualan_qty'] ?? 0) . "\n");
fwrite(STDOUT, 'Expected current stock       : ' . intval($snapshot['expected_stock'] ?? 0) . "\n");
fwrite(STDOUT, 'Negative historical events   : ' . intval($reflowSummary['negative_event_count'] ?? 0) . "\n");
fwrite(STDOUT, str_repeat('-', 90) . "\n");

foreach ($postCutoffRows as $row) {
    fwrite(STDOUT, sprintf(
        "rs#%d | %s | pj=%s | pb=%s | awal=%d | masuk=%d | keluar=%d | sisa=%d | %s\n",
        intval($row->id_rekaman_stok),
        (string) $row->waktu,
        $row->id_penjualan === null ? '-' : intval($row->id_penjualan),
        $row->id_pembelian === null ? '-' : intval($row->id_pembelian),
        intval($row->stok_awal),
        intval($row->stok_masuk),
        intval($row->stok_keluar),
        intval($row->stok_sisa),
        (string) ($row->keterangan ?? '')
    ));
}

function findBaselineRow(string $path, int $productId): ?array
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $handle = fopen($path, 'r');
    if ($handle === false) {
        return null;
    }

    $delimiter = ';';
    $header = fgetcsv($handle, 0, $delimiter);
    if (!$header || count($header) < 3) {
        fclose($handle);
        return null;
    }

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (intval($row[0] ?? 0) !== $productId) {
            continue;
        }

        fclose($handle);
        return [
            'id_produk' => intval($row[0] ?? 0),
            'nama_produk' => trim((string) ($row[1] ?? '')),
            'stok' => intval($row[2] ?? 0),
        ];
    }

    fclose($handle);
    return null;
}