<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$events = DB::table('rekaman_stoks')->where('keterangan', 'LIKE', '%REVISI JUMLAH SATUAN OBAT%')->get();
echo "Found " . count($events) . " events with REVISI JUMLAH SATUAN OBAT\n";
foreach ($events as $e) {
    echo "ID: {$e->id_rekaman_stok} | Product: {$e->id_produk} | Date: {$e->waktu}\n";
}
