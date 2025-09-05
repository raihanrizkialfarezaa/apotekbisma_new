<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\RekamanStok;
use App\Models\Produk;
use Carbon\Carbon;

class MonitorStockHealth extends Command
{
    protected $signature = 'stock:monitor {--fix : Automatically fix detected issues}';

    protected $description = 'Monitor stock health and detect anomalies';

    public function handle()
    {
        $this->info('🔍 Monitoring Stock Health...');
        
        $this->checkNegativeStock();
        $this->checkInconsistentRecords();
        $this->checkOrphanedRecords();
        
        $this->info('✅ Stock health monitoring completed.');
        
        return 0;
    }

    private function checkNegativeStock()
    {
        $this->info('1. Checking for negative stock products...');
        
        $negativeProducts = Produk::where('stok', '<', 0)->get();
        
        if ($negativeProducts->count() > 0) {
            $this->warn("Found {$negativeProducts->count()} products with negative stock:");
            
            foreach ($negativeProducts as $product) {
                $this->line("   - {$product->nama_produk}: {$product->stok}");
            }
            
            if ($this->option('fix')) {
                $this->info('   Normalizing negative stock to zero...');
                foreach ($negativeProducts as $product) {
                    $product->stok = 0;
                    $product->save();
                    
                    // Create adjustment record
                    RekamanStok::create([
                        'id_produk' => $product->id_produk,
                        'waktu' => Carbon::now(),
                        'stok_masuk' => 0,
                        'stok_keluar' => 0,
                        'stok_awal' => $product->stok,
                        'stok_sisa' => 0,
                        'keterangan' => 'System: Normalisasi stok negatif otomatis'
                    ]);
                }
                $this->info("   ✅ Fixed {$negativeProducts->count()} products");
            }
        } else {
            $this->info('   ✅ No products with negative stock found');
        }
    }

    private function checkInconsistentRecords()
    {
        $this->info('2. Checking for inconsistent stock records...');
        
        $inconsistentRecords = RekamanStok::whereRaw('stok_awal + stok_masuk - stok_keluar != stok_sisa')
            ->count();
        
        if ($inconsistentRecords > 0) {
            $this->warn("   Found {$inconsistentRecords} inconsistent stock records");
            
            if ($this->option('fix')) {
                $this->info('   Fixing inconsistent records...');
                $records = RekamanStok::whereRaw('stok_awal + stok_masuk - stok_keluar != stok_sisa')->get();
                
                foreach ($records as $record) {
                    $record->stok_sisa = $record->stok_awal + $record->stok_masuk - $record->stok_keluar;
                    $record->save();
                }
                $this->info("   ✅ Fixed {$records->count()} records");
            }
        } else {
            $this->info('   ✅ All stock records are consistent');
        }
    }

    private function checkOrphanedRecords()
    {
        $this->info('3. Checking for orphaned stock records...');
        
        $orphanedRecords = RekamanStok::whereDoesntHave('produk')->count();
        
        if ($orphanedRecords > 0) {
            $this->warn("   Found {$orphanedRecords} orphaned stock records");
            
            if ($this->option('fix')) {
                $this->info('   Removing orphaned records...');
                RekamanStok::whereDoesntHave('produk')->delete();
                $this->info("   ✅ Removed {$orphanedRecords} orphaned records");
            }
        } else {
            $this->info('   ✅ No orphaned records found');
        }
    }
}
