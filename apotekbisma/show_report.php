<?php
$d = json_decode(file_get_contents('stock_fix_report_2026-01-09_172812.json'), true);
echo "SUMMARY:\n";
echo "Total products: " . $d['summary']['total_products'] . "\n";
echo "Fixed: " . $d['summary']['fixed'] . "\n";
echo "Skipped: " . $d['summary']['skipped'] . "\n";
echo "Errors: " . $d['summary']['errors'] . "\n\n";

echo "TOP 10 Products with most fixes:\n";
$fixes = $d['fixes'];
uasort($fixes, function($a, $b) { return $b['updates_count'] - $a['updates_count']; });
$i = 0;
foreach ($fixes as $id => $f) {
    if ($i >= 10) break;
    echo "[$id] {$f['nama']} - {$f['updates_count']} records, stok: {$f['old_produk_stok']}->{$f['new_produk_stok']}\n";
    $i++;
}

echo "\n\nSearching for DEMACOLIN TAB and AMOXICILIN HJ:\n";
if (isset($fixes[204])) {
    $f = $fixes[204];
    echo "[204] DEMACOLIN TAB - {$f['updates_count']} records, stok: {$f['old_produk_stok']}->{$f['new_produk_stok']}\n";
    echo "  Opname stock: {$f['opname_stock']}\n";
}
if (isset($fixes[994])) {
    $f = $fixes[994];
    echo "[994] AMOXICILIN HJ - {$f['updates_count']} records, stok: {$f['old_produk_stok']}->{$f['new_produk_stok']}\n";
    echo "  Opname stock: {$f['opname_stock']}\n";
}
