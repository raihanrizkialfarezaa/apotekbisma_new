<?php

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$cutoffDate = '2025-12-31 23:59:59';

echo "# SANIZE GHOST ADJUSTMENTS REPORT\n\n";

// 1. Find Adjustments that claim to be for Dec 31 2025, but exist in 2026
$ghosts = DB::table('rekaman_stoks')
    ->where('keterangan', 'like', '%Stock Opname 31 Desember 2025%')
    ->where('waktu', '>', '2026-01-01 00:00:00')
    ->get();

echo "Found " . $ghosts->count() . " ghost adjustments from v3 script (dated in 2026).\n";

$deletedCount = 0;
foreach ($ghosts as $g) {
    echo "- Deleting Ghost ID {$g->id_rekaman_stok} (Product {$g->id_produk}): {$g->keterangan} at {$g->waktu}\n";
    DB::table('rekaman_stoks')->where('id_rekaman_stok', $g->id_rekaman_stok)->delete();
    $deletedCount++;
}

echo "\nTotal Cleaned: $deletedCount\n";

// 2. Also cleaning any duplicate agent adjustments if any remaining
$agentGhosts = DB::table('rekaman_stoks')
    ->where('keterangan', 'ADJUSTMENT_BY_AGENT_CSV_BASELINE')
    ->where('waktu', '>', '2026-01-01 00:00:00')
    ->delete(); // Should be 0 given my logic, but safety first

echo "Cleaned stray agent markers in 2026: $agentGhosts\n";
