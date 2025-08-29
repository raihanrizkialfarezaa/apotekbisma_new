<?php

require_once 'vendor/autoload.php';

use App\Models\RekamanStok;

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$record = RekamanStok::find(13486);
if ($record) {
    $expected = $record->stok_awal + $record->stok_masuk - $record->stok_keluar;
    $record->stok_sisa = max(0, $expected);
    $record->save();
    echo "✅ Fixed record 13486: {$record->stok_sisa}\n";
} else {
    echo "❌ Record 13486 not found\n";
}

?>
