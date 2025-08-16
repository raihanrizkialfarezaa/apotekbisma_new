<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Creating test inconsistent data...\n";

// Mengambil satu produk untuk dijadikan test data
$produk = DB::table('produk')
    ->where('stok', '>', 0)
    ->where('stok', '<', 1000)
    ->first();

if (!$produk) {
    echo "No suitable product found for testing\n";
    exit;
}

echo "Using product: {$produk->nama_produk} (ID: {$produk->id_produk})\n";
echo "Current stock: {$produk->stok}\n";

// Update stok di tabel produk ke nilai yang berbeda
$newStock = $produk->stok + 25; // Buat perbedaan yang jelas
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
    'stok_sisa' => $produk->stok - 10, // Nilai yang tidak konsisten
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

// Get details
$details = DB::table('rekaman_stoks as rs')
    ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
    ->select('p.nama_produk', 'p.stok as current_stok', 'rs.stok_awal', 'rs.stok_sisa', 'p.id_produk')
    ->where(function($query) {
        $query->whereRaw('rs.stok_awal != p.stok')
              ->orWhereRaw('rs.stok_sisa != p.stok');
    })
    ->whereIn('rs.id_rekaman_stok', function($query) {
        $query->select(DB::raw('MAX(id_rekaman_stok)'))
              ->from('rekaman_stoks')
              ->groupBy('id_produk');
    })
    ->get();

echo "\nInconsistent records details:\n";
foreach ($details as $detail) {
    echo "- {$detail->nama_produk} (ID: {$detail->id_produk}): Stock={$detail->current_stok}, StokAwal={$detail->stok_awal}, StokSisa={$detail->stok_sisa}\n";
}

echo "\nTest data created successfully! You can now test the web interface.\n";
