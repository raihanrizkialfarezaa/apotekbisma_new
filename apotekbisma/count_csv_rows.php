<?php
$f = __DIR__ . DIRECTORY_SEPARATOR . 'stock_updates_2025-12-31.csv';
if (!file_exists($f)) { echo "MISSING\n"; exit(1); }
$h = fopen($f, 'r');
$hdr = fgetcsv($h);
$c = 0;
while (fgetcsv($h) !== false) $c++;
echo $c . PHP_EOL;
