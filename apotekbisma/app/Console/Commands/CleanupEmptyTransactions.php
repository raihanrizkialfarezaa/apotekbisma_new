<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;

class CleanupEmptyTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transactions:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup empty transactions that have no details';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $emptyTransactions = Penjualan::whereDoesntHave('detail')->get();
        
        foreach ($emptyTransactions as $transaction) {
            $this->info("Menghapus transaksi kosong ID: {$transaction->id_penjualan}");
            $transaction->delete();
        }
        
        $this->info("Berhasil menghapus " . count($emptyTransactions) . " transaksi kosong.");
        
        return 0;
    }
}
