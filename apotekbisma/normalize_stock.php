<?php
/**
 * Script untuk menormalisasi stok produk yang minus menjadi 0
 * dan memperbaiki ketidaksinkronan rekaman stok
 * BACKUP DATABASE SEBELUM MENJALANKAN SCRIPT INI!
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

echo "=== STOCK NORMALIZATION & SYNCHRONIZATION SCRIPT ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n";
echo "Fungsi: \n";
echo "1. Menormalisasi semua stok minus menjadi 0\n";
echo "2. Memperbaiki ketidaksinkronan rekaman stok\n\n";

// Ambil semua produk yang memiliki stok negatif
$produk_minus = Produk::where('stok', '<', 0)->get();

// Analisis masalah sinkronisasi rekaman stok
$rekaman_awal_minus = RekamanStok::where('stok_awal', '<', 0)->count();
$rekaman_sisa_minus = RekamanStok::where('stok_sisa', '<', 0)->count();

echo "ANALISIS AWAL:\n";
echo "- Produk dengan stok minus: " . $produk_minus->count() . "\n";
echo "- Rekaman dengan stok_awal minus: {$rekaman_awal_minus}\n";
echo "- Rekaman dengan stok_sisa minus: {$rekaman_sisa_minus}\n\n";

if ($produk_minus->isEmpty() && $rekaman_awal_minus == 0 && $rekaman_sisa_minus == 0) {
    echo "✓ Tidak ada masalah ditemukan. Database sudah bersih dan sinkron.\n";
    exit;
}

$need_product_normalization = !$produk_minus->isEmpty();
$need_record_synchronization = ($rekaman_awal_minus > 0 || $rekaman_sisa_minus > 0);

if ($need_product_normalization) {
    echo "PRODUK DENGAN STOK MINUS:\n";
    echo str_repeat("=", 80) . "\n";
    foreach ($produk_minus as $produk) {
        echo sprintf(
            "%-50s | Stok: %5d -> 0\n",
            substr($produk->nama_produk, 0, 48),
            $produk->stok
        );
    }
    echo str_repeat("=", 80) . "\n";
}

if ($need_record_synchronization) {
    echo "MASALAH SINKRONISASI REKAMAN STOK:\n";
    echo "- {$rekaman_awal_minus} rekaman dengan stok_awal minus akan diperbaiki\n";
    echo "- {$rekaman_sisa_minus} rekaman dengan stok_sisa minus akan diperbaiki\n\n";
}
echo "Apakah Anda yakin ingin melanjutkan perbaikan? (y/N): ";
$confirmation = trim(fgets(STDIN));

if (strtolower($confirmation) !== 'y') {
    echo "Script dibatalkan.\n";
    exit;
}

echo "\nMemulai proses perbaikan...\n";

$updated_count = 0;
$sync_awal_count = 0;
$sync_sisa_count = 0;

DB::beginTransaction();

try {
    // 1. Normalisasi stok produk minus
    if ($need_product_normalization) {
        echo "\n1. NORMALISASI STOK PRODUK MINUS\n";
        echo str_repeat("=", 50) . "\n";
        
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
        
        echo "✅ Normalisasi produk selesai: {$updated_count} produk diperbaiki\n";
    }
    
    // 2. Sinkronisasi rekaman stok
    if ($need_record_synchronization) {
        echo "\n2. SINKRONISASI REKAMAN STOK\n";
        echo str_repeat("=", 50) . "\n";
        
        // Perbaiki rekaman dengan stok_awal minus
        $rekaman_awal_minus_records = RekamanStok::where('stok_awal', '<', 0)->get();
        foreach ($rekaman_awal_minus_records as $rekaman) {
            $produk = Produk::find($rekaman->id_produk);
            if ($produk) {
                $old_stok_awal = $rekaman->stok_awal;
                
                // Logika perbaikan stok_awal
                $new_stok_awal = $rekaman->stok_awal;
                
                if ($rekaman->stok_sisa >= 0) {
                    // Hitung berdasarkan stok_sisa + transaksi
                    if ($rekaman->stok_masuk > 0) {
                        $new_stok_awal = $rekaman->stok_sisa - $rekaman->stok_masuk;
                    } elseif ($rekaman->stok_keluar > 0) {
                        $new_stok_awal = $rekaman->stok_sisa + $rekaman->stok_keluar;
                    }
                }
                
                // Jika masih minus, gunakan stok current
                if ($new_stok_awal < 0) {
                    $new_stok_awal = max(0, $produk->stok);
                }
                
                $rekaman->stok_awal = $new_stok_awal;
                $rekaman->save();
                
                $sync_awal_count++;
                echo "  ✓ Rekaman ID {$rekaman->id_rekaman_stok}: stok_awal {$old_stok_awal} → {$new_stok_awal}\n";
            }
        }
        
        // Perbaiki rekaman dengan stok_sisa minus
        $rekaman_sisa_minus_records = RekamanStok::where('stok_sisa', '<', 0)->get();
        foreach ($rekaman_sisa_minus_records as $rekaman) {
            $produk = Produk::find($rekaman->id_produk);
            if ($produk) {
                $old_stok_sisa = $rekaman->stok_sisa;
                
                // Logika perbaikan stok_sisa
                $new_stok_sisa = $rekaman->stok_sisa;
                
                if ($rekaman->stok_awal >= 0) {
                    // Hitung berdasarkan stok_awal + transaksi
                    if ($rekaman->stok_masuk > 0) {
                        $new_stok_sisa = $rekaman->stok_awal + $rekaman->stok_masuk;
                    } elseif ($rekaman->stok_keluar > 0) {
                        $new_stok_sisa = $rekaman->stok_awal - $rekaman->stok_keluar;
                    }
                }
                
                // Jika masih minus, gunakan stok current
                if ($new_stok_sisa < 0) {
                    $new_stok_sisa = max(0, $produk->stok);
                }
                
                $rekaman->stok_sisa = $new_stok_sisa;
                $rekaman->save();
                
                $sync_sisa_count++;
                echo "  ✓ Rekaman ID {$rekaman->id_rekaman_stok}: stok_sisa {$old_stok_sisa} → {$new_stok_sisa}\n";
            }
        }
        
        echo "✅ Sinkronisasi rekaman selesai: {$sync_awal_count} stok_awal + {$sync_sisa_count} stok_sisa diperbaiki\n";
    }
    
    DB::commit();
    
    echo "\n=== PROSES SELESAI ===\n";
    if ($need_product_normalization) {
        echo "✅ Produk dinormalisasi: {$updated_count}\n";
    }
    if ($need_record_synchronization) {
        echo "✅ Rekaman stok_awal diperbaiki: {$sync_awal_count}\n";
        echo "✅ Rekaman stok_sisa diperbaiki: {$sync_sisa_count}\n";
    }
    echo "✅ Semua perubahan telah disimpan\n";
    echo "✅ Database sekarang konsisten dan sinkron\n";
    
    // Log aktivitas yang lebih lengkap
    $total_fixes = $updated_count + $sync_awal_count + $sync_sisa_count;
    $log_message = date('Y-m-d H:i:s') . " - Complete stock normalization and synchronization. Products normalized: {$updated_count}, Records fixed: {$sync_awal_count} + {$sync_sisa_count} = " . ($sync_awal_count + $sync_sisa_count) . "\n";
    file_put_contents('storage/logs/stock_normalization.log', $log_message, FILE_APPEND);
    
} catch (\Exception $e) {
    DB::rollback();
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Rollback dilakukan. Tidak ada perubahan yang disimpan.\n";
    exit(1);
}

echo "\nScript selesai pada: " . date('Y-m-d H:i:s') . "\n";
