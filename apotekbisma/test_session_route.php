<?php

use Illuminate\Support\Facades\Route;

// Test route untuk memverifikasi session id_penjualan
Route::get('/test-session-penjualan', function () {
    $sessionId = session('id_penjualan');
    $data = [
        'session_id_penjualan' => $sessionId,
        'session_exists' => $sessionId ? 'Ya' : 'Tidak',
        'all_session_data' => session()->all()
    ];
    
    return response()->json($data);
})->middleware('auth');
