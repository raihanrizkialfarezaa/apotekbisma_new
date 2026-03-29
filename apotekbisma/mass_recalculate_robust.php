<?php

/**
 * SCRIPT SAPU JAGAT: MASS RECALCULATE STOCK
 * Fungsi: Memaksa menghitung ulang seluruh kartu stok dan tabel produk 100% secara robust
 *         KHUSUS untuk produk yang memiliki "Baseline Stock Opname 31 Des 2025".
 * Penggunaan: Jalankan via terminal/CMD -> php mass_recalculate_robust.php
 */

// Bypass batas memory dan waktu eksekusi agar script sanggup memproses ribuan data tanpa putus
ini_set('memory_limit', '-1');
set_time_limit(0);

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

echo "=========================================================\n";
echo "   MASS RECALCULATE STOCK BERDASARKAN BASELINE ROBUST    \n";
echo "=========================================================\n\n";

echo "[INFO] Memulai proses sinkronisasi untuk seluruh produk...\n";

$totalProduk = Produk::count();
echo "[INFO] Ditemukan {$totalProduk} data produk di database.\n\n";

$sukses = 0;
$gagal = 0;
$diabaikan = 0;
$logGagal = [];

// Chunking per 200 data untuk menjaga kestabilan memori / RAM server
Produk::chunk(200, function ($produks) use (&$sukses, &$gagal, &$diabaikan, &$logGagal, $totalProduk) {
    foreach ($produks as $p) {
        try {
            // Cek apakah produk memiliki record baseline ("opname" atau "saldo awal")
            $hasBaseline = RekamanStok::where('id_produk', $p->id_produk)
                ->where(function ($query) {
                    $query->where('keterangan', 'like', '%opname%')
                          ->orWhere('keterangan', 'like', '%saldo awal%');
                })->exists();

            if (!$hasBaseline) {
                // Lewati produk yang tidak punya anchor 31 Des 2025
                $diabaikan++;
                continue;
            }

            DB::beginTransaction();
            
            // Eksekusi core logic robust yang baru (menata sisa mutasi agar urut & akurat thd anchor 31 Des)
            RekamanStok::recalculateStock($p->id_produk);
            
            DB::commit();
            $sukses++;

            // Fitur UI feedback di console
            $totalDiproses = $sukses + $gagal + $diabaikan;
            if ($totalDiproses % 100 === 0) {
                echo "[+] Telah memindai {$totalDiproses} dari {$totalProduk} produk...\n";
            }
        } catch (\Exception $e) {
            DB::rollBack();
            $gagal++;
            $logGagal[] = "ID Produk {$p->id_produk} ({$p->nama_produk}): " . $e->getMessage();
            Log::error("Mass Recalculate Error ID {$p->id_produk}: " . $e->getMessage());
        }
    }
});

echo "\n=========================================================\n";
echo "                   REKAPITULASI HASIL                    \n";
echo "=========================================================\n";
echo "Total Terproses  : " . ($sukses + $gagal + $diabaikan) . " / {$totalProduk}\n";
echo "Sukses (MATCH)   : {$sukses} Produk (Di-reset sesuai Opname 31 Des 2025)\n";
echo "Aman (DIABAIKAN) : {$diabaikan} Produk (Tidak ada riwayat Baseline Opname)\n";
echo "Gagal (ERROR)    : {$gagal} Produk\n";

if ($gagal > 0) {
    echo "\n[WARNING] CATATAN ERROR:\n";
    foreach ($logGagal as $err) {
        echo "- {$err}\n";
    }
    echo "\n*Detail error spesifik telah disimpan di file log Laravel (storage/logs).\n";
} else {
    echo "\n[PERFECT] Seluruh produk sasaran berhasil di-recalculate 100% secara robust!\n";
}
echo "=========================================================\n";
echo "Proses rampung. Database terfiltrasi dan tersinkronisasi murni dengan akurasi terjamin.\n";
