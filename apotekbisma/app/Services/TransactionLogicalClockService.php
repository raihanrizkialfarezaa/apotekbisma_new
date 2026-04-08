<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TransactionLogicalClockService
{
    private ?Carbon $cachedNow = null;

    public function now(): Carbon
    {
        if ($this->cachedNow !== null) {
            return $this->cachedNow->copy();
        }

        $wallNow = Carbon::now();
        $latestKnown = $this->resolveLatestKnownTimestamp();

        $this->cachedNow = $latestKnown !== null && $latestKnown->greaterThan($wallNow)
            ? $latestKnown
            : $wallNow;

        return $this->cachedNow->copy();
    }

    public function formatForDateTimeLocal(): string
    {
        return $this->now()->format('Y-m-d\TH:i:s');
    }

    private function resolveLatestKnownTimestamp(): ?Carbon
    {
        $cutoff = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');
        $candidates = [];

        $latestPenjualan = DB::table('penjualan')
            ->selectRaw('MAX(COALESCE(waktu, created_at)) as latest_waktu')
            ->whereRaw('COALESCE(waktu, created_at) > ?', [$cutoff])
            ->where('total_item', '>', 0)
            ->where('total_harga', '>', 0)
            ->where('bayar', '>', 0)
            ->where('diterima', '>', 0)
            ->value('latest_waktu');
        if ($latestPenjualan) {
            $candidates[] = Carbon::parse($latestPenjualan);
        }

        $latestPembelian = DB::table('pembelian')
            ->selectRaw('MAX(COALESCE(waktu_datang, waktu, created_at)) as latest_waktu')
            ->whereRaw('COALESCE(waktu_datang, waktu, created_at) > ?', [$cutoff])
            ->whereNotNull('no_faktur')
            ->whereRaw('TRIM(no_faktur) != ?', [''])
            ->whereRaw('LOWER(TRIM(no_faktur)) != ?', ['o'])
            ->where('total_harga', '>', 0)
            ->where('bayar', '>', 0)
            ->value('latest_waktu');
        if ($latestPembelian) {
            $candidates[] = Carbon::parse($latestPembelian);
        }

        $latestManualAdjustment = DB::table('rekaman_stoks')
            ->selectRaw('MAX(waktu) as latest_waktu')
            ->whereNull('id_penjualan')
            ->whereNull('id_pembelian')
            ->where('waktu', '>', $cutoff)
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
}