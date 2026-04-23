<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\BaselineStockReflowService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$productId = 862;
$targetWaktu = '2026-01-01 00:15:37';
$targetKeterangan = 'Perubahan Stok Manual: SO';
$targetStokAwal = 0;
$targetStokMasuk = 37;
$targetStokKeluar = 0;
$targetStokSisa = 37;
$shouldExecute = in_array('--execute', $argv, true);

function line(string $text = ''): void
{
    echo $text . PHP_EOL;
}

function section(string $title): void
{
    line();
    line('=== ' . $title . ' ===');
}

function findMatchingManualSo(int $productId, string $waktu, string $keterangan)
{
    return DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->whereNull('id_penjualan')
        ->whereNull('id_pembelian')
        ->where('waktu', $waktu)
        ->where('keterangan', $keterangan)
        ->first();
}

line('Repair target: VOLTAREN 50mg (#862) missing manual SO');
line('Mode: ' . ($shouldExecute ? 'EXECUTE' : 'DRY-RUN'));
line('Database: ' . DB::connection()->getDatabaseName());

$product = DB::table('produk')
    ->where('id_produk', $productId)
    ->first(['id_produk', 'nama_produk', 'stok']);

if (!$product) {
    line('Product not found. Abort.');
    exit(1);
}

section('Current Product State');
line('Nama: ' . (string) $product->nama_produk);
line('produk.stok: ' . (int) $product->stok);

$existingManualSo = findMatchingManualSo($productId, $targetWaktu, $targetKeterangan);

section('Target Manual SO Record');
if ($existingManualSo) {
    line('Record already exists:');
    line(sprintf(
        '  id=%d | waktu=%s | awal=%d | +%d | -%d | sisa=%d | %s',
        (int) $existingManualSo->id_rekaman_stok,
        (string) $existingManualSo->waktu,
        (int) $existingManualSo->stok_awal,
        (int) $existingManualSo->stok_masuk,
        (int) $existingManualSo->stok_keluar,
        (int) $existingManualSo->stok_sisa,
        (string) $existingManualSo->keterangan
    ));
} else {
    line('Record is missing and eligible for repair insertion.');
    line(sprintf(
        '  waktu=%s | awal=%d | +%d | -%d | sisa=%d | %s',
        $targetWaktu,
        $targetStokAwal,
        $targetStokMasuk,
        $targetStokKeluar,
        $targetStokSisa,
        $targetKeterangan
    ));
}

$reflowService = app(BaselineStockReflowService::class);
$beforeLedger = $reflowService->previewProductLedgers([$productId], Carbon::now()->format('Y-m-d H:i:s'))[$productId] ?? null;

section('Preview Before Repair');
if ($beforeLedger === null) {
    line('Ledger unavailable.');
} else {
    line('seed_source: ' . (string) ($beforeLedger['seed_source'] ?? '-'));
    line('final_stock: ' . (int) ($beforeLedger['final_stock'] ?? 0));
    foreach (array_slice($beforeLedger['rows'] ?? [], 0, 6) as $row) {
        line(sprintf(
            '  %s | awal=%d | +%d | -%d | sisa=%d | %s',
            (string) ($row['waktu'] ?? '-'),
            (int) ($row['stok_awal'] ?? 0),
            (int) ($row['stok_masuk'] ?? 0),
            (int) ($row['stok_keluar'] ?? 0),
            (int) ($row['stok_sisa'] ?? 0),
            (string) ($row['keterangan'] ?? '-')
        ));
    }
}

if (!$shouldExecute) {
    section('Dry-run Result');
    line('No database changes were made.');
    line('To execute the repair on production, run:');
    line('  php repair_voltaren_missing_so.php --execute');
    exit(0);
}

DB::beginTransaction();

try {
    $existingManualSo = findMatchingManualSo($productId, $targetWaktu, $targetKeterangan);

    if (!$existingManualSo) {
        $now = Carbon::now()->format('Y-m-d H:i:s');
        DB::table('rekaman_stoks')->insert([
            'id_produk' => $productId,
            'id_penjualan' => null,
            'id_pembelian' => null,
            'waktu' => $targetWaktu,
            'stok_awal' => $targetStokAwal,
            'stok_masuk' => $targetStokMasuk,
            'stok_keluar' => $targetStokKeluar,
            'stok_sisa' => $targetStokSisa,
            'keterangan' => $targetKeterangan,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        line('Inserted missing manual SO record.');
    } else {
        line('Manual SO record already existed; skipping insert.');
    }

    $summary = $reflowService->rebuildProducts([$productId], Carbon::now()->format('Y-m-d H:i:s'));
    DB::commit();

    section('Repair Result');
    line('Rebuild completed.');
    line('products_rebuilt: ' . (int) ($summary['products_rebuilt'] ?? 0));
    $finalRow = collect($summary['final_stock_by_product'] ?? [])->firstWhere('id_produk', $productId);
    if ($finalRow) {
        line('final_stock: ' . (int) ($finalRow['final_stock'] ?? 0));
        line('applied_stock: ' . (int) ($finalRow['applied_stock'] ?? 0));
    }

    $afterRecord = findMatchingManualSo($productId, $targetWaktu, $targetKeterangan);
    if ($afterRecord) {
        line(sprintf(
            'manual_so_after: id=%d | waktu=%s | awal=%d | +%d | -%d | sisa=%d',
            (int) $afterRecord->id_rekaman_stok,
            (string) $afterRecord->waktu,
            (int) $afterRecord->stok_awal,
            (int) $afterRecord->stok_masuk,
            (int) $afterRecord->stok_keluar,
            (int) $afterRecord->stok_sisa
        ));
    }
} catch (Throwable $e) {
    DB::rollBack();
    section('Repair Failed');
    line($e->getMessage());
    exit(1);
}
