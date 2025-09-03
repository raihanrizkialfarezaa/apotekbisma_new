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
        
        $produkData = DB::table('produk as p')
            ->join('rekaman_stoks as r', 'p.id_produk', '=', 'r.id_produk')
            ->leftJoin('rekaman_stoks as r2', function($join) {
                $join->on('r.id_produk', '=', 'r2.id_produk')
                     ->where('r.id_rekaman_stok', '<', DB::raw('r2.id_rekaman_stok'));
            })
            ->whereNull('r2.id_rekaman_stok')
            ->whereColumn('p.stok', '!=', 'r.stok_sisa')
            ->select('p.id_produk', 'p.nama_produk', 'p.stok', 'r.stok_sisa')
            ->get();
        
        if ($produkData->isEmpty()) {
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
        
        $totalSynchronized = DB::table('produk as p')
            ->join('rekaman_stoks as r', 'p.id_produk', '=', 'r.id_produk')
            ->leftJoin('rekaman_stoks as r2', function($join) {
                $join->on('r.id_produk', '=', 'r2.id_produk')
                     ->where('r.id_rekaman_stok', '<', DB::raw('r2.id_rekaman_stok'));
            })
            ->whereNull('r2.id_rekaman_stok')
            ->whereColumn('p.stok', '=', 'r.stok_sisa')
            ->count();
        
        $this->info("Sinkronisasi selesai!");
        $this->info("Produk yang disinkronkan: {$updated}");
        $this->info("Produk yang sudah sinkron: {$totalSynchronized}");
        
        return 0;
    }
}
