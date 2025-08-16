<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;

$client = new Client();

echo "Testing web interface with corrected logic...\n";

try {
    echo "Sending POST request for actual sync (dry_run=0)...\n";
    $response = $client->post('http://127.0.0.1:8000/stock-sync/perform', [
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'application/json'
        ],
        'form_params' => [
            '_token' => 'test-token',
            'dry_run' => '0'  // This should trigger actual sync
        ],
        'verify' => false,
        'timeout' => 30,
        'http_errors' => false
    ]);

    $statusCode = $response->getStatusCode();
    $body = $response->getBody()->getContents();
    
    echo "Response Status: $statusCode\n";
    echo "Response Body: $body\n\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
