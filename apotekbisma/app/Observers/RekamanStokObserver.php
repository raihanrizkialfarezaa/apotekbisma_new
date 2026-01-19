<?php

namespace App\Observers;

use App\Models\RekamanStok;
use Illuminate\Support\Facades\Log;

class RekamanStokObserver
{
    public function creating(RekamanStok $rekamanStok)
    {
        // Validasi konsistensi perhitungan sebelum menyimpan
        $expected_sisa = $rekamanStok->stok_awal + $rekamanStok->stok_masuk - $rekamanStok->stok_keluar;
        
        if ($expected_sisa != $rekamanStok->stok_sisa) {
            Log::warning("Stock record calculation mismatch on create: Expected {$expected_sisa}, got {$rekamanStok->stok_sisa}. Auto-correcting.", [
                'id_produk' => $rekamanStok->id_produk,
                'stok_awal' => $rekamanStok->stok_awal,
                'stok_masuk' => $rekamanStok->stok_masuk,
                'stok_keluar' => $rekamanStok->stok_keluar,
            ]);
            
            // Auto-correct the calculation
            $rekamanStok->stok_sisa = $expected_sisa;
        }
        
        // LOG negative stock but DO NOT prevent it - this maintains chain integrity
        // The business logic should prevent negative stock BEFORE creating the record
        if ($rekamanStok->stok_sisa < 0) {
            Log::warning("Creating stock record with negative sisa: {$rekamanStok->stok_sisa}", [
                'id_produk' => $rekamanStok->id_produk,
                'keterangan' => $rekamanStok->keterangan,
            ]);
            // DO NOT set to 0 - this breaks chain calculation!
        }
    }
    
    public function updating(RekamanStok $rekamanStok)
    {
        // Validasi konsistensi perhitungan saat update
        $expected_sisa = $rekamanStok->stok_awal + $rekamanStok->stok_masuk - $rekamanStok->stok_keluar;
        
        if ($expected_sisa != $rekamanStok->stok_sisa) {
            Log::warning("Stock record calculation mismatch on update: Expected {$expected_sisa}, got {$rekamanStok->stok_sisa}. Auto-correcting.", [
                'id_rekaman_stok' => $rekamanStok->id_rekaman_stok,
                'id_produk' => $rekamanStok->id_produk,
                'stok_awal' => $rekamanStok->stok_awal,
                'stok_masuk' => $rekamanStok->stok_masuk,
                'stok_keluar' => $rekamanStok->stok_keluar,
            ]);
            
            // Auto-correct the calculation
            $rekamanStok->stok_sisa = $expected_sisa;
        }
        
        // LOG negative stock but DO NOT prevent it - this maintains chain integrity
        if ($rekamanStok->stok_sisa < 0) {
            Log::warning("Updating stock record with negative sisa: {$rekamanStok->stok_sisa}", [
                'id_rekaman_stok' => $rekamanStok->id_rekaman_stok,
                'id_produk' => $rekamanStok->id_produk,
                'keterangan' => $rekamanStok->keterangan,
            ]);
            // DO NOT set to 0 - this breaks chain calculation!
        }
    }
}
