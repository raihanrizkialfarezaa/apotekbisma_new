<?php
/**
 * Script untuk menyinkronkan dan memperbaiki data rekaman stok
 * Mengatasi masalah stok_awal dan stok_sisa minus di rekaman_stoks
 * BACKUP DATABASE SEBELUM MENJALANKAN SCRIPT INI!
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

echo "=== SCRIPT SINKRONISASI REKAMAN STOK ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n";
echo "Fungsi: Memperbaiki rekaman stok yang tidak sinkron dengan stok produk\n\n";

echo "⚠️  PERINGATAN: Script ini akan mengubah data rekaman stok!\n";
echo "   Pastikan Anda sudah BACKUP database sebelum melanjutkan.\n\n";

// Konfirmasi awal
echo "Apakah Anda yakin ingin melanjutkan? (y/N): ";
$confirmation = trim(fgets(STDIN));

if (strtolower($confirmation) !== 'y') {
    echo "Script dibatalkan.\n";
    exit;
}

echo "\n=== MEMULAI PERBAIKAN ===\n\n";

DB::beginTransaction();

try {
    // 1. Perbaiki rekaman dengan stok_awal minus
    echo "1. Memperbaiki rekaman dengan stok_awal minus...\n";
    
    $rekaman_awal_minus = RekamanStok::where('stok_awal', '<', 0)->get();
    $count_awal_fixed = 0;
    
    foreach ($rekaman_awal_minus as $rekaman) {
        $produk = Produk::find($rekaman->id_produk);
        if ($produk) {
            $old_stok_awal = $rekaman->stok_awal;
            
            // Logika perbaikan:
            // - Jika stok_sisa valid (>=0), gunakan sebagai referensi
            // - Jika ada stok_masuk/keluar, hitung ulang stok_awal
            // - Jika tidak, gunakan stok current produk
            
            $new_stok_awal = $rekaman->stok_awal;
            
            if ($rekaman->stok_sisa >= 0) {
                // Hitung berdasarkan stok_sisa + transaksi
                if ($rekaman->stok_masuk > 0) {
                    $new_stok_awal = $rekaman->stok_sisa - $rekaman->stok_masuk;
                } elseif ($rekaman->stok_keluar > 0) {
                    $new_stok_awal = $rekaman->stok_sisa + $rekaman->stok_keluar;
                }
            }
            
            // Jika masih minus atau tidak masuk akal, gunakan stok current
            if ($new_stok_awal < 0) {
                $new_stok_awal = max(0, $produk->stok);
            }
            
            $rekaman->stok_awal = $new_stok_awal;
            $rekaman->save();
            
            echo "  ✓ Rekaman ID {$rekaman->id_rekaman_stok}: stok_awal {$old_stok_awal} → {$new_stok_awal}\n";
            $count_awal_fixed++;
        }
    }
    
    echo "  Total diperbaiki: {$count_awal_fixed} rekaman\n\n";
    
    // 2. Perbaiki rekaman dengan stok_sisa minus
    echo "2. Memperbaiki rekaman dengan stok_sisa minus...\n";
    
    $rekaman_sisa_minus = RekamanStok::where('stok_sisa', '<', 0)->get();
    $count_sisa_fixed = 0;
    
    foreach ($rekaman_sisa_minus as $rekaman) {
        $produk = Produk::find($rekaman->id_produk);
        if ($produk) {
            $old_stok_sisa = $rekaman->stok_sisa;
            
            // Logika perbaikan:
            // - Hitung berdasarkan stok_awal + transaksi
            // - Jika tidak valid, gunakan stok current produk
            
            $new_stok_sisa = $rekaman->stok_sisa;
            
            if ($rekaman->stok_awal >= 0) {
                // Hitung berdasarkan stok_awal + transaksi
                if ($rekaman->stok_masuk > 0) {
                    $new_stok_sisa = $rekaman->stok_awal + $rekaman->stok_masuk;
                } elseif ($rekaman->stok_keluar > 0) {
                    $new_stok_sisa = $rekaman->stok_awal - $rekaman->stok_keluar;
                }
            }
            
            // Jika masih minus atau tidak masuk akal, gunakan stok current
            if ($new_stok_sisa < 0) {
                $new_stok_sisa = max(0, $produk->stok);
            }
            
            $rekaman->stok_sisa = $new_stok_sisa;
            $rekaman->save();
            
            echo "  ✓ Rekaman ID {$rekaman->id_rekaman_stok}: stok_sisa {$old_stok_sisa} → {$new_stok_sisa}\n";
            $count_sisa_fixed++;
        }
    }
    
    echo "  Total diperbaiki: {$count_sisa_fixed} rekaman\n\n";
    
    // 3. Validasi konsistensi setelah perbaikan
    echo "3. Memvalidasi konsistensi data...\n";
    
    $masih_awal_minus = RekamanStok::where('stok_awal', '<', 0)->count();
    $masih_sisa_minus = RekamanStok::where('stok_sisa', '<', 0)->count();
    
    if ($masih_awal_minus > 0 || $masih_sisa_minus > 0) {
        echo "  ⚠️  Masih ada {$masih_awal_minus} rekaman stok_awal minus dan {$masih_sisa_minus} rekaman stok_sisa minus\n";
        echo "  Ini mungkin memerlukan investigasi manual lebih lanjut.\n";
    } else {
        echo "  ✅ Semua rekaman stok sudah konsisten (tidak ada nilai minus)\n";
    }
    
    echo "\n4. Membuat rekaman audit...\n";
    
    // Buat rekaman audit untuk perubahan ini
    $audit_keterangan = "Sinkronisasi Rekaman Stok: Perbaikan otomatis nilai minus pada " . date('Y-m-d H:i:s') . 
                       " - {$count_awal_fixed} stok_awal diperbaiki, {$count_sisa_fixed} stok_sisa diperbaiki";
    
    RekamanStok::create([
        'id_produk' => 1, // Gunakan produk pertama sebagai placeholder
        'waktu' => now(),
        'stok_masuk' => 0,
        'stok_keluar' => 0,
        'stok_awal' => 0,
        'stok_sisa' => 0,
        'keterangan' => $audit_keterangan
    ]);
    
    DB::commit();
    
    echo "\n=== PERBAIKAN SELESAI ===\n";
    echo "✅ Total rekaman stok_awal yang diperbaiki: {$count_awal_fixed}\n";
    echo "✅ Total rekaman stok_sisa yang diperbaiki: {$count_sisa_fixed}\n";
    echo "✅ Semua perubahan telah disimpan\n";
    echo "✅ Rekaman audit telah dibuat\n\n";
    
    // Log aktivitas
    $log_message = date('Y-m-d H:i:s') . " - Stock record synchronization completed. Fixed: {$count_awal_fixed} stok_awal + {$count_sisa_fixed} stok_sisa records\n";
    file_put_contents('storage/logs/stock_synchronization.log', $log_message, FILE_APPEND);
    
    echo "📋 Log disimpan di: storage/logs/stock_synchronization.log\n";
    
} catch (\Exception $e) {
    DB::rollback();
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Rollback dilakukan. Tidak ada perubahan yang disimpan.\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\nScript selesai pada: " . date('Y-m-d H:i:s') . "\n";
echo "\n🔄 Silakan jalankan sync_stock_analysis.php lagi untuk memverifikasi perbaikan.\n";
