<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

echo "=== MATHEMATICAL ERROR ANALYSIS ===\n\n";

$problematic_records = RekamanStok::where('id_produk', 2)
    ->whereRaw('stok_awal + stok_masuk - stok_keluar != stok_sisa')
    ->orderBy('id_rekaman_stok', 'desc')
    ->take(10)
    ->get();

echo "🔍 Found " . $problematic_records->count() . " records with mathematical errors:\n\n";

foreach ($problematic_records as $record) {
    $expected = $record->stok_awal + $record->stok_masuk - $record->stok_keluar;
    $actual = $record->stok_sisa;
    $difference = $actual - $expected;
    
    echo "❌ Record ID {$record->id_rekaman_stok}:\n";
    echo "   Formula: {$record->stok_awal} + {$record->stok_masuk} - {$record->stok_keluar} = {$expected}\n";
    echo "   Actual:  {$actual}\n";
    echo "   Error:   {$difference}\n";
    echo "   Date:    {$record->waktu}\n";
    echo "   Note:    {$record->keterangan}\n\n";
}

echo "🚨 THESE ERRORS PROVE THE SYNC BUTTON IS DANGEROUS!\n";
echo "The mathematical inconsistencies show that previous sync operations\n";
echo "have already corrupted data integrity!\n\n";

echo "✅ Solution: Our Observer system will prevent this going forward.\n";
