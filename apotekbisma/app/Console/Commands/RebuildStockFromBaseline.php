<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildStockFromBaseline extends Command
{
    protected $signature = 'stock:baseline-rebuild
                            {--apply : Terapkan perubahan ke database}
                            {--csv= : Path CSV baseline}
                            {--cutoff= : Cutoff datetime baseline}
                            {--until= : Batas akhir event (default: sekarang)}
                            {--include-negative-events : Tetap proses produk dengan event minus}
                            {--product=* : Batasi ke id_produk tertentu}';

    protected $description = 'Rebuild stok berbasis baseline CSV 31 Desember + event valid pasca-cutoff';

    private array $excludedManualPatterns = [];

    public function handle(): int
    {
        $startedAt = microtime(true);

        $csvPath = $this->option('csv') ?: base_path(config('stock.baseline_csv'));
        $cutoff = $this->option('cutoff') ?: config('stock.cutoff_datetime', '2025-12-31 23:59:59');
        $until = $this->option('until') ?: Carbon::now()->format('Y-m-d H:i:s');
        $apply = (bool) $this->option('apply');
        $includeNegative = (bool) $this->option('include-negative-events');
        $onlyProducts = collect($this->option('product'))->filter(fn($id) => is_numeric($id))->map(fn($id) => intval($id))->values()->all();

        $this->excludedManualPatterns = config('stock.excluded_manual_keterangan_patterns', []);

        $this->info('=== STOCK BASELINE REBUILD ===');
        $this->line('Mode        : ' . ($apply ? 'APPLY' : 'DRY-RUN'));
        $this->line('CSV         : ' . $csvPath);
        $this->line('Cutoff      : ' . $cutoff);
        $this->line('Until       : ' . $until);
        $this->line('Include minus events: ' . ($includeNegative ? 'YES' : 'NO (safe default)'));

        if (!file_exists($csvPath)) {
            $this->error('CSV baseline tidak ditemukan: ' . $csvPath);
            return 1;
        }

        try {
            $baselineData = $this->loadBaselineCsv($csvPath);
        } catch (\Throwable $e) {
            $this->error('Gagal membaca CSV baseline: ' . $e->getMessage());
            return 1;
        }

        $baselineMap = $baselineData['baseline_map'];
        $duplicateConflicts = $baselineData['duplicate_conflicts'];

        if (!empty($onlyProducts)) {
            $baselineMap = array_filter($baselineMap, function ($row) use ($onlyProducts) {
                return in_array((int) $row['id_produk'], $onlyProducts, true);
            });
            $this->line('Filter product: ' . implode(', ', $onlyProducts));
        }

        if (empty($baselineMap)) {
            $this->warn('Tidak ada data baseline yang bisa diproses.');
            return 0;
        }

        $baselineIds = array_keys($baselineMap);

        $products = DB::table('produk')
            ->select('id_produk', 'nama_produk', 'stok')
            ->whereIn('id_produk', $baselineIds)
            ->orderBy('id_produk', 'asc')
            ->get()
            ->keyBy('id_produk');

        $missingInDb = array_values(array_diff($baselineIds, array_map('intval', $products->keys()->all())));
        if (!empty($missingInDb)) {
            $this->warn('ID baseline tidak ditemukan di DB: ' . count($missingInDb));
        }

        $plans = [];

        foreach ($products as $product) {
            $productId = (int) $product->id_produk;
            $baselineStock = (int) $baselineMap[$productId]['stok'];

            $events = $this->collectEventsForProduct($productId, $cutoff, $until);

            $insertRows = [];
            $currentTime = Carbon::now();

            $runningStock = $baselineStock;
            $insertRows[] = [
                'id_produk' => $productId,
                'id_penjualan' => null,
                'id_pembelian' => null,
                'waktu' => $cutoff,
                'stok_awal' => $baselineStock,
                'stok_masuk' => 0,
                'stok_keluar' => 0,
                'stok_sisa' => $baselineStock,
                'keterangan' => 'BASELINE CSV 31-12-2025 (source of truth)',
                'created_at' => $currentTime,
                'updated_at' => $currentTime,
            ];

            $negativeEventCount = 0;
            foreach ($events as $event) {
                $stokAwal = $runningStock;
                $stokSisa = $stokAwal + $event['stok_masuk'] - $event['stok_keluar'];

                if ($stokSisa < 0) {
                    $negativeEventCount++;
                }

                $insertRows[] = [
                    'id_produk' => $productId,
                    'id_penjualan' => $event['id_penjualan'],
                    'id_pembelian' => $event['id_pembelian'],
                    'waktu' => $event['waktu'],
                    'stok_awal' => $stokAwal,
                    'stok_masuk' => $event['stok_masuk'],
                    'stok_keluar' => $event['stok_keluar'],
                    'stok_sisa' => $stokSisa,
                    'keterangan' => $event['keterangan'],
                    'created_at' => $currentTime,
                    'updated_at' => $currentTime,
                ];

                $runningStock = $stokSisa;
            }

            $finalStock = max(0, $runningStock);
            $currentStock = (int) $product->stok;

            $plans[] = [
                'id_produk' => $productId,
                'nama_produk' => $product->nama_produk,
                'stok_db_saat_ini' => $currentStock,
                'stok_baseline' => $baselineStock,
                'stok_hasil_rebuild' => $finalStock,
                'delta_stok' => $finalStock - $currentStock,
                'post_cutoff_events' => count($events),
                'negative_events_detected' => $negativeEventCount,
                'insert_rows' => $insertRows,
                'will_update_product_stock' => $finalStock !== $currentStock,
                'eligible_for_apply' => $negativeEventCount === 0 || $includeNegative,
            ];
        }

        $summary = $this->buildSummary($plans, $baselineData, $missingInDb, $apply, $cutoff, $until, $csvPath);

        if ($apply) {
            try {
                DB::transaction(function () use ($plans, $cutoff) {
                    foreach ($plans as $plan) {
                        if (!$plan['eligible_for_apply']) {
                            continue;
                        }

                        $productId = $plan['id_produk'];

                        DB::table('produk')
                            ->where('id_produk', $productId)
                            ->lockForUpdate()
                            ->first();

                        DB::table('rekaman_stoks')
                            ->where('id_produk', $productId)
                            ->where('waktu', '>=', $cutoff)
                            ->delete();

                        foreach (array_chunk($plan['insert_rows'], 500) as $chunk) {
                            DB::table('rekaman_stoks')->insert($chunk);
                        }

                        DB::table('produk')
                            ->where('id_produk', $productId)
                            ->update([
                                'stok' => $plan['stok_hasil_rebuild'],
                                'updated_at' => now(),
                            ]);
                    }
                }, 3);
            } catch (\Throwable $e) {
                $this->error('APPLY gagal dan di-rollback: ' . $e->getMessage());
                return 1;
            }
        }

        $report = [
            'summary' => $summary,
            'sample_top_delta' => collect($plans)
                ->sortByDesc(fn($p) => abs((int) $p['delta_stok']))
                ->take(100)
                ->values()
                ->map(function ($p) {
                    return [
                        'id_produk' => $p['id_produk'],
                        'nama_produk' => $p['nama_produk'],
                        'stok_db_saat_ini' => $p['stok_db_saat_ini'],
                        'stok_baseline' => $p['stok_baseline'],
                        'stok_hasil_rebuild' => $p['stok_hasil_rebuild'],
                        'delta_stok' => $p['delta_stok'],
                        'post_cutoff_events' => $p['post_cutoff_events'],
                        'negative_events_detected' => $p['negative_events_detected'],
                        'eligible_for_apply' => $p['eligible_for_apply'],
                    ];
                })
                ->all(),
            'skipped_negative_event_products' => collect($plans)
                ->filter(fn($p) => !$p['eligible_for_apply'] && (int) $p['negative_events_detected'] > 0)
                ->sortByDesc(fn($p) => abs((int) $p['delta_stok']))
                ->values()
                ->map(function ($p) {
                    return [
                        'id_produk' => $p['id_produk'],
                        'nama_produk' => $p['nama_produk'],
                        'stok_db_saat_ini' => $p['stok_db_saat_ini'],
                        'stok_hasil_rebuild' => $p['stok_hasil_rebuild'],
                        'delta_stok' => $p['delta_stok'],
                        'post_cutoff_events' => $p['post_cutoff_events'],
                        'negative_events_detected' => $p['negative_events_detected'],
                    ];
                })
                ->all(),
            'duplicate_conflicts' => $duplicateConflicts,
            'missing_baseline_ids_in_db' => $missingInDb,
        ];

        $reportFile = base_path('baseline_rebuild_report_' . date('Ymd_His') . '.json');
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $duration = round(microtime(true) - $startedAt, 2);

        $this->info('Selesai.');
        $this->line('Durasi: ' . $duration . ' detik');
        $this->line('Report: ' . $reportFile);
        $this->line('Produk diproses: ' . $summary['processed_products']);
        $this->line('Produk stok berubah: ' . $summary['products_stock_changed']);
        $this->line('Total abs delta stok: ' . $summary['total_abs_delta_stock']);

        return 0;
    }

    private function loadBaselineCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Tidak dapat membuka file CSV');
        }

        $header = fgetcsv($handle);
        if (!$header || count($header) < 3) {
            fclose($handle);
            throw new \RuntimeException('Header CSV tidak valid');
        }

        $baselineMap = [];
        $duplicateConflicts = [];
        $rowsRead = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 3) {
                continue;
            }

            $idProduk = intval($row[0]);
            $namaProduk = trim((string) $row[1]);
            $stok = is_numeric($row[2]) ? intval($row[2]) : 0;

            if ($idProduk <= 0) {
                continue;
            }

            $rowsRead++;

            if (isset($baselineMap[$idProduk]) && intval($baselineMap[$idProduk]['stok']) !== $stok) {
                $duplicateConflicts[$idProduk][] = [
                    'old_stok' => intval($baselineMap[$idProduk]['stok']),
                    'new_stok' => $stok,
                    'nama_produk' => $namaProduk,
                ];
            }

            $baselineMap[$idProduk] = [
                'id_produk' => $idProduk,
                'nama_produk' => $namaProduk,
                'stok' => $stok,
            ];
        }

        fclose($handle);

        return [
            'rows_read' => $rowsRead,
            'unique_ids' => count($baselineMap),
            'baseline_map' => $baselineMap,
            'duplicate_conflicts' => $duplicateConflicts,
        ];
    }

    private function collectEventsForProduct(int $productId, string $cutoff, string $until): array
    {
        $events = [];

        $pembelian = DB::table('pembelian_detail as pd')
            ->join('pembelian as p', 'pd.id_pembelian', '=', 'p.id_pembelian')
            ->where('pd.id_produk', $productId)
            ->whereRaw('COALESCE(p.waktu, p.created_at) > ?', [$cutoff])
            ->whereRaw('COALESCE(p.waktu, p.created_at) <= ?', [$until])
            ->groupBy('pd.id_pembelian', DB::raw('COALESCE(p.waktu, p.created_at)'))
            ->selectRaw('pd.id_pembelian as ref_id, COALESCE(p.waktu, p.created_at) as waktu_event, SUM(pd.jumlah) as qty, MAX(pd.id_pembelian_detail) as sort_key, MAX(p.no_faktur) as no_faktur')
            ->get();

        foreach ($pembelian as $row) {
            $events[] = [
                'type_priority' => 10,
                'sort_key' => intval($row->sort_key ?? 0),
                'waktu' => (string) $row->waktu_event,
                'id_penjualan' => null,
                'id_pembelian' => intval($row->ref_id),
                'stok_masuk' => intval($row->qty ?? 0),
                'stok_keluar' => 0,
                'keterangan' => 'Pembelian: Rebuild baseline' . (!empty($row->no_faktur) ? ' (Faktur: ' . $row->no_faktur . ')' : ''),
            ];
        }

        $penjualan = DB::table('penjualan_detail as pd')
            ->join('penjualan as p', 'pd.id_penjualan', '=', 'p.id_penjualan')
            ->where('pd.id_produk', $productId)
            ->whereRaw('COALESCE(p.waktu, p.created_at) > ?', [$cutoff])
            ->whereRaw('COALESCE(p.waktu, p.created_at) <= ?', [$until])
            ->groupBy('pd.id_penjualan', DB::raw('COALESCE(p.waktu, p.created_at)'))
            ->selectRaw('pd.id_penjualan as ref_id, COALESCE(p.waktu, p.created_at) as waktu_event, SUM(pd.jumlah) as qty, MAX(pd.id_penjualan_detail) as sort_key')
            ->get();

        foreach ($penjualan as $row) {
            $events[] = [
                'type_priority' => 20,
                'sort_key' => intval($row->sort_key ?? 0),
                'waktu' => (string) $row->waktu_event,
                'id_penjualan' => intval($row->ref_id),
                'id_pembelian' => null,
                'stok_masuk' => 0,
                'stok_keluar' => intval($row->qty ?? 0),
                'keterangan' => 'Penjualan: Rebuild baseline (ID: ' . intval($row->ref_id) . ')',
            ];
        }

        $manualRecords = DB::table('rekaman_stoks')
            ->select('id_rekaman_stok', 'waktu', 'stok_masuk', 'stok_keluar', 'keterangan')
            ->where('id_produk', $productId)
            ->whereNull('id_penjualan')
            ->whereNull('id_pembelian')
            ->where('waktu', '>', $cutoff)
            ->where('waktu', '<=', $until)
            ->orderBy('waktu', 'asc')
            ->orderBy('id_rekaman_stok', 'asc')
            ->get();

        foreach ($manualRecords as $record) {
            if ($this->isExcludedManualRecord((string) ($record->keterangan ?? ''))) {
                continue;
            }

            $stokMasuk = intval($record->stok_masuk ?? 0);
            $stokKeluar = intval($record->stok_keluar ?? 0);

            if ($stokMasuk === 0 && $stokKeluar === 0) {
                continue;
            }

            $events[] = [
                'type_priority' => 30,
                'sort_key' => intval($record->id_rekaman_stok),
                'waktu' => (string) $record->waktu,
                'id_penjualan' => null,
                'id_pembelian' => null,
                'stok_masuk' => $stokMasuk,
                'stok_keluar' => $stokKeluar,
                'keterangan' => (string) ($record->keterangan ?: 'Penyesuaian stok manual'),
            ];
        }

        usort($events, function ($a, $b) {
            $timeCmp = strcmp($a['waktu'], $b['waktu']);
            if ($timeCmp !== 0) {
                return $timeCmp;
            }

            if ($a['type_priority'] !== $b['type_priority']) {
                return $a['type_priority'] <=> $b['type_priority'];
            }

            return $a['sort_key'] <=> $b['sort_key'];
        });

        return $events;
    }

    private function isExcludedManualRecord(string $keterangan): bool
    {
        $needle = mb_strtolower(trim($keterangan));
        if ($needle === '') {
            return false;
        }

        foreach ($this->excludedManualPatterns as $pattern) {
            $p = mb_strtolower((string) $pattern);
            if ($p !== '' && str_contains($needle, $p)) {
                return true;
            }
        }

        return false;
    }

    private function buildSummary(array $plans, array $baselineData, array $missingInDb, bool $apply, string $cutoff, string $until, string $csvPath): array
    {
        $processedProducts = count($plans);
        $productsStockChanged = 0;
        $totalAbsDeltaStock = 0;
        $totalPostCutoffEvents = 0;
        $productsWithNegativeEvent = 0;
        $productsSkippedBecauseNegativeEvent = 0;
        $productsEligibleForApply = 0;

        foreach ($plans as $plan) {
            if ($plan['will_update_product_stock']) {
                $productsStockChanged++;
            }

            $totalAbsDeltaStock += abs((int) $plan['delta_stok']);
            $totalPostCutoffEvents += intval($plan['post_cutoff_events']);

            if (intval($plan['negative_events_detected']) > 0) {
                $productsWithNegativeEvent++;
            }

            if ($plan['eligible_for_apply']) {
                $productsEligibleForApply++;
            } else {
                $productsSkippedBecauseNegativeEvent++;
            }
        }

        return [
            'mode' => $apply ? 'APPLY' : 'DRY_RUN',
            'csv_path' => $csvPath,
            'cutoff' => $cutoff,
            'until' => $until,
            'baseline_rows_read' => intval($baselineData['rows_read'] ?? 0),
            'baseline_unique_ids' => intval($baselineData['unique_ids'] ?? 0),
            'baseline_duplicate_conflict_ids' => count($baselineData['duplicate_conflicts'] ?? []),
            'baseline_missing_in_db' => count($missingInDb),
            'processed_products' => $processedProducts,
            'products_stock_changed' => $productsStockChanged,
            'total_abs_delta_stock' => $totalAbsDeltaStock,
            'total_post_cutoff_events' => $totalPostCutoffEvents,
            'products_with_negative_event' => $productsWithNegativeEvent,
            'products_eligible_for_apply' => $productsEligibleForApply,
            'products_skipped_because_negative_event' => $productsSkippedBecauseNegativeEvent,
            'generated_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ];
    }
}
