<?php
require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

session()->forget('id_penjualan');

$produk = \App\Models\Produk::find(2);
$request = new \Illuminate\Http\Request();
$request->merge(['id_produk' => $produk->id_produk, 'waktu' => date('Y-m-d')]);

$controller = new \App\Http\Controllers\PenjualanDetailController();
$user = \App\Models\User::find(1);
if ($user) {
    auth()->loginUsingId($user->id);
}
$response = $controller->store($request);
if ($response instanceof \Illuminate\Http\JsonResponse) {
    $data = $response->getData(true);
    var_dump($data);
} else {
    var_dump($response);
}
