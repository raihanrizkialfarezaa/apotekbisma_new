<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CorrectPurchaseBayarFromJson extends Command
{
    protected $signature = 'stock:correct-purchase-bayar
                            {--file=* : Path file JSON input (bisa lebih dari satu)}
                            {--apply : Terapkan perubahan ke database}
                            {--from= : Filter tanggal faktur dari (Y-m-d atau Y-m-d H:i:s)}
                            {--until= : Filter tanggal faktur sampai (Y-m-d atau Y-m-d H:i:s)}
                            {--invoice=* : Batasi ke nomor faktur tertentu (bisa lebih dari satu)}
                            {--report= : Path report JSON output}';

    protected $description = 'Koreksi kolom bayar pada pembelian dari file JSON berdasarkan nomor faktur (tanpa re-import detail)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $filePaths = $this->resolveFilePaths($this->option('file'));
        if (empty($filePaths)) {
            $this->error('Tidak ada file JSON input yang valid. Gunakan --file atau pastikan file default Januari-Februari tersedia.');
            return 1;
        }

        $from = $this->parseOptionalDate((string) $this->option('from'), true);
        $until = $this->parseOptionalDate((string) $this->option('until'), false);
        if ($from === false || $until === false) {
            $this->error('Format tanggal --from/--until tidak valid. Gunakan Y-m-d atau Y-m-d H:i:s');
            return 1;
        }

        if ($from instanceof Carbon && $until instanceof Carbon && $from->gt($until)) {
            $this->error('--from tidak boleh lebih besar dari --until');
            return 1;
        }

        $invoiceFilter = collect($this->option('invoice'))
            ->map(function ($value) {
                return mb_strtoupper(trim((string) $value));
            })
            ->filter(function ($value) {
                return $value !== '';
            })
            ->unique()
            ->values();

        $sourceByInvoice = [];
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
                if ($invoiceDate === false) {
                    $invoiceDate = null;
                }

                if ($from instanceof Carbon && $invoiceDate instanceof Carbon && $invoiceDate->lt($from)) {
                    continue;
                }

                if ($until instanceof Carbon && $invoiceDate instanceof Carbon && $invoiceDate->gt($until)) {
                    continue;
                }

                $bayar = $this->parseNumericToInt($purchase['bayar'] ?? 0);
                if ($bayar <= 0) {
                    continue;
                }

                if (isset($sourceByInvoice[$invoiceNo]) && (int) $sourceByInvoice[$invoiceNo]['bayar'] !== $bayar) {
                    $sourceConflicts[$invoiceNo] = [
                        'no_faktur' => $invoiceNo,
                        'bayar_awal' => (int) $sourceByInvoice[$invoiceNo]['bayar'],
                        'bayar_baru' => $bayar,
                        'file_awal' => $sourceByInvoice[$invoiceNo]['source_file'] ?? null,
                        'file_baru' => $filePath,
                    ];
                    continue;
                }

                $sourceByInvoice[$invoiceNo] = [
                    'no_faktur' => $invoiceNo,
                    'bayar' => $bayar,
                    'tanggal_waktu_faktur' => $invoiceDate instanceof Carbon ? $invoiceDate->format('Y-m-d H:i:s') : $invoiceDateRaw,
                    'source_file' => $filePath,
                ];
            }
        }

        foreach (array_keys($sourceConflicts) as $conflictInvoice) {
            unset($sourceByInvoice[$conflictInvoice]);
        }

        if (empty($sourceByInvoice)) {
            $this->warn('Tidak ada nomor faktur dengan nilai bayar valid setelah filter.');
            return 0;
        }

        $dbRows = DB::table('pembelian')
            ->whereIn('no_faktur', array_keys($sourceByInvoice))
            ->select('id_pembelian', 'no_faktur', 'bayar', 'waktu')
            ->get();

        $updates = [];
        $unchanged = [];
        foreach ($dbRows as $row) {
            $invoiceNo = mb_strtoupper(trim((string) $row->no_faktur));
            $source = $sourceByInvoice[$invoiceNo] ?? null;
            if ($source === null) {
                continue;
            }

            $currentBayar = (int) round((float) ($row->bayar ?? 0));
            $targetBayar = (int) $source['bayar'];

            if ($currentBayar === $targetBayar) {
                $unchanged[] = [
                    'id_pembelian' => (int) $row->id_pembelian,
                    'no_faktur' => $invoiceNo,
                    'bayar_db' => $currentBayar,
                    'bayar_json' => $targetBayar,
                    'status' => 'sama',
                ];
                continue;
            }

            $updates[] = [
                'id_pembelian' => (int) $row->id_pembelian,
                'no_faktur' => $invoiceNo,
                'bayar_db' => $currentBayar,
                'bayar_json' => $targetBayar,
                'selisih' => $targetBayar - $currentBayar,
            ];
        }

        $foundInvoices = collect($dbRows)
            ->pluck('no_faktur')
            ->map(function ($value) {
                return mb_strtoupper(trim((string) $value));
            })
            ->all();

        $missingInvoices = array_values(array_diff(array_keys($sourceByInvoice), $foundInvoices));

        $report = [
            'generated_at' => now()->format('Y-m-d H:i:s'),
            'mode' => $apply ? 'APPLY' : 'DRY_RUN',
            'files' => $filePaths,
            'filters' => [
                'from' => $from instanceof Carbon ? $from->format('Y-m-d H:i:s') : null,
                'until' => $until instanceof Carbon ? $until->format('Y-m-d H:i:s') : null,
                'invoice' => $invoiceFilter->all(),
            ],
            'summary' => [
                'source_invoices' => count($sourceByInvoice),
                'db_found' => count($dbRows),
                'to_update' => count($updates),
                'unchanged' => count($unchanged),
                'missing_in_db' => count($missingInvoices),
                'source_conflicts' => count($sourceConflicts),
            ],
            'updates' => $updates,
            'unchanged' => array_slice($unchanged, 0, 200),
            'missing_in_db' => $missingInvoices,
            'source_conflicts' => array_values($sourceConflicts),
        ];

        $reportPath = $this->writeReport($report);

        $this->info('=== BATCH CORRECTION BAYAR PEMBELIAN ===');
        $this->line('Mode      : ' . ($apply ? 'APPLY' : 'DRY-RUN'));
        $this->line('Files     : ' . count($filePaths));
        $this->line('Report    : ' . $reportPath);
        $this->table(
            ['Source', 'DB Found', 'Update', 'Unchanged', 'Missing DB', 'Source Conflict'],
            [[count($sourceByInvoice), count($dbRows), count($updates), count($unchanged), count($missingInvoices), count($sourceConflicts)]]
        );

        if (!empty($sourceConflicts)) {
            $this->warn('Ada nomor faktur dengan nilai bayar konflik antar file sumber. Invoice konflik di-skip demi akurasi. Lihat report untuk detail.');
        }

        if (!$apply) {
            $this->info('Dry-run selesai. Jalankan dengan --apply untuk menerapkan update bayar.');
            return 0;
        }

        if (empty($updates)) {
            $this->warn('Tidak ada data bayar yang perlu diupdate.');
            return 0;
        }

        DB::transaction(function () use ($updates): void {
            $now = now();
            foreach ($updates as $row) {
                DB::table('pembelian')
                    ->where('id_pembelian', $row['id_pembelian'])
                    ->update([
                        'bayar' => $row['bayar_json'],
                        'updated_at' => $now,
                    ]);
            }
        }, 3);

        $this->info('Apply selesai. Total pembelian diupdate: ' . count($updates));
        return 0;
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
            $all = [];
            foreach ($decoded as $item) {
                if (is_array($item) && isset($item['purchases']) && is_array($item['purchases'])) {
                    foreach ($item['purchases'] as $purchase) {
                        $all[] = $purchase;
                    }
                }
            }

            return $all;
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
            : base_path('purchase_bayar_correction_report_' . Carbon::now()->format('Ymd_His') . '.json');

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
            return base_path('purchase_bayar_correction_report_' . Carbon::now()->format('Ymd_His') . '.json');
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
