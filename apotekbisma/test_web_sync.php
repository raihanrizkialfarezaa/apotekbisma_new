<?php

require 'vendor/autoload.php';

// Test web interface dengan HTTP client
use GuzzleHttp\Client;

$client = new Client();

echo "Testing web interface stock sync...\n";

// Check current inconsistent data before sync
echo "Before sync:\n";
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$count = DB::table('rekaman_stoks as rs')
    ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
    ->where(function($query) {
        $query->whereRaw('rs.stok_awal != p.stok')
              ->orWhereRaw('rs.stok_sisa != p.stok');
    })
    ->whereIn('rs.id_rekaman_stok', function($query) {
        $query->select(DB::raw('MAX(id_rekaman_stok)'))
              ->from('rekaman_stoks')
              ->groupBy('id_produk');
    })
    ->count();

echo "Found $count inconsistent records\n\n";

// Now test web interface
try {
    echo "Sending POST request to web interface...\n";
    $response = $client->post('http://127.0.0.1:8000/test-web-sync', [
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'application/json'
        ],
        'json' => [
            'action' => 'sync'
        ],
        'verify' => false,
        'timeout' => 30
    ]);

    $statusCode = $response->getStatusCode();
    $body = $response->getBody()->getContents();
    
    echo "Response Status: $statusCode\n";
    echo "Response Body: $body\n\n";
    
    // Check again after sync
    echo "After sync:\n";
    $countAfter = DB::table('rekaman_stoks as rs')
        ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
        ->where(function($query) {
            $query->whereRaw('rs.stok_awal != p.stok')
                  ->orWhereRaw('rs.stok_sisa != p.stok');
        })
        ->whereIn('rs.id_rekaman_stok', function($query) {
            $query->select(DB::raw('MAX(id_rekaman_stok)'))
                  ->from('rekaman_stoks')
                  ->groupBy('id_produk');
        })
        ->count();

    echo "Found $countAfter inconsistent records\n";
    echo "Fixed: " . ($count - $countAfter) . " records\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
