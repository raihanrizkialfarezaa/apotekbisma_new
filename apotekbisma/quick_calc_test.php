<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== QUICK CALCULATION VERIFICATION ===\n\n";

$controller = new \App\Http\Controllers\PenjualanDetailController();

echo "Testing loadForm calculation:\n";

$test_cases = [
    ['total' => 1200, 'diskon' => 0, 'diterima' => 1200],
    ['total' => 2400, 'diskon' => 10, 'diterima' => 2400],
    ['total' => 3600, 'diskon' => 15, 'diterima' => 4000]
];

foreach ($test_cases as $i => $case) {
    echo "\nTest " . ($i + 1) . ":\n";
    echo "Input - Total: {$case['total']}, Diskon: {$case['diskon']}%, Diterima: {$case['diterima']}\n";
    
    $response = $controller->loadForm($case['diskon'], $case['total'], $case['diterima']);
    $data = $response->getData(true);
    
    echo "Output - Bayar: {$data['bayar']}, Kembali: " . str_replace('Rp. ', '', $data['kembalirp']) . "\n";
    
    $expected_bayar = $case['total'] - ($case['diskon'] / 100 * $case['total']);
    $expected_kembali = $case['diterima'] - $expected_bayar;
    
    if ($data['bayar'] == $expected_bayar) {
        echo "✅ Bayar calculation correct\n";
    } else {
        echo "❌ Bayar calculation wrong: expected {$expected_bayar}, got {$data['bayar']}\n";
    }
    
    if (str_replace(['Rp. ', '.', ','], '', $data['kembalirp']) == $expected_kembali) {
        echo "✅ Kembali calculation correct\n";
    } else {
        echo "❌ Kembali calculation issue\n";
    }
}

echo "\n🎉 CALCULATION VERIFICATION COMPLETE!\n";
echo "\n=== QUICK TEST DONE ===\n";
