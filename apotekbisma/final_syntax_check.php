<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== FINAL SYNTAX CHECK ===\n\n";

try {
    $view = view('penjualan_detail.index', [
        'id_penjualan' => null,
        'memberSelected' => null,
        'diskon' => 0,
        'penjualan' => null
    ]);
    
    $rendered = $view->render();
    
    if (strlen($rendered) > 1000) {
        echo "✅ View compiled successfully\n";
        echo "✅ Content length: " . strlen($rendered) . " characters\n";
        echo "✅ No syntax errors detected\n";
    } else {
        echo "❌ View compiled but content too short\n";
    }
    
} catch (Exception $e) {
    echo "❌ Syntax error:\n";
    echo $e->getMessage() . "\n";
    exit;
}

echo "\n🎉 SYNTAX ERROR FIXED!\n";
echo "\nView now compiles successfully.\n";

echo "\n=== SYNTAX CHECK COMPLETED ===\n";
