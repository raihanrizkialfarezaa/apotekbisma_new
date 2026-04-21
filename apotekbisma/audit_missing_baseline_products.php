<?php

use App\Services\BaselineStockReflowService;
use App\Services\StockRuntimeIntegrityService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$limit = 50;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--limit=(\d+)$/', $argument, $matches)) {
        $limit = max(1, intval($matches[1]));
    }
}

$cutoff = Carbon::parse((string) config('stock.cutoff_datetime', '2025-12-31 23:59:59'));
$until = Carbon::now()->format('Y-m-d H:i:s');
$baselineIds = loadBaselineIds(base_path(config('stock.baseline_csv')));
$candidateProductIds = collect()
    ->merge(findRelevantRekamanProductIds($baselineIds, $cutoff))
    ->merge(findRelevantPenjualanProductIds($baselineIds, $cutoff))
    ->merge(findRelevantPembelianProductIds($baselineIds, $cutoff))
    ->map(static function ($productId) {
        return intval($productId);
    })
    ->filter(static function ($productId) {
        return $productId > 0;
    })
    ->unique()
    ->sort()
    ->values()
    ->all();

if (empty($candidateProductIds)) {
    fwrite(STDOUT, "Tidak ada produk non-baseline dengan event relevan pasca-cutoff.\n");
    exit(0);
}

$products = DB::table('produk')
    ->whereIn('id_produk', $candidateProductIds)
    ->get(['id_produk', 'nama_produk', 'stok'])
    ->keyBy('id_produk');

$reflowService = app(BaselineStockReflowService::class);
$integrityService = app(StockRuntimeIntegrityService::class);

$ledgerMap = [];
$currentMap = [];
foreach (array_chunk($candidateProductIds, 100) as $chunk) {
    $ledgerMap += $reflowService->previewProductLedgers($chunk, $until);
    $currentMap += $integrityService->previewCurrentSellableStockMap($chunk, $until, false);
}

$rows = [];
foreach ($candidateProductIds as $productId) {
    $product = $products->get($productId);
    if (!$product) {
        continue;
    }

    $ledger = $ledgerMap[$productId] ?? [];
    $current = $currentMap[$productId] ?? [];
    $latestCommitted = resolveLatestCommittedStock($productId);
    $cutoffSeed = resolveCutoffSeedCandidate($productId, $cutoff);

    $rows[] = [
        'id_produk' => $productId,
        'nama_produk' => (string) ($product->nama_produk ?? ''),
        'master_stock' => intval($product->stok ?? 0),
        'committed_stock' => intval($current['committed_stock'] ?? ($ledger['final_stock'] ?? 0)),
        'current_stock' => intval($current['raw_current_stock'] ?? 0),
        'latest_committed_stock' => $latestCommitted,
        'seed_source' => (string) ($ledger['seed_source'] ?? 'unknown'),
        'cutoff_seed_stock' => $cutoffSeed['stok'] ?? null,
        'cutoff_seed_time' => $cutoffSeed['waktu'] ?? null,
        'has_cutoff_seed' => $cutoffSeed !== null,
        'master_mismatch' => intval($product->stok ?? 0) !== intval($current['raw_current_stock'] ?? 0),
        'committed_mismatch' => $latestCommitted !== intval($current['committed_stock'] ?? ($ledger['final_stock'] ?? 0)),
        'has_negative_current_stock' => intval($current['raw_current_stock'] ?? 0) < 0,
    ];
}

$rowCollection = collect($rows);

fwrite(STDOUT, "Audit produk non-baseline dengan event relevan\n");
fwrite(STDOUT, str_repeat('=', 110) . "\n");
fwrite(STDOUT, 'Cutoff baseline                     : ' . $cutoff->format('Y-m-d H:i:s') . "\n");
fwrite(STDOUT, 'Total produk non-baseline relevan   : ' . $rowCollection->count() . "\n");
fwrite(STDOUT, 'Seed dari rekaman cutoff            : ' . $rowCollection->where('seed_source', 'baseline_rekaman')->count() . "\n");
fwrite(STDOUT, 'Seed nol default                    : ' . $rowCollection->where('seed_source', 'zero_default')->count() . "\n");
fwrite(STDOUT, 'Mismatch master vs current          : ' . $rowCollection->where('master_mismatch', true)->count() . "\n");
fwrite(STDOUT, 'Mismatch latest committed vs reflow : ' . $rowCollection->where('committed_mismatch', true)->count() . "\n");
fwrite(STDOUT, 'Current stock negatif               : ' . $rowCollection->where('has_negative_current_stock', true)->count() . "\n");
fwrite(STDOUT, str_repeat('-', 110) . "\n");

printSection(
    'Produk dengan seed rekaman cutoff',
    $rowCollection->where('seed_source', 'baseline_rekaman')->take($limit)
);

printSection(
    'Produk zero-default tanpa cutoff seed',
    $rowCollection->where('seed_source', 'zero_default')->take($limit)
);

printSection(
    'Produk yang masih mismatch master/current',
    $rowCollection->where('master_mismatch', true)->take($limit)
);

printSection(
    'Produk yang mismatch latest committed/reflow',
    $rowCollection->where('committed_mismatch', true)->take($limit)
);

function printSection(string $title, Collection $rows): void
{
    fwrite(STDOUT, $title . "\n");
    fwrite(STDOUT, str_repeat('-', 110) . "\n");

    if ($rows->isEmpty()) {
        fwrite(STDOUT, "(kosong)\n\n");
        return;
    }

    foreach ($rows as $row) {
        fwrite(STDOUT, sprintf(
            "#%d | %s | seed=%s | cutoff=%s@%s | master=%d | committed=%d | current=%d | latest_ok=%d\n",
            intval($row['id_produk'] ?? 0),
            shortenText((string) ($row['nama_produk'] ?? ''), 28),
            (string) ($row['seed_source'] ?? ''),
            $row['cutoff_seed_stock'] === null ? '-' : intval($row['cutoff_seed_stock']),
            $row['cutoff_seed_time'] === null ? '-' : (string) $row['cutoff_seed_time'],
            intval($row['master_stock'] ?? 0),
            intval($row['committed_stock'] ?? 0),
            intval($row['current_stock'] ?? 0),
            intval($row['latest_committed_stock'] ?? 0)
        ));
    }

    fwrite(STDOUT, "\n");
}

function findRelevantRekamanProductIds(array $baselineIds, Carbon $cutoff): array
{
    $query = DB::table('rekaman_stoks')
        ->distinct()
        ->where(function ($builder) use ($cutoff) {
            $builder->where('waktu', '>', $cutoff->format('Y-m-d H:i:s'))
                ->orWhere(function ($seedQuery) use ($cutoff) {
                    $seedQuery->whereNull('id_penjualan')
                        ->whereNull('id_pembelian')
                        ->where('waktu', '>=', $cutoff->copy()->startOfDay()->format('Y-m-d H:i:s'))
                        ->where('waktu', '<=', $cutoff->copy()->addDay()->endOfDay()->format('Y-m-d H:i:s'))
                        ->where(function ($messageQuery) {
                            $messageQuery->where('keterangan', 'like', '%Saldo Awal Stok%')
                                ->orWhere('keterangan', 'like', '%histori sebelum cutoff%');
                        });
                });
        });

    if (!empty($baselineIds)) {
        $query->whereNotIn('id_produk', $baselineIds);
    }

    return $query->pluck('id_produk')->all();
}

function findRelevantPenjualanProductIds(array $baselineIds, Carbon $cutoff): array
{
    $query = DB::table('penjualan_detail as pd')
        ->join('penjualan as p', 'p.id_penjualan', '=', 'pd.id_penjualan')
        ->distinct()
        ->whereRaw('COALESCE(p.waktu, p.created_at) > ?', [$cutoff->format('Y-m-d H:i:s')]);

    if (!empty($baselineIds)) {
        $query->whereNotIn('pd.id_produk', $baselineIds);
    }

    return $query->pluck('pd.id_produk')->all();
}

function findRelevantPembelianProductIds(array $baselineIds, Carbon $cutoff): array
{
    $query = DB::table('pembelian_detail as pd')
        ->join('pembelian as p', 'p.id_pembelian', '=', 'pd.id_pembelian')
        ->distinct()
        ->whereRaw('COALESCE(p.waktu_datang, p.waktu, p.created_at) > ?', [$cutoff->format('Y-m-d H:i:s')]);

    if (!empty($baselineIds)) {
        $query->whereNotIn('pd.id_produk', $baselineIds);
    }

    return $query->pluck('pd.id_produk')->all();
}

function resolveLatestCommittedStock(int $productId): int
{
    $record = DB::table('rekaman_stoks as rs')
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
        ->first(['rs.stok_sisa']);

    return intval($record->stok_sisa ?? 0);
}

function resolveCutoffSeedCandidate(int $productId, Carbon $cutoff): ?array
{
    $candidates = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->whereNull('id_penjualan')
        ->whereNull('id_pembelian')
        ->where(function ($query) {
            $query->where('keterangan', 'like', '%Saldo Awal Stok%')
                ->orWhere('keterangan', 'like', '%histori sebelum cutoff%');
        })
        ->where('waktu', '>=', $cutoff->copy()->startOfDay()->format('Y-m-d H:i:s'))
        ->where('waktu', '<=', $cutoff->copy()->addDay()->endOfDay()->format('Y-m-d H:i:s'))
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get(['id_rekaman_stok', 'waktu', 'stok_sisa']);

    if ($candidates->isEmpty()) {
        return null;
    }

    $sorted = $candidates->sort(function ($left, $right) use ($cutoff) {
        $leftTime = Carbon::parse($left->waktu);
        $rightTime = Carbon::parse($right->waktu);

        $leftScore = resolveCutoffSeedScore($leftTime, $cutoff);
        $rightScore = resolveCutoffSeedScore($rightTime, $cutoff);

        if ($leftScore !== $rightScore) {
            return $leftScore <=> $rightScore;
        }

        if ($leftScore === 1) {
            $timeCompare = $rightTime->getTimestamp() <=> $leftTime->getTimestamp();
            if ($timeCompare !== 0) {
                return $timeCompare;
            }
        } elseif ($leftScore === 2) {
            $timeCompare = $leftTime->getTimestamp() <=> $rightTime->getTimestamp();
            if ($timeCompare !== 0) {
                return $timeCompare;
            }
        }

        return intval($left->id_rekaman_stok ?? 0) <=> intval($right->id_rekaman_stok ?? 0);
    })->values();

    $seed = $sorted->first();
    if (!$seed) {
        return null;
    }

    return [
        'stok' => intval($seed->stok_sisa ?? 0),
        'waktu' => (string) ($seed->waktu ?? ''),
    ];
}

function resolveCutoffSeedScore(Carbon $candidateTime, Carbon $cutoff): int
{
    if ($candidateTime->format('Y-m-d H:i:s') === $cutoff->format('Y-m-d H:i:s')) {
        return 0;
    }

    if ($candidateTime->lte($cutoff)) {
        return 1;
    }

    return 2;
}

function loadBaselineIds(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $handle = fopen($path, 'r');
    if ($handle === false) {
        return [];
    }

    $header = fgetcsv($handle, 0, ';');
    if ($header === false) {
        fclose($handle);
        return [];
    }

    $productIds = [];
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        $productId = intval($row[0] ?? 0);
        if ($productId > 0) {
            $productIds[] = $productId;
        }
    }

    fclose($handle);

    return array_values(array_unique($productIds));
}

function shortenText(string $value, int $maxLength): string
{
    if (strlen($value) <= $maxLength) {
        return $value;
    }

    return substr($value, 0, max(0, $maxLength - 3)) . '...';
}