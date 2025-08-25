<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;

$produk = Produk::find(2);
echo "Stok produk ID 2: " . $produk->stok . PHP_EOL;
