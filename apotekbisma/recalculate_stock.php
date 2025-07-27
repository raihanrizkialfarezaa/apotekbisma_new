<?php
/**
 * Script untuk menghitung ulang stok produk berdasarkan transaksi
 * BACKUP DATABASE SEBELUM MENJALANKAN SCRIPT INI!
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\PembelianDetail;
use App\Models\PenjualanDetail;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

echo "=== STOCK RECALCULATION SCRIPT ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n";
echo "PERINGATAN: Script ini akan menghitung ulang semua stok berdasarkan transaksi!\n";
echo "Pastikan Anda sudah backup database sebelum menjalankan ini.\n\n";

// Opsi mode
echo "Pilih mode:\n";
echo "1. PREVIEW - Lihat perubahan tanpa menyimpan\n";
echo "2. EXECUTE - Jalankan perubahan ke database\n";
echo "3. SPECIFIC - Recalculate produk tertentu saja\n";
echo "Masukkan pilihan (1/2/3): ";
$mode = trim(fgets(STDIN));

$preview_mode = ($mode == '1');
$specific_mode = ($mode == '3');
$execute_mode = ($mode == '2');

if (!in_array($mode, ['1', '2', '3'])) {
    echo "Mode tidak valid. Script dibatalkan.\n";
    exit;
}

// Jika mode specific, minta nama produk
$specific_product = null;
if ($specific_mode) {
    echo "Masukkan nama produk (contoh: acifar): ";
    $product_name = trim(fgets(STDIN));
    $specific_product = Produk::where('nama_produk', 'LIKE', "%{$product_name}%")->first();
    
    if (!$specific_product) {
        echo "Produk '{$product_name}' tidak ditemukan.\n";
        exit;
    }
    
    echo "Produk ditemukan: {$specific_product->nama_produk} (ID: {$specific_product->id_produk})\n";
    echo "Stok saat ini: {$specific_product->stok}\n\n";
}

// Konfirmasi final untuk execute
if ($execute_mode) {
    echo "KONFIRMASI: Ketik 'EXECUTE' untuk menjalankan perubahan ke database: ";
    $confirmation = trim(fgets(STDIN));
    
    if ($confirmation !== 'EXECUTE') {
        echo "Script dibatalkan.\n";
        exit;
    }
}

echo "\nMemulai " . ($preview_mode ? "preview" : ($specific_mode ? "specific recalculation" : "recalculation")) . "...\n";
echo str_repeat("=", 80) . "\n";

// Ambil produk berdasarkan mode
if ($specific_mode) {
    $products = collect([$specific_product]);
} else {
    $products = Produk::all();
}

$updated_count = 0;
$issues_found = [];

foreach ($products as $product) {
    // Hitung total pembelian dari detail pembelian
    $total_pembelian = PembelianDetail::where('id_produk', $product->id_produk)->sum('jumlah');
    
    // Hitung total penjualan dari detail penjualan
    $total_penjualan = PenjualanDetail::where('id_produk', $product->id_produk)->sum('jumlah');
    
    // Hitung stok yang seharusnya
    $calculated_stock = $total_pembelian - $total_penjualan;
    
    // Pastikan stok tidak negatif (sesuai dengan business rule)
    if ($calculated_stock < 0) {
        $issues_found[] = [
            'produk' => $product->nama_produk,
            'id' => $product->id_produk,
            'issue' => 'Calculated stock is negative',
            'pembelian' => $total_pembelian,
            'penjualan' => $total_penjualan,
            'calculated' => $calculated_stock
        ];
        $calculated_stock = 0; // Normalisasi ke 0
    }
    
    $old_stock = $product->stok;
    $difference = $calculated_stock - $old_stock;
    
    // Tampilkan informasi jika ada perubahan
    if ($old_stock != $calculated_stock) {
        echo sprintf(
            "%-30s | Current: %3d | Calculated: %3d | Diff: %+3d | Pembelian: %3d | Penjualan: %3d\n",
            substr($product->nama_produk, 0, 28),
            $old_stock,
            $calculated_stock,
            $difference,
            $total_pembelian,
            $total_penjualan
        );
        
        // Update stok jika bukan preview mode
        if ($execute_mode || $specific_mode) {
            $product->stok = $calculated_stock;
            $product->save();
        }
        
        $updated_count++;
    }
}

echo str_repeat("=", 80) . "\n";

// Tampilkan summary
echo "\n=== SUMMARY ===\n";
echo "Total produk diproses: " . $products->count() . "\n";
echo "Produk yang perlu diupdate: {$updated_count}\n";

if (!empty($issues_found)) {
    echo "\n=== ISSUES DITEMUKAN ===\n";
    foreach ($issues_found as $issue) {
        echo "- {$issue['produk']} (ID: {$issue['id']}): {$issue['issue']}\n";
        echo "  Pembelian: {$issue['pembelian']}, Penjualan: {$issue['penjualan']}, Calculated: {$issue['calculated']}\n";
    }
}

if ($preview_mode) {
    echo "\n*** PREVIEW MODE - Tidak ada perubahan yang disimpan ***\n";
    echo "Jalankan dengan mode 2 (EXECUTE) untuk menyimpan perubahan.\n";
} elseif ($execute_mode || $specific_mode) {
    echo "\n*** RECALCULATION COMPLETED ***\n";
    echo "Perubahan telah disimpan ke database.\n";
    
    // Log aktivitas
    $log_message = date('Y-m-d H:i:s') . " - Stock recalculation completed. Updated: {$updated_count} products\n";
    file_put_contents('storage/logs/stock_recalculation.log', $log_message, FILE_APPEND);
}

echo "\nScript selesai pada: " . date('Y-m-d H:i:s') . "\n";
