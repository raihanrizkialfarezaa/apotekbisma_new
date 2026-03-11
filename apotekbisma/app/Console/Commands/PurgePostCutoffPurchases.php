<?php

namespace App\Console\Commands;

use App\Models\RekamanStok;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgePostCutoffPurchases extends Command
{
    protected $signature = 'stock:purge-post-cutoff-purchases
                            {--apply : Terapkan penghapusan ke database}
                            {--force : Lewati konfirmasi saat mode apply}
                            {--cutoff= : Cutoff datetime baseline}
                            {--until= : Batas akhir pembelian yang akan dipurge}
                            {--csv= : Path CSV baseline}
                            {--purchase=* : Batasi ke id_pembelian tertentu}
                            {--delete-audits : Ikut hapus audit perubahan tanggal pembelian terkait}';

    protected $description = 'Hapus pembelian pasca-cutoff secara terkontrol tanpa menyentuh penjualan, lalu sinkronkan stok produk terdampak';

    public function handle(): int
    {
        $startedAt = microtime(true);
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');
        $deleteAudits = (bool) $this->option('delete-audits');
        $cutoff = (string) ($this->option('cutoff') ?: config('stock.cutoff_datetime', '2025-12-31 23:59:59'));
        $until = (string) ($this->option('until') ?: Carbon::now()->format('Y-m-d H:i:s'));
        $csvPath = (string) ($this->option('csv') ?: base_path(config('stock.baseline_csv')));
        $purchaseFilter = collect($this->option('purchase'))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $this->info('=== POST-CUTOFF PURCHASE PURGE ===');
        $this->line('Mode        : ' . ($apply ? 'APPLY' : 'DRY-RUN'));
        $this->line('Cutoff      : ' . $cutoff);
        $this->line('Until       : ' . $until);
        $this->line('CSV         : ' . $csvPath);
        $this->line('Delete audits: ' . ($deleteAudits ? 'YES' : 'NO'));

        $baselineIds = $this->loadBaselineIds($csvPath);
        $candidatePurchases = $this->collectCandidatePurchases($cutoff, $until, $purchaseFilter->all());
        $purchaseIds = $candidatePurchases->pluck('id_pembelian')->map(fn ($id) => (int) $id)->values();

        if ($purchaseIds->isEmpty()) {
            $this->warn('Tidak ada pembelian pasca-cutoff yang cocok untuk dipurge.');
            return 0;
        }

        $detailRows = DB::table('pembelian_detail')
            ->whereIn('id_pembelian', $purchaseIds->all())
            ->select('id_pembelian', 'id_produk', 'jumlah')
            ->get();

        $rekamanRows = DB::table('rekaman_stoks')
            ->whereIn('id_pembelian', $purchaseIds->all())
            ->select('id_rekaman_stok', 'id_pembelian', 'id_produk', 'waktu', 'stok_masuk')
            ->get();

        $auditCount = $deleteAudits
            ? DB::table('transaction_date_change_audits')
                ->where('transaction_type', 'pembelian')
                ->whereIn('transaction_id', $purchaseIds->all())
                ->count()
            : 0;

        $detailProductIds = $detailRows->pluck('id_produk')->map(fn ($id) => (int) $id);
        $rekamanProductIds = $rekamanRows->pluck('id_produk')->map(fn ($id) => (int) $id);
        $affectedProductIds = $detailProductIds
            ->concat($rekamanProductIds)
            ->unique()
            ->values();

        $baselineIntersectionProductIds = $affectedProductIds
            ->filter(fn ($id) => isset($baselineIds[(int) $id]))
            ->values();

        $summary = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'cutoff' => $cutoff,
            'until' => $until,
            'purchase_count' => $purchaseIds->count(),
            'detail_count' => $detailRows->count(),
            'rekaman_count' => $rekamanRows->count(),
            'audit_count' => $auditCount,
            'affected_product_count' => $affectedProductIds->count(),
            'baseline_intersection_product_count' => $baselineIntersectionProductIds->count(),
            'non_baseline_product_count' => $affectedProductIds->count() - $baselineIntersectionProductIds->count(),
            'purchase_filter' => $purchaseFilter->all(),
        ];

        $report = [
            'summary' => $summary,
            'sample_purchases' => $candidatePurchases->take(100)->values()->all(),
            'sample_affected_product_ids' => $affectedProductIds->take(200)->values()->all(),
            'sample_baseline_intersection_product_ids' => $baselineIntersectionProductIds->take(200)->values()->all(),
        ];

        $reportPath = base_path('post_cutoff_purchase_purge_report_' . Carbon::now()->format('Ymd_His') . '.json');
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->table(
            ['Purchase', 'Detail', 'Rekaman', 'Affected Products', 'Baseline Intersection'],
            [[
                $purchaseIds->count(),
                $detailRows->count(),
                $rekamanRows->count(),
                $affectedProductIds->count(),
                $baselineIntersectionProductIds->count(),
            ]]
        );
        $this->line('Report      : ' . $reportPath);

        if (!$apply) {
            $this->info('Dry-run selesai. Jalankan ulang dengan --apply --force bila ingin menerapkan penghapusan.');
            return 0;
        }

        if (!$force && !$this->confirm('Penghapusan pembelian pasca-cutoff ini bersifat destruktif. Lanjutkan?', false)) {
            $this->warn('Apply dibatalkan.');
            return 1;
        }

        try {
            DB::transaction(function () use ($purchaseIds, $deleteAudits) {
                foreach ($purchaseIds->chunk(500) as $chunk) {
                    $ids = $chunk->all();

                    DB::table('rekaman_stoks')->whereIn('id_pembelian', $ids)->delete();
                    DB::table('pembelian_detail')->whereIn('id_pembelian', $ids)->delete();

                    if ($deleteAudits) {
                        DB::table('transaction_date_change_audits')
                            ->where('transaction_type', 'pembelian')
                            ->whereIn('transaction_id', $ids)
                            ->delete();
                    }

                    DB::table('pembelian')->whereIn('id_pembelian', $ids)->delete();
                }
            }, 3);
        } catch (\Throwable $e) {
            $this->error('Apply gagal dan di-rollback: ' . $e->getMessage());
            return 1;
        }

        foreach ($affectedProductIds as $productId) {
            $remainingRecords = DB::table('rekaman_stoks')->where('id_produk', $productId)->exists();

            if ($remainingRecords) {
                RekamanStok::recalculateStock($productId);
                continue;
            }

            DB::table('produk')->where('id_produk', $productId)->update(['stok' => 0, 'updated_at' => now()]);
        }

        $elapsed = round(microtime(true) - $startedAt, 2);
        $this->info('Apply selesai.');
        $this->line('Purchase deleted : ' . $purchaseIds->count());
        $this->line('Affected products: ' . $affectedProductIds->count());
        $this->line('Elapsed seconds  : ' . $elapsed);

        return 0;
    }

    private function collectCandidatePurchases(string $cutoff, string $until, array $purchaseFilter)
    {
        $query = DB::table('pembelian as p')
            ->leftJoin('pembelian_detail as pd', 'pd.id_pembelian', '=', 'p.id_pembelian')
            ->whereRaw('COALESCE(p.waktu_datang, p.waktu, p.created_at) > ?', [$cutoff])
            ->whereRaw('COALESCE(p.waktu_datang, p.waktu, p.created_at) <= ?', [$until])
            ->groupBy(
                'p.id_pembelian',
                'p.no_faktur',
                'p.created_at',
                'p.waktu',
                'p.waktu_datang',
                'p.total_item',
                'p.total_harga',
                'p.bayar'
            )
            ->orderByRaw('COALESCE(p.waktu_datang, p.waktu, p.created_at) asc')
            ->selectRaw('p.id_pembelian, p.no_faktur, p.created_at, p.waktu, p.waktu_datang, p.total_item, p.total_harga, p.bayar, COALESCE(p.waktu_datang, p.waktu, p.created_at) as waktu_efektif, COUNT(pd.id_pembelian_detail) as detail_count, COUNT(DISTINCT pd.id_produk) as unique_product_count, COALESCE(SUM(pd.jumlah), 0) as total_qty');

        if (!empty($purchaseFilter)) {
            $query->whereIn('p.id_pembelian', $purchaseFilter);
        }

        return $query->get()->map(function ($row) {
            return [
                'id_pembelian' => (int) $row->id_pembelian,
                'no_faktur' => $row->no_faktur,
                'created_at' => $row->created_at,
                'waktu' => $row->waktu,
                'waktu_datang' => $row->waktu_datang,
                'waktu_efektif' => $row->waktu_efektif,
                'total_item' => (int) ($row->total_item ?? 0),
                'total_harga' => (int) ($row->total_harga ?? 0),
                'bayar' => (int) ($row->bayar ?? 0),
                'detail_count' => (int) ($row->detail_count ?? 0),
                'unique_product_count' => (int) ($row->unique_product_count ?? 0),
                'total_qty' => (int) ($row->total_qty ?? 0),
            ];
        });
    }

    private function loadBaselineIds(string $csvPath): array
    {
        if (!file_exists($csvPath)) {
            return [];
        }

        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            return [];
        }

        $header = fgetcsv($handle, 0, $this->detectDelimiter($csvPath));
        if (!$header) {
            fclose($handle);
            return [];
        }

        $normalizedHeader = array_map(fn ($value) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $value))), $header);
        $productIndex = null;

        foreach (['id_produk', 'produk_id_produk'] as $candidateHeader) {
            $foundIndex = array_search($candidateHeader, $normalizedHeader, true);
            if ($foundIndex !== false) {
                $productIndex = $foundIndex;
                break;
            }
        }

        if ($productIndex === null) {
            fclose($handle);
            return [];
        }

        $baselineIds = [];
        while (($row = fgetcsv($handle, 0, $this->detectDelimiter($csvPath))) !== false) {
            $productId = trim((string) ($row[$productIndex] ?? ''));
            if ($productId !== '' && is_numeric($productId)) {
                $baselineIds[(int) $productId] = true;
            }
        }

        fclose($handle);

        return $baselineIds;
    }

    private function detectDelimiter(string $csvPath): string
    {
        $firstLine = '';
        $handle = fopen($csvPath, 'r');
        if ($handle) {
            $firstLine = (string) fgets($handle);
            fclose($handle);
        }

        $commaCount = substr_count($firstLine, ',');
        $semicolonCount = substr_count($firstLine, ';');

        return $semicolonCount > $commaCount ? ';' : ',';
    }
}