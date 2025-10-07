<?php
require __DIR__ . '/../vendor/autoload.php';

// Boot the framework
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use Illuminate\Http\Request;
use App\Http\Controllers\KartuStokController;

$produk = Produk::first();
if (!$produk) {
    echo "NO_PRODUCT\n";
    exit(0);
}

$req = Request::create('/', 'GET', []);
$controller = new KartuStokController();
$response = $controller->data($produk->id_produk, $req);
// $response is a JsonResponse or Response; getContent() to inspect
if (is_object($response) && method_exists($response, 'getContent')) {
    echo $response->getContent() . "\n";
} else {
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
