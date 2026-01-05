<?php
$f = __DIR__ . DIRECTORY_SEPARATOR . 'stock_updates_2025-12-31.csv';
if (!file_exists($f)) { echo "MISSING\n"; exit(1); }
$h = fopen($f, 'r');
$hdr = fgetcsv($h);
$found = [];
$targets = ['33','34'];
while (($row = fgetcsv($h)) !== false) {
    $id = (string)$row[0];
    if (in_array($id, $targets, true)) {
        $found[$id][] = $row;
    }
}
foreach ($targets as $t) {
    if (!isset($found[$t])) {
        echo "ID {$t}: NOT FOUND\n";
    } else {
        foreach ($found[$t] as $r) {
            echo "ID {$t}: FOUND | nama={$r[1]} | count={$r[2]} | first={$r[3]} | last={$r[4]} | keterangan={$r[5]}\n";
        }
    }
}
