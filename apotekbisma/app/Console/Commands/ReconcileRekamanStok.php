<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

class ReconcileRekamanStok extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rekaman:reconcile {--produk=} {--dry-run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile RekamanStok stok_awal and stok_sisa to match Produk.stok';

    public function handle()
    {
        $produkId = $this->option('produk');
        $dryRun = $this->option('dry-run');

        $produkQuery = Produk::orderBy('id_produk');
        if ($produkId) {
            $produkQuery->where('id_produk', $produkId);
        }

        $produks = $produkQuery->get();
        if ($produks->isEmpty()) {
            $this->info('No products found');
            return 0;
        }

        foreach ($produks as $produk) {
            $this->info('Reconciling product id=' . $produk->id_produk . ' nama=' . $produk->nama_produk);

            $current = $produk->stok; // current physical stock

            // Get records latest-first
            // Use only the waktu order; model primary key is id_rekaman_stok
            $rekamans = RekamanStok::where('id_produk', $produk->id_produk)
                ->orderBy('waktu', 'desc')
                ->get();

            if ($rekamans->isEmpty()) {
                $this->info('  no RekamanStok found for this product');
                continue;
            }

            $count = 0;
            DB::beginTransaction();
            try {
                foreach ($rekamans as $rek) {
                    $before = $current;
                    if ($rek->stok_masuk && $rek->stok_masuk > 0) {
                        $prev = $current - $rek->stok_masuk;
                    } elseif ($rek->stok_keluar && $rek->stok_keluar > 0) {
                        $prev = $current + $rek->stok_keluar;
                    } else {
                        // no change recorded; skip but keep current
                        $prev = $current;
                    }

                    $newSisa = $current;
                    $newAwal = $prev;

                    $count++;

                    $rekId = $rek->{ $rek->getKeyName() } ?? '(n/a)';

                    if ($dryRun) {
                        $this->line(sprintf('  [%d] waktu=%s %s=%s stok_awal:%s -> %s, stok_sisa:%s -> %s',
                            $count, $rek->waktu, $rek->getKeyName(), $rekId, $rek->stok_awal, $newAwal, $rek->stok_sisa, $newSisa));
                    } else {
                        $rek->stok_awal = $newAwal;
                        $rek->stok_sisa = $newSisa;
                        $rek->save();
                    }

                    $current = $prev;
                }

                if (!$dryRun) {
                    DB::commit();
                    $this->info('  Updated ' . $count . ' RekamanStok records. Final current estimate: ' . $current);
                } else {
                    DB::rollBack();
                    $this->info('  Dry-run complete. Would have updated ' . $count . ' records.');
                }

            } catch (\Exception $e) {
                DB::rollBack();
                $this->error('  Error: ' . $e->getMessage());
            }
        }

        return 0;
    }
}
