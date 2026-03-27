<?php

namespace App\Console\Commands;

use App\Models\Produk;
use App\Models\RekamanStok;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairPostCutoffStockFromJson extends Command
{
    protected $signature = 'stock:repair-post-cutoff-from-json
                            {--file=* : Path file JSON input (bisa lebih dari satu)}
                            {--apply : Terapkan perbaikan ke database}
                            {--cutoff= : Cutoff baseline stok (Y-m-d atau Y-m-d H:i:s), default dari config stock.cutoff_datetime}
                            {--from= : Filter tanggal faktur dari (Y-m-d atau Y-m-d H:i:s)}
                            {--until= : Filter tanggal faktur sampai (Y-m-d atau Y-m-d H:i:s)}
                            {--invoice=* : Batasi ke nomor faktur tertentu (bisa lebih dari satu)}
                            {--product=* : Batasi ke id produk tertentu (bisa lebih dari satu)}
                            {--report= : Path report JSON output}';

    protected $description = 'Audit + repair stok post-cutoff baseline menggunakan JSON pembelian sebagai source of truth (non-destruktif, robust)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $filePaths = $this->resolveFilePaths((array) $this->option('file'));
        if (empty($filePaths)) {
            $this->error('Tidak ada file JSON input yang valid. Gunakan --file atau pastikan file default Januari-Februari tersedia.');
            return 1;
        }

        $cutoff = $this->resolveCutoff((string) $this->option('cutoff'));
        if ($cutoff === false) {
            $this->error('Format --cutoff tidak valid. Gunakan Y-m-d atau Y-m-d H:i:s');
            return 1;
        }

        $from = $this->parseOptionalDate((string) $this->option('from'), true);
        $until = $this->parseOptionalDate((string) $this->option('until'), false);
        if ($from === false || $until === false) {
            $this->error('Format tanggal --from/--until tidak valid. Gunakan Y-m-d atau Y-m-d H:i:s');
            return 1;
        }

        if (!($from instanceof Carbon)) {
            $from = $cutoff->copy()->addSecond();
        }

        if ($until instanceof Carbon && $from->gt($until)) {
            $this->error('--from tidak boleh lebih besar dari --until');
            return 1;
        }

        $invoiceFilter = collect((array) $this->option('invoice'))
            ->map(function ($value) {
                return mb_strtoupper(trim((string) $value));
            })
            ->filter(function ($value) {
                return $value !== '';
            })
            ->unique()
            ->values();

        $productFilter = collect((array) $this->option('product'))
            ->map(function ($value) {
                return intval($value);
            })
            ->filter(function ($value) {
                return $value > 0;
            })
            ->unique()
            ->values();

        $source = $this->buildSourceMaps($filePaths, $cutoff, $from, $until, $invoiceFilter, $productFilter);

        if (empty($source['invoices'])) {
            $this->warn('Tidak ada invoice source valid setelah filter cutoff/date/invoice/product.');
            return 0;
        }

        $dbInvoices = DB::table('pembelian')
            ->whereIn('no_faktur', array_keys($source['invoices']))
            ->select('id_pembelian', 'no_faktur', 'waktu', 'waktu_datang', 'created_at')
            ->get()
            ->keyBy(function ($row) {
                return mb_strtoupper(trim((string) $row->no_faktur));
            });

        $missingInvoices = array_values(array_diff(array_keys($source['invoices']), $dbInvoices->keys()->all()));

        $invoiceQtyMismatches = [];
        $expectedInboundByProduct = [];
        $actualInboundByProduct = [];
        $matchedInvoiceCount = 0;

        foreach ($source['invoices'] as $invoiceNo => $invoiceData) {
            if (!isset($dbInvoices[$invoiceNo])) {
                continue;
            }

            $matchedInvoiceCount++;
            $dbInvoice = $dbInvoices[$invoiceNo];

            $detailRows = DB::table('pembelian_detail')
                ->where('id_pembelian', $dbInvoice->id_pembelian)
                ->select('id_produk', DB::raw('SUM(jumlah) as qty'))
                ->groupBy('id_produk')
                ->get();

            $dbProductQty = [];
            foreach ($detailRows as $row) {
                $pid = intval($row->id_produk);
                if ($pid <= 0) {
                    continue;
                }

                $dbQty = intval(round((float) $row->qty));
                $dbProductQty[$pid] = $dbQty;
                $actualInboundByProduct[$pid] = ($actualInboundByProduct[$pid] ?? 0) + $dbQty;
            }

            foreach ($invoiceData['products'] as $pid => $qty) {
                $expectedInboundByProduct[$pid] = ($expectedInboundByProduct[$pid] ?? 0) + $qty;
            }

            $mismatchProducts = $this->compareProductQtyMaps($invoiceData['products'], $dbProductQty);
            if (!empty($mismatchProducts)) {
                $invoiceQtyMismatches[] = [
                    'no_faktur' => $invoiceNo,
                    'id_pembelian' => (int) $dbInvoice->id_pembelian,
                    'source_file' => $invoiceData['source_file'] ?? null,
                    'source_waktu' => $invoiceData['tanggal_waktu_faktur'] ?? null,
                    'mismatches' => $mismatchProducts,
                ];
            }
        }

        $impactedProductIds = collect(array_unique(array_merge(
            array_keys($expectedInboundByProduct),
            array_keys($actualInboundByProduct)
        )))->map(function ($value) {
            return intval($value);
        })->filter(function ($value) {
            return $value > 0;
        })->values();

        if ($productFilter->isNotEmpty()) {
            $impactedProductIds = $impactedProductIds
                ->filter(function ($pid) use ($productFilter) {
                    return $productFilter->contains($pid);
                })
                ->values();
        }

        $productRepairs = [];
        $repairErrors = [];

        foreach ($impactedProductIds as $productId) {
            $expectedQty = intval($expectedInboundByProduct[$productId] ?? 0);
            $actualQty = intval($actualInboundByProduct[$productId] ?? 0);
            $deltaPurchase = $expectedQty - $actualQty;

            $produk = Produk::find($productId);
            $integrityBefore = RekamanStok::verifyIntegrity($productId);

            $repairInfo = [
                'id_produk' => $productId,
                'nama_produk' => $produk ? $produk->nama_produk : null,
                'expected_pembelian_qty' => $expectedQty,
                'actual_pembelian_qty' => $actualQty,
                'delta_pembelian_qty' => $deltaPurchase,
                'integrity_before' => $integrityBefore,
                'action' => 'none',
                'adjustment' => null,
                'repair' => null,
                'integrity_after' => $integrityBefore,
                'error' => null,
            ];

            if ($apply) {
                try {
                    DB::transaction(function () use ($productId, $expectedQty, $actualQty, $deltaPurchase, &$repairInfo) {
                        if ($deltaPurchase !== 0) {
                            $repairInfo['adjustment'] = $this->createJsonSourceStockAdjustment(
                                $productId,
                                $deltaPurchase,
                                $expectedQty,
                                $actualQty
                            );
                            $repairInfo['action'] = 'adjust-and-repair';
                        } else {
                            $repairInfo['action'] = 'repair-chain';
                        }

                        $repairInfo['repair'] = RekamanStok::fullRepair($productId);
                    }, 3);

                    $repairInfo['integrity_after'] = RekamanStok::verifyIntegrity($productId);
                } catch (\Throwable $e) {
                    $repairInfo['error'] = $e->getMessage();
                    $repairErrors[] = [
                        'id_produk' => $productId,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $productRepairs[] = $repairInfo;
        }

        $deltaProducts = collect($productRepairs)
            ->filter(function ($row) {
                return intval($row['delta_pembelian_qty'] ?? 0) !== 0;
            })
            ->count();

        $invalidAfter = collect($productRepairs)
            ->filter(function ($row) {
                return !($row['integrity_after']['valid'] ?? false);
            })
            ->count();

        $report = [
            'generated_at' => now()->format('Y-m-d H:i:s'),
            'mode' => $apply ? 'APPLY' : 'DRY_RUN',
            'source' => [
                'files' => $filePaths,
                'cutoff' => $cutoff->format('Y-m-d H:i:s'),
                'from' => $from->format('Y-m-d H:i:s'),
                'until' => $until instanceof Carbon ? $until->format('Y-m-d H:i:s') : null,
                'invoice_filter' => $invoiceFilter->all(),
                'product_filter' => $productFilter->all(),
            ],
            'summary' => [
                'source_invoices' => count($source['invoices']),
                'source_conflicts' => count($source['conflicts']),
                'missing_in_db' => count($missingInvoices),
                'matched_invoices' => $matchedInvoiceCount,
                'invoice_qty_mismatch' => count($invoiceQtyMismatches),
                'impacted_products' => $impactedProductIds->count(),
                'products_with_purchase_delta' => $deltaProducts,
                'repair_errors' => count($repairErrors),
                'invalid_integrity_after' => $invalidAfter,
            ],
            'source_conflicts' => array_values($source['conflicts']),
            'missing_in_db' => $missingInvoices,
            'invoice_qty_mismatch' => $invoiceQtyMismatches,
            'product_repairs' => $productRepairs,
            'repair_errors' => $repairErrors,
        ];

        $reportPath = $this->writeReport($report);

        $this->info('=== REPAIR STOK POST-CUTOFF DARI JSON ===');
        $this->line('Mode      : ' . ($apply ? 'APPLY' : 'DRY-RUN'));
        $this->line('Cutoff    : ' . $cutoff->format('Y-m-d H:i:s'));
        $this->line('From      : ' . $from->format('Y-m-d H:i:s'));
        $this->line('Until     : ' . ($until instanceof Carbon ? $until->format('Y-m-d H:i:s') : '-'));
        $this->line('Files     : ' . count($filePaths));
        $this->line('Report    : ' . $reportPath);

        $this->table(
            ['Source Inv', 'Conflict', 'Missing DB', 'Mismatch Inv', 'Products', 'Delta Prod', 'Errors', 'Invalid After'],
            [[
                count($source['invoices']),
                count($source['conflicts']),
                count($missingInvoices),
                count($invoiceQtyMismatches),
                $impactedProductIds->count(),
                $deltaProducts,
                count($repairErrors),
                $invalidAfter,
            ]]
        );

        if (!$apply) {
            $this->info('Dry-run selesai. Jalankan dengan --apply untuk menerapkan perbaikan.');
        } elseif (!empty($repairErrors)) {
            $this->warn('Apply selesai dengan beberapa error. Lihat report untuk detail produk yang gagal diperbaiki.');
        } else {
            $this->info('Apply selesai tanpa error.');
        }

        return 0;
    }

    private function buildSourceMaps(
        array $filePaths,
        Carbon $cutoff,
        Carbon $from,
        ?Carbon $until,
        $invoiceFilter,
        $productFilter
    ): array {
        $sourceInvoices = [];
        $sourceConflicts = [];

        foreach ($filePaths as $filePath) {
            $decoded = json_decode((string) file_get_contents($filePath), true);
            if (!is_array($decoded)) {
                $this->warn('File dilewati karena bukan JSON object/array valid: ' . $filePath);
                continue;
            }

            $purchases = $this->extractPurchases($decoded);
            if (empty($purchases)) {
                $this->warn('File dilewati karena tidak memiliki key purchases valid: ' . $filePath);
                continue;
            }

            foreach ($purchases as $purchase) {
                $invoiceNo = mb_strtoupper(trim((string) ($purchase['nomor_faktur'] ?? '')));
                if ($invoiceNo === '') {
                    continue;
                }

                if ($invoiceFilter->isNotEmpty() && !$invoiceFilter->contains($invoiceNo)) {
                    continue;
                }

                $invoiceDateRaw = (string) ($purchase['tanggal_waktu_faktur'] ?? $purchase['tanggal_waktu_obat_datang'] ?? '');
                $invoiceDate = $this->parseOptionalDate($invoiceDateRaw, true);
                if (!($invoiceDate instanceof Carbon)) {
                    continue;
                }

                if ($invoiceDate->lte($cutoff)) {
                    continue;
                }

                if ($invoiceDate->lt($from)) {
                    continue;
                }

                if ($until instanceof Carbon && $invoiceDate->gt($until)) {
                    continue;
                }

                $productQty = [];
                foreach ((array) ($purchase['detail'] ?? []) as $detail) {
                    if (!is_array($detail)) {
                        continue;
                    }

                    $productId = intval($detail['id_produk'] ?? 0);
                    if ($productId <= 0) {
                        continue;
                    }

                    if ($productFilter->isNotEmpty() && !$productFilter->contains($productId)) {
                        continue;
                    }

                    $jumlah = $this->parseNumericToInt($detail['jumlah'] ?? 0);
                    if ($jumlah <= 0) {
                        continue;
                    }

                    $productQty[$productId] = ($productQty[$productId] ?? 0) + $jumlah;
                }

                if (empty($productQty)) {
                    continue;
                }

                ksort($productQty);

                $newEntry = [
                    'no_faktur' => $invoiceNo,
                    'tanggal_waktu_faktur' => $invoiceDate->format('Y-m-d H:i:s'),
                    'source_file' => $filePath,
                    'products' => $productQty,
                ];

                if (isset($sourceInvoices[$invoiceNo])) {
                    $existing = $sourceInvoices[$invoiceNo];
                    if ($existing['products'] !== $newEntry['products']) {
                        $sourceConflicts[$invoiceNo] = [
                            'no_faktur' => $invoiceNo,
                            'file_awal' => $existing['source_file'] ?? null,
                            'file_baru' => $filePath,
                            'tanggal_awal' => $existing['tanggal_waktu_faktur'] ?? null,
                            'tanggal_baru' => $newEntry['tanggal_waktu_faktur'],
                        ];
                    }

                    continue;
                }

                $sourceInvoices[$invoiceNo] = $newEntry;
            }
        }

        foreach (array_keys($sourceConflicts) as $invoiceNo) {
            unset($sourceInvoices[$invoiceNo]);
        }

        return [
            'invoices' => $sourceInvoices,
            'conflicts' => $sourceConflicts,
        ];
    }

    private function compareProductQtyMaps(array $source, array $db): array
    {
        $allProductIds = array_unique(array_merge(array_keys($source), array_keys($db)));
        sort($allProductIds);

        $mismatches = [];
        foreach ($allProductIds as $pid) {
            $sourceQty = intval($source[$pid] ?? 0);
            $dbQty = intval($db[$pid] ?? 0);
            if ($sourceQty === $dbQty) {
                continue;
            }

            $mismatches[] = [
                'id_produk' => intval($pid),
                'qty_source' => $sourceQty,
                'qty_db' => $dbQty,
                'delta' => $sourceQty - $dbQty,
            ];
        }

        return $mismatches;
    }

    private function createJsonSourceStockAdjustment(int $productId, int $deltaPurchase, int $expectedQty, int $actualQty): array
    {
        $produk = Produk::where('id_produk', $productId)->lockForUpdate()->first();
        if (!$produk) {
            throw new \RuntimeException('Produk tidak ditemukan: ' . $productId);
        }

        $latestRecord = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->orderBy('waktu', 'desc')
            ->orderBy('id_rekaman_stok', 'desc')
            ->lockForUpdate()
            ->first();

        $stokAwal = $latestRecord ? intval($latestRecord->stok_sisa) : intval($produk->stok);
        $stokSisa = $stokAwal + $deltaPurchase;

        $now = Carbon::now();
        $keterangan = sprintf(
            'Stock Opname: Auto Repair Post-Cutoff JSON Source (delta pembelian %d; expected %d, db %d)',
            $deltaPurchase,
            $expectedQty,
            $actualQty
        );

        RekamanStok::create([
            'id_produk' => $productId,
            'waktu' => $now->format('Y-m-d H:i:s.u'),
            'stok_awal' => $stokAwal,
            'stok_masuk' => $deltaPurchase > 0 ? $deltaPurchase : 0,
            'stok_keluar' => $deltaPurchase < 0 ? abs($deltaPurchase) : 0,
            'stok_sisa' => $stokSisa,
            'keterangan' => $keterangan,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('produk')
            ->where('id_produk', $productId)
            ->update([
                'stok' => max(0, $stokSisa),
                'updated_at' => $now,
            ]);

        return [
            'stok_awal' => $stokAwal,
            'stok_sisa_target' => $stokSisa,
            'stok_masuk' => $deltaPurchase > 0 ? $deltaPurchase : 0,
            'stok_keluar' => $deltaPurchase < 0 ? abs($deltaPurchase) : 0,
            'keterangan' => $keterangan,
        ];
    }

    private function resolveCutoff(string $value)
    {
        $raw = trim($value);
        if ($raw === '') {
            $raw = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');
        }

        return $this->parseOptionalDate($raw, false);
    }

    private function resolvePath(string $path): ?string
    {
        $value = trim($path);
        if ($value === '') {
            return null;
        }

        if (is_file($value)) {
            return $value;
        }

        $candidate = base_path($value);
        if (is_file($candidate)) {
            return $candidate;
        }

        return null;
    }

    private function resolveFilePaths(array $files): array
    {
        $inputs = collect($files)
            ->map(function ($value) {
                return trim((string) $value);
            })
            ->filter(function ($value) {
                return $value !== '';
            })
            ->values();

        if ($inputs->isEmpty()) {
            $inputs = collect([
                'DATA_INPUT_JANUARI_EXECUTABLE.json',
                'DATA_INPUT_FEBRUARI_EXECUTABLE.json',
            ]);
        }

        $resolved = [];
        foreach ($inputs as $item) {
            $path = $this->resolvePath($item);
            if ($path === null || !is_file($path) || !is_readable($path)) {
                $this->warn('File dilewati (tidak ditemukan/tidak bisa dibaca): ' . $item);
                continue;
            }

            $resolved[] = $path;
        }

        return array_values(array_unique($resolved));
    }

    private function parseOptionalDate(string $value, bool $startOfDay)
    {
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                $dateOnly = Carbon::createFromFormat('Y-m-d', $raw);
                if ($dateOnly === false || $dateOnly->format('Y-m-d') !== $raw) {
                    return false;
                }

                return $startOfDay
                    ? Carbon::createFromFormat('Y-m-d H:i:s', $raw . ' 00:00:00')
                    : Carbon::createFromFormat('Y-m-d H:i:s', $raw . ' 23:59:59');
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $raw)) {
                $withTime = Carbon::createFromFormat('Y-m-d H:i:s', $raw);
                if ($withTime === false || $withTime->format('Y-m-d H:i:s') !== $raw) {
                    return false;
                }

                return $withTime;
            }

            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function extractPurchases(array $decoded): array
    {
        if (isset($decoded['purchases']) && is_array($decoded['purchases'])) {
            return $decoded['purchases'];
        }

        if ($this->isListArray($decoded)) {
            return $decoded;
        }

        return [];
    }

    private function isListArray(array $value): bool
    {
        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }

    private function parseNumericToInt($value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_float($value)) {
            return (int) max(0, round($value));
        }

        if (is_numeric($value)) {
            return (int) max(0, round((float) $value));
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return 0;
        }

        $normalized = str_replace(['rp', 'idr', ' '], '', strtolower($stringValue));
        if ($normalized === '' || $normalized === '-') {
            return 0;
        }

        if (strpos($normalized, ',') !== false && strpos($normalized, '.') !== false) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (strpos($normalized, ',') !== false) {
            $normalized = str_replace(',', '.', $normalized);
        }

        if (!is_numeric($normalized)) {
            return 0;
        }

        return (int) max(0, round((float) $normalized));
    }

    private function writeReport(array $report): string
    {
        $pathOption = trim((string) $this->option('report'));
        $reportPath = $pathOption !== ''
            ? $pathOption
            : base_path('post_cutoff_stock_repair_report_' . Carbon::now()->format('Ymd_His') . '.json');

        $outputPath = $this->resolveOutputPath($reportPath);
        $this->ensureOutputDirectoryExists($outputPath);

        file_put_contents(
            $outputPath,
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $outputPath;
    }

    private function resolveOutputPath(string $path): string
    {
        $value = trim($path);
        if ($value === '') {
            return base_path('post_cutoff_stock_repair_report_' . Carbon::now()->format('Ymd_His') . '.json');
        }

        if (
            preg_match('/^[A-Za-z]:\\\\|^\\\\\\\\/', $value) === 1
            || strpos($value, '/') === 0
        ) {
            return $value;
        }

        return base_path($value);
    }

    private function ensureOutputDirectoryExists(string $outputPath): void
    {
        $directory = dirname($outputPath);
        if ($directory === '' || $directory === '.') {
            return;
        }

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }
}
