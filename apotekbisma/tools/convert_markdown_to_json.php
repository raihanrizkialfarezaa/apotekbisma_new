<?php

declare(strict_types=1);

/**
 * Convert markdown files containing fenced JSON blocks into pure JSON.
 *
 * Usage:
 *   php tools/convert_markdown_to_json.php <input.md> <output.json> [--mode=pages|merged]
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/convert_markdown_to_json.php <input.md> <output.json> [--mode=pages|merged]" . PHP_EOL);
    exit(1);
}

$inputPath = (string) $argv[1];
$outputPath = (string) $argv[2];
$mode = 'pages';

foreach (array_slice($argv, 3) as $arg) {
    if (str_starts_with($arg, '--mode=')) {
        $mode = strtolower(trim(substr($arg, 7)));
    }
}

if (!in_array($mode, ['pages', 'merged'], true)) {
    fwrite(STDERR, "Invalid mode: {$mode}. Use --mode=pages or --mode=merged" . PHP_EOL);
    exit(1);
}

if (!is_file($inputPath) || !is_readable($inputPath)) {
    fwrite(STDERR, "Input file not found or unreadable: {$inputPath}" . PHP_EOL);
    exit(1);
}

$raw = file_get_contents($inputPath);
if ($raw === false) {
    fwrite(STDERR, "Failed to read input file: {$inputPath}" . PHP_EOL);
    exit(1);
}

$blocks = extractJsonCodeBlocks($raw);
if ($blocks === []) {
    $blocks = [$raw];
}

$entries = [];
foreach ($blocks as $index => $block) {
    $segments = splitConcatenatedJsonValues($block);
    if ($segments === []) {
        continue;
    }

    foreach ($segments as $segmentIndex => $segment) {
        $sanitized = stripJsonComments($segment);
        $decoded = json_decode($sanitized, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $message = json_last_error_msg();
            fwrite(
                STDERR,
                "Invalid JSON at block " . ($index + 1) . ", segment " . ($segmentIndex + 1) . ": {$message}" . PHP_EOL
            );
            exit(1);
        }

        foreach (normalizeTopLevelPayload($decoded) as $item) {
            $entries[] = $item;
        }
    }
}

if ($entries === []) {
    fwrite(STDERR, "No JSON payload found in markdown file: {$inputPath}" . PHP_EOL);
    exit(1);
}

$outputData = $mode === 'merged'
    ? buildMergedPayload($entries, basename($inputPath))
    : $entries;

$json = json_encode($outputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "Failed to encode JSON output." . PHP_EOL);
    exit(1);
}

if (file_put_contents($outputPath, $json . PHP_EOL) === false) {
    fwrite(STDERR, "Failed to write output file: {$outputPath}" . PHP_EOL);
    exit(1);
}

$purchaseCount = countMergedPurchases($entries);
fwrite(
    STDOUT,
    "Success: {$inputPath} -> {$outputPath} | entries=" . count($entries) . " | purchases={$purchaseCount} | mode={$mode}" . PHP_EOL
);

function extractJsonCodeBlocks(string $markdown): array
{
    preg_match_all('/```json\\s*(.*?)```/is', $markdown, $matches);

    $blocks = [];
    foreach ($matches[1] ?? [] as $block) {
        $trimmed = trim((string) $block);
        if ($trimmed !== '') {
            $blocks[] = $trimmed;
        }
    }

    return $blocks;
}

function splitConcatenatedJsonValues(string $text): array
{
    $segments = [];
    $length = strlen($text);
    $i = 0;

    while ($i < $length) {
        while ($i < $length && isIgnorableSeparator($text[$i])) {
            $i++;
        }

        if ($i >= $length) {
            break;
        }

        $start = $i;
        $first = $text[$i];

        if ($first !== '{' && $first !== '[') {
            throw new RuntimeException('Unexpected character while parsing JSON stream at offset ' . $i . ': ' . $first);
        }

        $depth = 0;
        $inString = false;
        $escaped = false;

        for (; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '{' || $char === '[') {
                $depth++;
                continue;
            }

            if ($char === '}' || $char === ']') {
                $depth--;

                if ($depth < 0) {
                    throw new RuntimeException('Unbalanced JSON structure near offset ' . $i);
                }

                if ($depth === 0) {
                    $segments[] = substr($text, $start, $i - $start + 1);
                    $i++;
                    break;
                }
            }
        }

        if ($depth !== 0) {
            throw new RuntimeException('Unbalanced JSON structure at end of input stream.');
        }
    }

    return $segments;
}

function normalizeTopLevelPayload(mixed $decoded): array
{
    if (!is_array($decoded)) {
        throw new RuntimeException('Top-level JSON value must be object or array.');
    }

    if (isAssocArray($decoded)) {
        return [$decoded];
    }

    $result = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) {
            throw new RuntimeException('Array payload must contain only object elements.');
        }

        $result[] = $item;
    }

    return $result;
}

function buildMergedPayload(array $entries, string $sourceFile): array
{
    $first = $entries[0] ?? [];
    $allPurchases = [];

    foreach ($entries as $entry) {
        $purchases = $entry['purchases'] ?? [];
        if (is_array($purchases)) {
            foreach ($purchases as $purchase) {
                if (is_array($purchase)) {
                    $allPurchases[] = $purchase;
                }
            }
        }
    }

    return [
        'schema_version' => (string) ($first['schema_version'] ?? '2.0'),
        'source' => 'markdown-json-converted:' . $sourceFile,
        'generated_at' => date('Y-m-d H:i:s'),
        'ppn_persen' => (float) ($first['ppn_persen'] ?? 0),
        'purchases' => $allPurchases,
    ];
}

function countMergedPurchases(array $entries): int
{
    $count = 0;

    foreach ($entries as $entry) {
        if (!isset($entry['purchases']) || !is_array($entry['purchases'])) {
            continue;
        }

        $count += count($entry['purchases']);
    }

    return $count;
}

function isAssocArray(array $array): bool
{
    if ($array === []) {
        return false;
    }

    return array_keys($array) !== range(0, count($array) - 1);
}

function isIgnorableSeparator(string $char): bool
{
    return $char === "\n"
        || $char === "\r"
        || $char === "\t"
        || $char === ' '
        || $char === ','
        || $char === ';';
}

function stripJsonComments(string $json): string
{
    $result = '';
    $length = strlen($json);
    $inString = false;
    $escaped = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $json[$i];
        $next = $i + 1 < $length ? $json[$i + 1] : '';

        if ($inString) {
            $result .= $char;

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if ($char === '"') {
                $inString = false;
            }

            continue;
        }

        if ($char === '"') {
            $inString = true;
            $result .= $char;
            continue;
        }

        if ($char === '/' && $next === '/') {
            $i += 2;
            while ($i < $length && $json[$i] !== "\n" && $json[$i] !== "\r") {
                $i++;
            }

            if ($i < $length) {
                $result .= $json[$i];
            }

            continue;
        }

        if ($char === '/' && $next === '*') {
            $i += 2;
            while ($i + 1 < $length && !($json[$i] === '*' && $json[$i + 1] === '/')) {
                $i++;
            }

            $i++;
            continue;
        }

        $result .= $char;
    }

    return $result;
}
