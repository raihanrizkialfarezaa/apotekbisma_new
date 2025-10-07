<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\KartuStokController;
use App\Models\Produk;
use Illuminate\Http\Request;

$produk = Produk::first();
if (!$produk) { echo "NO_PRODUCT\n"; exit(0); }

$controller = new KartuStokController();
$req = Request::create('/', 'GET', ['date_filter' => 'month']);
$response = $controller->data($produk->id_produk, $req);
$content = $response->getContent();
$json = json_decode($content, true);
if (!$json || !isset($json['data'])) { echo "NO_DATA or parse error\n"; echo $content; exit(1); }

$missing = [];
foreach ($json['data'] as $i => $row) {
    $has_expired = array_key_exists('expired_date', $row);
    $has_supplier = array_key_exists('supplier', $row);
    if (!$has_expired || !$has_supplier) {
        $missing[] = ['index' => $i, 'has_expired' => $has_expired, 'has_supplier' => $has_supplier, 'row_keys' => array_keys($row)];
    }
}

if (empty($missing)) {
    echo "ALL_ROWS_HAVE_KEYS\n";
    exit(0);
}

echo "MISSING ROWS:\n" . json_encode($missing, JSON_PRETTY_PRINT) . "\n";
exit(0);
