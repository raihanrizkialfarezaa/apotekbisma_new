<?php

namespace App\Console\Commands;

use App\Models\Produk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NormalizeStock extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stock:normalize';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Normalize negative stock values to zero';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting stock normalization...');
        
        // Cari semua produk dengan stok negatif
        $negativeStockProducts = Produk::where('stok', '<', 0)->get();
        
        if ($negativeStockProducts->count() === 0) {
            $this->info('No products with negative stock found.');
            return 0;
        }
        
        $this->info("Found {$negativeStockProducts->count()} products with negative stock:");
        
        foreach ($negativeStockProducts as $produk) {
            $this->line("- {$produk->nama_produk} (Kode: {$produk->kode_produk}) - Stock: {$produk->stok}");
        }
        
        if ($this->confirm('Do you want to normalize these stocks to zero?')) {
            // Update semua produk dengan stok negatif menjadi 0
            $updated = Produk::where('stok', '<', 0)->update(['stok' => 0]);
            
            $this->info("Successfully normalized {$updated} products' stock to zero.");
            
            // Log the normalization
            Log::info("Stock normalization completed. {$updated} products updated to zero stock.", [
                'timestamp' => now(),
                'updated_products' => $negativeStockProducts->pluck('kode_produk')->toArray()
            ]);
            
        } else {
            $this->info('Stock normalization cancelled.');
        }
        
        return 0;
    }
}
