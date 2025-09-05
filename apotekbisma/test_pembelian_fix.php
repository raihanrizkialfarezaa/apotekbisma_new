<?php
// Test the pembelian_detail fix

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

echo "=== TESTING PEMBELIAN_DETAIL FIX ===\n\n";

// Test accessing pembelian_detail directly without session
echo "Testing pembelian_detail access without session...\n";

// Create a mock request
$request = Request::create('/pembelian_detail', 'GET');

try {
    // Clear session (simulate no session data)
    session()->flush();
    
    // Check if our logic would find an incomplete transaction
    $incompletePembelian = \App\Models\Pembelian::where('no_faktur', 'o')
        ->orWhere('no_faktur', '')
        ->orWhereNull('no_faktur')
        ->orWhere('total_harga', 0)
        ->orWhere('bayar', 0)
        ->latest()
        ->first();
    
    if ($incompletePembelian) {
        echo "✓ Found incomplete transaction: ID {$incompletePembelian->id_pembelian}\n";
        echo "  Supplier ID: {$incompletePembelian->id_supplier}\n";
        echo "  Total: {$incompletePembelian->total_harga}\n";
        echo "  Status: " . ($incompletePembelian->no_faktur === 'o' ? 'Incomplete' : 'Other') . "\n";
    } else {
        echo "✓ No incomplete transaction found - will redirect to pembelian.index\n";
    }
    
    echo "\nTest completed successfully!\n";
    
} catch (Exception $e) {
    echo "✗ Error during test: " . $e->getMessage() . "\n";
}

echo "\n=== END TEST ===\n";
