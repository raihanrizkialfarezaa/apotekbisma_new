<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\PenjualanDetail;
use App\Models\PembelianDetail;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FixStockInconsistencyComprehensive extends Command
{
    protected $signature = 'stock:fix-comprehensive {--dry-run : Preview changes without applying them} {--product-id= : Fix specific product only}';
    protected $description = 'Fix comprehensive stock inconsistencies and data corruption';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $productId = $this->option('product-id');
        
        $this->info('=== COMPREHENSIVE STOCK FIX ===');
        $this->info('Mode: ' . ($dryRun ? 'DRY RUN (preview only)' : 'LIVE EXECUTION'));
        
        if ($dryRun) {
            $this->warn('This is a DRY RUN - no changes will be made');
        }
        
        $this->info('');
        
        if ($productId) {
            $produkList = Produk::where('id_produk', $productId)->get();
            if ($produkList->isEmpty()) {
                $this->error("Product with ID {$productId} not found");
                return Command::FAILURE;
            }
        } else {
            $produkList = Produk::all();
        }
        
        $stats = [
            'products_checked' => 0,
            'products_fixed' => 0,
            'records_created' => 0,
            'records_updated' => 0,
            'inconsistencies_found' => 0
        ];
        
        DB::beginTransaction();
        
        try {
            foreach ($produkList as $produk) {
                $this->info("Processing: {$produk->nama_produk} (ID: {$produk->id_produk})");
                
                // 1. Calculate correct stock from transactions
                $totalPembelian = PembelianDetail::join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
                    ->where('pembelian_detail.id_produk', $produk->id_produk)
                    ->where('pembelian.no_faktur', '!=', 'o')
                    ->whereNotNull('pembelian.no_faktur')
                    ->sum('pembelian_detail.jumlah');
                
                $totalPenjualan = PenjualanDetail::join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
                    ->where('penjualan_detail.id_produk', $produk->id_produk)
                    ->where('penjualan.bayar', '>', 0)
                    ->sum('penjualan_detail.jumlah');
                
                // 2. Calculate manual adjustments
                $perubahanManual = RekamanStok::where('id_produk', $produk->id_produk)
                    ->whereNull('id_pembelian')
                    ->whereNull('id_penjualan')
                    ->where(function($query) {
                        $query->where('keterangan', 'LIKE', '%Update Stok Manual%')
                              ->orWhere('keterangan', 'LIKE', '%Perubahan Stok Manual%');
                    })
                    ->get()
                    ->sum(function($item) {
                        return $item->stok_masuk - $item->stok_keluar;
                    });
                
                $stokSeharusnya = $totalPembelian - $totalPenjualan + $perubahanManual;
                $stokAktual = $produk->stok;
                
                $this->line("  Current stock: {$stokAktual}");
                $this->line("  Calculated stock: {$stokSeharusnya} (Buy: {$totalPembelian}, Sell: {$totalPenjualan}, Manual: {$perubahanManual})");
                
                if ($stokSeharusnya != $stokAktual) {
                    $this->warn("  ❌ INCONSISTENCY FOUND: Difference of " . ($stokAktual - $stokSeharusnya));
                    $stats['inconsistencies_found']++;
                    
                    if (!$dryRun) {
                        // Fix the stock
                        $oldStock = $produk->stok;
                        $produk->stok = $stokSeharusnya;
                        $produk->save();
                        
                        // Create audit record
                        RekamanStok::create([
                            'id_produk' => $produk->id_produk,
                            'waktu' => Carbon::now(),
                            'stok_masuk' => $stokSeharusnya > $oldStock ? ($stokSeharusnya - $oldStock) : 0,
                            'stok_keluar' => $stokSeharusnya < $oldStock ? ($oldStock - $stokSeharusnya) : 0,
                            'stok_awal' => $oldStock,
                            'stok_sisa' => $stokSeharusnya,
                            'keterangan' => 'SYSTEM FIX: Comprehensive stock correction - automated fix'
                        ]);
                        
                        $stats['products_fixed']++;
                        $stats['records_created']++;
                        $this->info("  ✅ FIXED: Stock corrected to {$stokSeharusnya}");
                    }
                } else {
                    $this->info("  ✅ CONSISTENT");
                }
                
                // 3. Fix inconsistent stock records
                $inconsistentRecords = RekamanStok::where('id_produk', $produk->id_produk)
                    ->whereRaw('(stok_awal - stok_keluar + stok_masuk) != stok_sisa')
                    ->get();
                
                if ($inconsistentRecords->count() > 0) {
                    $this->warn("  Found {$inconsistentRecords->count()} inconsistent stock records");
                    
                    foreach ($inconsistentRecords as $record) {
                        $correctStokSisa = $record->stok_awal - $record->stok_keluar + $record->stok_masuk;
                        
                        $this->line("    Record {$record->id_rekaman_stok}: stok_sisa {$record->stok_sisa} -> {$correctStokSisa}");
                        
                        if (!$dryRun) {
                            $record->stok_sisa = $correctStokSisa;
                            $record->save();
                            $stats['records_updated']++;
                        }
                    }
                }
                
                $stats['products_checked']++;
                $this->line('');
            }
            
            if (!$dryRun) {
                DB::commit();
                $this->info('✅ All changes committed successfully');
            } else {
                DB::rollBack();
                $this->info('✅ Dry run completed - no changes made');
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('❌ Error occurred: ' . $e->getMessage());
            return Command::FAILURE;
        }
        
        $this->info('');
        $this->info('=== SUMMARY ===');
        $this->info("Products checked: {$stats['products_checked']}");
        $this->info("Inconsistencies found: {$stats['inconsistencies_found']}");
        $this->info("Products fixed: {$stats['products_fixed']}");
        $this->info("Records created: {$stats['records_created']}");
        $this->info("Records updated: {$stats['records_updated']}");
        
        return Command::SUCCESS;
    }
}
