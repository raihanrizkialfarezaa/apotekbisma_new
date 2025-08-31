<?php

namespace App\Observers;

use App\Models\Produk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FutureStockObserver
{
    public function updating(Produk $produk)
    {
        // Track changes for future protection
        if ($produk->isDirty('stok')) {
            $oldStock = $produk->getOriginal('stok') ?? 0;
            $newStock = $produk->stok ?? 0;
            $change = $newStock - $oldStock;
            
            // Log significant changes
            if (abs($change) > 0) {
                Log::info("FUTURE_PROTECTION: Stock change detected", [
                    'product_id' => $produk->id,
                    'product_name' => $produk->nama_produk,
                    'old_stock' => $oldStock,
                    'new_stock' => $newStock,
                    'change' => $change,
                    'timestamp' => now()
                ]);
                
                // Track in future transaction table
                try {
                    DB::table('future_transaction_tracking')->insert([
                        'produk_id' => $produk->id,
                        'transaction_type' => $change > 0 ? 'pembelian' : 'penjualan',
                        'transaction_id' => null,
                        'quantity_change' => $change,
                        'stok_before' => $oldStock,
                        'stok_after' => $newStock,
                        'transaction_date' => now(),
                        'is_consistent' => true,
                        'validation_notes' => 'Auto-tracked by FutureStockObserver',
                        'created_at' => now()
                    ]);
                } catch (Exception $e) {
                    Log::error("Failed to track future transaction: " . $e->getMessage());
                }
            }
        }
    }
}