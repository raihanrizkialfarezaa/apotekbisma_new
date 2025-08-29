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
            Log::error("Stock record calculation error: Expected {$expected_sisa}, got {$rekamanStok->stok_sisa}");
            
            // Auto-correct the calculation
            $rekamanStok->stok_sisa = $expected_sisa;
            
            Log::info("Auto-corrected stock record calculation to: {$expected_sisa}");
        }
        
        // Pastikan stok_sisa tidak negatif
        if ($rekamanStok->stok_sisa < 0) {
            Log::warning("Prevented negative stock in record: {$rekamanStok->stok_sisa} set to 0");
            $rekamanStok->stok_sisa = 0;
        }
    }
    
    public function updating(RekamanStok $rekamanStok)
    {
        // Validasi konsistensi perhitungan saat update
        $expected_sisa = $rekamanStok->stok_awal + $rekamanStok->stok_masuk - $rekamanStok->stok_keluar;
        
        if ($expected_sisa != $rekamanStok->stok_sisa) {
            Log::error("Stock record update calculation error: Expected {$expected_sisa}, got {$rekamanStok->stok_sisa}");
            
            // Auto-correct the calculation
            $rekamanStok->stok_sisa = $expected_sisa;
            
            Log::info("Auto-corrected stock record update calculation to: {$expected_sisa}");
        }
        
        // Pastikan stok_sisa tidak negatif
        if ($rekamanStok->stok_sisa < 0) {
            Log::warning("Prevented negative stock in record update: {$rekamanStok->stok_sisa} set to 0");
            $rekamanStok->stok_sisa = 0;
        }
    }
}
