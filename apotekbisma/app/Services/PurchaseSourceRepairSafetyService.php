<?php

namespace App\Services;

class PurchaseSourceRepairSafetyService
{
    public function assessMismatch(string $rawName, int $sourceQty, int $dbQty, bool $overrideApplied = false): array
    {
        if ($sourceQty === $dbQty) {
            return [
                'auto_repair_safe' => true,
                'reason' => 'quantities_match',
                'pack_count_tokens' => $this->extractPackCountTokens($rawName),
            ];
        }

        if ($overrideApplied) {
            return [
                'auto_repair_safe' => true,
                'reason' => 'explicit_source_override',
                'pack_count_tokens' => $this->extractPackCountTokens($rawName),
            ];
        }

        $packCountTokens = $this->extractPackCountTokens($rawName);
        $hasComplexPackPattern = $this->hasComplexPackPattern($rawName);

        if ($hasComplexPackPattern) {
            return [
                'auto_repair_safe' => false,
                'reason' => 'complex_pack_pattern_requires_manual_review',
                'pack_count_tokens' => $packCountTokens,
            ];
        }

        $largerQty = max($sourceQty, $dbQty);
        $smallerQty = min($sourceQty, $dbQty);
        $ratio = null;

        if ($smallerQty > 0 && $largerQty % $smallerQty === 0) {
            $ratio = (int) ($largerQty / $smallerQty);
        }

        if (!empty($packCountTokens) && $ratio !== null && in_array((string) $ratio, $packCountTokens, true)) {
            return [
                'auto_repair_safe' => false,
                'reason' => 'quantity_ratio_matches_pack_count_and_requires_manual_review',
                'pack_count_tokens' => $packCountTokens,
            ];
        }

        if (!empty($packCountTokens) && $largerQty >= max(2, ($smallerQty * 2))) {
            return [
                'auto_repair_safe' => false,
                'reason' => 'packed_product_quantity_mismatch_requires_manual_review',
                'pack_count_tokens' => $packCountTokens,
            ];
        }

        if ($ratio !== null && $ratio >= 10) {
            return [
                'auto_repair_safe' => false,
                'reason' => 'extreme_quantity_ratio_requires_manual_review',
                'pack_count_tokens' => $packCountTokens,
            ];
        }

        return [
            'auto_repair_safe' => true,
            'reason' => 'simple_quantity_delta',
            'pack_count_tokens' => $packCountTokens,
        ];
    }

    public function extractPackCountTokens(string $rawName): array
    {
        $text = mb_strtolower($rawName);
        $tokens = [];

        preg_match_all('/\b([0-9]{1,4})\s*\'?s\b/u', $text, $apostropheMatches);
        foreach (($apostropheMatches[1] ?? []) as $packCount) {
            $tokens[] = (string) ((int) $packCount);
        }

        preg_match_all('/\b([0-9]{1,4})\s*(?:tab|tabs|tablet|kap|kapl|kaplet|caps|cap|pcs|pc|strip|sach|sachet|supp|suppo)\b/u', $text, $unitMatches);
        foreach (($unitMatches[1] ?? []) as $packCount) {
            $tokens[] = (string) ((int) $packCount);
        }

        preg_match_all('/\b(?:bx|box|dus|pak|pack|strip|fl|btl)\s*\/\s*([0-9]{1,4})\b/u', $text, $slashMatches);
        foreach (($slashMatches[1] ?? []) as $packCount) {
            $tokens[] = (string) ((int) $packCount);
        }

        preg_match_all('/\b([0-9]{1,4})\s*x\s*([0-9]{1,4})\b/u', $text, $crossMatches, PREG_SET_ORDER);
        foreach ($crossMatches as $match) {
            $tokens[] = (string) ((int) ($match[1] ?? 0));
            $tokens[] = (string) ((int) ($match[2] ?? 0));
        }

        $tokens = array_values(array_unique(array_filter($tokens, function ($item) {
            return $item !== '0' && $item !== '';
        })));
        sort($tokens);

        return $tokens;
    }

    private function hasComplexPackPattern(string $rawName): bool
    {
        return preg_match('/\b[0-9]{1,4}\s*x\s*[0-9]{1,4}\b/u', mb_strtolower($rawName)) === 1;
    }
}