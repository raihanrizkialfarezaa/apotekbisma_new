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
        
        $updated = 0;
        $synchronized = 0;
        $currentTime = Carbon::now();
        
        $produkData = DB::select("
            SELECT p.id_produk, p.nama_produk, p.stok, latest_rekaman.stok_sisa
            FROM produk as p
            LEFT JOIN (
                SELECT id_produk, stok_sisa,
                ROW_NUMBER() OVER (PARTITION BY id_produk ORDER BY waktu DESC, id_rekaman_stok DESC) as rn
                FROM rekaman_stoks
            ) as latest_rekaman ON p.id_produk = latest_rekaman.id_produk AND latest_rekaman.rn = 1
            WHERE latest_rekaman.stok_sisa IS NOT NULL 
            AND p.stok != latest_rekaman.stok_sisa
        ");
        
        if (empty($produkData)) {
            $totalProduk = Produk::whereExists(function($query) {
                $query->select(DB::raw(1))
                      ->from('rekaman_stoks')
                      ->whereColumn('rekaman_stoks.id_produk', 'produk.id_produk');
            })->count();
            
            $this->info("Sinkronisasi selesai!");
            $this->info("Produk yang disinkronkan: 0");
            $this->info("Produk yang sudah sinkron: {$totalProduk}");
            return 0;
        }
        
        $insertData = [];
        $produkNames = [];
        
        foreach ($produkData as $row) {
            $stokProduk = intval($row->stok);
            $stokSisa = intval($row->stok_sisa);
            $selisih = $stokProduk - $stokSisa;
            
            $insertData[] = [
                'id_produk' => $row->id_produk,
                'waktu' => $currentTime,
                'stok_awal' => $stokSisa,
                'stok_masuk' => $selisih > 0 ? $selisih : 0,
                'stok_keluar' => $selisih < 0 ? abs($selisih) : 0,
                'stok_sisa' => $stokProduk,
                'keterangan' => 'Sinkronisasi otomatis stok produk',
                'created_at' => $currentTime,
                'updated_at' => $currentTime
            ];
            
            $produkNames[] = "{$row->nama_produk} (Selisih: {$selisih})";
            $updated++;
        }
        
        if (!empty($insertData)) {
            DB::beginTransaction();
            try {
                DB::table('rekaman_stoks')->insert($insertData);
                DB::commit();
                
                foreach ($produkNames as $name) {
                    $this->line("Produk: {$name} - Berhasil disinkronkan");
                }
            } catch (\Exception $e) {
                DB::rollback();
                $this->error("Error bulk insert: " . $e->getMessage());
                return 1;
            }
        }
        
        $totalSynchronized = DB::select("
            SELECT COUNT(*) as count
            FROM produk as p
            LEFT JOIN (
                SELECT id_produk, stok_sisa,
                ROW_NUMBER() OVER (PARTITION BY id_produk ORDER BY waktu DESC, id_rekaman_stok DESC) as rn
                FROM rekaman_stoks
            ) as latest_rekaman ON p.id_produk = latest_rekaman.id_produk AND latest_rekaman.rn = 1
            WHERE latest_rekaman.stok_sisa IS NOT NULL 
            AND p.stok = latest_rekaman.stok_sisa
        ")[0]->count;
        
        $this->info("Sinkronisasi selesai!");
        $this->info("Produk yang disinkronkan: {$updated}");
        $this->info("Produk yang sudah sinkron: {$totalSynchronized}");
        
        return 0;
    }
}
