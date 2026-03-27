<?php

namespace App\Console\Commands;

use App\Models\RekamanStok;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeBaselineStockWaktu extends Command
{
    protected $signature = 'stock:normalize-baseline-waktu
                            {--apply : Terapkan perubahan ke database}
                            {--cutoff= : Cutoff baseline stok (Y-m-d atau Y-m-d H:i:s), default dari config stock.cutoff_datetime}
                            {--report= : Path report JSON output}';

    protected $description = 'Normalisasi waktu record baseline Saldo Awal Stok agar kembali ke cutoff 31-12-2025 dan recalculate produk terdampak';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $cutoff = $this->resolveCutoff((string) $this->option('cutoff'));
        if ($cutoff === false) {
            $this->error('Format --cutoff tidak valid. Gunakan Y-m-d atau Y-m-d H:i:s');
            return 1;
        }

        $targetTime = $cutoff->format('Y-m-d H:i:s');

        $records = DB::table('rekaman_stoks')
            ->whereNull('id_pembelian')
            ->whereNull('id_penjualan')
            ->where('keterangan', 'like', '%Saldo Awal Stok per 31-12-2025%')
            ->whereRaw('DATE(waktu) > ?', [$cutoff->format('Y-m-d')])
            ->orderBy('id_rekaman_stok', 'asc')
            ->get(['id_rekaman_stok', 'id_produk', 'waktu', 'stok_awal', 'stok_sisa', 'keterangan']);

        $updates = [];
        $affectedProducts = [];
        foreach ($records as $record) {
            $currentWaktu = $this->normalizeDateTime($record->waktu);
            if ($currentWaktu === $targetTime) {
                continue;
            }

            $updates[] = [
                'id_rekaman_stok' => (int) $record->id_rekaman_stok,
                'id_produk' => (int) $record->id_produk,
                'waktu_lama' => $currentWaktu,
                'waktu_baru' => $targetTime,
                'stok_awal' => (int) $record->stok_awal,
                'stok_sisa' => (int) $record->stok_sisa,
                'keterangan' => (string) $record->keterangan,
            ];
            $affectedProducts[(int) $record->id_produk] = true;
        }

        $report = [
            'generated_at' => now()->format('Y-m-d H:i:s'),
            'mode' => $apply ? 'APPLY' : 'DRY_RUN',
            'cutoff' => $targetTime,
            'summary' => [
                'baseline_records_found' => $records->count(),
                'to_update' => count($updates),
                'affected_products' => count($affectedProducts),
            ],
            'updates_preview' => array_slice($updates, 0, 200),
        ];

        $reportPath = $this->writeReport($report);

        $this->info('=== NORMALISASI BASELINE STOCK WAKTU ===');
        $this->line('Mode      : ' . ($apply ? 'APPLY' : 'DRY-RUN'));
        $this->line('Cutoff    : ' . $targetTime);
        $this->line('Report    : ' . $reportPath);
        $this->table(
            ['Found', 'To Update', 'Affected Products'],
            [[(int) $records->count(), count($updates), count($affectedProducts)]]
        );

        if (!$apply) {
            $this->info('Dry-run selesai. Jalankan dengan --apply untuk menerapkan normalisasi baseline.');
            return 0;
        }

        if (empty($updates)) {
            $this->warn('Tidak ada baseline record yang perlu dinormalisasi.');
            return 0;
        }

        DB::transaction(function () use ($updates, $targetTime): void {
            foreach ($updates as $row) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $row['id_rekaman_stok'])
                    ->update([
                        'waktu' => $targetTime,
                    ]);
            }
        }, 3);

        foreach (array_keys($affectedProducts) as $productId) {
            RekamanStok::recalculateStock((int) $productId);
        }

        $this->info('Apply selesai. Baseline records dinormalisasi: ' . count($updates));
        $this->info('Produk direcalculate ulang: ' . count($affectedProducts));

        return 0;
    }

    private function resolveCutoff(string $value)
    {
        $raw = trim($value);
        if ($raw === '') {
            $raw = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                $dateOnly = Carbon::createFromFormat('Y-m-d', $raw);
                if ($dateOnly === false || $dateOnly->format('Y-m-d') !== $raw) {
                    return false;
                }

                return Carbon::createFromFormat('Y-m-d H:i:s', $raw . ' 23:59:59');
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $raw)) {
                $withTime = Carbon::createFromFormat('Y-m-d H:i:s', $raw);
                if ($withTime === false || $withTime->format('Y-m-d H:i:s') !== $raw) {
                    return false;
                }

                return $withTime;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    private function normalizeDateTime($value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function writeReport(array $report): string
    {
        $pathOption = trim((string) $this->option('report'));
        $reportPath = $pathOption !== ''
            ? $pathOption
            : base_path('baseline_waktu_normalize_report_' . Carbon::now()->format('Ymd_His') . '.json');

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
            return base_path('baseline_waktu_normalize_report_' . Carbon::now()->format('Ymd_His') . '.json');
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
