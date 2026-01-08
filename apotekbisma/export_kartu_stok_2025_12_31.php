<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '1024M');
set_time_limit(600);

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

// Export kartu stok: latest rekaman ON DATE 2025-12-31 for ALL products
$DATE = '2025-12-31';

echo "Membuat export kartu stok untuk tanggal {$DATE}...\n";

try {
    // get column definitions so we can export full structure with prefixed names
    $prodCols = DB::select("DESCRIBE produk");
    $rekCols = DB::select("DESCRIBE rekaman_stoks");

    $prodSelect = [];
    $prodHeader = [];
    foreach ($prodCols as $c) {
        $field = $c->Field;
        $prodSelect[] = "p.`$field` as `produk_$field`";
        $prodHeader[] = "produk_$field";
    }

    $rekSelect = [];
    $rekHeader = [];
    foreach ($rekCols as $c) {
        $field = $c->Field;
        $rekSelect[] = "rs.`$field` as `rekaman_$field`";
        $rekHeader[] = "rekaman_$field";
    }

    $selectClause = implode(', ', array_merge($prodSelect, $rekSelect));

    $sql = "SELECT {$selectClause} FROM produk p
        LEFT JOIN rekaman_stoks rs ON rs.id_rekaman_stok = (
            SELECT id_rekaman_stok FROM rekaman_stoks r2
            WHERE r2.id_produk = p.id_produk AND DATE(r2.waktu) = ?
            ORDER BY r2.waktu DESC, r2.id_rekaman_stok DESC
            LIMIT 1
        )
        ORDER BY p.id_produk ASC";

    $rows = DB::select($sql, [$DATE]);

    $outFile = __DIR__ . '/kartu_stok_' . str_replace('-', '', $DATE) . '.csv';
    $fp = fopen($outFile, 'w');
    if ($fp === false) throw new Exception("Tidak bisa menulis file output: {$outFile}");

    // write UTF-8 BOM for Excel compatibility
    fwrite($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // headers
    $headers = array_merge($prodHeader, $rekHeader);
    fputcsv($fp, $headers);

    foreach ($rows as $r) {
        $line = [];
        foreach ($prodHeader as $h) {
            $line[] = property_exists($r, $h) ? $r->{$h} : null;
        }
        foreach ($rekHeader as $h) {
            $line[] = property_exists($r, $h) ? $r->{$h} : null;
        }
        fputcsv($fp, $line);
    }

    fclose($fp);

    echo "Selesai. File CSV dibuat: {$outFile}\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Jumlah baris (termasuk header): " . (count($rows) + 1) . "\n";

?>
