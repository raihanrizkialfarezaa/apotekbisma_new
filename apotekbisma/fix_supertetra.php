<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\BaselineStockReflowService;

$idRekaman = 563882;
$idProduk = 778;

DB::beginTransaction();
try {
    $rekaman = DB::table('rekaman_stoks')->where('id_rekaman_stok', $idRekaman)->first();
    if ($rekaman) {
        echo "Found event: {$rekaman->keterangan}\n";
        DB::table('rekaman_stoks')->where('id_rekaman_stok', $idRekaman)->delete();
        echo "Deleted event {$idRekaman}\n";
        
        $service = app(BaselineStockReflowService::class);
        $summary = $service->rebuildProducts([$idProduk]);
        
        echo "Rebuild Summary:\n";
        print_r($summary);
        
        DB::commit();
        echo "Successfully committed.\n";
    } else {
        echo "Event {$idRekaman} not found.\n";
        DB::rollBack();
    }
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
