<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;

echo "=======================================================\n";
echo "   OPERATIONAL INTEGRITY CHECK\n";
echo "   Memastikan Stok Produk == Kartu Stok Terakhir\n";
echo "=======================================================\n\n";

$allProducts = Produk::all();
$mismatch = 0;
$fixed = 0;

foreach ($allProducts as $produk) {
    $lastRecord = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('created_at', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
        
    $kartuStok = $lastRecord ? intval($lastRecord->stok_sisa) : 0;
    $produkStok = intval($produk->stok);
    
    if ($produkStok != $kartuStok) {
        $mismatch++;
        echo "MISMATCH: {$produk->nama_produk}\n";
        echo "  Tabel Produk: {$produkStok}\n";
        echo "  Kartu Stok  : {$kartuStok}\n";
        
        // Auto-fix agar operasional aman
        DB::table('produk')
            ->where('id_produk', $produk->id_produk)
            ->update(['stok' => $kartuStok]);
        $fixed++;
        echo "  [FIXED] Update produk ke {$kartuStok}\n\n";
    }
}

if ($mismatch == 0) {
    echo "✓ SEMUA PRODUK AMAN (Tabel Produk sinkron dengan Kartu Stok)\n";
} else {
    echo "⚠️ Ditemukan {$mismatch} ketidaksinkronan, tapi {$fixed} sudah diperbaiki otomatis.\n";
    echo "Sekarang sistem siap digunakan.\n";
}
