<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Mengambil satu produk untuk dijadikan test data
$produk = DB::table('produk')
    ->where('stok', '>', 0)
    ->where('stok', '<', 1000)
    ->first();

if (!$produk) {
    echo "No suitable product found for testing\n";
    exit;
}

echo "Creating test inconsistent data for: {$produk->nama_produk}\n";
echo "Current stock: {$produk->stok}\n";

// Update stok di tabel produk ke nilai yang berbeda
$newStock = $produk->stok + 10;
DB::table('produk')
    ->where('id_produk', $produk->id_produk)
    ->update(['stok' => $newStock]);

echo "Updated product stock to: {$newStock}\n";

// Buat rekaman stok yang tidak konsisten
DB::table('rekaman_stoks')->insert([
    'id_produk' => $produk->id_produk,
    'waktu' => now(),
    'stok_awal' => $produk->stok, // Nilai lama
    'stok_masuk' => 0,
    'stok_keluar' => 0,
    'stok_sisa' => $produk->stok - 5, // Nilai yang tidak konsisten
    'created_at' => now(),
    'updated_at' => now()
]);

echo "Created inconsistent rekaman_stok record\n";

// Verify the inconsistency
$count = DB::table('rekaman_stoks as rs')
    ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
    ->where(function($query) {
        $query->whereRaw('rs.stok_awal != p.stok')
              ->orWhereRaw('rs.stok_sisa != p.stok');
    })
    ->whereIn('rs.id_rekaman_stok', function($query) {
        $query->select(DB::raw('MAX(id_rekaman_stok)'))
              ->from('rekaman_stoks')
              ->groupBy('id_produk');
    })
    ->count();

echo "Now found {$count} inconsistent records\n";

echo "\nTest data created successfully! You can now test the web interface.\n";
