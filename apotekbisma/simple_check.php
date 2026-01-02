<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;

$cutoff = '2025-12-31 23:00:00';

$total = DB::table('rekaman_stoks')->count();
$after = DB::table('rekaman_stoks')->where('waktu', '>', $cutoff)->count();
$on31 = DB::table('rekaman_stoks')->whereDate('waktu', '2025-12-31')->count();

echo "Total rekaman: {$total}\n";
echo "Pada 31 Des: {$on31}\n";
echo "Setelah cutoff: {$after}\n\n";

$amox = Produk::where('nama_produk', 'LIKE', '%Amoxicillin%')->first();
if ($amox) {
    echo "AMOXICILLIN:\n";
    echo "  Stok produk: {$amox->stok}\n";
    
    $last = DB::table('rekaman_stoks')
        ->where('id_produk', $amox->id_produk)
        ->orderBy('waktu', 'desc')
        ->first();
    
    if ($last) {
        echo "  Stok rekaman: {$last->stok_sisa}\n";
        echo "  Waktu: {$last->waktu}\n";
    }
}
