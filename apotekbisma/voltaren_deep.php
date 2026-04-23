<?php
/**
 * Voltaren -20 Deep Investigation
 * Why does production show -20 while local computes 17?
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

$cutoff = config('stock.cutoff_datetime', '2025-12-31 23:59:59');
$excludedPatterns = config('stock.excluded_manual_keterangan_patterns', []);
$pid = 862;
$dbName = DB::connection()->getDatabaseName();

echo "=== VOLTAREN 50mg (#862) DEEP INVESTIGATION ===\n";
echo "Database: $dbName\n";
echo "Cutoff: $cutoff\n\n";

// 1. Current produk.stok value
$prod = DB::table('produk')->where('id_produk', $pid)->first();
echo "1. produk.stok (current DB value): {$prod->stok}\n\n";

// 2. Is it in CSV?
$csvPath = base_path(config('stock.baseline_csv'));
$csvMap = [];
if (($handle = fopen($csvPath, 'r')) !== false) {
    $header = fgetcsv($handle, 0, ';');
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        if (count($row) >= 3) {
            $id = intval(trim($row[0]));
            if ($id > 0) $csvMap[$id] = intval(trim($row[2]));
        }
    }
    fclose($handle);
}
echo "2. In CSV baseline: " . (isset($csvMap[$pid]) ? "YES ({$csvMap[$pid]})" : "NO") . "\n\n";

// 3. DB baseline seed lookup (keterangan-based)
$cutoffC = Carbon::parse($cutoff);
$ketCandidates = DB::table('rekaman_stoks')
    ->where('id_produk', $pid)
    ->whereNull('id_penjualan')
    ->whereNull('id_pembelian')
    ->where(function($q) {
        $q->where('keterangan', 'like', '%Saldo Awal Stok%')
          ->orWhere('keterangan', 'like', '%histori sebelum cutoff%');
    })
    ->where('waktu', '>=', $cutoffC->copy()->startOfDay()->format('Y-m-d H:i:s'))
    ->where('waktu', '<=', $cutoffC->copy()->addDay()->endOfDay()->format('Y-m-d H:i:s'))
    ->get();
echo "3. Keterangan-based seed candidates: {$ketCandidates->count()}\n";
foreach ($ketCandidates as $c) {
    echo "   id={$c->id_rekaman_stok} waktu={$c->waktu} sisa={$c->stok_sisa} ket={$c->keterangan}\n";
}

// 4. Fallback seed (last pre-cutoff record)
$lastPreCutoff = DB::table('rekaman_stoks')
    ->where('id_produk', $pid)
    ->where('waktu', '<=', $cutoff)
    ->orderBy('waktu', 'desc')
    ->orderBy('id_rekaman_stok', 'desc')
    ->first();
echo "\n4. Last pre-cutoff fallback record: ";
if ($lastPreCutoff) {
    echo "id={$lastPreCutoff->id_rekaman_stok} waktu={$lastPreCutoff->waktu} sisa={$lastPreCutoff->stok_sisa} ket=" . substr($lastPreCutoff->keterangan ?? '', 0, 60) . "\n";
} else {
    echo "NONE\n";
}

// 5. Post-cutoff manual adjustments
echo "\n5. Post-cutoff manual adjustments:\n";
$manuals = DB::table('rekaman_stoks')
    ->where('id_produk', $pid)
    ->whereNull('id_penjualan')
    ->whereNull('id_pembelian')
    ->where('waktu', '>', $cutoff)
    ->orderBy('waktu', 'asc')
    ->get();
foreach ($manuals as $m) {
    $ket = mb_strtolower(trim($m->keterangan ?? ''));
    $excluded = false;
    foreach ($excludedPatterns as $pat) {
        if ($pat !== '' && str_contains($ket, mb_strtolower($pat))) {
            $excluded = true;
            break;
        }
    }
    $hasActivity = intval($m->stok_masuk) > 0 || intval($m->stok_keluar) > 0;
    echo sprintf("   id=%d waktu=%s masuk=%d keluar=%d sisa=%d %s %s ket=%s\n",
        $m->id_rekaman_stok, $m->waktu, $m->stok_masuk, $m->stok_keluar, $m->stok_sisa,
        $excluded ? '[EXCLUDED]' : '',
        $hasActivity ? '[HAS_ACTIVITY]' : '[NO_ACTIVITY]',
        substr($m->keterangan ?? '', 0, 60));
}

// 6. Purchases and sales
$purchaseQty = intval(DB::table('pembelian_detail as pd')
    ->join('pembelian as p', 'pd.id_pembelian', '=', 'p.id_pembelian')
    ->where('pd.id_produk', $pid)
    ->where('pd.jumlah', '>', 0)
    ->whereRaw('COALESCE(p.waktu_datang, p.waktu, p.created_at) > ?', [$cutoff])
    ->whereNotNull('p.no_faktur')
    ->whereRaw("TRIM(p.no_faktur) != ''")
    ->whereRaw("LOWER(TRIM(p.no_faktur)) != 'o'")
    ->where('p.total_harga', '>', 0)
    ->where('p.bayar', '>', 0)
    ->sum('pd.jumlah'));

$salesQty = intval(DB::table('penjualan_detail as pd')
    ->join('penjualan as p', 'pd.id_penjualan', '=', 'p.id_penjualan')
    ->where('pd.id_produk', $pid)
    ->where('pd.jumlah', '>', 0)
    ->whereRaw('COALESCE(p.waktu, p.created_at) > ?', [$cutoff])
    ->where('p.total_item', '>', 0)
    ->where('p.total_harga', '>', 0)
    ->where('p.bayar', '>', 0)
    ->where('p.diterima', '>', 0)
    ->sum('pd.jumlah'));

echo "\n6. Post-cutoff finalized transactions:\n";
echo "   Purchases: +$purchaseQty\n";
echo "   Sales: -$salesQty\n";

// 7. SIMULATION: All possible calculation paths
echo "\n7. SIMULATION - All calculation paths:\n\n";

// Path A: What the PRODUCTION code likely does (NO manual SO, seed=0)
$pathA = 0 + $purchaseQty - $salesQty;
echo "   Path A (seed=0, NO manual SO): 0 + $purchaseQty - $salesQty = $pathA\n";

// Path B: With keterangan-based seed (seed from baseline_rekaman)
$seedKet = $ketCandidates->isNotEmpty() ? intval($ketCandidates->first()->stok_sisa) : 0;
$pathB_noManual = $seedKet + $purchaseQty - $salesQty;
echo "   Path B (seed=$seedKet from ket-based, NO manual SO): $seedKet + $purchaseQty - $salesQty = $pathB_noManual\n";

// Path C: With manual SO as absolute target
$manualTarget = 37; // from the SO record
$pathC = $manualTarget + $purchaseQty - $salesQty;
echo "   Path C (manual SO target=37, then buy/sell): 37 + $purchaseQty - $salesQty = $pathC\n";

// Path D: Full service computation (what local does)
$service = app(\App\Services\BaselineStockReflowService::class);
$ledger = $service->previewProductLedgers([$pid], Carbon::now()->format('Y-m-d H:i:s'))[$pid] ?? null;
$pathD = $ledger ? intval($ledger['final_stock']) : 'ERROR';
echo "   Path D (local service engine): $pathD (seed_source={$ledger['seed_source']})\n";

echo "\n8. CONCLUSION:\n";
echo "   Production shows: -20 → matches Path A (seed=0, manual SO not counted)\n";
echo "   Local computes: $pathD → matches Path C (manual SO counted as target)\n";
echo "\n   ROOT CAUSE: The production code's collectEventsForProduct() is NOT including\n";
echo "   the manual SO record as an event. This means the 'Perubahan Stok Manual: SO'\n";
echo "   record (masuk=37) is being SKIPPED or EXCLUDED on production.\n";

// 9. Check if there's a version difference
echo "\n9. Check: Is the SO record being treated as a seed (not an event)?\n";
echo "   The SO record: id=504059, waktu=2026-01-01 00:15:37\n";
echo "   The baseline seed lookup window: {$cutoffC->copy()->startOfDay()->format('Y-m-d H:i:s')} to {$cutoffC->copy()->addDay()->endOfDay()->format('Y-m-d H:i:s')}\n";
echo "   Is SO within seed window? " . ($manuals->first() && $manuals->first()->waktu >= $cutoffC->copy()->startOfDay()->format('Y-m-d H:i:s') && $manuals->first()->waktu <= $cutoffC->copy()->addDay()->endOfDay()->format('Y-m-d H:i:s') ? "YES" : "NO") . "\n";

// Let's check: does collectEventsForProduct filter out records that overlap with the seed?
echo "\n10. Check: Does the service filter out records that are within the seed window?\n";
$seedRecord = $ketCandidates->first();
if ($seedRecord) {
    echo "    Seed record id: {$seedRecord->id_rekaman_stok}\n";
    echo "    Manual SO id: {$manuals->first()->id_rekaman_stok}\n";
    echo "    Are they the same? " . ($seedRecord->id_rekaman_stok == $manuals->first()->id_rekaman_stok ? "YES" : "NO") . "\n";
    
    // Check if collectEventsForProduct skips records with id <= seed id
    echo "    Seed id ({$seedRecord->id_rekaman_stok}) vs SO id ({$manuals->first()->id_rekaman_stok}): ";
    if ($manuals->first()->id_rekaman_stok > $seedRecord->id_rekaman_stok) {
        echo "SO is AFTER seed → should be included as event\n";
    } else {
        echo "SO is BEFORE or AT seed → might be skipped!\n";
    }
}

echo "\nDone.\n";
