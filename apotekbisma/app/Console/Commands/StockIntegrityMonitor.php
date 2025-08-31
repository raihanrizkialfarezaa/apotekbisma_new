<?php

namespace App\Console\Commands;

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Console\Command;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class StockIntegrityMonitor extends Command
{
    protected $signature = 'stock:monitor';
    protected $description = 'Monitor stock integrity and auto-fix minor issues';

    public function handle()
    {
        $this->info('🔍 Starting Stock Integrity Monitor...');
        
        $issues_found = 0;
        $auto_fixed = 0;
        $critical_issues = [];
        
        $products = Produk::all();
        
        foreach ($products as $product) {
            $calculated_stock = $this->calculateStockFromRecords($product->id_produk);
            
            if ($calculated_stock !== null && $calculated_stock != $product->stok) {
                $issues_found++;
                $difference = abs($calculated_stock - $product->stok);
                
                if ($difference <= 5 && $calculated_stock >= 0) {
                    $this->autoFixStock($product, $calculated_stock);
                    $auto_fixed++;
                    $this->info("✅ Auto-fixed: {$product->nama_produk} ({$product->stok} → {$calculated_stock})");
                } else {
                    $critical_issues[] = [
                        'product' => $product->nama_produk,
                        'id' => $product->id_produk,
                        'current' => $product->stok,
                        'calculated' => $calculated_stock,
                        'difference' => $difference
                    ];
                    $this->error("❌ Critical issue: {$product->nama_produk} (DB: {$product->stok}, Calc: {$calculated_stock})");
                }
            }
        }
        
        if (count($critical_issues) > 0) {
            Log::critical('Stock integrity issues detected', [
                'count' => count($critical_issues),
                'issues' => $critical_issues,
                'timestamp' => Carbon::now()
            ]);
            
            $this->error("🚨 CRITICAL: " . count($critical_issues) . " major stock inconsistencies detected!");
            $this->error("📧 Administrator has been notified via logs");
        }
        
        $this->info("📊 Monitoring complete:");
        $this->info("• Issues found: {$issues_found}");
        $this->info("• Auto-fixed: {$auto_fixed}");
        $this->info("• Critical issues: " . count($critical_issues));
        
        return count($critical_issues) == 0 ? 0 : 1;
    }
    
    private function calculateStockFromRecords($id_produk)
    {
        $records = RekamanStok::where('id_produk', $id_produk)
                              ->orderBy('waktu', 'asc')
                              ->get();
        
        if ($records->isEmpty()) {
            return null;
        }
        
        $stock = $records->first()->stok_awal ?? 0;
        
        foreach ($records as $record) {
            if ($record->stok_masuk > 0) {
                $stock += $record->stok_masuk;
            }
            if ($record->stok_keluar > 0) {
                $stock -= $record->stok_keluar;
            }
        }
        
        return max(0, $stock);
    }
    
    private function autoFixStock($product, $correct_stock)
    {
        $old_stock = $product->stok;
        $product->stok = $correct_stock;
        $product->save();
        
        RekamanStok::create([
            'id_produk' => $product->id_produk,
            'waktu' => Carbon::now(),
            'stok_masuk' => $correct_stock > $old_stock ? ($correct_stock - $old_stock) : 0,
            'stok_keluar' => $old_stock > $correct_stock ? ($old_stock - $correct_stock) : 0,
            'stok_awal' => $old_stock,
            'stok_sisa' => $correct_stock,
            'keterangan' => 'AUTO-FIX: Stock integrity correction by monitor'
        ]);
        
        Log::info("Stock auto-corrected: {$product->nama_produk} from {$old_stock} to {$correct_stock}");
    }
}
