<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\PenjualanDetail;
use App\Models\PembelianDetail;
use Carbon\Carbon;

class FixMissingStockRecord extends Command
{
    protected $signature = 'stock:fix-missing-records';
    protected $description = 'Perbaiki rekaman stok yang hilang untuk perubahan manual';

    public function handle()
    {
        $this->info('Memulai perbaikan rekaman stok yang hilang...');
        
        $produkList = Produk::all();
        $fixed = 0;
        
        foreach ($produkList as $produk) {
            $this->info("Memeriksa produk: {$produk->nama_produk}");
            
            // Hitung stok berdasarkan transaksi
            $totalMasuk = PembelianDetail::join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
                                       ->where('pembelian_detail.id_produk', $produk->id_produk)
                                       ->sum('pembelian_detail.jumlah');
            
            $totalKeluar = PenjualanDetail::join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
                                        ->where('penjualan_detail.id_produk', $produk->id_produk)
                                        ->sum('penjualan_detail.jumlah');
            
            $stokSeharusnya = $totalMasuk - $totalKeluar;
            $stokAktual = $produk->stok;
            
            // Jika ada selisih, berarti ada perubahan manual
            if ($stokSeharusnya != $stokAktual) {
                $selisih = $stokAktual - $stokSeharusnya;
                
                $this->warn("  Ditemukan selisih: Seharusnya {$stokSeharusnya}, Aktual {$stokAktual}, Selisih {$selisih}");
                
                // Cek apakah sudah ada rekaman untuk penyesuaian ini
                $existingRecord = RekamanStok::where('id_produk', $produk->id_produk)
                                           ->whereNull('id_pembelian')
                                           ->whereNull('id_penjualan')
                                           ->where(function($query) {
                                               $query->where('keterangan', 'LIKE', '%Update Stok Manual%')
                                                     ->orWhere('keterangan', 'LIKE', '%Perubahan Stok Manual%')
                                                     ->orWhere('keterangan', 'LIKE', '%Penyesuaian Manual%')
                                                     ->orWhere('keterangan', 'LIKE', '%Rekonstruksi%');
                                           })
                                           ->first();
                
                if (!$existingRecord) {
                    // Buat rekaman stok untuk penyesuaian
                    RekamanStok::create([
                        'id_produk' => $produk->id_produk,
                        'waktu' => Carbon::now(),
                        'stok_masuk' => $selisih > 0 ? $selisih : 0,
                        'stok_keluar' => $selisih < 0 ? abs($selisih) : 0,
                        'stok_awal' => $stokSeharusnya,
                        'stok_sisa' => $stokAktual,
                        'keterangan' => 'Update Stok Manual - Rekonstruksi data sistem'
                    ]);
                    
                    $this->info("  ✓ Rekaman stok berhasil dibuat");
                    $fixed++;
                } else {
                    $this->info("  - Rekaman sudah ada, dilewati");
                }
            } else {
                $this->info("  ✓ Stok sesuai, tidak perlu penyesuaian");
            }
        }
        
        $this->info("\nSelesai! Total rekaman yang diperbaiki: {$fixed}");
        return 0;
    }
}
