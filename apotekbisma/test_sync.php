<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== TEST SISTEM SINKRONISASI ===\n\n";

echo "1. SEBELUM TEST - Status saat ini:\n";
$produk = \App\Models\Produk::find(2);
echo "Stok produk: {$produk->stok}\n";

$penjualan = \App\Models\Penjualan::find(603);
echo "Penjualan 603 - Waktu: {$penjualan->waktu}\n";

$rekaman = \App\Models\RekamanStok::where('id_penjualan', 603)->first();
if($rekaman) {
    echo "RekamanStok terkait - Waktu: {$rekaman->waktu} | Keluar: {$rekaman->stok_keluar} | Sisa: {$rekaman->stok_sisa}\n";
}

echo "\n2. TEST: Ubah waktu transaksi dari {$penjualan->waktu} ke 2025-09-03 00:00:00\n";

// Simulate PenjualanController@update
\Illuminate\Support\Facades\DB::beginTransaction();

try {
    $penjualan->waktu = '2025-09-03 00:00:00';
    $penjualan->save();
    
    // Sync RekamanStok
    $updated = \App\Models\RekamanStok::where('id_penjualan', 603)
        ->update(['waktu' => $penjualan->waktu]);
    
    \Illuminate\Support\Facades\DB::commit();
    
    echo "✓ Update berhasil. Records yang disync: {$updated}\n";
    
} catch (\Exception $e) {
    \Illuminate\Support\Facades\DB::rollBack();
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n3. SETELAH TEST - Verifikasi sinkronisasi:\n";
$penjualan_after = \App\Models\Penjualan::find(603);
echo "Penjualan 603 - Waktu: {$penjualan_after->waktu}\n";

$rekaman_after = \App\Models\RekamanStok::where('id_penjualan', 603)->first();
if($rekaman_after) {
    echo "RekamanStok terkait - Waktu: {$rekaman_after->waktu} | Keluar: {$rekaman_after->stok_keluar} | Sisa: {$rekaman_after->stok_sisa}\n";
    
    if($penjualan_after->waktu == $rekaman_after->waktu) {
        echo "✓ SINKRON: Waktu sudah sesuai!\n";
    } else {
        echo "✗ TIDAK SINKRON: Waktu tidak sesuai!\n";
    }
} else {
    echo "✗ RekamanStok tidak ditemukan!\n";
}

echo "\n4. TEST: Kembalikan ke waktu asli 2025-09-01 00:00:00\n";

\Illuminate\Support\Facades\DB::beginTransaction();

try {
    $penjualan->waktu = '2025-09-01 00:00:00';
    $penjualan->save();
    
    \App\Models\RekamanStok::where('id_penjualan', 603)
        ->update(['waktu' => $penjualan->waktu]);
    
    \Illuminate\Support\Facades\DB::commit();
    
    echo "✓ Dikembalikan ke waktu asli\n";
    
} catch (\Exception $e) {
    \Illuminate\Support\Facades\DB::rollBack();
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n5. FINAL - Verifikasi stok tidak berubah:\n";
$produk_final = \App\Models\Produk::find(2);
echo "Stok produk: {$produk_final->stok}\n";

if($produk->stok == $produk_final->stok) {
    echo "✓ BENAR: Stok tidak berubah saat edit waktu!\n";
} else {
    echo "✗ ERROR: Stok berubah padahal hanya edit waktu!\n";
}

echo "\n=== TEST SELESAI ===\n";
