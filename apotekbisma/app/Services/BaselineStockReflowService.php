<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BaselineStockReflowService
{
    private const BASELINE_RECORD_KETERANGAN = 'Saldo Awal Stok per 31-12-2025';
    private const NON_BASELINE_PRE_CUTOFF_SEED_KETERANGAN = 'Saldo Awal Stok dari histori sebelum cutoff';
    private const NON_BASELINE_ZERO_SEED_KETERANGAN = 'Saldo Awal Stok Produk Baru';

    private ?array $cachedBaselineData = null;
    private string $csvDelimiter = ',';
    private array $excludedManualPatterns;

    public function __construct()
    {
        $this->excludedManualPatterns = config('stock.excluded_manual_keterangan_patterns', []);
    }

    public function rebuildProducts(array $productIds, ?string $until = null): array
    {
        $normalizedProductIds = array_values(array_unique(array_filter(array_map('intval', $productIds), function ($productId) {
            return $productId > 0;
        })));

        if (empty($normalizedProductIds)) {
            return [
                'products_rebuilt' => 0,
                'products_with_negative_event' => 0,
                'negative_event_count' => 0,
                'cutoff' => config('stock.cutoff_datetime', '2025-12-31 23:59:59'),
                'until' => $until ? Carbon::parse($until)->format('Y-m-d H:i:s') : Carbon::now()->format('Y-m-d H:i:s'),
                'csv_delimiter' => $this->csvDelimiter,
            ];
        }

        $cutoff = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');
        $resolvedUntil = Carbon::parse($until ?: Carbon::now())->format('Y-m-d H:i:s');

        if ($resolvedUntil <= $cutoff) {
            throw new \RuntimeException('Reflow stok tidak valid karena until harus lebih besar dari cutoff baseline.');
        }

        $baselineData = $this->loadBaselineCsv(base_path(config('stock.baseline_csv')));
        $baselineMap = $baselineData['baseline_map'];

        $products = DB::table('produk')
            ->whereIn('id_produk', $normalizedProductIds)
            ->select('id_produk', 'nama_produk', 'stok')
            ->get()
            ->keyBy('id_produk');

        $missingProductsInDb = array_values(array_diff($normalizedProductIds, array_map('intval', $products->keys()->all())));
        if (!empty($missingProductsInDb)) {
            throw new \RuntimeException('Produk tidak ditemukan di DB: ' . implode(', ', $missingProductsInDb));
        }

        $plans = [];
        $totalNegativeEventCount = 0;
        $productsWithNegativeEvent = 0;
        $locks = $this->acquireProductLocks($normalizedProductIds);

        try {
            foreach ($products as $product) {
                $productId = (int) $product->id_produk;
                $seed = $this->resolveSeedForProduct($productId, $cutoff, $baselineMap);
                $events = $this->collectEventsForProduct($productId, $cutoff, $resolvedUntil);
                $currentTime = Carbon::now();
                $runningStock = intval($seed['stok']);
                $negativeEventCount = 0;
                $insertRows = [
                    [
                        'id_produk' => $productId,
                        'id_penjualan' => null,
                        'id_pembelian' => null,
                        'waktu' => $cutoff,
                        'stok_awal' => $runningStock,
                        'stok_masuk' => 0,
                        'stok_keluar' => 0,
                        'stok_sisa' => $runningStock,
                        'keterangan' => $seed['keterangan'],
                        'created_at' => $currentTime,
                        'updated_at' => $currentTime,
                    ],
                ];

                foreach ($events as $event) {
                    $stokAwal = $runningStock;
                    $stokSisa = $stokAwal + intval($event['stok_masuk']) - intval($event['stok_keluar']);

                    if ($stokSisa < 0) {
                        $negativeEventCount++;
                    }

                    $insertRows[] = [
                        'id_produk' => $productId,
                        'id_penjualan' => $event['id_penjualan'],
                        'id_pembelian' => $event['id_pembelian'],
                        'waktu' => $event['waktu'],
                        'stok_awal' => $stokAwal,
                        'stok_masuk' => intval($event['stok_masuk']),
                        'stok_keluar' => intval($event['stok_keluar']),
                        'stok_sisa' => $stokSisa,
                        'keterangan' => $event['keterangan'],
                        'created_at' => $currentTime,
                        'updated_at' => $currentTime,
                    ];

                    $runningStock = $stokSisa;
                }

                if ($negativeEventCount > 0) {
                    $productsWithNegativeEvent++;
                    $totalNegativeEventCount += $negativeEventCount;
                }

                $plans[] = [
                    'id_produk' => $productId,
                    'seed_source' => $seed['source'],
                    'insert_rows' => $insertRows,
                    'stok_hasil_rebuild' => max(0, $runningStock),
                    'negative_event_count' => $negativeEventCount,
                ];
            }

            foreach ($plans as $plan) {
                DB::table('produk')
                    ->where('id_produk', $plan['id_produk'])
                    ->lockForUpdate()
                    ->first();

                DB::table('rekaman_stoks')
                    ->where('id_produk', $plan['id_produk'])
                    ->where('waktu', '>=', $cutoff)
                    ->delete();

                foreach (array_chunk($plan['insert_rows'], 500) as $chunk) {
                    DB::table('rekaman_stoks')->insert($chunk);
                }

                DB::table('produk')
                    ->where('id_produk', $plan['id_produk'])
                    ->update([
                        'stok' => $plan['stok_hasil_rebuild'],
                        'updated_at' => Carbon::now(),
                    ]);
            }
        } finally {
            $this->releaseProductLocks($locks);
        }

        return [
            'products_rebuilt' => count($plans),
            'products_with_negative_event' => $productsWithNegativeEvent,
            'negative_event_count' => $totalNegativeEventCount,
            'cutoff' => $cutoff,
            'until' => $resolvedUntil,
            'csv_delimiter' => $baselineData['delimiter'] ?? $this->csvDelimiter,
        ];
    }

    private function resolveSeedForProduct(int $productId, string $cutoff, array $baselineMap): array
    {
        if (isset($baselineMap[$productId])) {
            return [
                'stok' => intval($baselineMap[$productId]['stok']),
                'keterangan' => self::BASELINE_RECORD_KETERANGAN,
                'source' => 'baseline_csv',
            ];
        }

        $lastPreCutoffRecord = DB::table('rekaman_stoks')
            ->select('stok_sisa', 'stok_awal', 'stok_masuk', 'stok_keluar')
            ->where('id_produk', $productId)
            ->where('waktu', '<=', $cutoff)
            ->orderBy('waktu', 'desc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();

        if ($lastPreCutoffRecord) {
            $seedStock = $lastPreCutoffRecord->stok_sisa !== null
                ? intval($lastPreCutoffRecord->stok_sisa)
                : intval($lastPreCutoffRecord->stok_awal) + intval($lastPreCutoffRecord->stok_masuk) - intval($lastPreCutoffRecord->stok_keluar);

            return [
                'stok' => $seedStock,
                'keterangan' => self::NON_BASELINE_PRE_CUTOFF_SEED_KETERANGAN,
                'source' => 'pre_cutoff_rekaman',
            ];
        }

        return [
            'stok' => 0,
            'keterangan' => self::NON_BASELINE_ZERO_SEED_KETERANGAN,
            'source' => 'zero_default',
        ];
    }

    private function acquireProductLocks(array $productIds): array
    {
        $locks = [];
        sort($productIds);

        foreach ($productIds as $productId) {
            $lock = Cache::lock('stock_history_reflow_' . $productId, 30);
            if (!$lock->get()) {
                $this->releaseProductLocks($locks);
                throw new \RuntimeException('Stok produk sedang diproses oleh request lain. Silakan ulangi beberapa detik lagi.');
            }

            $locks[] = $lock;
        }

        return $locks;
    }

    /**
     * @param Lock[] $locks
     */
    private function releaseProductLocks(array $locks): void
    {
        foreach (array_reverse($locks) as $lock) {
            try {
                $lock->release();
            } catch (\Throwable $e) {
            }
        }
    }

    private function loadBaselineCsv(string $path): array
    {
        if ($this->cachedBaselineData !== null) {
            return $this->cachedBaselineData;
        }

        if (!$this->isReadableFilePath($path)) {
            $this->cachedBaselineData = [
                'delimiter' => $this->csvDelimiter,
                'rows_read' => 0,
                'unique_ids' => 0,
                'baseline_map' => [],
                'duplicate_conflicts' => [],
            ];

            Log::warning('Baseline CSV path is invalid or unreadable, fallback to pre-cutoff seed', [
                'path' => $path,
            ]);

            return $this->cachedBaselineData;
        }

        $this->csvDelimiter = $this->detectDelimiter($path);

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Tidak dapat membuka file CSV baseline.');
        }

        $header = fgetcsv($handle, 0, $this->csvDelimiter);
        if (!$header || count($header) < 3) {
            fclose($handle);
            throw new \RuntimeException('Header CSV baseline tidak valid.');
        }

        $baselineMap = [];
        $duplicateConflicts = [];
        $rowsRead = 0;

        while (($row = fgetcsv($handle, 0, $this->csvDelimiter)) !== false) {
            if (count($row) < 3) {
                continue;
            }

            $productId = intval($this->normalizeCsvCell($row[0]));
            $productName = trim((string) $this->normalizeCsvCell($row[1]));
            $stockRaw = $this->normalizeCsvCell($row[2]);
            $stock = is_numeric($stockRaw) ? intval($stockRaw) : 0;

            if ($productId <= 0) {
                continue;
            }

            $rowsRead++;

            if (isset($baselineMap[$productId]) && intval($baselineMap[$productId]['stok']) !== $stock) {
                $duplicateConflicts[$productId][] = [
                    'old_stok' => intval($baselineMap[$productId]['stok']),
                    'new_stok' => $stock,
                    'nama_produk' => $productName,
                ];
            }

            $baselineMap[$productId] = [
                'id_produk' => $productId,
                'nama_produk' => $productName,
                'stok' => $stock,
            ];
        }

        fclose($handle);

        $this->cachedBaselineData = [
            'delimiter' => $this->csvDelimiter,
            'rows_read' => $rowsRead,
            'unique_ids' => count($baselineMap),
            'baseline_map' => $baselineMap,
            'duplicate_conflicts' => $duplicateConflicts,
        ];

        return $this->cachedBaselineData;
    }

    private function detectDelimiter(string $path): string
    {
        if (!$this->isReadableFilePath($path)) {
            throw new \RuntimeException('Path CSV baseline tidak valid atau tidak dapat dibaca: ' . $path);
        }

        $sample = @file_get_contents($path, false, null, 0, 2048);
        if ($sample === false) {
            throw new \RuntimeException('Tidak dapat membaca sampel CSV baseline.');
        }

        $sample = preg_replace('/^\xEF\xBB\xBF/', '', $sample);
        $firstLine = strtok($sample, "\r\n") ?: '';

        $delimiterScores = [
            ';' => substr_count($firstLine, ';'),
            ',' => substr_count($firstLine, ','),
            "\t" => substr_count($firstLine, "\t"),
        ];

        arsort($delimiterScores);
        $delimiter = array_key_first($delimiterScores);

        return $delimiter ?: ',';
    }

    private function isReadableFilePath(?string $path): bool
    {
        $normalizedPath = trim((string) $path);
        if ($normalizedPath === '') {
            return false;
        }

        return is_file($normalizedPath) && is_readable($normalizedPath);
    }

    private function normalizeCsvCell($value): string
    {
        return trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $value));
    }

    private function collectEventsForProduct(int $productId, string $cutoff, string $until): array
    {
        $events = [];

        $pembelianEvents = DB::table('pembelian_detail as pd')
            ->join('pembelian as p', 'pd.id_pembelian', '=', 'p.id_pembelian')
            ->where('pd.id_produk', $productId)
            ->where('pd.jumlah', '>', 0)
            ->whereNotNull('p.no_faktur')
            ->where('p.no_faktur', '!=', '')
            ->where('p.no_faktur', '!=', 'o')
            ->where('p.total_harga', '>', 0)
            ->where('p.bayar', '>', 0)
            ->whereRaw('COALESCE(p.waktu_datang, p.waktu, p.created_at) > ?', [$cutoff])
            ->whereRaw('COALESCE(p.waktu_datang, p.waktu, p.created_at) <= ?', [$until])
            ->groupBy('pd.id_pembelian', DB::raw('COALESCE(p.waktu_datang, p.waktu, p.created_at)'))
            ->selectRaw('pd.id_pembelian as ref_id, COALESCE(p.waktu_datang, p.waktu, p.created_at) as waktu_event, SUM(pd.jumlah) as qty, MAX(pd.id_pembelian_detail) as sort_key, MAX(p.no_faktur) as no_faktur')
            ->get();

        foreach ($pembelianEvents as $row) {
            $events[] = [
                'type_priority' => 10,
                'sort_key' => intval($row->sort_key ?? 0),
                'waktu' => (string) $row->waktu_event,
                'id_penjualan' => null,
                'id_pembelian' => intval($row->ref_id),
                'stok_masuk' => intval($row->qty ?? 0),
                'stok_keluar' => 0,
                'keterangan' => 'Pembelian',
            ];
        }

        $penjualanEvents = DB::table('penjualan_detail as pd')
            ->join('penjualan as p', 'pd.id_penjualan', '=', 'p.id_penjualan')
            ->where('pd.id_produk', $productId)
            ->where('pd.jumlah', '>', 0)
            ->where('p.total_item', '>', 0)
            ->where('p.total_harga', '>', 0)
            ->where('p.bayar', '>', 0)
            ->where('p.diterima', '>', 0)
            ->whereRaw('COALESCE(p.waktu, p.created_at) > ?', [$cutoff])
            ->whereRaw('COALESCE(p.waktu, p.created_at) <= ?', [$until])
            ->groupBy('pd.id_penjualan', DB::raw('COALESCE(p.waktu, p.created_at)'))
            ->selectRaw('pd.id_penjualan as ref_id, COALESCE(p.waktu, p.created_at) as waktu_event, SUM(pd.jumlah) as qty, MAX(pd.id_penjualan_detail) as sort_key')
            ->get();

        foreach ($penjualanEvents as $row) {
            $events[] = [
                'type_priority' => 20,
                'sort_key' => intval($row->sort_key ?? 0),
                'waktu' => (string) $row->waktu_event,
                'id_penjualan' => intval($row->ref_id),
                'id_pembelian' => null,
                'stok_masuk' => 0,
                'stok_keluar' => intval($row->qty ?? 0),
                'keterangan' => 'Penjualan',
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

        usort($events, function (array $left, array $right) {
            $timeComparison = strcmp($left['waktu'], $right['waktu']);
            if ($timeComparison !== 0) {
                return $timeComparison;
            }

            if ($left['type_priority'] !== $right['type_priority']) {
                return $left['type_priority'] <=> $right['type_priority'];
            }

            return $left['sort_key'] <=> $right['sort_key'];
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
            $normalizedPattern = mb_strtolower((string) $pattern);
            if ($normalizedPattern !== '' && str_contains($needle, $normalizedPattern)) {
                return true;
            }
        }

        return false;
    }
}