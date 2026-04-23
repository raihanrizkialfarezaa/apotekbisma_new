<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$keyword = isset($argv[1]) ? trim((string) $argv[1]) : 'VOLTAR';

function line(string $text = ''): void
{
    echo $text . PHP_EOL;
}

function title(string $text): void
{
    line();
    line('=== ' . $text . ' ===');
}

function yesNo(bool $value): string
{
    return $value ? 'YES' : 'NO';
}

$products = DB::table('produk')
    ->select('id_produk', 'nama_produk', 'stok')
    ->where('nama_produk', 'like', '%' . $keyword . '%')
    ->orderBy('id_produk')
    ->get();

line('Database: ' . DB::connection()->getDatabaseName());
line('Keyword: ' . $keyword);

title('Matching Products');
if ($products->isEmpty()) {
    line('No products found.');
    exit(1);
}

foreach ($products as $product) {
    line(sprintf(
        '#%d | %s | stok=%d',
        (int) $product->id_produk,
        (string) $product->nama_produk,
        (int) $product->stok
    ));
}

foreach ($products as $product) {
    $productId = (int) $product->id_produk;

    title('Blocking Drafts For #' . $productId . ' ' . (string) $product->nama_produk);

    $penjualanDrafts = DB::table('penjualan_detail as pd')
        ->join('penjualan as p', 'pd.id_penjualan', '=', 'p.id_penjualan')
        ->where('pd.id_produk', $productId)
        ->where('pd.jumlah', '>', 0)
        ->where(function ($query) {
            $query->where('p.total_item', '<=', 0)
                ->orWhere('p.total_harga', '<=', 0)
                ->orWhere('p.bayar', '<=', 0)
                ->orWhere('p.diterima', '<=', 0);
        })
        ->groupBy(
            'p.id_penjualan',
            'p.waktu',
            'p.created_at',
            'p.total_item',
            'p.total_harga',
            'p.bayar',
            'p.diterima'
        )
        ->orderBy('p.id_penjualan')
        ->selectRaw('p.id_penjualan, COALESCE(p.waktu, p.created_at) AS waktu_ref, SUM(pd.jumlah) AS qty, p.total_item, p.total_harga, p.bayar, p.diterima')
        ->get();

    if ($penjualanDrafts->isEmpty()) {
        line('No blocking draft penjualan found.');
    } else {
        line('Draft penjualan rows: ' . $penjualanDrafts->count());
        foreach ($penjualanDrafts as $draft) {
            $reasons = [];
            if ((int) ($draft->total_item ?? 0) <= 0) {
                $reasons[] = 'total_item<=0';
            }
            if ((int) ($draft->total_harga ?? 0) <= 0) {
                $reasons[] = 'total_harga<=0';
            }
            if ((int) ($draft->bayar ?? 0) <= 0) {
                $reasons[] = 'bayar<=0';
            }
            if ((int) ($draft->diterima ?? 0) <= 0) {
                $reasons[] = 'diterima<=0';
            }

            line(sprintf(
                'penjualan#%d | waktu=%s | qty=%d | total_item=%d | total_harga=%d | bayar=%d | diterima=%d | reasons=%s',
                (int) $draft->id_penjualan,
                (string) ($draft->waktu_ref ?? '-'),
                (int) ($draft->qty ?? 0),
                (int) ($draft->total_item ?? 0),
                (int) ($draft->total_harga ?? 0),
                (int) ($draft->bayar ?? 0),
                (int) ($draft->diterima ?? 0),
                implode(', ', $reasons)
            ));
        }
    }

    $pembelianDrafts = DB::table('pembelian_detail as pd')
        ->join('pembelian as p', 'pd.id_pembelian', '=', 'p.id_pembelian')
        ->where('pd.id_produk', $productId)
        ->where('pd.jumlah', '>', 0)
        ->where(function ($query) {
            $query->where('p.no_faktur', 'o')
                ->orWhere('p.no_faktur', '')
                ->orWhereNull('p.no_faktur')
                ->orWhere('p.total_harga', '<=', 0)
                ->orWhere('p.bayar', '<=', 0);
        })
        ->groupBy(
            'p.id_pembelian',
            'p.waktu',
            'p.waktu_datang',
            'p.created_at',
            'p.no_faktur',
            'p.total_harga',
            'p.bayar'
        )
        ->orderBy('p.id_pembelian')
        ->selectRaw('p.id_pembelian, COALESCE(p.waktu_datang, p.waktu, p.created_at) AS waktu_ref, SUM(pd.jumlah) AS qty, p.no_faktur, p.total_harga, p.bayar')
        ->get();

    if ($pembelianDrafts->isEmpty()) {
        line('No blocking draft pembelian found.');
    } else {
        line('Draft pembelian rows: ' . $pembelianDrafts->count());
        foreach ($pembelianDrafts as $draft) {
            $reasons = [];
            $noFaktur = (string) ($draft->no_faktur ?? 'NULL');
            $trimmedNoFaktur = trim($noFaktur);

            if ($draft->no_faktur === null) {
                $reasons[] = 'no_faktur=NULL';
            } elseif ($trimmedNoFaktur === '') {
                $reasons[] = 'no_faktur=empty';
            } elseif (strtolower($trimmedNoFaktur) === 'o') {
                $reasons[] = 'no_faktur=o';
            }

            if ((int) ($draft->total_harga ?? 0) <= 0) {
                $reasons[] = 'total_harga<=0';
            }
            if ((int) ($draft->bayar ?? 0) <= 0) {
                $reasons[] = 'bayar<=0';
            }

            line(sprintf(
                'pembelian#%d | waktu=%s | qty=%d | no_faktur=%s | total_harga=%d | bayar=%d | reasons=%s',
                (int) $draft->id_pembelian,
                (string) ($draft->waktu_ref ?? '-'),
                (int) ($draft->qty ?? 0),
                $noFaktur,
                (int) ($draft->total_harga ?? 0),
                (int) ($draft->bayar ?? 0),
                implode(', ', $reasons)
            ));
        }
    }

    $hasAnyBlockingDraft = !$penjualanDrafts->isEmpty() || !$pembelianDrafts->isEmpty();
    line('Manual stock mutation currently blocked: ' . yesNo($hasAnyBlockingDraft));
}