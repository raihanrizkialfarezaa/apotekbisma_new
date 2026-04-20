<?php

namespace App\Services;

class ProductMappingSafetyService
{
    public function passesIdentityGuard(string $rawName, array $candidateAggressiveTokens): bool
    {
        $identityTokens = $this->extractIdentityTokens($rawName);
        $requiredVariants = $this->extractVariantTokens($rawName);
        if (empty($identityTokens)) {
            return $this->candidateMatchesRequiredVariants($requiredVariants, $candidateAggressiveTokens);
        }

        if (empty($candidateAggressiveTokens)) {
            return false;
        }

        $dominantToken = $identityTokens[0];
        if (!$this->candidateMatchesToken($dominantToken, $candidateAggressiveTokens)) {
            return false;
        }

        if (count($identityTokens) === 1) {
            return $this->candidateMatchesRequiredVariants($requiredVariants, $candidateAggressiveTokens);
        }

        $secondaryTokens = array_slice($identityTokens, 1, 2);
        $matchedSecondary = 0;

        foreach ($secondaryTokens as $token) {
            if ($this->candidateMatchesToken($token, $candidateAggressiveTokens)) {
                $matchedSecondary++;
            }
        }

        if (mb_strlen($dominantToken) < 6 && !empty($secondaryTokens) && $matchedSecondary === 0) {
            return false;
        }

        return $this->candidateMatchesRequiredVariants($requiredVariants, $candidateAggressiveTokens);
    }

    public function extractIdentityTokens(string $rawName): array
    {
        $normalized = $this->normalizeProductName($rawName);
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/\s+/u', $normalized) ?: [];
        $drop = [
            'mg', 'ml', 'gr', 'g', 'mcg', 'tab', 'tabs', 'kap', 'kapl', 'caps',
            'syr', 'susp', 'cr', 'gel', 'drop', 'drops', 'liq', 'box', 'strip', 'sach',
            'sachet', 'lbr', 'new', 'adult', 'anak', 'baby', 'small', 'plus', 'forte',
            'exp', 'od', 'dx', 'hj', 'kng', 'nova', 'hexp', 'gdn', 'ifi', 'no', 'liquid',
            'ori', 'original', 'all', 'var', 'extra', 'cool', 'mint', 'green', 'tea',
            'straw', 'orange', 'jeruk', 'madu', 'obh', 'gg', 'nr', 'noretur', 'retur',
            'btl', 'botol', 'fl', 'tube', 'cream', 'salep', 'oint', 'lotion', 'batuk',
            'flu', 'kids', 'kid',
        ];

        $tokens = [];
        foreach ($parts as $token) {
            $token = trim((string) $token);
            if ($token === '' || preg_match('/^[0-9]+$/', $token)) {
                continue;
            }

            $token = $this->normalizeAggressiveToken($token);
            if ($token === '' || mb_strlen($token) < 3) {
                continue;
            }

            if (in_array($token, $drop, true)) {
                continue;
            }

            $tokens[$token] = mb_strlen($token);
        }

        $identityTokens = array_keys($tokens);
        usort($identityTokens, function (string $left, string $right): int {
            $lengthCompare = mb_strlen($right) <=> mb_strlen($left);
            if ($lengthCompare !== 0) {
                return $lengthCompare;
            }

            return strcmp($left, $right);
        });

        return array_values(array_unique($identityTokens));
    }

    private function candidateMatchesToken(string $identityToken, array $candidateAggressiveTokens): bool
    {
        foreach ($candidateAggressiveTokens as $candidateToken) {
            $candidateToken = trim((string) $candidateToken);
            if ($candidateToken === '') {
                continue;
            }

            if ($candidateToken === $identityToken) {
                return true;
            }

            if (
                mb_strlen($candidateToken) >= 4
                && mb_strlen($identityToken) >= 4
                && (str_contains($candidateToken, $identityToken) || str_contains($identityToken, $candidateToken))
            ) {
                return true;
            }

            if (mb_strlen($candidateToken) < 4 || mb_strlen($identityToken) < 4) {
                continue;
            }

            similar_text($identityToken, $candidateToken, $similarity);
            if ((float) $similarity >= 78.0) {
                return true;
            }
        }

        return false;
    }

    private function candidateMatchesRequiredVariants(array $requiredVariants, array $candidateAggressiveTokens): bool
    {
        if (empty($requiredVariants)) {
            return true;
        }

        $candidateVariants = [];
        foreach ($candidateAggressiveTokens as $candidateToken) {
            $normalizedVariant = $this->normalizeVariantToken((string) $candidateToken);
            if ($normalizedVariant !== null) {
                $candidateVariants[$normalizedVariant] = true;
            }
        }

        foreach ($requiredVariants as $variant) {
            if (!isset($candidateVariants[$variant])) {
                return false;
            }
        }

        return true;
    }

    private function extractVariantTokens(string $rawName): array
    {
        $normalized = $this->normalizeProductName($rawName);
        if ($normalized === '') {
            return [];
        }

        $variants = [];
        foreach (preg_split('/\s+/u', $normalized) ?: [] as $token) {
            $variant = $this->normalizeVariantToken((string) $token);
            if ($variant !== null) {
                $variants[$variant] = true;
            }
        }

        return array_keys($variants);
    }

    private function normalizeVariantToken(string $token): ?string
    {
        $value = trim(mb_strtolower($token));
        if ($value === '') {
            return null;
        }

        return match ($value) {
            'dewasa', 'adult' => 'adult',
            'anak', 'kid', 'kids', 'child', 'children' => 'anak',
            'baby' => 'baby',
            'junior' => 'junior',
            default => null,
        };
    }

    private function normalizeProductName(string $value): string
    {
        $value = mb_strtolower($value);
        $value = str_replace(['&', "'", '"', '`'], ' ', $value);
        $value = preg_replace('/\([^\)]*\)/', ' ', $value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        return trim((string) $value);
    }

    private function normalizeAggressiveToken(string $token): string
    {
        $map = [
            'cetirizine' => 'cetirizin',
            'cetirizinehcl' => 'cetirizin',
            'amlodipine' => 'amlodipin',
            'amlodipin' => 'amlodipin',
            'amoxicillin' => 'amoxicilin',
            'amoxicilin' => 'amoxicilin',
            'chloride' => 'klorida',
            'sodium' => 'natrium',
            'dinitrate' => 'dinitrat',
            'diklo' => 'diclofenac',
            'diclo' => 'diclofenac',
            'nadiklo' => 'natriumdiclofenac',
            'na' => 'natrium',
            'stpsl' => 'strepsil',
            'strepsils' => 'strepsil',
            'histigo' => 'hystigo',
            'jrg' => 'junior',
            'sirup' => 'syr',
            'onemed' => 'onemed',
            'kehamilan' => 'testpack',
            'krim' => 'cream',
            'bals' => 'balm',
            'guaifenesin' => 'guafinesin',
            'guaifinesin' => 'guafinesin',
            'guaifnesin' => 'guafinesin',
        ];

        if (isset($map[$token])) {
            $token = (string) $map[$token];
        }

        $token = str_replace(' ', '', $token);
        $token = preg_replace('/[^a-z0-9]+/u', '', $token);

        return trim((string) $token);
    }
}