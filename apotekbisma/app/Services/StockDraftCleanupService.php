<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockDraftCleanupService
{
    public function cleanupStalePembelianDrafts(?int $excludeId = null): array
    {
        $threshold = now()->subMinutes((int) config('stock.stale_draft_minutes', 30));
        $cutoff = config('stock.cutoff_datetime', '2025-12-31 23:59:59');
        $summary = [
            'checked' => 0,
            'deleted_headers' => 0,
            'deleted_detail_rows' => 0,
            'reverted_stock_qty' => 0,
            'skipped_headers' => 0,
        ];

        $draftIds = DB::table('pembelian')
            ->select('id_pembelian')
            ->where('created_at', '<', $threshold)
            ->whereRaw('COALESCE(waktu, created_at) > ?', [$cutoff])
            ->where(function ($query) {
                $query->where('no_faktur', 'o')
                    ->orWhere('no_faktur', '')
                    ->orWhereNull('no_faktur')
                    ->orWhere('total_harga', '<=', 0)
                    ->orWhere('bayar', '<=', 0);
            })
            ->when($excludeId, function ($query) use ($excludeId) {
                $query->where('id_pembelian', '!=', $excludeId);
            })
            ->orderBy('id_pembelian')
            ->pluck('id_pembelian');

        foreach ($draftIds as $draftId) {
            $summary['checked']++;

            DB::transaction(function () use ($draftId, &$summary) {
                $draft = DB::table('pembelian')
                    ->where('id_pembelian', $draftId)
                    ->lockForUpdate()
                    ->first();

                if (!$draft || !$this->isIncompletePembelian($draft)) {
                    return;
                }

                $details = DB::table('pembelian_detail')
                    ->where('id_pembelian', $draftId)
                    ->lockForUpdate()
                    ->get();

                $groupedDetails = [];
                foreach ($details as $detail) {
                    $qty = max(0, intval($detail->jumlah ?? 0));
                    if ($qty === 0) {
                        continue;
                    }

                    $productId = intval($detail->id_produk);
                    $groupedDetails[$productId] = ($groupedDetails[$productId] ?? 0) + $qty;
                }

                foreach ($groupedDetails as $productId => $qty) {
                    $produk = DB::table('produk')
                        ->where('id_produk', $productId)
                        ->lockForUpdate()
                        ->first();

                    if (!$produk || intval($produk->stok) < $qty) {
                        $summary['skipped_headers']++;
                        Log::warning('Skip cleanup draft pembelian karena stok tidak cukup untuk rollback aman.', [
                            'id_pembelian' => $draftId,
                            'id_produk' => $productId,
                            'stok_saat_ini' => intval($produk->stok ?? 0),
                            'qty_rollback' => $qty,
                        ]);
                        return;
                    }
                }

                foreach ($groupedDetails as $productId => $qty) {
                    $produk = DB::table('produk')
                        ->where('id_produk', $productId)
                        ->lockForUpdate()
                        ->first();

                    DB::table('produk')
                        ->where('id_produk', $productId)
                        ->update([
                            'stok' => intval($produk->stok) - $qty,
                            'updated_at' => now(),
                        ]);

                    $summary['reverted_stock_qty'] += $qty;
                }

                DB::table('rekaman_stoks')->where('id_pembelian', $draftId)->delete();
                $summary['deleted_detail_rows'] += DB::table('pembelian_detail')->where('id_pembelian', $draftId)->delete();
                DB::table('pembelian')->where('id_pembelian', $draftId)->delete();
                $summary['deleted_headers']++;
            }, 3);
        }

        return $summary;
    }

    public function cleanupStalePenjualanDrafts(?int $excludeId = null): array
    {
        $threshold = now()->subMinutes((int) config('stock.stale_draft_minutes', 30));
        $cutoff = config('stock.cutoff_datetime', '2025-12-31 23:59:59');
        $summary = [
            'checked' => 0,
            'deleted_headers' => 0,
            'deleted_detail_rows' => 0,
            'restored_stock_qty' => 0,
        ];

        $draftIds = DB::table('penjualan')
            ->select('id_penjualan')
            ->where('created_at', '<', $threshold)
            ->whereRaw('COALESCE(waktu, created_at) > ?', [$cutoff])
            ->where(function ($query) {
                $query->where('total_item', '<=', 0)
                    ->orWhere('total_harga', '<=', 0)
                    ->orWhere('bayar', '<=', 0)
                    ->orWhere('diterima', '<=', 0);
            })
            ->when($excludeId, function ($query) use ($excludeId) {
                $query->where('id_penjualan', '!=', $excludeId);
            })
            ->orderBy('id_penjualan')
            ->pluck('id_penjualan');

        foreach ($draftIds as $draftId) {
            $summary['checked']++;

            DB::transaction(function () use ($draftId, &$summary) {
                $draft = DB::table('penjualan')
                    ->where('id_penjualan', $draftId)
                    ->lockForUpdate()
                    ->first();

                if (!$draft || !$this->isIncompletePenjualan($draft)) {
                    return;
                }

                $details = DB::table('penjualan_detail')
                    ->where('id_penjualan', $draftId)
                    ->lockForUpdate()
                    ->get();

                $groupedDetails = [];
                foreach ($details as $detail) {
                    $qty = max(0, intval($detail->jumlah ?? 0));
                    if ($qty === 0) {
                        continue;
                    }

                    $productId = intval($detail->id_produk);
                    $groupedDetails[$productId] = ($groupedDetails[$productId] ?? 0) + $qty;
                }

                foreach ($groupedDetails as $productId => $qty) {
                    $produk = DB::table('produk')
                        ->where('id_produk', $productId)
                        ->lockForUpdate()
                        ->first();

                    if (!$produk) {
                        continue;
                    }

                    DB::table('produk')
                        ->where('id_produk', $productId)
                        ->update([
                            'stok' => intval($produk->stok) + $qty,
                            'updated_at' => now(),
                        ]);

                    $summary['restored_stock_qty'] += $qty;
                }

                DB::table('rekaman_stoks')->where('id_penjualan', $draftId)->delete();
                $summary['deleted_detail_rows'] += DB::table('penjualan_detail')->where('id_penjualan', $draftId)->delete();
                DB::table('penjualan')->where('id_penjualan', $draftId)->delete();
                $summary['deleted_headers']++;
            }, 3);
        }

        return $summary;
    }

    private function isIncompletePembelian($draft): bool
    {
        return in_array($draft->no_faktur, ['o', ''], true)
            || $draft->no_faktur === null
            || intval($draft->total_harga ?? 0) <= 0
            || intval($draft->bayar ?? 0) <= 0;
    }

    private function isIncompletePenjualan($draft): bool
    {
        return intval($draft->total_item ?? 0) <= 0
            || intval($draft->total_harga ?? 0) <= 0
            || intval($draft->bayar ?? 0) <= 0
            || intval($draft->diterima ?? 0) <= 0;
    }
}