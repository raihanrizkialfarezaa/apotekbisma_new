<?php

namespace App\Console\Commands;

use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\PenjualanDetail;
use App\Models\PembelianDetail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SyncStockConsistency extends Command
{
    protected $signature = 'stock:sync {--fix : Automatically fix inconsistencies}';
    
    protected $description = 'Sync and verify stock consistency across all products';

    public function handle()
    {
        $this->info('🔄 Starting Stock Consistency Check...');
        
        $products = Produk::all();
        $inconsistencies = 0;
        $fixes = 0;
        
        foreach ($products as $produk) {
            $this->line("Checking: {$produk->nama_produk}");
            
            // Hitung stok berdasarkan transaksi
            $totalPembelian = DB::table('pembelian_detail')
                ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
                ->where('pembelian_detail.id_produk', $produk->id_produk)
                ->where('pembelian.no_faktur', '!=', 'o')
                ->whereNotNull('pembelian.no_faktur')
                ->where('pembelian.bayar', '>', 0)
                ->sum('pembelian_detail.jumlah');
            
            $totalPenjualan = DB::table('penjualan_detail')
                ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
                ->where('penjualan_detail.id_produk', $produk->id_produk)
                ->where('penjualan.bayar', '>', 0)
                ->sum('penjualan_detail.jumlah');
            
            $perubahanManual = RekamanStok::where('id_produk', $produk->id_produk)
                ->whereNull('id_pembelian')
                ->whereNull('id_penjualan')
                ->get()
                ->sum(function($item) {
                    return $item->stok_masuk - $item->stok_keluar;
                });
            
            $stokSeharusnya = max(0, $totalPembelian - $totalPenjualan + $perubahanManual);
            $stokAktual = $produk->stok;
            
            if ($stokSeharusnya != $stokAktual) {
                $inconsistencies++;
                $selisih = $stokAktual - $stokSeharusnya;
                
                $this->warn("  ❌ Inconsistency: Expected {$stokSeharusnya}, Actual {$stokAktual}, Diff {$selisih}");
                
                if ($this->option('fix')) {
                    // Fix the inconsistency
                    $produk->stok = $stokSeharusnya;
                    $produk->save();
                    
                    // Create reconciliation record
                    RekamanStok::create([
                        'id_produk' => $produk->id_produk,
                        'waktu' => Carbon::now(),
                        'stok_masuk' => $selisih > 0 ? 0 : abs($selisih),
                        'stok_keluar' => $selisih > 0 ? $selisih : 0,
                        'stok_awal' => $stokAktual,
                        'stok_sisa' => $stokSeharusnya,
                        'keterangan' => 'Auto Sync: Sistem penyesuaian otomatis selisih ' . $selisih
                    ]);
                    
                    $fixes++;
                    $this->info("  ✅ Fixed to: {$stokSeharusnya}");
                }
            } else {
                $this->info("  ✅ Consistent");
            }
        }
        
        $this->info("\n📊 Summary:");
        $this->info("Total products checked: " . $products->count());
        $this->info("Inconsistencies found: {$inconsistencies}");
        
        if ($this->option('fix')) {
            $this->info("Inconsistencies fixed: {$fixes}");
            $this->info("🎉 Stock synchronization completed successfully!");
        } else {
            if ($inconsistencies > 0) {
                $this->warn("💡 Run with --fix option to automatically correct inconsistencies");
            }
        }
        
        return Command::SUCCESS;
    }
}
