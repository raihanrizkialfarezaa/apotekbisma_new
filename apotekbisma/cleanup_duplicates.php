<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== PEMBERSIHAN DATA DUPLIKASI ===\n\n";

echo "1. Cari duplikasi RekamanStok untuk ACETHYLESISTEIN (ID: 2):\n";
$duplicates = \App\Models\RekamanStok::where('id_produk', 2)
    ->where('keterangan', 'LIKE', '%Perubahan Stok Manual%')
    ->get();

echo "Ditemukan " . $duplicates->count() . " entry 'Perubahan Stok Manual':\n";
foreach($duplicates as $dup) {
    echo "ID: {$dup->id_rekaman_stok} | Waktu: {$dup->waktu} | Masuk: {$dup->stok_masuk} | Keluar: {$dup->stok_keluar} | Keterangan: {$dup->keterangan}\n";
}

echo "\n2. Hapus entry manual yang tidak diinginkan:\n";
$deleted = \App\Models\RekamanStok::where('id_produk', 2)
    ->where('keterangan', 'LIKE', '%Perubahan Stok Manual%')
    ->delete();
echo "Dihapus: {$deleted} entry\n";

echo "\n3. Cari entry dengan keterangan 'Penghapusan transaksi penjualan':\n";
$penghapusan = \App\Models\RekamanStok::where('id_produk', 2)
    ->where('keterangan', 'LIKE', '%Penghapusan transaksi penjualan%')
    ->get();

echo "Ditemukan " . $penghapusan->count() . " entry penghapusan:\n";
foreach($penghapusan as $peng) {
    echo "ID: {$peng->id_rekaman_stok} | Waktu: {$peng->waktu} | Masuk: {$peng->stok_masuk} | Keluar: {$peng->stok_keluar} | Keterangan: {$peng->keterangan}\n";
}

$deleted_penghapusan = \App\Models\RekamanStok::where('id_produk', 2)
    ->where('keterangan', 'LIKE', '%Penghapusan transaksi penjualan%')
    ->delete();
echo "Dihapus: {$deleted_penghapusan} entry penghapusan\n";

echo "\n4. Recalculate stok saat ini berdasarkan transaksi yang tersisa:\n";
$produk = \App\Models\Produk::find(2);
echo "Stok produk saat ini: {$produk->stok}\n";

$rekaman_terakhir = \App\Models\RekamanStok::where('id_produk', 2)
    ->orderBy('id_rekaman_stok', 'desc')
    ->first();

if($rekaman_terakhir) {
    echo "Stok_sisa terakhir di rekaman: {$rekaman_terakhir->stok_sisa}\n";
    
    if($produk->stok != $rekaman_terakhir->stok_sisa) {
        echo "Sinkronisasi stok diperlukan!\n";
        $produk->stok = $rekaman_terakhir->stok_sisa;
        $produk->save();
        echo "Stok produk diupdate menjadi: {$produk->stok}\n";
    } else {
        echo "Stok sudah sinkron.\n";
    }
}

echo "\n5. Status akhir - 5 rekaman terakhir:\n";
$final_rekaman = \App\Models\RekamanStok::where('id_produk', 2)
    ->orderBy('id_rekaman_stok', 'desc')
    ->take(5)
    ->get();

foreach($final_rekaman as $r) {
    $penjualan_info = $r->id_penjualan ? " | Penjualan: {$r->id_penjualan}" : "";
    $pembelian_info = $r->id_pembelian ? " | Pembelian: {$r->id_pembelian}" : "";
    echo "ID: {$r->id_rekaman_stok} | Waktu: {$r->waktu} | Masuk: {$r->stok_masuk} | Keluar: {$r->stok_keluar} | Sisa: {$r->stok_sisa}{$penjualan_info}{$pembelian_info} | Keterangan: {$r->keterangan}\n";
}

echo "\n=== PEMBERSIHAN SELESAI ===\n";
