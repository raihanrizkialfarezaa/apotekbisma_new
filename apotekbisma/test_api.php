<?php

echo "Testing API endpoint...\n";

$url = 'http://127.0.0.1:8000/pembelian_detail/produk-data';
$context = stream_context_create([
    'http' => [
        'timeout' => 5,
        'header' => "Accept: application/json\r\n"
    ]
]);

$response = @file_get_contents($url, false, $context);

if ($response !== false) {
    $data = json_decode($response, true);
    echo "Success! Found " . count($data) . " products\n";
    
    if (count($data) > 0) {
        echo "First product: " . $data[0]['nama_produk'] . " - Stock: " . $data[0]['stok'] . "\n";
        echo "API endpoint is working correctly!\n";
    }
} else {
    echo "Failed to connect to server. Make sure Laravel server is running.\n";
}
