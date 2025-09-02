<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\RekamanStok;
use App\Models\Penjualan;
use App\Models\Pembelian;
use Illuminate\Support\Facades\DB;

class SyncRekamanStok extends Command
{
    protected $signature = 'sync:rekaman-stok';
    protected $description = 'Sinkronisasi waktu RekamanStok dengan transaksi parent';

    public function handle()
    {
        $this->info('=== SCRIPT SINKRONISASI REKAMAN STOK ===');

        DB::beginTransaction();

        try {
            // 1. Sinkronisasi RekamanStok yang terkait dengan Penjualan
            $this->info('1. Sinkronisasi RekamanStok dengan Penjualan...');
            $penjualan_records = RekamanStok::whereNotNull('id_penjualan')->get();
            $penjualan_updated = 0;
            
            foreach ($penjualan_records as $rekaman) {
                $penjualan = Penjualan::find($rekaman->id_penjualan);
                if ($penjualan && $penjualan->waktu && $rekaman->waktu != $penjualan->waktu) {
                    $old_waktu = $rekaman->waktu;
                    $rekaman->waktu = $penjualan->waktu;
                    $rekaman->save();
                    $this->line("  Updated RekamanStok ID {$rekaman->id_rekaman_stok}: {$old_waktu} -> {$penjualan->waktu}");
                    $penjualan_updated++;
                } elseif ($penjualan && !$penjualan->waktu) {
                    $this->line("  Skipped RekamanStok ID {$rekaman->id_rekaman_stok}: Penjualan {$penjualan->id_penjualan} has null waktu");
                }
            }
            $this->info("  Total Penjualan records updated: {$penjualan_updated}");

            // 2. Sinkronisasi RekamanStok yang terkait dengan Pembelian
            $this->info('2. Sinkronisasi RekamanStok dengan Pembelian...');
            $pembelian_records = RekamanStok::whereNotNull('id_pembelian')->get();
            $pembelian_updated = 0;
            
            foreach ($pembelian_records as $rekaman) {
                $pembelian = Pembelian::find($rekaman->id_pembelian);
                if ($pembelian && $pembelian->waktu && $rekaman->waktu != $pembelian->waktu) {
                    $old_waktu = $rekaman->waktu;
                    $rekaman->waktu = $pembelian->waktu;
                    $rekaman->save();
                    $this->line("  Updated RekamanStok ID {$rekaman->id_rekaman_stok}: {$old_waktu} -> {$pembelian->waktu}");
                    $pembelian_updated++;
                } elseif ($pembelian && !$pembelian->waktu) {
                    $this->line("  Skipped RekamanStok ID {$rekaman->id_rekaman_stok}: Pembelian {$pembelian->id_pembelian} has null waktu");
                }
            }
            $this->info("  Total Pembelian records updated: {$pembelian_updated}");

            // 3. Hapus duplikasi yang mungkin terjadi
            $this->info('3. Menghapus duplikasi RekamanStok...');
            $duplicates = DB::select("
                SELECT id_produk, id_penjualan, id_pembelian, COUNT(*) as count
                FROM rekaman_stoks 
                WHERE (id_penjualan IS NOT NULL OR id_pembelian IS NOT NULL)
                GROUP BY id_produk, id_penjualan, id_pembelian
                HAVING COUNT(*) > 1
            ");
            
            $deleted_count = 0;
            foreach ($duplicates as $dup) {
                if ($dup->id_penjualan) {
                    $records = RekamanStok::where('id_produk', $dup->id_produk)
                                         ->where('id_penjualan', $dup->id_penjualan)
                                         ->orderBy('id_rekaman_stok', 'desc')
                                         ->get();
                } else {
                    $records = RekamanStok::where('id_produk', $dup->id_produk)
                                         ->where('id_pembelian', $dup->id_pembelian)
                                         ->orderBy('id_rekaman_stok', 'desc')
                                         ->get();
                }
                
                // Keep the latest, delete others
                for ($i = 1; $i < $records->count(); $i++) {
                    $this->line("  Deleting duplicate RekamanStok ID {$records[$i]->id_rekaman_stok}");
                    $records[$i]->delete();
                    $deleted_count++;
                }
            }
            $this->info("  Total duplicate records deleted: {$deleted_count}");

            DB::commit();
            $this->info('=== SINKRONISASI BERHASIL ===');
            
            // 4. Verifikasi hasil
            $this->info('4. Verifikasi hasil untuk produk ID 2...');
            $rekaman_produk_2 = RekamanStok::where('id_produk', 2)
                                          ->orderBy('id_rekaman_stok', 'desc')
                                          ->take(5)
                                          ->get();
            
            foreach ($rekaman_produk_2 as $r) {
                $this->line("ID: {$r->id_rekaman_stok} | Waktu: {$r->waktu} | Jenis: {$r->jenis_transaksi}");
                if ($r->id_penjualan) {
                    $penjualan = Penjualan::find($r->id_penjualan);
                    if ($penjualan) {
                        $status = ($r->waktu == $penjualan->waktu) ? "SYNC ✓" : "MISMATCH ✗";
                        $this->line("  Penjualan waktu: {$penjualan->waktu} | Status: {$status}");
                    }
                }
                if ($r->id_pembelian) {
                    $pembelian = Pembelian::find($r->id_pembelian);
                    if ($pembelian) {
                        $status = ($r->waktu == $pembelian->waktu) ? "SYNC ✓" : "MISMATCH ✗";
                        $this->line("  Pembelian waktu: {$pembelian->waktu} | Status: {$status}");
                    }
                }
            }

        } catch (\Exception $e) {
            DB::rollback();
            $this->error("ERROR: " . $e->getMessage());
            $this->error("ROLLBACK performed.");
            return 1;
        }

        return 0;
    }
}
