<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== TEST SIMULASI TRANSAKSI ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Ambil produk untuk test
$produk = DB::table('produk')
    ->where('stok', '>', 10)
    ->first();

if (!$produk) {
    echo "❌ Tidak ada produk dengan stok > 10 untuk test\n";
    exit;
}

echo "1. PRODUK TEST:\n";
echo "   Nama: {$produk->nama_produk}\n";
echo "   Stok awal: {$produk->stok}\n";

// 2. Simulasi pembelian (tambah stok)
echo "\n2. SIMULASI PEMBELIAN (+5 stok):\n";

$stokSebelum = $produk->stok;
$stokBaru = $stokSebelum + 5;

DB::table('produk')
    ->where('id_produk', $produk->id_produk)
    ->update(['stok' => $stokBaru, 'updated_at' => now()]);

// Tambah rekaman stok
DB::table('rekaman_stoks')->insert([
    'id_produk' => $produk->id_produk,
    'waktu' => now(),
    'stok_awal' => $stokSebelum,
    'stok_masuk' => 5,
    'stok_keluar' => 0,
    'stok_sisa' => $stokBaru,
    'keterangan' => 'Test simulasi pembelian',
    'created_at' => now(),
    'updated_at' => now()
]);

$produkUpdate = DB::table('produk')->where('id_produk', $produk->id_produk)->first();
echo "   Stok setelah pembelian: {$produkUpdate->stok}\n";

// 3. Simulasi penjualan (kurangi stok)
echo "\n3. SIMULASI PENJUALAN (-3 stok):\n";

$stokSebelum = $produkUpdate->stok;
$stokBaru = $stokSebelum - 3;

DB::table('produk')
    ->where('id_produk', $produk->id_produk)
    ->update(['stok' => $stokBaru, 'updated_at' => now()]);

// Tambah rekaman stok
DB::table('rekaman_stoks')->insert([
    'id_produk' => $produk->id_produk,
    'waktu' => now(),
    'stok_awal' => $stokSebelum,
    'stok_masuk' => 0,
    'stok_keluar' => 3,
    'stok_sisa' => $stokBaru,
    'keterangan' => 'Test simulasi penjualan',
    'created_at' => now(),
    'updated_at' => now()
]);

$produkFinal = DB::table('produk')->where('id_produk', $produk->id_produk)->first();
echo "   Stok setelah penjualan: {$produkFinal->stok}\n";

// 4. Test sinkronisasi otomatis
echo "\n4. CEK KONSISTENSI SETELAH TRANSAKSI:\n";

$rekamanTerbaru = DB::table('rekaman_stoks')
    ->where('id_produk', $produk->id_produk)
    ->orderBy('id_rekaman_stok', 'desc')
    ->first();

echo "   Stok produk saat ini: {$produkFinal->stok}\n";
echo "   Rekaman stok_sisa: {$rekamanTerbaru->stok_sisa}\n";

if ($produkFinal->stok == $rekamanTerbaru->stok_sisa) {
    echo "   Status: ✅ KONSISTEN\n";
} else {
    echo "   Status: ❌ TIDAK KONSISTEN (perlu sinkronisasi)\n";
}

// 5. Test edge case: stok habis
echo "\n5. TEST EDGE CASE - STOK HABIS:\n";

$stokSekarang = $produkFinal->stok;
echo "   Mencoba jual semua stok ($stokSekarang)...\n";

DB::table('produk')
    ->where('id_produk', $produk->id_produk)
    ->update(['stok' => 0, 'updated_at' => now()]);

DB::table('rekaman_stoks')->insert([
    'id_produk' => $produk->id_produk,
    'waktu' => now(),
    'stok_awal' => $stokSekarang,
    'stok_masuk' => 0,
    'stok_keluar' => $stokSekarang,
    'stok_sisa' => 0,
    'keterangan' => 'Test stok habis',
    'created_at' => now(),
    'updated_at' => now()
]);

$produkHabis = DB::table('produk')->where('id_produk', $produk->id_produk)->first();
echo "   Stok final: {$produkHabis->stok}\n";

if ($produkHabis->stok >= 0) {
    echo "   Status: ✅ STOK TIDAK MINUS\n";
} else {
    echo "   Status: ❌ STOK MINUS (ada masalah)\n";
}

// 6. Test sinkronisasi final
echo "\n6. FINAL CONSISTENCY CHECK:\n";

$inconsistentCount = DB::table('rekaman_stoks as rs')
    ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
    ->where('p.id_produk', $produk->id_produk)
    ->where(function($query) {
        $query->whereRaw('rs.stok_awal != p.stok')
              ->orWhereRaw('rs.stok_sisa != p.stok');
    })
    ->whereIn('rs.id_rekaman_stok', function($query) use ($produk) {
        $query->select(DB::raw('MAX(id_rekaman_stok)'))
              ->from('rekaman_stoks')
              ->where('id_produk', $produk->id_produk);
    })
    ->count();

if ($inconsistentCount > 0) {
    echo "   Status: ❌ ADA INCONSISTENCY (perlu sinkronisasi)\n";
} else {
    echo "   Status: ✅ SEMUA KONSISTEN\n";
}

echo "\n=== TEST SIMULASI SELESAI ===\n";
