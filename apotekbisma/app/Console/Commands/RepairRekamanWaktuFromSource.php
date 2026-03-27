<?php

namespace App\Console\Commands;

use App\Models\RekamanStok;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairRekamanWaktuFromSource extends Command
{
    protected $signature = 'stock:repair-rekaman-waktu
                            {--apply : Terapkan perbaikan ke database}
                            {--only-date= : Batasi hanya rekaman dengan tanggal ini (Y-m-d)}
                            {--report= : Path report JSON output}';

    protected $description = 'Perbaiki kolom waktu rekaman_stoks agar sinkron ke waktu transaksi sumber (penjualan/pembelian), lalu recalculate stok produk terdampak';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $onlyDate = trim((string) $this->option('only-date'));

        if ($onlyDate !== '' && !$this->isValidDateOnly($onlyDate)) {
            $this->error('Format --only-date tidak valid. Gunakan Y-m-d');
            return 1;
        }

        $candidates = DB::table('rekaman_stoks as rs')
            ->leftJoin('penjualan as pj', 'pj.id_penjualan', '=', 'rs.id_penjualan')
            ->leftJoin('pembelian as pb', 'pb.id_pembelian', '=', 'rs.id_pembelian')
            ->where(function ($query) {
                $query->whereNotNull('rs.id_penjualan')
                    ->orWhereNotNull('rs.id_pembelian');
            })
            ->when($onlyDate !== '', function ($query) use ($onlyDate) {
                $query->whereDate('rs.waktu', $onlyDate);
            })
            ->orderBy('rs.id_rekaman_stok', 'asc')
            ->get([
                'rs.id_rekaman_stok',
                'rs.id_produk',
                'rs.id_pembelian',
                'rs.id_penjualan',
                'rs.waktu as waktu_rekaman',
                'pj.waktu as waktu_penjualan',
                'pb.waktu as waktu_pembelian',
                'pb.waktu_datang as waktu_datang_pembelian',
                'pb.created_at as created_pembelian',
                'rs.keterangan',
            ]);

        $updates = [];
        $affectedProducts = [];

        foreach ($candidates as $row) {
            $expected = $this->resolveExpectedWaktu($row);
            if ($expected === null) {
                continue;
            }

            $current = $this->normalizeDateTime($row->waktu_rekaman);
            if ($current === null) {
                continue;
            }

            if ($current === $expected) {
                continue;
            }

            $updates[] = [
                'id_rekaman_stok' => (int) $row->id_rekaman_stok,
                'id_produk' => (int) $row->id_produk,
                'id_pembelian' => $row->id_pembelian !== null ? (int) $row->id_pembelian : null,
                'id_penjualan' => $row->id_penjualan !== null ? (int) $row->id_penjualan : null,
                'waktu_lama' => $current,
                'waktu_baru' => $expected,
                'keterangan' => (string) ($row->keterangan ?? ''),
            ];

            $affectedProducts[(int) $row->id_produk] = true;
        }

        $report = [
            'generated_at' => now()->format('Y-m-d H:i:s'),
            'mode' => $apply ? 'APPLY' : 'DRY_RUN',
            'filter' => [
                'only_date' => $onlyDate !== '' ? $onlyDate : null,
            ],
            'summary' => [
                'candidates' => $candidates->count(),
                'to_fix' => count($updates),
                'affected_products' => count($affectedProducts),
            ],
            'updates_preview' => array_slice($updates, 0, 300),
        ];

        $reportPath = $this->writeReport($report);

        $this->info('=== REPAIR REKAMAN WAKTU DARI SUMBER TRANSAKSI ===');
        $this->line('Mode      : ' . ($apply ? 'APPLY' : 'DRY-RUN'));
        $this->line('Only Date : ' . ($onlyDate !== '' ? $onlyDate : '-'));
        $this->line('Report    : ' . $reportPath);
        $this->table(
            ['Candidates', 'To Fix', 'Affected Products'],
            [[(int) $candidates->count(), count($updates), count($affectedProducts)]]
        );

        if (!$apply) {
            $this->info('Dry-run selesai. Jalankan dengan --apply untuk menerapkan perbaikan waktu.');
            return 0;
        }

        if (empty($updates)) {
            $this->warn('Tidak ada rekaman yang perlu diperbaiki.');
            return 0;
        }

        DB::transaction(function () use ($updates): void {
            foreach ($updates as $row) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $row['id_rekaman_stok'])
                    ->update([
                        'waktu' => $row['waktu_baru'],
                    ]);
            }
        }, 3);

        $productIds = array_keys($affectedProducts);
        foreach ($productIds as $productId) {
            RekamanStok::recalculateStock((int) $productId);
        }

        $this->info('Apply selesai. Rekaman waktu diperbaiki: ' . count($updates));
        $this->info('Produk direcalculate ulang: ' . count($productIds));

        return 0;
    }

    private function resolveExpectedWaktu($row): ?string
    {
        if ($row->id_penjualan !== null) {
            return $this->normalizeDateTime($row->waktu_penjualan);
        }

        if ($row->id_pembelian !== null) {
            $expected = $this->normalizeDateTime($row->waktu_datang_pembelian);
            if ($expected !== null) {
                return $expected;
            }

            $expected = $this->normalizeDateTime($row->waktu_pembelian);
            if ($expected !== null) {
                return $expected;
            }

            return $this->normalizeDateTime($row->created_pembelian);
        }

        return null;
    }

    private function normalizeDateTime($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function isValidDateOnly(string $value): bool
    {
        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
            return $date !== false && $date->format('Y-m-d') === $value;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function writeReport(array $report): string
    {
        $pathOption = trim((string) $this->option('report'));
        $reportPath = $pathOption !== ''
            ? $pathOption
            : base_path('rekaman_waktu_repair_report_' . Carbon::now()->format('Ymd_His') . '.json');

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
            return base_path('rekaman_waktu_repair_report_' . Carbon::now()->format('Ymd_His') . '.json');
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
