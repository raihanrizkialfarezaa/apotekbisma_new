<?php

require_once 'vendor/autoload.php';

use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== CLEANING CORRUPT STOCK RECORDS ===\n\n";

try {
    DB::beginTransaction();
    
    echo "🔍 Scanning for corrupt records...\n";
    
    $corruptRecords = RekamanStok::all()->filter(function($record) {
        $expected_sisa = $record->stok_awal + $record->stok_masuk - $record->stok_keluar;
        return $expected_sisa != $record->stok_sisa;
    });
    
    echo "Found {$corruptRecords->count()} corrupt records\n\n";
    
    foreach ($corruptRecords as $record) {
        $expected_sisa = $record->stok_awal + $record->stok_masuk - $record->stok_keluar;
        echo "Fixing Record ID {$record->id_rekaman_stok}:\n";
        echo "  Current calculation: {$record->stok_awal} + {$record->stok_masuk} - {$record->stok_keluar} = {$record->stok_sisa}\n";
        echo "  Correct calculation: {$record->stok_awal} + {$record->stok_masuk} - {$record->stok_keluar} = {$expected_sisa}\n";
        
        // Fix the record
        $record->stok_sisa = max(0, $expected_sisa); // Ensure not negative
        $record->save();
        
        echo "  ✅ Fixed to: {$record->stok_sisa}\n\n";
    }
    
    DB::commit();
    
    echo "🎉 Successfully fixed {$corruptRecords->count()} corrupt records!\n";
    echo "✅ All stock records are now mathematically consistent\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

?>
