<?php
/**
 * Script untuk menormalisasi stok produk yang minus menjadi 0
 * BACKUP DATABASE SEBELUM MENJALANKAN SCRIPT INI!
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

echo "=== STOCK NORMALIZATION SCRIPT ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n";
echo "Fungsi: Menormalisasi semua stok minus menjadi 0\n\n";

// Ambil semua produk yang memiliki stok negatif
$produk_minus = Produk::where('stok', '<', 0)->get();

if ($produk_minus->isEmpty()) {
    echo "✓ Tidak ada produk dengan stok minus. Database sudah bersih.\n";
    exit;
}

echo "Ditemukan " . $produk_minus->count() . " produk dengan stok minus:\n";
echo str_repeat("=", 80) . "\n";

foreach ($produk_minus as $produk) {
    echo sprintf(
        "%-50s | Stok: %5d -> 0\n",
        substr($produk->nama_produk, 0, 48),
        $produk->stok
    );
}

echo str_repeat("=", 80) . "\n";
echo "Apakah Anda yakin ingin menormalisasi semua stok minus menjadi 0? (y/N): ";
$confirmation = trim(fgets(STDIN));

if (strtolower($confirmation) !== 'y') {
    echo "Script dibatalkan.\n";
    exit;
}

echo "\nMemulai normalisasi...\n";

$updated_count = 0;

DB::beginTransaction();

try {
    foreach ($produk_minus as $produk) {
        $old_stock = $produk->stok;
        
        // Update stok menjadi 0
        $produk->stok = 0;
        $produk->save();
        
        // Buat rekaman stok untuk audit trail
        RekamanStok::create([
            'id_produk' => $produk->id_produk,
            'waktu' => now(),
            'stok_masuk' => abs($old_stock), // Jumlah yang dinormalisasi
            'stok_awal' => $old_stock,
            'stok_sisa' => 0,
            'keterangan' => 'Normalisasi Stok: Koreksi stok minus menjadi 0 (sistem otomatis)'
        ]);
        
        $updated_count++;
        echo "✓ {$produk->nama_produk} - stok dinormalisasi dari {$old_stock} menjadi 0\n";
    }
    
    DB::commit();
    
    echo "\n=== NORMALISASI SELESAI ===\n";
    echo "Total produk yang dinormalisasi: {$updated_count}\n";
    echo "Semua stok minus telah diubah menjadi 0\n";
    echo "Rekaman perubahan telah disimpan untuk audit trail\n";
    
    // Log aktivitas
    $log_message = date('Y-m-d H:i:s') . " - Stock normalization completed. Normalized: {$updated_count} products from negative to 0\n";
    file_put_contents('storage/logs/stock_normalization.log', $log_message, FILE_APPEND);
    
} catch (\Exception $e) {
    DB::rollback();
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Rollback dilakukan. Tidak ada perubahan yang disimpan.\n";
    exit(1);
}

echo "\nScript selesai pada: " . date('Y-m-d H:i:s') . "\n";
