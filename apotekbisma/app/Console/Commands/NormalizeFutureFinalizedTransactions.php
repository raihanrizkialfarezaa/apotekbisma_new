<?php

namespace App\Console\Commands;

use App\Models\Pembelian;
use App\Models\Penjualan;
use App\Services\TransactionDateMutationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeFutureFinalizedTransactions extends Command
{
    protected $signature = 'transactions:normalize-future-finalized
                            {--apply : Terapkan perubahan ke database}
                            {--tolerance-minutes= : Toleransi menit ke masa depan, default config stock.max_future_transaction_minutes}
                            {--report= : Path report JSON output}';

    protected $description = 'Normalisasi transaksi final yang bertanggal di masa depan agar urutan stok kembali stabil';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $toleranceMinutes = max(0, (int) ($this->option('tolerance-minutes') ?: config('stock.max_future_transaction_minutes', 5)));
        $wallNow = Carbon::now();
        $threshold = $wallNow->copy()->addMinutes($toleranceMinutes);

        $futureTransactions = $this->collectFutureTransactions($threshold);
        $plan = $this->buildNormalizationPlan($futureTransactions, $wallNow, $threshold);

        $report = [
            'generated_at' => $wallNow->format('Y-m-d H:i:s'),
            'mode' => $apply ? 'APPLY' : 'DRY_RUN',
            'threshold' => $threshold->format('Y-m-d H:i:s'),
            'summary' => [
                'future_transactions' => count($futureTransactions),
                'future_penjualan' => count(array_filter($futureTransactions, function (array $row) {
                    return $row['type'] === 'penjualan';
                })),
                'future_pembelian' => count(array_filter($futureTransactions, function (array $row) {
                    return $row['type'] === 'pembelian';
                })),
                'strategy' => $plan['strategy'],
                'target_start' => $plan['target_start'],
                'target_end' => $plan['target_end'],
            ],
            'preview' => $plan['transactions'],
        ];

        $reportPath = $this->writeReport($report);

        $this->info('=== NORMALISASI TRANSAKSI FINAL MASA DEPAN ===');
        $this->line('Mode      : ' . ($apply ? 'APPLY' : 'DRY-RUN'));
        $this->line('Threshold : ' . $threshold->format('Y-m-d H:i:s'));
        $this->line('Report    : ' . $reportPath);
        $this->table(
            ['Future Tx', 'Future Sales', 'Future Purchases', 'Strategy'],
            [[count($futureTransactions), $report['summary']['future_penjualan'], $report['summary']['future_pembelian'], $plan['strategy']]]
        );

        if (!$apply) {
            $this->info('Dry-run selesai. Jalankan ulang dengan --apply untuk menerapkan normalisasi.');
            return 0;
        }

        if (empty($futureTransactions)) {
            $this->warn('Tidak ada transaksi final masa depan yang perlu dinormalisasi.');
            return 0;
        }

        DB::transaction(function () use ($plan): void {
            $mutationService = app(TransactionDateMutationService::class);

            foreach ($plan['transactions'] as $row) {
                $newWaktu = $row['new_waktu'];

                if ($row['type'] === 'penjualan') {
                    $penjualan = Penjualan::query()->lockForUpdate()->findOrFail($row['id']);
                    $oldWaktu = Carbon::parse($penjualan->waktu ?? $penjualan->created_at)->format('Y-m-d H:i:s');
                    $penjualan->waktu = $newWaktu;
                    $penjualan->save();

                    $mutationService->handlePenjualanFinalDateChange($penjualan, $oldWaktu, $newWaktu);
                    continue;
                }

                $pembelian = Pembelian::query()->lockForUpdate()->findOrFail($row['id']);
                $oldWaktu = Carbon::parse($pembelian->waktu_datang ?? $pembelian->waktu ?? $pembelian->created_at)->format('Y-m-d H:i:s');
                $pembelian->waktu = $newWaktu;
                $pembelian->waktu_datang = $newWaktu;
                $pembelian->save();

                $mutationService->handlePembelianFinalDateChange($pembelian, $oldWaktu, $newWaktu);
            }
        }, 3);

        $this->info('Normalisasi transaksi final masa depan selesai: ' . count($plan['transactions']) . ' transaksi.');

        return 0;
    }

    private function collectFutureTransactions(Carbon $threshold): array
    {
        $thresholdString = $threshold->format('Y-m-d H:i:s');

        $futurePenjualan = DB::table('penjualan')
            ->selectRaw("'penjualan' as type, id_penjualan as id, CONCAT('penjualan#', id_penjualan) as reference, COALESCE(waktu, created_at) as old_waktu, TIMESTAMPDIFF(MINUTE, ?, COALESCE(waktu, created_at)) as minutes_ahead", [$thresholdString])
            ->where('total_item', '>', 0)
            ->where('total_harga', '>', 0)
            ->where('bayar', '>', 0)
            ->where('diterima', '>', 0)
            ->whereRaw('COALESCE(waktu, created_at) > ?', [$thresholdString])
            ->orderByRaw('COALESCE(waktu, created_at) asc')
            ->get()
            ->map(function ($row) {
                return (array) $row;
            })
            ->all();

        $futurePembelian = DB::table('pembelian')
            ->selectRaw("'pembelian' as type, id_pembelian as id, COALESCE(no_faktur, CONCAT('pembelian#', id_pembelian)) as reference, COALESCE(waktu_datang, waktu, created_at) as old_waktu, TIMESTAMPDIFF(MINUTE, ?, COALESCE(waktu_datang, waktu, created_at)) as minutes_ahead", [$thresholdString])
            ->whereNotNull('no_faktur')
            ->whereRaw('TRIM(no_faktur) != ?', [''])
            ->whereRaw('LOWER(TRIM(no_faktur)) != ?', ['o'])
            ->where('total_harga', '>', 0)
            ->where('bayar', '>', 0)
            ->whereRaw('COALESCE(waktu_datang, waktu, created_at) > ?', [$thresholdString])
            ->orderByRaw('COALESCE(waktu_datang, waktu, created_at) asc')
            ->get()
            ->map(function ($row) {
                return (array) $row;
            })
            ->all();

        $transactions = array_merge($futurePenjualan, $futurePembelian);

        usort($transactions, function (array $left, array $right) {
            return strcmp((string) $left['old_waktu'], (string) $right['old_waktu']);
        });

        return $transactions;
    }

    private function buildNormalizationPlan(array $futureTransactions, Carbon $wallNow, Carbon $threshold): array
    {
        if (empty($futureTransactions)) {
            return [
                'strategy' => 'none',
                'target_start' => null,
                'target_end' => null,
                'transactions' => [],
            ];
        }

        $count = count($futureTransactions);
        $latestSafe = $this->resolveLatestSafeTimestamp($threshold);
        $targetStart = $latestSafe
            ? $latestSafe->copy()->addSecond()
            : $wallNow->copy()->subSeconds(max(0, $count - 1));
        $targetEnd = $targetStart->copy()->addSeconds(max(0, $count - 1));
        $strategy = 'after_latest_safe';

        if ($targetEnd->greaterThan($wallNow)) {
            $targetStart = $wallNow->copy()->subSeconds(max(0, $count - 1));
            $targetEnd = $wallNow->copy();
            $strategy = 'packed_before_now';
        }

        $transactions = [];
        foreach (array_values($futureTransactions) as $index => $row) {
            $newWaktu = $targetStart->copy()->addSeconds($index)->format('Y-m-d H:i:s');
            $row['new_waktu'] = $newWaktu;
            $transactions[] = $row;
        }

        return [
            'strategy' => $strategy,
            'target_start' => $targetStart->format('Y-m-d H:i:s'),
            'target_end' => $targetEnd->format('Y-m-d H:i:s'),
            'transactions' => $transactions,
        ];
    }

    private function resolveLatestSafeTimestamp(Carbon $threshold): ?Carbon
    {
        $thresholdString = $threshold->format('Y-m-d H:i:s');
        $candidates = [];

        $latestPenjualan = DB::table('penjualan')
            ->selectRaw('MAX(COALESCE(waktu, created_at)) as latest_waktu')
            ->where('total_item', '>', 0)
            ->where('total_harga', '>', 0)
            ->where('bayar', '>', 0)
            ->where('diterima', '>', 0)
            ->whereRaw('COALESCE(waktu, created_at) <= ?', [$thresholdString])
            ->value('latest_waktu');
        if ($latestPenjualan) {
            $candidates[] = Carbon::parse($latestPenjualan);
        }

        $latestPembelian = DB::table('pembelian')
            ->selectRaw('MAX(COALESCE(waktu_datang, waktu, created_at)) as latest_waktu')
            ->whereNotNull('no_faktur')
            ->whereRaw('TRIM(no_faktur) != ?', [''])
            ->whereRaw('LOWER(TRIM(no_faktur)) != ?', ['o'])
            ->where('total_harga', '>', 0)
            ->where('bayar', '>', 0)
            ->whereRaw('COALESCE(waktu_datang, waktu, created_at) <= ?', [$thresholdString])
            ->value('latest_waktu');
        if ($latestPembelian) {
            $candidates[] = Carbon::parse($latestPembelian);
        }

        $latestManualAdjustment = DB::table('rekaman_stoks')
            ->selectRaw('MAX(waktu) as latest_waktu')
            ->whereNull('id_penjualan')
            ->whereNull('id_pembelian')
            ->where('waktu', '<=', $thresholdString)
            ->value('latest_waktu');
        if ($latestManualAdjustment) {
            $candidates[] = Carbon::parse($latestManualAdjustment);
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function (Carbon $left, Carbon $right) {
            if ($left->equalTo($right)) {
                return 0;
            }

            return $left->lessThan($right) ? -1 : 1;
        });

        return end($candidates)->copy();
    }

    private function writeReport(array $report): string
    {
        $pathOption = trim((string) $this->option('report'));
        $reportPath = $pathOption !== ''
            ? $pathOption
            : base_path('future_transaction_normalize_report_' . Carbon::now()->format('Ymd_His') . '.json');

        $outputPath = $this->resolveOutputPath($reportPath);
        $directory = dirname($outputPath);
        if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

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
            return base_path('future_transaction_normalize_report_' . Carbon::now()->format('Ymd_His') . '.json');
        }

        if (
            preg_match('/^[A-Za-z]:\\\\|^\\\\\\\\/', $value) === 1
            || strpos($value, '/') === 0
        ) {
            return $value;
        }

        return base_path($value);
    }
}