<?php

/**
 * SCRIPT SAPU JAGAT: MASS RECALCULATE STOCK V2 (TIME ANCHOR FIXER)
 * Fungsi: 
 * 1. Memaksa tanggal "Saldo Awal Stok" mundur ke titik mutlak 31 Desember 2025 23:59:59.
 * 2. Menghitung ulang urutan kartu stok berdasarkan kronologi yang sudah disehatkan.
 */

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
echo " MASS RECALCULATE STOCK + CHRONOLOGICAL ANCHOR REPAIR \n";
echo "=========================================================\n\n";

echo "[INFO] Memulai perbaikan kronologi Opname & Kalkulasi Ulang...\n";

$totalProduk = Produk::count();
$sukses = 0;
$gagal = 0;
$diabaikan = 0;
$diperbaikiKronologi = 0;

Produk::chunk(200, function ($produks) use (&$sukses, &$gagal, &$diabaikan, &$diperbaikiKronologi, $totalProduk) {
    foreach ($produks as $p) {
        try {
            $hasBaseline = RekamanStok::where('id_produk', $p->id_produk)
                ->where(function ($query) {
                    $query->where('keterangan', 'like', '%opname%')
                          ->orWhere('keterangan', 'like', '%saldo awal%');
                })->exists();

            if (!$hasBaseline) {
                $diabaikan++;
                continue;
            }

            DB::beginTransaction();
            
            // FIX: Ada laporan "Saldo Awal" terinput dengan waktu hari ini (Maret 2026), 
            // sehingga bergeser ke pucuk/akhir urutan. Kita kembalikan ia secara paksa
            // ke 31 Desember 2025 agar menjadi pondasi mutlak rekaman.
            $fixedCount = DB::table('rekaman_stoks')
                ->where('id_produk', $p->id_produk)
                ->where(function ($query) {
                    $query->where('keterangan', 'like', '%opname%')
                          ->orWhere('keterangan', 'like', '%saldo awal%');
                })
                ->where('waktu', '!=', '2025-12-31 23:59:59') // Hanya update jika belum Desember 2025
                ->update(['waktu' => '2025-12-31 23:59:59']);

            if ($fixedCount > 0) {
                $diperbaikiKronologi++;
            }
            
            // Setelah kronologi sehat, jalankan kalkulasi kartu stok secara berurutan
            RekamanStok::recalculateStock($p->id_produk);
            
            DB::commit();
            $sukses++;

        } catch (\Exception $e) {
            DB::rollBack();
            $gagal++;
            Log::error("Recalculate Error ID {$p->id_produk}: " . $e->getMessage());
        }
    }
});

echo "\n=========================================================\n";
echo "REKAPITULASI HASIL REPAIR\n";
echo "=========================================================\n";
echo "Total Terproses         : " . ($sukses + $gagal + $diabaikan) . " / {$totalProduk}\n";
echo "Selesai (MATCH)         : {$sukses} Produk\n";
echo "Kronologi Waktu Direset : {$diperbaikiKronologi} Produk (Anchor digeser ke 31-12-2025)\n";
echo "Aman (DIABAIKAN)        : {$diabaikan} Produk (Bukan Produk Opname)\n";
echo "Gagal (ERROR)           : {$gagal} Produk\n";
echo "=========================================================\n";
echo "Selesai! Silakan cek kembali kartu stok mulai Januari/Februari.\n";
