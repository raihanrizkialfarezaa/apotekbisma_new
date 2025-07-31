<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\PenjualanDetail;
use App\Models\PembelianDetail;
use Carbon\Carbon;

class FixStockInconsistency extends Command
{
    protected $signature = 'stock:fix-inconsistency';
    protected $description = 'Perbaiki inkonsistensi stok berdasarkan transaksi yang sebenarnya';

    public function handle()
    {
        $this->info('Memulai perbaikan inkonsistensi stok...');
        
        $produkList = Produk::all();
        $fixed = 0;
        
        foreach ($produkList as $produk) {
            $this->info("Memeriksa produk: {$produk->nama_produk}");
            
            // Hitung stok berdasarkan transaksi yang sebenarnya
            $totalMasuk = PembelianDetail::join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
                                       ->where('pembelian_detail.id_produk', $produk->id_produk)
                                       ->where('pembelian.no_faktur', '!=', 'o') // Hanya transaksi yang sudah selesai
                                       ->whereNotNull('pembelian.no_faktur')
                                       ->sum('pembelian_detail.jumlah');
            
            $totalKeluar = PenjualanDetail::join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
                                        ->where('penjualan_detail.id_produk', $produk->id_produk)
                                        ->where('penjualan.bayar', '>', 0) // Hanya transaksi yang sudah dibayar
                                        ->sum('penjualan_detail.jumlah');
            
            // Hitung perubahan manual dari rekaman stok
            $perubahanManual = RekamanStok::where('id_produk', $produk->id_produk)
                                         ->whereNull('id_pembelian')
                                         ->whereNull('id_penjualan')
                                         ->where(function($query) {
                                             $query->where('keterangan', 'LIKE', '%Update Stok Manual%')
                                                   ->orWhere('keterangan', 'LIKE', '%Perubahan Stok Manual%');
                                         })
                                         ->get()
                                         ->sum(function($item) {
                                             return $item->stok_masuk - $item->stok_keluar;
                                         });
            
            $stokSeharusnya = $totalMasuk - $totalKeluar + $perubahanManual;
            $stokAktual = $produk->stok;
            
            // Jika ada selisih, perbaiki
            if ($stokSeharusnya != $stokAktual) {
                $selisih = $stokSeharusnya - $stokAktual;
                
                $this->warn("  Ditemukan inkonsistensi:");
                $this->warn("    Total Masuk: {$totalMasuk}");
                $this->warn("    Total Keluar: {$totalKeluar}");
                $this->warn("    Perubahan Manual: {$perubahanManual}");
                $this->warn("    Seharusnya: {$stokSeharusnya}, Aktual: {$stokAktual}, Selisih: {$selisih}");
                
                // Update stok produk
                $produk->stok = $stokSeharusnya;
                $produk->save();
                
                // Buat rekaman perbaikan
                RekamanStok::create([
                    'id_produk' => $produk->id_produk,
                    'waktu' => Carbon::now(),
                    'stok_masuk' => $selisih > 0 ? $selisih : 0,
                    'stok_keluar' => $selisih < 0 ? abs($selisih) : 0,
                    'stok_awal' => $stokAktual,
                    'stok_sisa' => $stokSeharusnya,
                    'keterangan' => 'Update Stok Manual - Perbaikan inkonsistensi sistem'
                ]);
                
                $this->info("  ✓ Stok diperbaiki dari {$stokAktual} menjadi {$stokSeharusnya}");
                $fixed++;
            } else {
                $this->info("  ✓ Stok konsisten");
            }
        }
        
        $this->info("\nSelesai! Total produk yang diperbaiki: {$fixed}");
        return 0;
    }
}
