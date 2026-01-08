<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

// Replicate controller logic exactly
$id = 63;
$controller = new \App\Http\Controllers\KartuStokController();
$request = new Request();
$request->merge(['order' => [['column' => 1, 'dir' => 'desc']]]); 

// Manually call getDataFiltered logic since we can't call private/protected method easily 
// and we want to see the array BEFORE Yajra processes it

// 1. Get Raw Data
$data = $controller->getDataFiltered($id, $request);

// 2. Sort Logic (Copied from Controller)
$sortKey = 'waktu_raw';
$orderDir = 'desc';

usort($data, function($a, $b) use ($sortKey, $orderDir) {
    $valA = $a[$sortKey] ?? '';
    $valB = $b[$sortKey] ?? '';
    
    if ($sortKey === 'waktu_raw') {
        $valA = strtotime($valA) ?: 0;
        $valB = strtotime($valB) ?: 0;
    }
    
    $cmp = $valA <=> $valB;
    return $orderDir === 'desc' ? -$cmp : $cmp;
});

// 3. Renumber Logic
$no = 1;
foreach ($data as &$row) {
    if (isset($row['DT_RowIndex']) && $row['DT_RowIndex'] !== '') {
        $row['DT_RowIndex'] = $no++;
    }
}

// 4. Output first 10 rows to see what fits in "2, 3, 4, 7" gap 
// Note: User screenshot shows Row 2 (ID 1052?), Row 3 (ID 1053?), Row 4 (ID 1056), then Row 7 (ID 989)
// Let's identify who is at Index 4 (Row 5) and Index 5 (Row 6)

echo "=== FIRST 10 ROWS AFTER DESC SORT ===\n";
for ($i = 0; $i < 10; $i++) {
    if (!isset($data[$i])) break;
    $r = $data[$i];
    echo "Row " . $r['DT_RowIndex'] . " | ";
    echo "Date: " . strip_tags($r['tanggal']) . " | ";
    echo "WaktuRaw: " . $r['waktu_raw'] . " | ";
    echo "Ket: " . strip_tags($r['keterangan']) . "\n";
}
