<?php

namespace App\Services;

class PurchaseSourceOverrideService
{
    public function applyDetailOverride(string $invoiceNo, array $detail): array
    {
        $invoiceOverrides = config('stock.purchase_source_overrides.' . $this->normalizeInvoiceNo($invoiceNo), []);
        if (empty($invoiceOverrides)) {
            return $detail;
        }

        $productId = intval($detail['id_produk'] ?? 0);
        $rawName = trim((string) ($detail['nama_produk'] ?? ''));

        foreach ($invoiceOverrides as $override) {
            if (!$this->matchesOverride($override, $productId, $rawName)) {
                continue;
            }

            foreach (['id_produk', 'nama_produk', 'jumlah', 'harga_beli', 'subtotal'] as $field) {
                if (array_key_exists($field, $override)) {
                    $detail[$field] = $override[$field];
                }
            }

            if (!empty($override['reason'])) {
                $detail['_source_override_reason'] = (string) $override['reason'];
            }

            return $detail;
        }

        return $detail;
    }

    private function matchesOverride(array $override, int $productId, string $rawName): bool
    {
        $overrideProductId = intval($override['product_id'] ?? 0);
        if ($overrideProductId > 0 && $overrideProductId !== $productId) {
            return false;
        }

        $overrideRawName = trim((string) ($override['raw_name'] ?? ''));
        if ($overrideRawName !== '' && $this->normalizeProductName($overrideRawName) !== $this->normalizeProductName($rawName)) {
            return false;
        }

        return true;
    }

    private function normalizeInvoiceNo(string $invoiceNo): string
    {
        return mb_strtoupper(trim($invoiceNo));
    }

    private function normalizeProductName(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value);

        return trim((string) $value);
    }
}