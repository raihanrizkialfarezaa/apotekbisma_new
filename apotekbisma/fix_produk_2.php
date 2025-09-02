<?php

require_once 'vendor/autoload.php';

use Illuminate\Support\Facades\DB;

// Setup Laravel
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== PERBAIKAN STOK PRODUK 2 ===\n";

// Identifikasi record yang salah
$masalah1 = DB::table('rekaman_stoks')->where('id_rekaman_stok', 13620)->first();
$masalah2 = DB::table('rekaman_stoks')->where('id_rekaman_stok', 13618)->first();

echo "Record 1 (ID 13620): {$masalah1->keterangan} - Masuk: {$masalah1->stok_masuk}\n";
echo "Record 2 (ID 13618): {$masalah2->keterangan} - Masuk: {$masalah2->stok_masuk}\n";

$total_salah = $masalah1->stok_masuk + $masalah2->stok_masuk;
echo "Total stok salah yang akan dihapus: {$total_salah}\n";

// Cek stok produk saat ini
$produk = DB::table('produk')->where('id_produk', 2)->first();
echo "Stok produk saat ini: {$produk->stok}\n";

$stok_benar = $produk->stok - $total_salah;
echo "Stok yang benar setelah koreksi: {$stok_benar}\n";

echo "\n=== MULAI PERBAIKAN ===\n";

DB::beginTransaction();
try {
    // Hapus record yang salah
    DB::table('rekaman_stoks')->where('id_rekaman_stok', 13620)->delete();
    echo "✓ Deleted record ID 13620\n";
    
    DB::table('rekaman_stoks')->where('id_rekaman_stok', 13618)->delete();
    echo "✓ Deleted record ID 13618\n";
    
    // Perbaiki stok produk
    DB::table('produk')->where('id_produk', 2)->update(['stok' => $stok_benar]);
    echo "✓ Updated produk stok to {$stok_benar}\n";
    
    // Update stok_sisa di record RekamanStok terakhir
    $latest_record = DB::table('rekaman_stoks')
        ->where('id_produk', 2)
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    if($latest_record) {
        DB::table('rekaman_stoks')
            ->where('id_rekaman_stok', $latest_record->id_rekaman_stok)
            ->update(['stok_sisa' => $stok_benar]);
        echo "✓ Updated last record stok_sisa to {$stok_benar}\n";
    }
    
    DB::commit();
    echo "\n🎉 PERBAIKAN BERHASIL!\n";
    
} catch (\Exception $e) {
    DB::rollback();
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== VERIFIKASI HASIL ===\n";
$produk_final = DB::table('produk')->where('id_produk', 2)->first();
echo "Stok produk final: {$produk_final->stok}\n";

$latest_final = DB::table('rekaman_stoks')
    ->where('id_produk', 2)
    ->orderBy('id_rekaman_stok', 'desc')
    ->first();
echo "Stok_sisa terakhir: {$latest_final->stok_sisa}\n";

$manual_count = DB::table('rekaman_stoks')
    ->where('id_produk', 2)
    ->where('keterangan', 'LIKE', '%Perubahan Stok Manual%')
    ->count();
echo "Sisa 'Perubahan Stok Manual': {$manual_count}\n";
