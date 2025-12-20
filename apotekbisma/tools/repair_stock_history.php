<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\RekamanStok;
use App\Models\Produk;
use Illuminate\Support\Facades\DB;

echo "=== SCRIPT PERBAIKAN REKAMAN STOK ===\n";
echo "Mulai perbaikan...\n\n";

// Disable query log to save memory
DB::connection()->disableQueryLog();

$products = Produk::all();
$count = 0;
$total = $products->count();

foreach ($products as $product) {
    $count++;
    echo "[{$count}/{$total}] Processing Product ID: {$product->id_produk} ({$product->nama_produk})... ";

    $records = RekamanStok::where('id_produk', $product->id_produk)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();

    if ($records->isEmpty()) {
        echo "No records.\n";
        continue;
    }

    $currentStock = 0;
    $updatedCount = 0;

    DB::beginTransaction();
    try {
        foreach ($records as $record) {
            $originalSisa = $record->stok_sisa;
            $originalAwal = $record->stok_awal;

            // Update stok_awal
            $record->stok_awal = $currentStock;

            // Calculate new sisa
            if ($record->jenis_transaksi == 'pembelian' || $record->jenis_transaksi == 'masuk') { // Adjust based on actual values
                 // Assuming stok_masuk is populated
                 $currentStock += $record->stok_masuk;
            } else {
                 $currentStock -= $record->stok_keluar;
            }
            
            // Fallback if logic is strictly based on columns
            // $currentStock = $record->stok_awal + $record->stok_masuk - $record->stok_keluar;
            // But we just set stok_awal to currentStock.
            
            // Let's stick to the logic:
            // New Sisa = Previous Sisa (Current Stock) + Masuk - Keluar
            $newSisa = $record->stok_awal + $record->stok_masuk - $record->stok_keluar;
            $record->stok_sisa = $newSisa;
            $currentStock = $newSisa;

            if ($originalSisa != $record->stok_sisa || $originalAwal != $record->stok_awal) {
                $record->save();
                $updatedCount++;
            }
        }

        // Update Master Product Stock
        if ($product->stok != $currentStock) {
            echo "Updating Product Stock from {$product->stok} to {$currentStock}. ";
            $product->stok = $currentStock;
            $product->save();
        }

        DB::commit();
        echo "Updated {$updatedCount} records.\n";

    } catch (\Exception $e) {
        DB::rollBack();
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\nSelesai.\n";
