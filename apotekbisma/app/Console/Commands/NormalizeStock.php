<?php

namespace App\Console\Commands;

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NormalizeStock extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stock:normalize 
                            {--dry-run : Preview changes without saving}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Normalize negative stock values to zero';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('=== STOCK NORMALIZATION ===');
        $this->info('Date: ' . now()->format('Y-m-d H:i:s'));

        // Ambil semua produk yang memiliki stok negatif
        $produkMinus = Produk::where('stok', '<', 0)->get();

        if ($produkMinus->isEmpty()) {
            $this->info('✓ No products with negative stock found. Database is clean.');
            return Command::SUCCESS;
        }

        $this->warn("Found {$produkMinus->count()} products with negative stock:");
        
        $headers = ['Product Name', 'Code', 'Current Stock', 'Will Become'];
        $rows = [];

        foreach ($produkMinus as $produk) {
            $rows[] = [
                substr($produk->nama_produk, 0, 30),
                $produk->kode_produk,
                $produk->stok,
                '0'
            ];
        }

        $this->table($headers, $rows);

        if ($dryRun) {
            $this->info('*** DRY RUN MODE - No changes will be saved ***');
            return Command::SUCCESS;
        }

        if (!$force && !$this->confirm('Do you want to normalize all negative stock to zero?')) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        $updatedCount = 0;

        DB::beginTransaction();

        try {
            $progressBar = $this->output->createProgressBar($produkMinus->count());
            $progressBar->start();

            foreach ($produkMinus as $produk) {
                $oldStock = $produk->stok;
                
                // Update stok menjadi 0
                $produk->stok = 0;
                $produk->save();
                
                // Buat rekaman stok untuk audit trail
                RekamanStok::create([
                    'id_produk' => $produk->id_produk,
                    'waktu' => now(),
                    'stok_masuk' => abs($oldStock), // Jumlah yang dinormalisasi
                    'stok_awal' => $oldStock,
                    'stok_sisa' => 0,
                    'keterangan' => 'Normalisasi Stok: Koreksi stok minus menjadi 0 (sistem otomatis)'
                ]);
                
                $updatedCount++;
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);

            DB::commit();
            
            $this->info("✓ Normalization completed successfully!");
            $this->info("Total products normalized: {$updatedCount}");
            $this->info("All negative stock values have been changed to 0");
            $this->info("Change records have been saved for audit trail");
            
            // Log the normalization
            Log::info("Stock normalization completed via artisan command.", [
                'timestamp' => now(),
                'updated_count' => $updatedCount,
                'updated_products' => $produkMinus->pluck('kode_produk')->toArray()
            ]);
            
        } catch (\Exception $e) {
            DB::rollback();
            $this->error("❌ ERROR: " . $e->getMessage());
            $this->error("Rollback performed. No changes were saved.");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
