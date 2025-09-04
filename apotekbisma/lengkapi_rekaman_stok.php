<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

echo "=== PERBAIKAN LENGKAP REKAMAN STOK ===\n\n";

DB::beginTransaction();

try {
    // Ambil semua produk yang tidak memiliki rekaman stok
    $produk_tanpa_rekaman = DB::select("
        SELECT p.id_produk, p.nama_produk, p.stok
        FROM produk p
        LEFT JOIN rekaman_stoks rs ON p.id_produk = rs.id_produk
        WHERE rs.id_produk IS NULL
        ORDER BY p.nama_produk
    ");
    
    echo "Ditemukan " . count($produk_tanpa_rekaman) . " produk tanpa rekaman stok\n";
    echo str_repeat("=", 60) . "\n";
    
    $batchSize = 50;
    $batches = array_chunk($produk_tanpa_rekaman, $batchSize);
    $currentTime = Carbon::now();
    
    foreach ($batches as $batchIndex => $batch) {
        echo "Batch " . ($batchIndex + 1) . "/" . count($batches) . " (items: " . count($batch) . ")\n";
        
        $insertData = [];
        foreach ($batch as $produk_data) {
            $insertData[] = [
                'id_produk' => $produk_data->id_produk,
                'waktu' => $currentTime,
                'stok_masuk' => $produk_data->stok,
                'stok_awal' => 0,
                'stok_sisa' => $produk_data->stok,
                'keterangan' => 'Rekonstruksi: Rekaman stok awal produk',
                'created_at' => $currentTime,
                'updated_at' => $currentTime
            ];
        }
        
        // Bulk insert untuk efisiensi
        DB::table('rekaman_stoks')->insert($insertData);
        
        echo "✓ Berhasil dibuat " . count($insertData) . " rekaman stok\n";
    }
    
    DB::commit();
    echo "\n✅ SEMUA REKAMAN STOK BERHASIL DIBUAT\n";
    
    // Verifikasi hasil
    echo "\nVERIFIKASI HASIL:\n";
    echo str_repeat("=", 30) . "\n";
    
    $total_produk = Produk::count();
    $produk_dengan_rekaman = DB::select("
        SELECT COUNT(DISTINCT p.id_produk) as count
        FROM produk p
        INNER JOIN rekaman_stoks rs ON p.id_produk = rs.id_produk
    ")[0]->count;
    
    echo "Total produk: {$total_produk}\n";
    echo "Produk dengan rekaman stok: {$produk_dengan_rekaman}\n";
    echo "Persentase: " . round(($produk_dengan_rekaman / $total_produk) * 100, 2) . "%\n";
    
    if ($produk_dengan_rekaman == $total_produk) {
        echo "🎉 SEMUA PRODUK SEKARANG MEMILIKI REKAMAN STOK!\n";
    }
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
}
