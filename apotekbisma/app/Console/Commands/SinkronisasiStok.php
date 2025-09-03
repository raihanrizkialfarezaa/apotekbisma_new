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

    private $batchSize = 500;

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
        
        $produkDataQuery = "
            WITH latest_rekaman AS (
                SELECT 
                    id_produk,
                    stok_sisa,
                    ROW_NUMBER() OVER (PARTITION BY id_produk ORDER BY id_rekaman_stok DESC) as rn
                FROM rekaman_stoks
            )
            SELECT 
                p.id_produk,
                p.nama_produk,
                p.stok,
                lr.stok_sisa
            FROM produk p
            INNER JOIN latest_rekaman lr ON p.id_produk = lr.id_produk
            WHERE lr.rn = 1 AND p.stok != lr.stok_sisa
        ";
        
        $produkData = DB::select($produkDataQuery);
        
        if (empty($produkData)) {
            $synchronizedQuery = "
                WITH latest_rekaman AS (
                    SELECT 
                        id_produk,
                        stok_sisa,
                        ROW_NUMBER() OVER (PARTITION BY id_produk ORDER BY id_rekaman_stok DESC) as rn
                    FROM rekaman_stoks
                )
                SELECT COUNT(*) as total
                FROM produk p
                INNER JOIN latest_rekaman lr ON p.id_produk = lr.id_produk
                WHERE lr.rn = 1 AND p.stok = lr.stok_sisa
            ";
            
            $totalProduk = DB::select($synchronizedQuery)[0]->total ?? 0;
            
            $this->info("Sinkronisasi selesai!");
            $this->info("Produk yang disinkronkan: 0");
            $this->info("Produk yang sudah sinkron: {$totalProduk}");
            return 0;
        }
        
        $chunks = array_chunk($produkData, $this->batchSize);
        $produkNames = [];
        
        DB::beginTransaction();
        try {
            foreach ($chunks as $chunk) {
                $insertData = [];
                
                foreach ($chunk as $row) {
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
                
                DB::table('rekaman_stoks')->insert($insertData);
            }
            
            DB::commit();
            
            foreach ($produkNames as $name) {
                $this->line("Produk: {$name} - Berhasil disinkronkan");
            }
            
        } catch (\Exception $e) {
            DB::rollback();
            $this->error("Error bulk insert: " . $e->getMessage());
            return 1;
        }
        
        $synchronizedQuery = "
            WITH latest_rekaman AS (
                SELECT 
                    id_produk,
                    stok_sisa,
                    ROW_NUMBER() OVER (PARTITION BY id_produk ORDER BY id_rekaman_stok DESC) as rn
                FROM rekaman_stoks
            )
            SELECT COUNT(*) as total
            FROM produk p
            INNER JOIN latest_rekaman lr ON p.id_produk = lr.id_produk
            WHERE lr.rn = 1 AND p.stok = lr.stok_sisa
        ";
        
        $totalSynchronized = DB::select($synchronizedQuery)[0]->total ?? 0;
        
        $this->info("Sinkronisasi selesai!");
        $this->info("Produk yang disinkronkan: {$updated}");
        $this->info("Produk yang sudah sinkron: {$totalSynchronized}");
        
        return 0;
    }
}
