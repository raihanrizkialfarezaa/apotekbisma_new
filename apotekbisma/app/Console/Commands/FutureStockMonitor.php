<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Produk;

class FutureStockMonitor extends Command
{
    protected $signature = 'future:monitor {--alert} {--report}';
    protected $description = 'Monitor future stock transactions and detect anomalies';

    public function handle()
    {
        $this->info('🛡️ Future Stock Protection Monitor');
        $this->info('================================');
        
        // Check today's transactions
        $todayTransactions = DB::table('future_transaction_tracking')
            ->whereDate('transaction_date', today())
            ->get();
            
        $this->info('📊 Today transactions: ' . $todayTransactions->count());
        
        if ($this->option('report')) {
            $this->generateReport($todayTransactions);
        }
        
        if ($this->option('alert')) {
            $this->checkAlerts($todayTransactions);
        }
        
        // Check consistency
        $inconsistent = $todayTransactions->where('is_consistent', false);
        if ($inconsistent->count() > 0) {
            $this->error('⚠️ Found ' . $inconsistent->count() . ' inconsistent transactions');
            return 1;
        }
        
        $this->info('✅ All transactions consistent');
        return 0;
    }
    
    private function generateReport($transactions)
    {
        $this->info('\nđź"‹ Transaction Report:');
        $this->info('=====================');
        
        foreach ($transactions as $trans) {
            $product = Produk::find($trans->produk_id);
            $this->line(sprintf(
                '%s %s: %+d units at %s',
                $trans->transaction_type === 'penjualan' ? '💰' : '📦',
                $product->nama_produk ?? 'Unknown',
                $trans->quantity_change,
                $trans->transaction_date
            ));
        }
    }
    
    private function checkAlerts($transactions)
    {
        $this->info('\n🚨 Alert Check:');
        $this->info('==============');
        
        // Check for large transactions
        $largeTransactions = $transactions->where(function($trans) {
            return abs($trans->quantity_change) > 100;
        });
        
        if ($largeTransactions->count() > 0) {
            $this->warn('⚠️ Found ' . $largeTransactions->count() . ' large transactions (>100 units)');
        } else {
            $this->info('✅ No large transactions detected');
        }
    }
}