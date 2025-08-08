<?php

namespace App\Observers;

use App\Models\Produk;
use Illuminate\Support\Facades\Log;

class ProdukObserver
{
    /**
     * Handle the Produk "saving" event.
     */
    public function saving(Produk $produk)
    {
        // Pastikan stok tidak pernah negatif
        if ($produk->stok < 0) {
            $produk->stok = 0;
        }
    }

    /**
     * Handle the Produk "saved" event.
     */
    public function saved(Produk $produk)
    {
        // Log jika ada normalisasi stok
        if ($produk->isDirty('stok') && $produk->getOriginal('stok') < 0) {
            Log::info("Stok produk {$produk->nama_produk} dinormalisasi dari {$produk->getOriginal('stok')} menjadi {$produk->stok}");
        }
    }
}
