<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\Penjualan;
use App\Models\Pembelian;
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
        $this->info('Memulai sinkronisasi komprehensif...');
        
        $this->perbaikiTransaksiNull();
        $this->perbaikiRekaman();
        $this->sinkronisasiStok();
        
        return 0;
    }

    private function perbaikiTransaksiNull()
    {
        $this->info('1. Memperbaiki transaksi dengan waktu NULL...');
        
        DB::beginTransaction();
        try {
            $penjualanNull = Penjualan::whereNull('waktu')->get();
            $fixedPenjualan = 0;
            
            foreach ($penjualanNull as $penjualan) {
                $waktuDefault = $penjualan->created_at ?? Carbon::today();
                $penjualan->waktu = $waktuDefault;
                $penjualan->save();
                
                RekamanStok::where('id_penjualan', $penjualan->id_penjualan)
                           ->update(['waktu' => $waktuDefault]);
                
                $fixedPenjualan++;
            }
            
            $pembelianNull = Pembelian::whereNull('waktu')->get();
            $fixedPembelian = 0;
            
            foreach ($pembelianNull as $pembelian) {
                $waktuDefault = $pembelian->created_at ?? Carbon::today();
                $pembelian->waktu = $waktuDefault;
                $pembelian->save();
                
                RekamanStok::where('id_pembelian', $pembelian->id_pembelian)
                           ->update(['waktu' => $waktuDefault]);
                
                $fixedPembelian++;
            }
            
            DB::commit();
            $this->info("   Diperbaiki: {$fixedPenjualan} penjualan, {$fixedPembelian} pembelian");
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Error memperbaiki transaksi NULL: " . $e->getMessage());
        }
    }

    private function perbaikiRekaman()
    {
        $this->info('2. Memperbaiki produk tanpa rekaman stok...');
        
        DB::beginTransaction();
        try {
            $produkTanpaRekaman = DB::select("
                SELECT p.id_produk, p.nama_produk, p.stok
                FROM produk p
                LEFT JOIN rekaman_stoks rs ON p.id_produk = rs.id_produk
                WHERE rs.id_produk IS NULL
                ORDER BY p.nama_produk
            ");
            
            if (!empty($produkTanpaRekaman)) {
                $batchSize = 50;
                $batches = array_chunk($produkTanpaRekaman, $batchSize);
                $currentTime = Carbon::now();
                $totalFixed = 0;
                
                foreach ($batches as $batch) {
                    $insertData = [];
                    foreach ($batch as $produkData) {
                        $insertData[] = [
                            'id_produk' => $produkData->id_produk,
                            'waktu' => $currentTime,
                            'stok_masuk' => $produkData->stok,
                            'stok_awal' => 0,
                            'stok_sisa' => $produkData->stok,
                            'keterangan' => 'Rekonstruksi: Rekaman stok awal produk',
                            'created_at' => $currentTime,
                            'updated_at' => $currentTime
                        ];
                        $totalFixed++;
                    }
                    
                    DB::table('rekaman_stoks')->insert($insertData);
                }
                
                $this->info("   Dibuat rekaman untuk: {$totalFixed} produk");
            } else {
                $this->info("   Semua produk sudah memiliki rekaman stok");
            }
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Error memperbaiki rekaman: " . $e->getMessage());
        }
    }

    private function sinkronisasiStok()
    {
        $this->info('3. Sinkronisasi stok produk dengan rekaman...');
        
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
            return;
        }
        
        $chunks = array_chunk($produkData, $this->batchSize);
        
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
                    
                    $updated++;
                }
                
                DB::table('rekaman_stoks')->insert($insertData);
            }
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollback();
            $this->error("Error sinkronisasi stok: " . $e->getMessage());
            return;
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
    }
}
