<?php
require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Full sequence check for Product 63 ===\n\n";

echo "Getting ALL records sorted by waktu ASC (how backend assigns row numbers):\n\n";

$recs = DB::table('rekaman_stoks')
    ->where('id_produk', 63)
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

$rowNum = 1;
$dec31Found = false;

foreach ($recs as $r) {
    if (str_contains($r->waktu, '2025-12-31') || 
        str_contains($r->waktu, '2025-12-19') ||
        str_contains($r->waktu, '2026-01-01') ||
        str_contains($r->waktu, '2026-01-02')) {
        
        $date = date('d M Y H:i', strtotime($r->waktu));
        echo "Row $rowNum | ID: $r->id_rekaman_stok | Date: $date\n";
        
        if (str_contains($r->waktu, '2025-12-31')) {
            $dec31Found = true;
            echo "  *** DEC 31 FOUND AT ROW $rowNum ***\n";
        }
    }
    $rowNum++;
}

echo "\nTotal records: " . ($rowNum - 1) . "\n";
echo "Dec 31 found: " . ($dec31Found ? "YES" : "NO") . "\n";
