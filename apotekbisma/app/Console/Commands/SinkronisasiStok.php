<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SinkronisasiStok extends Command
{
    protected $signature = 'stok:sinkronisasi';

    protected $description = 'Sinkronisasi data stok produk dengan rekaman stok';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Memulai sinkronisasi stok...');
        
        $produkList = Produk::all();
        $updated = 0;
        $synchronized = 0;
        
        foreach ($produkList as $produk) {
            $latestRekaman = RekamanStok::where('id_produk', $produk->id_produk)
                ->orderBy('waktu', 'desc')
                ->orderBy('id_rekaman_stok', 'desc')
                ->first();
            
            if (!$latestRekaman) {
                continue;
            }
            
            $stokProduk = intval($produk->stok);
            $stokSisa = intval($latestRekaman->stok_sisa);
            
            if ($stokProduk === $stokSisa) {
                $synchronized++;
                continue;
            }
            
            DB::beginTransaction();
            
            try {
                RekamanStok::$skipMutators = true;
                
                $selisih = $stokProduk - $stokSisa;
                
                $newRekaman = new RekamanStok();
                $newRekaman->id_produk = $produk->id_produk;
                $newRekaman->waktu = Carbon::now();
                $newRekaman->stok_awal = $stokSisa;
                
                if ($selisih > 0) {
                    $newRekaman->stok_masuk = $selisih;
                    $newRekaman->stok_keluar = 0;
                } else {
                    $newRekaman->stok_masuk = 0;
                    $newRekaman->stok_keluar = abs($selisih);
                }
                
                $newRekaman->stok_sisa = $stokProduk;
                $newRekaman->keterangan = 'Sinkronisasi otomatis stok produk';
                
                $newRekaman->save();
                
                RekamanStok::$skipMutators = false;
                
                DB::commit();
                $updated++;
                
                $this->line("Produk: {$produk->nama_produk} - Selisih: {$selisih} - Berhasil disinkronkan");
                
            } catch (\Exception $e) {
                DB::rollback();
                RekamanStok::$skipMutators = false;
                $this->error("Error pada produk {$produk->nama_produk}: " . $e->getMessage());
            }
        }
        
        $this->info("Sinkronisasi selesai!");
        $this->info("Produk yang disinkronkan: {$updated}");
        $this->info("Produk yang sudah sinkron: {$synchronized}");
        
        return 0;
    }
}
