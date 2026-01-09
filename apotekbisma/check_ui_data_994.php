<?php
/**
 * Check what data is actually being sent to the UI for product 994
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Http\Controllers\KartuStokController;
use Illuminate\Http\Request;

echo "=======================================================\n";
echo "CHECKING UI DATA FOR PRODUCT 994 (AMOXICILIN 500mg HJ)\n";
echo "=======================================================\n\n";

$controller = new KartuStokController();
$request = new Request();
$request->merge(['date_filter' => 'all']);

// Call the same method the UI uses
$data = $controller->getDataFiltered(994, $request);

echo "Total records returned to UI: " . count($data) . "\n\n";

// Check for records around cutoff
echo "RECORDS AROUND CUTOFF (sorted by waktu_raw DESC):\n";
echo str_repeat("-", 140) . "\n";

// Sort by waktu_raw DESC
usort($data, function($a, $b) {
    $timeA = strtotime($a['waktu_raw'] ?? '1970-01-01');
    $timeB = strtotime($b['waktu_raw'] ?? '1970-01-01');
    return $timeB - $timeA;
});

$count = 0;
foreach ($data as $row) {
    $waktu = $row['waktu_raw'] ?? '';
    
    // Only show records around cutoff
    if ($waktu >= '2025-12-25' && $waktu <= '2026-01-05') {
        $count++;
        $masuk = $row['stok_masuk'] ?? '-';
        $keluar = $row['stok_keluar'] ?? '-';
        $sisa = $row['stok_sisa'] ?? '-';
        $ket = substr($row['keterangan'] ?? '', 0, 60);
        
        echo "  waktu_raw: {$waktu}\n";
        echo "  tanggal: " . ($row['tanggal'] ?? 'N/A') . "\n";
        echo "  masuk: {$masuk}, keluar: {$keluar}, sisa: {$sisa}\n";
        echo "  keterangan: {$ket}\n";
        echo str_repeat("-", 80) . "\n";
    }
}

echo "\nRecords around cutoff: {$count}\n\n";

// Check if cutoff record exists
$hasOpname = false;
foreach ($data as $row) {
    $waktuRaw = (string) ($row['waktu_raw'] ?? '');
    $ket = (string) ($row['keterangan'] ?? '');
    if (strpos($waktuRaw, '2025-12-31') !== false && strpos($waktuRaw, '23:59:59') !== false) {
        $hasOpname = true;
        echo "STOCK OPNAME RECORD FOUND IN UI DATA:\n";
        print_r($row);
        break;
    }

    // Fallback: match by description text
    if (!$hasOpname && stripos($ket, 'stock opname') !== false && strpos($waktuRaw, '2025-12-31') !== false) {
        $hasOpname = true;
        echo "STOCK OPNAME RECORD FOUND IN UI DATA (matched by keterangan):\n";
        print_r($row);
        break;
    }
}

if (!$hasOpname) {
    echo "!! WARNING: Stock Opname record (2025-12-31 23:59:59) NOT FOUND in UI data !!\n";
}
