<?php
$f = __DIR__ . DIRECTORY_SEPARATOR . 'stock_updates_2025-12-31.csv';
if (!file_exists($f)) { echo "MISSING\n"; exit(1); }
$h = fopen($f, 'r');
$hdr = fgetcsv($h);
$bad = 0;
$examples = [];
while (($row = fgetcsv($h)) !== false) {
    $first = $row[3] ?? '';
    $last = $row[4] ?? '';
    if (substr($first, 0, 10) !== '2025-12-31' || substr($last, 0, 10) !== '2025-12-31') {
        $bad++;
        if (count($examples) < 5) $examples[] = $row;
    }
}
if ($bad) {
    echo "MISMATCH:$bad\n";
    foreach ($examples as $e) echo implode('|', $e) . "\n";
} else {
    echo "OK: all rows are DATE=2025-12-31\n";
}
