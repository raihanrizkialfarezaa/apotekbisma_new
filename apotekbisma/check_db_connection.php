<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== DATABASE CONNECTION INFO ===\n";
echo "DB Host: " . config('database.connections.mysql.host') . "\n";
echo "DB Name: " . config('database.connections.mysql.database') . "\n";
echo "DB User: " . config('database.connections.mysql.username') . "\n";
echo "\n";

// Verify we're connected to the right database
use Illuminate\Support\Facades\DB;

$testRecord = DB::table('rekaman_stoks')
    ->where('id_rekaman_stok', 176469)
    ->first();

if ($testRecord) {
    echo "=== STOCK OPNAME RECORD 176469 EXISTS ===\n";
    echo "  waktu: " . $testRecord->waktu . "\n";
    echo "  keterangan: " . $testRecord->keterangan . "\n";
    echo "  stok_sisa: " . $testRecord->stok_sisa . "\n";
} else {
    echo "ERROR: Stock Opname record 176469 NOT FOUND!\n";
}

// Count all Stock Opname records
$soCount = DB::table('rekaman_stoks')
    ->where('waktu', '2025-12-31 23:59:59')
    ->where('keterangan', 'LIKE', '%Stock Opname%')
    ->count();

echo "\nTotal Stock Opname records at 2025-12-31 23:59:59: " . $soCount . "\n";
