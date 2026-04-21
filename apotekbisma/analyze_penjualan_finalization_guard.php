<?php

use App\Services\BaselineStockReflowService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$transactionId = intval($argv[1] ?? 0);

if ($transactionId <= 0) {
    fwrite(STDERR, "Usage: php analyze_penjualan_finalization_guard.php <id_penjualan>\n");
    exit(1);
}

$cutoff = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');
$staleMinutes = intval(config('stock.stale_draft_minutes', 30));
$now = Carbon::now();

$header = DB::table('penjualan')
    ->where('id_penjualan', $transactionId)
    ->first();

if (!$header) {
    fwrite(STDERR, "Transaksi #{$transactionId} tidak ditemukan.\n");
    exit(1);
}

$details = DB::table('penjualan_detail as pd')
    ->join('produk as p', 'p.id_produk', '=', 'pd.id_produk')
    ->where('pd.id_penjualan', $transactionId)
    ->select(
        'pd.id_produk',
        'p.nama_produk',
        'p.stok as master_stock',
        'pd.jumlah',
        'pd.harga_jual',
        'pd.subtotal'
    )
    ->orderBy('p.nama_produk')
    ->get();

if ($details->isEmpty()) {
    fwrite(STDOUT, "Transaksi #{$transactionId} tidak memiliki detail.\n");
    exit(0);
}

$productIds = $details->pluck('id_produk')
    ->map(function ($id) {
        return intval($id);
    })
    ->filter(function ($id) {
        return $id > 0;
    })
    ->unique()
    ->values()
    ->all();

$qtyByProduct = $details
    ->groupBy('id_produk')
    ->map(function ($rows) {
        return intval($rows->sum('jumlah'));
    })
    ->all();

$isIncomplete = is_penjualan_incomplete($header, $transactionId);
$transactionStatus = $isIncomplete ? 'INCOMPLETE_DRAFT' : 'FINALIZED';

$reflowService = app(BaselineStockReflowService::class);
$previewSummary = $reflowService->previewRebuildSummary($productIds, $now->format('Y-m-d H:i:s'));
$reflowFinalMap = [];
foreach (($previewSummary['final_stock_by_product'] ?? []) as $row) {
    $productId = intval($row['id_produk'] ?? 0);
    if ($productId <= 0) {
        continue;
    }

    $reflowFinalMap[$productId] = intval($row['final_stock'] ?? 0);
}

$lines = [];
$blockingProducts = [];

foreach ($productIds as $productId) {
    $productName = (string) ($details->firstWhere('id_produk', $productId)->nama_produk ?? ('Produk #' . $productId));
    $masterStock = intval($details->firstWhere('id_produk', $productId)->master_stock ?? 0);
    $currentTxQty = intval($qtyByProduct[$productId] ?? 0);
    $latestCommittedSnapshot = resolve_latest_committed_snapshot($productId);
    $latestCommittedStock = intval($latestCommittedSnapshot['stok_sisa'] ?? 0);
    $reflowCommittedStock = intval($reflowFinalMap[$productId] ?? 0);
    $openDraftPenjualan = resolve_open_penjualan_drafts($productId, $cutoff);
    $openDraftPembelianQty = resolve_open_pembelian_qty($productId, $cutoff);
    $openDraftPenjualanQty = array_sum(array_map(function (array $row) {
        return intval($row['qty']);
    }, $openDraftPenjualan));

    $expectedCurrentFromLatestRecord = $latestCommittedStock + $openDraftPembelianQty - $openDraftPenjualanQty;
    $expectedCurrentFromReflow = $reflowCommittedStock + $openDraftPembelianQty - $openDraftPenjualanQty;

    $otherDraftQty = $openDraftPenjualanQty;
    if ($isIncomplete) {
        $otherDraftQty -= $currentTxQty;
    }

    $committedAfterFinalize = $reflowCommittedStock;
    if ($isIncomplete) {
        $committedAfterFinalize -= $currentTxQty;
    }

    $projectedAfterFinalize = $committedAfterFinalize + $openDraftPembelianQty - max(0, $otherDraftQty);

    if ($projectedAfterFinalize < 0) {
        $blockingProducts[] = [
            'id_produk' => $productId,
            'nama_produk' => $productName,
            'projected_after_finalize' => $projectedAfterFinalize,
            'master_stock' => $masterStock,
            'latest_committed_stock' => $latestCommittedStock,
            'reflow_committed_stock' => $reflowCommittedStock,
            'open_draft_penjualan_qty' => $openDraftPenjualanQty,
            'current_tx_qty' => $currentTxQty,
            'open_draft_pembelian_qty' => $openDraftPembelianQty,
            'open_penjualan_drafts' => $openDraftPenjualan,
        ];
    }

    $lines[] = [
        'id_produk' => $productId,
        'nama_produk' => $productName,
        'qty_transaksi' => $currentTxQty,
        'master_stock' => $masterStock,
        'latest_committed_stock' => $latestCommittedStock,
        'reflow_committed_stock' => $reflowCommittedStock,
        'open_draft_penjualan_qty' => $openDraftPenjualanQty,
        'open_draft_pembelian_qty' => $openDraftPembelianQty,
        'expected_current_from_latest_record' => $expectedCurrentFromLatestRecord,
        'expected_current_from_reflow' => $expectedCurrentFromReflow,
        'projected_after_finalize' => $projectedAfterFinalize,
        'reflow_delta_vs_latest_record' => $reflowCommittedStock - $latestCommittedStock,
    ];
}

fwrite(STDOUT, "Analisis guard finalisasi penjualan #{$transactionId}\n");
fwrite(STDOUT, str_repeat('=', 100) . "\n");
fwrite(STDOUT, 'Status header saat ini : ' . $transactionStatus . "\n");
fwrite(STDOUT, 'Waktu transaksi       : ' . format_nullable_datetime($header->waktu ?? $header->created_at ?? null) . "\n");
fwrite(STDOUT, 'Cutoff baseline       : ' . $cutoff . "\n");
fwrite(STDOUT, 'Stale draft threshold : ' . $staleMinutes . " menit\n");
fwrite(STDOUT, str_repeat('-', 100) . "\n");
fwrite(STDOUT, sprintf(
    "%-5s %-28s %8s %8s %8s %8s %8s %8s %8s %8s %8s\n",
    'ID',
    'Produk',
    'QtyTx',
    'Master',
    'LastOK',
    'Reflow',
    'DraftJ',
    'DraftB',
    'ExpRec',
    'ExpRef',
    'AfterFin'
));
fwrite(STDOUT, str_repeat('-', 100) . "\n");

foreach ($lines as $line) {
    fwrite(STDOUT, sprintf(
        "%-5d %-28s %8d %8d %8d %8d %8d %8d %8d %8d %8d\n",
        $line['id_produk'],
        mb_strimwidth($line['nama_produk'], 0, 28, ''),
        $line['qty_transaksi'],
        $line['master_stock'],
        $line['latest_committed_stock'],
        $line['reflow_committed_stock'],
        $line['open_draft_penjualan_qty'],
        $line['open_draft_pembelian_qty'],
        $line['expected_current_from_latest_record'],
        $line['expected_current_from_reflow'],
        $line['projected_after_finalize']
    ));

    if ($line['reflow_delta_vs_latest_record'] !== 0 || $line['master_stock'] !== $line['expected_current_from_reflow']) {
        fwrite(STDOUT, sprintf(
            "      delta_reflow_vs_last=%d | master_vs_reflow_expected=%d\n",
            $line['reflow_delta_vs_latest_record'],
            $line['master_stock'] - $line['expected_current_from_reflow']
        ));
    }
}

fwrite(STDOUT, str_repeat('-', 100) . "\n");

if (empty($blockingProducts)) {
    fwrite(STDOUT, "Kesimpulan guard: TIDAK ADA produk yang semestinya diblokir pada kondisi data saat ini.\n");
} else {
    fwrite(STDOUT, "Kesimpulan guard: ADA produk yang valid diblokir oleh reflow baseline.\n");
    fwrite(STDOUT, str_repeat('-', 100) . "\n");

    foreach ($blockingProducts as $product) {
        fwrite(STDOUT, sprintf(
            "Produk blokir: %s (#%d) | projected_after_finalize=%d | master=%d | latest_record=%d | reflow=%d | draft_jual=%d | draft_beli=%d | qty_tx=%d\n",
            $product['nama_produk'],
            $product['id_produk'],
            $product['projected_after_finalize'],
            $product['master_stock'],
            $product['latest_committed_stock'],
            $product['reflow_committed_stock'],
            $product['open_draft_penjualan_qty'],
            $product['open_draft_pembelian_qty'],
            $product['current_tx_qty']
        ));

        if (!empty($product['open_penjualan_drafts'])) {
            fwrite(STDOUT, "  Draft penjualan terbuka terkait:\n");
            foreach ($product['open_penjualan_drafts'] as $draft) {
                fwrite(STDOUT, sprintf(
                    "    - #%d | qty=%d | waktu=%s | age=%s menit | total_item=%d | total_harga=%d | bayar=%d | diterima=%d%s\n",
                    $draft['id_penjualan'],
                    $draft['qty'],
                    $draft['waktu'],
                    $draft['age_minutes'],
                    $draft['total_item'],
                    $draft['total_harga'],
                    $draft['bayar'],
                    $draft['diterima'],
                    $draft['id_penjualan'] === $transactionId ? ' [CURRENT_TX]' : ''
                ));
            }
        }

        $relatedRecords = DB::table('rekaman_stoks as rs')
            ->leftJoin('penjualan as pj', 'pj.id_penjualan', '=', 'rs.id_penjualan')
            ->leftJoin('pembelian as pb', 'pb.id_pembelian', '=', 'rs.id_pembelian')
            ->where('rs.id_produk', $product['id_produk'])
            ->where('rs.waktu', '>', $cutoff)
            ->orderByRaw('COALESCE(pb.waktu_datang, pb.waktu, pj.waktu, rs.waktu) asc')
            ->orderBy('rs.created_at', 'asc')
            ->orderBy('rs.id_rekaman_stok', 'asc')
            ->get([
                'rs.id_rekaman_stok',
                'rs.waktu',
                'rs.id_penjualan',
                'rs.id_pembelian',
                'rs.stok_awal',
                'rs.stok_masuk',
                'rs.stok_keluar',
                'rs.stok_sisa',
                'rs.keterangan',
            ]);

        fwrite(STDOUT, "  Rekaman stok existing post-cutoff:\n");
        foreach ($relatedRecords as $record) {
            fwrite(STDOUT, sprintf(
                "    - rs#%d | waktu=%s | pj=%s | pb=%s | awal=%d | masuk=%d | keluar=%d | sisa=%d | %s\n",
                intval($record->id_rekaman_stok),
                format_nullable_datetime($record->waktu),
                $record->id_penjualan === null ? '-' : intval($record->id_penjualan),
                $record->id_pembelian === null ? '-' : intval($record->id_pembelian),
                intval($record->stok_awal),
                intval($record->stok_masuk),
                intval($record->stok_keluar),
                intval($record->stok_sisa),
                (string) ($record->keterangan ?? '')
            ));
        }
    }
}

function is_penjualan_incomplete($header, int $transactionId): bool
{
    $hasDetail = DB::table('penjualan_detail')
        ->where('id_penjualan', $transactionId)
        ->exists();

    $hasIncompleteDetail = DB::table('penjualan_detail')
        ->where('id_penjualan', $transactionId)
        ->where('jumlah', '<=', 0)
        ->exists();

    return !$hasDetail
        || $hasIncompleteDetail
        || intval($header->total_item ?? 0) <= 0
        || intval($header->total_harga ?? 0) <= 0
        || intval($header->bayar ?? 0) <= 0
        || intval($header->diterima ?? 0) <= 0;
}

function resolve_latest_committed_snapshot(int $productId): array
{
    $row = DB::table('rekaman_stoks as rs')
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
        ->orderByRaw("CASE
            WHEN rs.id_pembelian IS NOT NULL THEN 0
            WHEN rs.id_penjualan IS NOT NULL THEN 1
            WHEN LOWER(COALESCE(rs.keterangan, '')) LIKE '%stock opname%' THEN 2
            WHEN LOWER(COALESCE(rs.keterangan, '')) LIKE '%perubahan stok manual%' THEN 2
            WHEN LOWER(COALESCE(rs.keterangan, '')) LIKE '%penyesuaian stok%' THEN 2
            WHEN LOWER(COALESCE(rs.keterangan, '')) LIKE '%saldo awal stok%' THEN 2
            ELSE 3
        END DESC")
        ->orderBy('rs.created_at', 'desc')
        ->orderBy('rs.id_rekaman_stok', 'desc')
        ->first([
            'rs.id_rekaman_stok',
            'rs.waktu',
            'rs.stok_sisa',
            'rs.keterangan',
            'rs.id_penjualan',
            'rs.id_pembelian',
        ]);

    if (!$row) {
        return [
            'id_rekaman_stok' => null,
            'waktu' => null,
            'stok_sisa' => 0,
            'keterangan' => null,
            'id_penjualan' => null,
            'id_pembelian' => null,
        ];
    }

    return [
        'id_rekaman_stok' => intval($row->id_rekaman_stok ?? 0),
        'waktu' => (string) ($row->waktu ?? ''),
        'stok_sisa' => intval($row->stok_sisa ?? 0),
        'keterangan' => (string) ($row->keterangan ?? ''),
        'id_penjualan' => $row->id_penjualan === null ? null : intval($row->id_penjualan),
        'id_pembelian' => $row->id_pembelian === null ? null : intval($row->id_pembelian),
    ];
}

function resolve_open_penjualan_drafts(int $productId, string $cutoff): array
{
    $rows = DB::table('penjualan_detail as pd')
        ->join('penjualan as p', 'p.id_penjualan', '=', 'pd.id_penjualan')
        ->where('pd.id_produk', $productId)
        ->where('pd.jumlah', '>', 0)
        ->whereRaw('COALESCE(p.waktu, p.created_at) > ?', [$cutoff])
        ->where(function ($query) {
            $query->whereRaw('COALESCE(p.total_item, 0) <= 0')
                ->orWhereRaw('COALESCE(p.total_harga, 0) <= 0')
                ->orWhereRaw('COALESCE(p.bayar, 0) <= 0')
                ->orWhereRaw('COALESCE(p.diterima, 0) <= 0');
        })
        ->groupBy(
            'pd.id_penjualan',
            DB::raw('COALESCE(p.waktu, p.created_at)'),
            'p.total_item',
            'p.total_harga',
            'p.bayar',
            'p.diterima'
        )
        ->selectRaw('pd.id_penjualan as id_penjualan, SUM(pd.jumlah) as qty, COALESCE(p.waktu, p.created_at) as waktu, MAX(p.total_item) as total_item, MAX(p.total_harga) as total_harga, MAX(p.bayar) as bayar, MAX(p.diterima) as diterima')
        ->orderBy('id_penjualan')
        ->get();

    return $rows->map(function ($row) {
        $waktu = $row->waktu ? Carbon::parse($row->waktu) : null;

        return [
            'id_penjualan' => intval($row->id_penjualan ?? 0),
            'qty' => intval($row->qty ?? 0),
            'waktu' => format_nullable_datetime($row->waktu ?? null),
            'age_minutes' => $waktu ? Carbon::now()->diffInMinutes($waktu) : null,
            'total_item' => intval($row->total_item ?? 0),
            'total_harga' => intval($row->total_harga ?? 0),
            'bayar' => intval($row->bayar ?? 0),
            'diterima' => intval($row->diterima ?? 0),
        ];
    })->values()->all();
}

function resolve_open_pembelian_qty(int $productId, string $cutoff): int
{
    return intval(DB::table('pembelian_detail as pd')
        ->join('pembelian as p', 'p.id_pembelian', '=', 'pd.id_pembelian')
        ->where('pd.id_produk', $productId)
        ->where('pd.jumlah', '>', 0)
        ->whereRaw('COALESCE(p.waktu_datang, p.waktu, p.created_at) > ?', [$cutoff])
        ->where(function ($query) {
            $query->whereNull('p.no_faktur')
                ->orWhereRaw('TRIM(COALESCE(p.no_faktur, ?)) = ?', ['', ''])
                ->orWhereRaw('LOWER(TRIM(COALESCE(p.no_faktur, ?))) = ?', ['', 'o'])
                ->orWhereRaw('COALESCE(p.total_harga, 0) <= 0')
                ->orWhereRaw('COALESCE(p.bayar, 0) <= 0');
        })
        ->sum('pd.jumlah'));
}

function format_nullable_datetime($value): string
{
    if (!$value) {
        return '-';
    }

    try {
        return Carbon::parse($value)->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return (string) $value;
    }
}