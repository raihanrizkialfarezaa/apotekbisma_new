<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PenjualanDetailController;

// Test route untuk simulasi transaksi
Route::post('/test-add-product', function () {
    $request = new \Illuminate\Http\Request([
        'id_produk' => 2, // ACETHYLESISTEIN 200mg
        'id_penjualan' => null // Simulasi transaksi baru
    ]);
    
    $controller = new PenjualanDetailController();
    $response = $controller->store($request);
    
    return $response;
})->middleware('auth');
