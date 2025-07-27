<?php
/**
 * Script untuk mengecek detail stok produk tertentu
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

echo "=== STOCK CHECKER SCRIPT ===\n";
echo "Masukkan nama produk yang ingin dicek (contoh: acifar): ";
$product_name = trim(fgets(STDIN));

// Cari produk
$produk = Produk::where('nama_produk', 'LIKE', "%{$product_name}%")
                ->orWhere('kode_produk', 'LIKE', "%{$product_name}%")
                ->first();

if (!$produk) {
    echo "Produk '{$product_name}' tidak ditemukan.\n";
    
    // Tampilkan produk yang mirip
    $similar = Produk::where('nama_produk', 'LIKE', "%{$product_name}%")
                     ->orWhere('kode_produk', 'LIKE', "%{$product_name}%")
                     ->take(5)
                     ->get();
    
    if ($similar->count() > 0) {
        echo "\nProduk yang mirip:\n";
        foreach ($similar as $prod) {
            echo "- {$prod->nama_produk} (Kode: {$prod->kode_produk})\n";
        }
    }
    exit;
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "DETAIL PRODUK: {$produk->nama_produk}\n";
echo str_repeat("=", 80) . "\n";
echo "ID Produk      : {$produk->id_produk}\n";
echo "Kode Produk    : {$produk->kode_produk}\n";
echo "Stok Saat Ini  : {$produk->stok}\n";
echo "Harga Beli     : Rp " . number_format($produk->harga_beli, 0, ',', '.') . "\n";
echo "Harga Jual     : Rp " . number_format($produk->harga_jual, 0, ',', '.') . "\n";

// Hitung total transaksi
$total_pembelian = PembelianDetail::where('id_produk', $produk->id_produk)->sum('jumlah');
$total_penjualan = PenjualanDetail::where('id_produk', $produk->id_produk)->sum('jumlah');
$calculated_stock = $total_pembelian - $total_penjualan;

echo "\n" . str_repeat("-", 80) . "\n";
echo "PERHITUNGAN STOK:\n";
echo str_repeat("-", 80) . "\n";
echo "Total Pembelian : {$total_pembelian}\n";
echo "Total Penjualan : {$total_penjualan}\n";
echo "Stok Seharusnya : {$calculated_stock}\n";
echo "Stok Database   : {$produk->stok}\n";
echo "Selisih         : " . ($produk->stok - $calculated_stock) . "\n";

if ($produk->stok != $calculated_stock) {
    echo "\n⚠️  PERINGATAN: Stok tidak sesuai dengan perhitungan transaksi!\n";
} else {
    echo "\n✅ Stok sudah sesuai dengan perhitungan transaksi.\n";
}

// Detail transaksi pembelian
echo "\n" . str_repeat("-", 80) . "\n";
echo "TRANSAKSI PEMBELIAN (10 terakhir):\n";
echo str_repeat("-", 80) . "\n";
$pembelian_detail = PembelianDetail::where('id_produk', $produk->id_produk)
                                   ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
                                   ->orderBy('pembelian.waktu', 'desc')
                                   ->take(10)
                                   ->select('pembelian_detail.*', 'pembelian.waktu')
                                   ->get();

if ($pembelian_detail->count() > 0) {
    foreach ($pembelian_detail as $detail) {
        echo sprintf("Tanggal: %s | Jumlah: %3d | Subtotal: Rp %s\n", 
                    $detail->waktu, 
                    $detail->jumlah,
                    number_format($detail->subtotal, 0, ',', '.')
                );
    }
} else {
    echo "Tidak ada transaksi pembelian.\n";
}

// Detail transaksi penjualan
echo "\n" . str_repeat("-", 80) . "\n";
echo "TRANSAKSI PENJUALAN (10 terakhir):\n";
echo str_repeat("-", 80) . "\n";
$penjualan_detail = PenjualanDetail::where('id_produk', $produk->id_produk)
                                   ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
                                   ->orderBy('penjualan.waktu', 'desc')
                                   ->take(10)
                                   ->select('penjualan_detail.*', 'penjualan.waktu')
                                   ->get();

if ($penjualan_detail->count() > 0) {
    foreach ($penjualan_detail as $detail) {
        echo sprintf("Tanggal: %s | Jumlah: %3d | Subtotal: Rp %s\n", 
                    $detail->waktu, 
                    $detail->jumlah,
                    number_format($detail->subtotal, 0, ',', '.')
                );
    }
} else {
    echo "Tidak ada transaksi penjualan.\n";
}

// Rekaman stok
echo "\n" . str_repeat("-", 80) . "\n";
echo "REKAMAN STOK (10 terakhir):\n";
echo str_repeat("-", 80) . "\n";
$rekaman_stok = RekamanStok::where('id_produk', $produk->id_produk)
                           ->orderBy('waktu', 'desc')
                           ->take(10)
                           ->get();

if ($rekaman_stok->count() > 0) {
    foreach ($rekaman_stok as $rekaman) {
        $masuk = $rekaman->stok_masuk ?? 0;
        $keluar = $rekaman->stok_keluar ?? 0;
        $type = $masuk > 0 ? 'MASUK' : 'KELUAR';
        $jumlah = $masuk > 0 ? $masuk : $keluar;
        
        echo sprintf("Waktu: %s | %s: %3d | Sisa: %3d\n", 
                    $rekaman->waktu, 
                    $type,
                    $jumlah,
                    $rekaman->stok_sisa
                );
    }
} else {
    echo "Tidak ada rekaman stok.\n";
}

echo "\n" . str_repeat("=", 80) . "\n";

// Tanya apakah ingin fix stok
if ($produk->stok != $calculated_stock) {
    echo "Apakah Anda ingin memperbaiki stok produk ini? (y/n): ";
    $fix = trim(fgets(STDIN));
    
    if (strtolower($fix) == 'y') {
        $old_stock = $produk->stok;
        $produk->stok = max(0, $calculated_stock); // Pastikan tidak negatif
        $produk->save();
        
        echo "✅ Stok berhasil diperbaiki dari {$old_stock} menjadi {$produk->stok}\n";
        
        // Log aktivitas
        $log_message = date('Y-m-d H:i:s') . " - Stock fixed for {$produk->nama_produk} (ID: {$produk->id_produk}) from {$old_stock} to {$produk->stok}\n";
        file_put_contents('storage/logs/stock_fixes.log', $log_message, FILE_APPEND);
    }
}

echo "Script selesai.\n";
