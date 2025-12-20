<?php

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use App\Models\Kategori;

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Testing Backdated Input Logic...\n";

$kategori = Kategori::first();
if (!$kategori) {
    $kategori = Kategori::create(['nama_kategori' => 'Test Category']);
}

// 1. Create a dummy product
$produk = Produk::create([
    'nama_produk' => 'TEST_BACKDATE_' . time(),
    'id_kategori' => $kategori->id_kategori,
    'stok' => 0,
    'harga_beli' => 1000,
    'harga_jual' => 2000,
    'kode_produk' => 'TEST' . time(), // Just in case
]);

echo "Created Product: {$produk->nama_produk} (ID: {$produk->id_produk})\n";

// 2. Add transaction for TODAY
echo "Adding transaction for TODAY (+100)...\n";
RekamanStok::create([
    'id_produk' => $produk->id_produk,
    'waktu' => Carbon::now(),
    'stok_awal' => 0, // Logic should handle this, but let's see
    'stok_masuk' => 100,
    'stok_keluar' => 0,
    'stok_sisa' => 100,
    'keterangan' => 'Today Transaction',
]);

// Refresh product
$produk->refresh();
echo "Stock after Today's transaction: {$produk->stok} (Expected: 100)\n";

// 3. Add transaction for YESTERDAY (Backdated)
echo "Adding transaction for YESTERDAY (+50)...\n";
RekamanStok::create([
    'id_produk' => $produk->id_produk,
    'waktu' => Carbon::yesterday(),
    'stok_awal' => 0,
    'stok_masuk' => 50,
    'stok_keluar' => 0,
    'stok_sisa' => 50, // Initial guess, but system should recalculate
    'keterangan' => 'Yesterday Transaction',
]);

// 4. Verify Results
$produk->refresh();
echo "Stock after Yesterday's transaction: {$produk->stok} (Expected: 150)\n";

$history = RekamanStok::where('id_produk', $produk->id_produk)
    ->orderBy('waktu', 'asc')
    ->get();

echo "\nTransaction History:\n";
foreach ($history as $record) {
    echo "ID: {$record->id} | Date: {$record->waktu} | In: {$record->stok_masuk} | Out: {$record->stok_keluar} | Sisa: {$record->stok_sisa}\n";
}

// Cleanup
// $produk->delete();
// RekamanStok::where('id_produk', $produk->id)->delete();
