<?php

namespace App\Console\Commands;

use App\Services\BaselineStockReflowService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ImportPostCutoffPurchasesFromMarkdown extends Command
{
    protected $signature = 'stock:import-post-cutoff-purchases
                            {--file=* : Path file markdown faktur (bisa lebih dari satu)}
                            {--file-dir= : Direktori pencarian file markdown saat --file tidak diisi (default: root project)}
                            {--file-glob=INPUT_FAKTUR_PEMBELIAN*.md : Pattern glob file markdown saat --file tidak diisi (pisahkan dengan koma jika lebih dari satu)}
                            {--alias= : Path JSON alias nama produk markdown ke id_produk atau nama produk DB}
                            {--alias-template= : Path output template JSON untuk alias produk unresolved}
                            {--alias-suggestions= : Path output JSON kandidat alias produk unresolved}
                            {--alias-autofill : Isi alias-template dengan kandidat ber-confidence tinggi}
                            {--apply : Terapkan insert ke database}
                            {--allow-partial : Tetap apply walau ada baris gagal mapping}
                            {--cutoff= : Cutoff baseline datetime}
                            {--from= : Batas awal tanggal faktur}
                            {--until= : Batas akhir tanggal faktur}
                            {--report= : Path report JSON output}
                            {--source=markdown-reinput-admin : Label sumber import untuk audit}';

    protected $description = 'Import ulang pembelian pasca-cutoff dari file markdown tervalidasi admin, lalu reflow stok produk terdampak';

    private array $supplierIndex = [];
    private array $supplierRows = [];
    private array $productIndex = [];
    private array $productTokenIndex = [];
    private array $productById = [];
    private array $productAliasMap = [];
    private array $productRows = [];

    public function handle(BaselineStockReflowService $reflowService): int
    {
        $startedAt = microtime(true);

        if (!Schema::hasColumn('pembelian', 'no_faktur')) {
            $this->error('Kolom pembelian.no_faktur tidak ditemukan. Import dibatalkan demi keamanan.');
            return 1;
        }

        $filePaths = $this->resolveMarkdownPaths();
        if (empty($filePaths)) {
            $this->error('Tidak ada file markdown yang valid.');
            return 1;
        }

        $cutoff = (string) ($this->option('cutoff') ?: config('stock.cutoff_datetime', '2025-12-31 23:59:59'));
        $until = (string) ($this->option('until') ?: Carbon::now()->format('Y-m-d H:i:s'));
        $from = (string) ($this->option('from') ?: Carbon::parse($cutoff)->addSecond()->format('Y-m-d H:i:s'));

        $apply = (bool) $this->option('apply');
        $allowPartial = (bool) $this->option('allow-partial');
        $sourceLabel = trim((string) $this->option('source')) !== ''
            ? trim((string) $this->option('source'))
            : 'markdown-reinput-admin';

        $this->line('=== IMPORT PEMBELIAN PASCA-CUTOFF (MARKDOWN) ===');
        $this->line('Mode          : ' . ($apply ? 'APPLY' : 'DRY-RUN'));
        $this->line('Allow partial : ' . ($allowPartial ? 'YES' : 'NO'));
        $this->line('Cutoff        : ' . $cutoff);
        $this->line('From          : ' . $from);
        $this->line('Until         : ' . $until);
        $this->line('Source label  : ' . $sourceLabel);
        $this->line('Files         : ' . count($filePaths));

        try {
            $this->buildSupplierIndex();
            $this->buildProductIndex();
            $this->loadProductAliases((string) $this->option('alias'));
        } catch (\Throwable $e) {
            $this->error('Gagal memuat index supplier/produk: ' . $e->getMessage());
            return 1;
        }

        $parsed = $this->parseMarkdownFiles($filePaths);

        $aggregation = $this->aggregateInvoices($parsed['sections']);
        $invoiceRows = $aggregation['invoices'];
        $aggregateIssues = $aggregation['issues'];

        $dateFiltered = $this->filterInvoicesByDateRange($invoiceRows, $from, $until, $cutoff);
        $invoiceRows = $dateFiltered['rows'];
        $dateIssues = $dateFiltered['issues'];

        $mapping = $this->mapSuppliersAndProducts($invoiceRows);
        $preparedInvoices = $mapping['invoices'];
        $mappingIssues = $mapping['issues'];

        $existingCheck = $this->resolveExistingInvoices($preparedInvoices);
        $insertableInvoices = $existingCheck['insertable'];
        $existingAsSame = $existingCheck['already_same'];
        $existingConflicts = $existingCheck['conflicts'];

        $issues = array_merge(
            $parsed['issues'],
            $aggregateIssues,
            $dateIssues,
            $mappingIssues,
            $existingConflicts
        );

        $unresolvedProductNames = collect($issues)
            ->filter(function ($item) {
                return is_array($item)
                    && (($item['type'] ?? null) === 'product_mapping_failed')
                    && trim((string) ($item['nama_produk_raw'] ?? '')) !== '';
            })
            ->map(function ($item) {
                return trim((string) $item['nama_produk_raw']);
            })
            ->unique()
            ->values()
            ->all();

        $report = [
            'generated_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'mode' => $apply ? 'APPLY' : 'DRY_RUN',
            'allow_partial' => $allowPartial,
            'cutoff' => $cutoff,
            'from' => $from,
            'until' => $until,
            'source_label' => $sourceLabel,
            'input_files' => $filePaths,
            'summary' => [
                'parsed_sections' => count($parsed['sections']),
                'raw_invoice_groups' => count($aggregation['invoices']),
                'invoices_in_range' => count($dateFiltered['rows']),
                'invoices_ready_for_mapping' => count($preparedInvoices),
                'invoices_existing_same' => count($existingAsSame),
                'invoices_insertable' => count($insertableInvoices),
                'issues_total' => count($issues),
            ],
            'issues' => $issues,
            'unresolved_product_names' => $unresolvedProductNames,
            'already_existing_same' => $existingAsSame,
            'insertable_preview' => array_map(function (array $invoice): array {
                return [
                    'no_faktur' => $invoice['no_faktur'],
                    'supplier' => $invoice['supplier_name'],
                    'supplier_id' => $invoice['id_supplier'],
                    'waktu' => $invoice['waktu'],
                    'total_item' => $invoice['total_item'],
                    'total_harga' => $invoice['total_harga'],
                    'detail_count' => count($invoice['details']),
                ];
            }, array_slice($insertableInvoices, 0, 200)),
        ];

        $reportPath = $this->writeReport($report);
        $aliasSuggestions = $this->buildAliasSuggestions($unresolvedProductNames);
        $aliasTemplatePath = $this->writeAliasTemplateIfNeeded($unresolvedProductNames, $aliasSuggestions);
        $aliasSuggestionPath = $this->writeAliasSuggestionsIfNeeded($aliasSuggestions);

        $this->table(
            ['Sections', 'Invoice in Range', 'Insertable', 'Existing Same', 'Issues'],
            [[
                count($parsed['sections']),
                count($dateFiltered['rows']),
                count($insertableInvoices),
                count($existingAsSame),
                count($issues),
            ]]
        );
        $this->line('Report        : ' . $reportPath);
        if ($aliasTemplatePath !== null) {
            $this->line('Alias template: ' . $aliasTemplatePath);
        }
        if ($aliasSuggestionPath !== null) {
            $this->line('Alias suggest : ' . $aliasSuggestionPath);
        }

        if (!$apply) {
            $this->info('Dry-run selesai. Tidak ada perubahan database.');
            return 0;
        }

        if (!empty($issues) && !$allowPartial) {
            $this->error('Apply dibatalkan karena ada issue. Jalankan tanpa --apply untuk review report, atau gunakan --allow-partial bila memang disetujui.');
            return 1;
        }

        if (empty($insertableInvoices)) {
            $this->warn('Tidak ada invoice baru yang perlu diinsert.');
            return 0;
        }

        $inserted = [
            'invoice_count' => 0,
            'detail_count' => 0,
            'affected_product_ids' => [],
        ];

        try {
            DB::transaction(function () use (&$inserted, $insertableInvoices, $reflowService, $until): void {
                foreach ($insertableInvoices as $invoice) {
                    $headerId = DB::table('pembelian')->insertGetId([
                        'id_supplier' => $invoice['id_supplier'],
                        'total_item' => $invoice['total_item'],
                        'total_harga' => $invoice['total_harga'],
                        'diskon' => 0,
                        'bayar' => $invoice['total_harga'],
                        'no_faktur' => $invoice['no_faktur'],
                        'waktu' => $invoice['waktu'],
                        'waktu_datang' => $invoice['waktu'],
                        'created_at' => $invoice['waktu'],
                        'updated_at' => Carbon::now(),
                    ]);

                    $rows = [];
                    foreach ($invoice['details'] as $detail) {
                        $rows[] = [
                            'id_pembelian' => $headerId,
                            'id_produk' => $detail['id_produk'],
                            'harga_beli' => $detail['harga_beli'],
                            'jumlah' => $detail['jumlah'],
                            'subtotal' => $detail['subtotal'],
                            'created_at' => $invoice['waktu'],
                            'updated_at' => Carbon::now(),
                        ];

                        $inserted['affected_product_ids'][$detail['id_produk']] = true;
                    }

                    foreach (array_chunk($rows, 500) as $chunk) {
                        DB::table('pembelian_detail')->insert($chunk);
                    }

                    $inserted['invoice_count']++;
                    $inserted['detail_count'] += count($rows);
                }

                $affectedProductIds = array_values(array_map('intval', array_keys($inserted['affected_product_ids'])));

                if (!empty($affectedProductIds)) {
                    $reflowService->rebuildProducts($affectedProductIds, $until);
                }
            }, 3);
        } catch (\Throwable $e) {
            $this->error('Apply gagal dan otomatis rollback: ' . $e->getMessage());
            return 1;
        }

        $elapsed = round(microtime(true) - $startedAt, 2);
        $this->info('Apply sukses.');
        $this->line('Invoice inserted: ' . $inserted['invoice_count']);
        $this->line('Detail inserted : ' . $inserted['detail_count']);
        $this->line('Produk terdampak: ' . count($inserted['affected_product_ids']));
        $this->line('Elapsed (s)     : ' . $elapsed);

        return 0;
    }

    private function resolveMarkdownPaths(): array
    {
        $manualPaths = collect($this->option('file'))
            ->map(fn ($path) => trim((string) $path))
            ->filter(fn ($path) => $path !== '')
            ->values();

        if ($manualPaths->isEmpty()) {
            $discoveredPaths = $this->discoverMarkdownPaths();
            if (!empty($discoveredPaths)) {
                return $discoveredPaths;
            }

            $manualPaths = collect([
                base_path('INPUT_FAKTUR_PEMBELIAN_JANUARI.md'),
                base_path('INPUT_FAKTUR_PEMBELIAN_FEBRUARI.md'),
            ]);
        }

        $valid = [];
        foreach ($manualPaths as $path) {
            $realPath = $this->resolvePath($path);
            if ($realPath !== null) {
                $valid[] = $realPath;
                continue;
            }

            $this->warn('File tidak ditemukan atau tidak bisa dibaca: ' . $path);
        }

        return array_values(array_unique($valid));
    }

    private function discoverMarkdownPaths(): array
    {
        $dirOption = trim((string) $this->option('file-dir'));
        $globOption = trim((string) $this->option('file-glob'));

        $searchDir = $this->resolveDirectoryPath($dirOption);
        if ($searchDir === null) {
            $this->warn('Direktori pencarian file markdown tidak ditemukan: ' . ($dirOption !== '' ? $dirOption : '(default)'));
            return [];
        }

        $patterns = preg_split('/\s*,\s*/', $globOption !== '' ? $globOption : 'INPUT_FAKTUR_PEMBELIAN*.md') ?: [];
        $resolved = [];

        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }

            $target = rtrim($searchDir, "\\/") . DIRECTORY_SEPARATOR . $pattern;
            $matches = glob($target, GLOB_NOSORT);
            if ($matches === false) {
                $this->warn('Gagal membaca pattern file markdown: ' . $target);
                continue;
            }

            foreach ($matches as $path) {
                if (!is_file($path) || !is_readable($path)) {
                    continue;
                }

                $resolvedPath = realpath($path);
                $resolved[] = $resolvedPath !== false ? $resolvedPath : $path;
            }
        }

        $resolved = array_values(array_unique($resolved));
        natsort($resolved);

        return array_values($resolved);
    }

    private function resolvePath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        if (is_file($path) && is_readable($path)) {
            return $path;
        }

        $candidate = base_path($path);
        if (is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }

        return null;
    }

    private function resolveDirectoryPath(string $path): ?string
    {
        if ($path === '') {
            return base_path();
        }

        if (is_dir($path)) {
            return $path;
        }

        $candidate = base_path($path);
        if (is_dir($candidate)) {
            return $candidate;
        }

        return null;
    }

    private function parseMarkdownFiles(array $filePaths): array
    {
        $sections = [];
        $issues = [];

        foreach ($filePaths as $filePath) {
            $content = @file_get_contents($filePath);
            if ($content === false) {
                $issues[] = [
                    'type' => 'file_read_error',
                    'file' => $filePath,
                    'message' => 'Gagal membaca file markdown.',
                ];
                continue;
            }

            $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
            $currentHeader = null;
            $currentStart = 1;
            $currentLines = [];

            foreach ($lines as $index => $line) {
                $lineNo = $index + 1;
                if (strpos((string) $line, '### HALAMAN') === 0) {
                    if ($currentHeader !== null) {
                        $section = $this->buildSection($filePath, $currentHeader, $currentLines, $currentStart);
                        if ($section !== null) {
                            $sections[] = $section;
                        }
                    }

                    $currentHeader = (string) $line;
                    $currentStart = $lineNo;
                    $currentLines = [];
                    continue;
                }

                if ($currentHeader !== null) {
                    $currentLines[] = (string) $line;
                }
            }

            if ($currentHeader !== null) {
                $section = $this->buildSection($filePath, $currentHeader, $currentLines, $currentStart);
                if ($section !== null) {
                    $sections[] = $section;
                }
            }
        }

        return [
            'sections' => $sections,
            'issues' => $issues,
        ];
    }

    private function buildSection(string $filePath, string $header, array $sectionLines, int $startLine): ?array
    {
        $joinedText = trim(implode("\n", $sectionLines));

        if ($joinedText === '') {
            return null;
        }

        if (preg_match('/data\s+tidak\s+tersedia/i', $joinedText)) {
            return null;
        }

        $supplierName = $this->extractSupplierNameFromHeader($header);
        $invoiceNo = $this->extractInvoiceNumber($header, $sectionLines);
        $invoiceDate = $this->extractInvoiceDate($header, $sectionLines);

        $rows = $this->extractRowsFromSection($sectionLines, $startLine);

        if (empty($rows)) {
            return null;
        }

        return [
            'file' => $filePath,
            'header' => $header,
            'start_line' => $startLine,
            'supplier_name' => $supplierName,
            'no_faktur' => $invoiceNo,
            'invoice_date' => $invoiceDate,
            'rows' => $rows,
        ];
    }

    private function extractSupplierNameFromHeader(string $header): ?string
    {
        $clean = trim($header);

        if (preg_match('/^###\s+HALAMAN\s+[^:]+:\s*(.+)$/i', $clean, $matches)) {
            $value = trim((string) $matches[1]);
            $value = preg_replace('/\s*\([^\)]*\)\s*$/', '', $value);
            return $this->normalizeTitleCase($value);
        }

        return null;
    }

    private function extractInvoiceNumber(string $header, array $sectionLines): ?string
    {
        if (preg_match('/\(([^\)]*)\)/', $header, $headerParenthesis)) {
            $candidate = $this->extractInvoiceFromText((string) $headerParenthesis[1]);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        foreach ($sectionLines as $line) {
            $candidate = $this->extractInvoiceFromText((string) $line);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function extractInvoiceFromText(string $text): ?string
    {
        $normalized = trim($text);

        $patterns = [
            '/(?:faktur(?:\s+[a-z]+)?|nota(?:\s+penyerahan\s+barang)?|nppb|no\.?\s*nota)\s*:\s*([^\|\)\n]+)/i',
            '/(?:no\.?\s*nppb\s*\/\s*no\.?\s*np|no\.?\s*nppb|no\.?\s*np)\s*:\s*([^\|\)\n]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                $raw = trim((string) $matches[1]);
                $raw = preg_replace('/\s*-\s*halaman.*$/i', '', $raw);
                $raw = preg_replace('/\s+\*+.*$/', '', $raw);
                $raw = trim((string) $raw, "* \t\n\r\0\x0B");
                if ($raw !== '') {
                    return mb_strtoupper($raw);
                }
            }
        }

        return null;
    }

    private function extractInvoiceDate(string $header, array $sectionLines): ?string
    {
        $candidates = array_merge([$header], $sectionLines);

        foreach ($candidates as $line) {
            $text = (string) $line;

            $patterns = [
                '/Tanggal\s*:\s*([0-9]{1,2}[\/\-][0-9A-Za-z]{1,3}[\/\-][0-9]{2,4}|[0-9]{1,2}\s+[A-Za-z]+\s+[0-9]{4})/i',
                '/Tgl\.?\s*(?:NP)?\s*:\s*([0-9]{1,2}[\/\-][0-9A-Za-z]{1,3}[\/\-][0-9]{2,4}|[0-9]{1,2}\s+[A-Za-z]+\s+[0-9]{4})/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text, $matches)) {
                    $parsed = $this->parseDateString((string) $matches[1]);
                    if ($parsed !== null) {
                        return $parsed;
                    }
                }
            }
        }

        if (preg_match('/\/([0-9]{2}-[0-9]{2}-[0-9]{4})\//', $header, $matches)) {
            $parsed = $this->parseDateString((string) $matches[1]);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        foreach ($sectionLines as $line) {
            if (preg_match('/\/([0-9]{2}-[0-9]{2}-[0-9]{4})\//', (string) $line, $matches)) {
                $parsed = $this->parseDateString((string) $matches[1]);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        return null;
    }

    private function parseDateString(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        $value = str_replace('.', '-', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        $monthMap = [
            'januari' => '01',
            'februari' => '02',
            'maret' => '03',
            'april' => '04',
            'mei' => '05',
            'juni' => '06',
            'juli' => '07',
            'agustus' => '08',
            'september' => '09',
            'oktober' => '10',
            'november' => '11',
            'desember' => '12',
        ];

        foreach ($monthMap as $indo => $monthNumber) {
            $value = preg_replace('/\b' . preg_quote($indo, '/') . '\b/i', $monthNumber, $value);
        }

        $formats = ['d/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y', 'd-M-Y', 'd-m-Y'];

        foreach ($formats as $format) {
            try {
                $dt = Carbon::createFromFormat($format, $value);
                if ($dt !== false) {
                    return $dt->format('Y-m-d') . ' 12:00:00';
                }
            } catch (\Throwable $e) {
            }
        }

        if (preg_match('/^([0-9]{1,2})\s+([0-9]{2})\s+([0-9]{4})$/', $value, $matches)) {
            $day = str_pad((string) $matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad((string) $matches[2], 2, '0', STR_PAD_LEFT);
            $year = (string) $matches[3];
            return $year . '-' . $month . '-' . $day . ' 12:00:00';
        }

        try {
            return Carbon::parse($value)->format('Y-m-d 12:00:00');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function extractRowsFromSection(array $lines, int $startLine): array
    {
        $rows = [];
        $lineCount = count($lines);
        $i = 0;

        while ($i < $lineCount) {
            $line = (string) $lines[$i];
            if (!$this->looksLikeTableRow($line)) {
                $i++;
                continue;
            }

            if (($i + 1) >= $lineCount || !preg_match('/^\|\s*-+/', trim((string) $lines[$i + 1]))) {
                $i++;
                continue;
            }

            $headerCells = $this->splitMarkdownRow($line);
            if (empty($headerCells)) {
                $i++;
                continue;
            }

            $headerMap = $this->buildHeaderMap($headerCells);
            $i += 2;

            while ($i < $lineCount) {
                $rowLine = (string) $lines[$i];
                if (!$this->looksLikeTableRow($rowLine)) {
                    break;
                }

                $cells = $this->splitMarkdownRow($rowLine);
                if (count($cells) < 2) {
                    $i++;
                    continue;
                }

                if ($this->isSeparatorRow($cells)) {
                    $i++;
                    continue;
                }

                $row = $this->extractRowData($cells, $headerMap, $startLine + $i);
                if ($row !== null) {
                    $rows[] = $row;
                }

                $i++;
            }
        }

        return $rows;
    }

    private function looksLikeTableRow(string $line): bool
    {
        $trim = trim($line);
        return $trim !== '' && strpos($trim, '|') === 0 && strrpos($trim, '|') !== false;
    }

    private function splitMarkdownRow(string $line): array
    {
        $trim = trim($line);
        $trim = trim($trim, '|');
        $parts = explode('|', $trim);

        return array_map(function ($value) {
            return trim((string) $value);
        }, $parts);
    }

    private function buildHeaderMap(array $headerCells): array
    {
        $map = [];
        foreach ($headerCells as $idx => $headerCell) {
            $normalized = $this->normalizeText($headerCell);
            $map[$normalized] = $idx;
        }

        return $map;
    }

    private function isSeparatorRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (!preg_match('/^-+$/', str_replace(' ', '', (string) $cell))) {
                return false;
            }
        }

        return true;
    }

    private function extractRowData(array $cells, array $headerMap, int $lineNo): ?array
    {
        $nameIndex = $this->findHeaderIndex($headerMap, ['nama produk', 'produk']);
        $qtyIndex = $this->findHeaderIndex($headerMap, ['qty', 'jumlah', 'kuantum', 'unit']);
        $unitPriceIndex = $this->findHeaderIndex($headerMap, ['harga satuan', 'harga']);
        $subtotalIndex = $this->findHeaderIndex($headerMap, ['netto total', 'jumlah netto', 'sub total', 'subtotal', 'total', 'rp + ppn']);
        $batchIndex = $this->findHeaderIndex($headerMap, ['batch', 'batch/exp', 'ed / batch', 'batch & ed', 'no. batch']);

        if ($nameIndex === null || $qtyIndex === null) {
            return null;
        }

        $productName = trim((string) ($cells[$nameIndex] ?? ''));
        if ($productName === '' || preg_match('/^[-]+$/', $productName)) {
            return null;
        }

        $qtyRaw = trim((string) ($cells[$qtyIndex] ?? '0'));
        $qty = $this->parseQuantity($qtyRaw);
        if ($qty <= 0) {
            return null;
        }

        $unitPrice = 0;
        if ($unitPriceIndex !== null) {
            $unitPrice = $this->parseCurrencyToInt((string) ($cells[$unitPriceIndex] ?? '0'));
        }

        $subtotal = 0;
        if ($subtotalIndex !== null) {
            $subtotal = $this->parseCurrencyToInt((string) ($cells[$subtotalIndex] ?? '0'));
        }

        if ($subtotal <= 0 && $unitPrice > 0) {
            $subtotal = $unitPrice * $qty;
        }

        if ($unitPrice <= 0 && $subtotal > 0) {
            $unitPrice = (int) max(1, round($subtotal / max($qty, 1)));
        }

        if ($unitPrice <= 0) {
            $unitPrice = 1;
        }

        if ($subtotal <= 0) {
            $subtotal = $unitPrice * $qty;
        }

        $batch = '';
        if ($batchIndex !== null) {
            $batch = trim((string) ($cells[$batchIndex] ?? ''));
        }

        return [
            'line' => $lineNo,
            'nama_produk_raw' => $productName,
            'jumlah' => $qty,
            'harga_beli' => $unitPrice,
            'subtotal' => $subtotal,
            'batch' => $batch,
        ];
    }

    private function findHeaderIndex(array $headerMap, array $patterns): ?int
    {
        foreach ($patterns as $pattern) {
            foreach ($headerMap as $header => $index) {
                if (strpos($header, $this->normalizeText($pattern)) !== false) {
                    return $index;
                }
            }
        }

        return null;
    }

    private function parseQuantity(string $value): int
    {
        $normalized = str_replace(',', '.', $value);
        if (preg_match('/-?[0-9]+(?:\.[0-9]+)?/', $normalized, $matches)) {
            $qty = (float) $matches[0];
            return (int) max(0, round($qty));
        }

        return 0;
    }

    private function parseCurrencyToInt(string $value): int
    {
        $clean = strtolower(trim($value));
        if ($clean === '') {
            return 0;
        }

        $clean = str_replace(['rp', 'idr', ' '], '', $clean);

        if ($clean === '' || $clean === '-') {
            return 0;
        }

        if (strpos($clean, ',') !== false && strpos($clean, '.') !== false) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } elseif (strpos($clean, ',') !== false) {
            $parts = explode(',', $clean);
            if (count($parts) === 2 && strlen($parts[1]) <= 2) {
                $clean = $parts[0] . '.' . $parts[1];
            } else {
                $clean = str_replace(',', '', $clean);
            }
        } else {
            $clean = str_replace('.', '', $clean);
        }

        if (!is_numeric($clean)) {
            return 0;
        }

        return (int) max(0, round((float) $clean));
    }

    private function aggregateInvoices(array $sections): array
    {
        $issues = [];
        $grouped = [];

        foreach ($sections as $section) {
            $invoiceNo = trim((string) ($section['no_faktur'] ?? ''));
            if ($invoiceNo === '') {
                $issues[] = [
                    'type' => 'missing_invoice_number',
                    'file' => $section['file'],
                    'line' => $section['start_line'],
                    'header' => $section['header'],
                    'message' => 'Nomor faktur/nota tidak ditemukan.',
                ];
                continue;
            }

            if (!isset($grouped[$invoiceNo])) {
                $grouped[$invoiceNo] = [
                    'no_faktur' => $invoiceNo,
                    'supplier_name' => $section['supplier_name'],
                    'invoice_date' => $section['invoice_date'],
                    'source_sections' => [],
                    'rows' => [],
                    'row_keys' => [],
                ];
            }

            if ($grouped[$invoiceNo]['supplier_name'] === null && $section['supplier_name'] !== null) {
                $grouped[$invoiceNo]['supplier_name'] = $section['supplier_name'];
            }

            if ($grouped[$invoiceNo]['invoice_date'] === null && $section['invoice_date'] !== null) {
                $grouped[$invoiceNo]['invoice_date'] = $section['invoice_date'];
            }

            $grouped[$invoiceNo]['source_sections'][] = [
                'file' => $section['file'],
                'line' => $section['start_line'],
                'header' => $section['header'],
            ];

            foreach ($section['rows'] as $row) {
                $dedupeKey = implode('|', [
                    $this->normalizeProductName($row['nama_produk_raw']),
                    $row['batch'],
                    $row['jumlah'],
                    $row['harga_beli'],
                    $row['subtotal'],
                ]);

                if (isset($grouped[$invoiceNo]['row_keys'][$dedupeKey])) {
                    continue;
                }

                $grouped[$invoiceNo]['row_keys'][$dedupeKey] = true;
                $grouped[$invoiceNo]['rows'][] = $row;
            }
        }

        $invoices = [];
        foreach ($grouped as $invoiceNo => $payload) {
            if (empty($payload['rows'])) {
                continue;
            }

            $invoices[] = [
                'no_faktur' => $invoiceNo,
                'supplier_name' => $payload['supplier_name'],
                'invoice_date' => $payload['invoice_date'],
                'source_sections' => $payload['source_sections'],
                'rows' => $payload['rows'],
            ];
        }

        return [
            'invoices' => $invoices,
            'issues' => $issues,
        ];
    }

    private function filterInvoicesByDateRange(array $invoices, string $from, string $until, string $cutoff): array
    {
        $issues = [];
        $rows = [];

        $fromTs = Carbon::parse($from);
        $untilTs = Carbon::parse($until);
        $cutoffTs = Carbon::parse($cutoff);

        foreach ($invoices as $invoice) {
            $date = $invoice['invoice_date'] ?? null;
            if ($date === null) {
                $issues[] = [
                    'type' => 'missing_invoice_date',
                    'no_faktur' => $invoice['no_faktur'],
                    'message' => 'Tanggal faktur tidak ditemukan.',
                ];
                continue;
            }

            try {
                $dt = Carbon::parse($date);
            } catch (\Throwable $e) {
                $issues[] = [
                    'type' => 'invalid_invoice_date',
                    'no_faktur' => $invoice['no_faktur'],
                    'invoice_date' => $date,
                    'message' => 'Tanggal faktur tidak valid.',
                ];
                continue;
            }

            if ($dt->lessThanOrEqualTo($cutoffTs)) {
                continue;
            }

            if ($dt->lt($fromTs) || $dt->gt($untilTs)) {
                continue;
            }

            $invoice['invoice_date'] = $dt->format('Y-m-d H:i:s');
            $rows[] = $invoice;
        }

        return [
            'rows' => $rows,
            'issues' => $issues,
        ];
    }

    private function mapSuppliersAndProducts(array $invoiceRows): array
    {
        $issues = [];
        $mappedInvoices = [];

        foreach ($invoiceRows as $invoice) {
            $supplierName = trim((string) ($invoice['supplier_name'] ?? ''));
            $supplierMapping = $this->resolveSupplier($supplierName);

            if (!$supplierMapping['ok']) {
                $issues[] = [
                    'type' => 'supplier_mapping_failed',
                    'no_faktur' => $invoice['no_faktur'],
                    'supplier_name' => $supplierName,
                    'message' => $supplierMapping['message'],
                ];
                continue;
            }

            $detailByProduct = [];
            $rowIssues = [];

            foreach ($invoice['rows'] as $row) {
                $productName = $row['nama_produk_raw'];
                $productMapping = $this->resolveProduct($productName);

                if (!$productMapping['ok']) {
                    $rowIssues[] = [
                        'type' => 'product_mapping_failed',
                        'no_faktur' => $invoice['no_faktur'],
                        'line' => $row['line'],
                        'nama_produk_raw' => $productName,
                        'message' => $productMapping['message'],
                    ];
                    continue;
                }

                $productId = $productMapping['id_produk'];
                if (!isset($detailByProduct[$productId])) {
                    $detailByProduct[$productId] = [
                        'id_produk' => $productId,
                        'nama_produk_db' => $productMapping['nama_produk'],
                        'jumlah' => 0,
                        'subtotal' => 0,
                    ];
                }

                $detailByProduct[$productId]['jumlah'] += (int) $row['jumlah'];
                $detailByProduct[$productId]['subtotal'] += (int) $row['subtotal'];
            }

            if (!empty($rowIssues)) {
                $issues = array_merge($issues, $rowIssues);
                continue;
            }

            $details = array_values(array_map(function (array $detail): array {
                $jumlah = max(1, (int) $detail['jumlah']);
                $subtotal = max(1, (int) $detail['subtotal']);
                $hargaBeli = (int) max(1, round($subtotal / $jumlah));

                return [
                    'id_produk' => (int) $detail['id_produk'],
                    'nama_produk' => $detail['nama_produk_db'],
                    'jumlah' => $jumlah,
                    'harga_beli' => $hargaBeli,
                    'subtotal' => $hargaBeli * $jumlah,
                ];
            }, $detailByProduct));

            if (empty($details)) {
                $issues[] = [
                    'type' => 'invoice_without_valid_details',
                    'no_faktur' => $invoice['no_faktur'],
                    'message' => 'Tidak ada detail yang bisa dimapping ke produk database.',
                ];
                continue;
            }

            $totalItem = array_reduce($details, function ($carry, array $item) {
                return $carry + (int) $item['jumlah'];
            }, 0);

            $totalHarga = array_reduce($details, function ($carry, array $item) {
                return $carry + (int) $item['subtotal'];
            }, 0);

            $mappedInvoices[] = [
                'no_faktur' => $invoice['no_faktur'],
                'supplier_name' => $supplierName,
                'id_supplier' => (int) $supplierMapping['id_supplier'],
                'waktu' => $invoice['invoice_date'],
                'total_item' => (int) max(1, $totalItem),
                'total_harga' => (int) max(1, $totalHarga),
                'details' => $details,
                'source_sections' => $invoice['source_sections'],
            ];
        }

        return [
            'invoices' => $mappedInvoices,
            'issues' => $issues,
        ];
    }

    private function resolveExistingInvoices(array $preparedInvoices): array
    {
        if (empty($preparedInvoices)) {
            return [
                'insertable' => [],
                'already_same' => [],
                'conflicts' => [],
            ];
        }

        $invoiceNumbers = array_values(array_unique(array_map(function (array $invoice): string {
            return (string) $invoice['no_faktur'];
        }, $preparedInvoices)));

        $existingHeaders = DB::table('pembelian')
            ->whereIn('no_faktur', $invoiceNumbers)
            ->get()
            ->keyBy('no_faktur');

        if ($existingHeaders->isEmpty()) {
            return [
                'insertable' => $preparedInvoices,
                'already_same' => [],
                'conflicts' => [],
            ];
        }

        $existingDetailRows = DB::table('pembelian_detail as pd')
            ->join('pembelian as p', 'p.id_pembelian', '=', 'pd.id_pembelian')
            ->whereIn('p.no_faktur', $invoiceNumbers)
            ->select('p.no_faktur', 'p.id_supplier', 'pd.id_produk', 'pd.jumlah')
            ->get();

        $existingSignatures = [];
        foreach ($existingDetailRows as $detail) {
            $invoiceNo = (string) $detail->no_faktur;
            if (!isset($existingSignatures[$invoiceNo])) {
                $existingSignatures[$invoiceNo] = [];
            }

            $productId = (int) $detail->id_produk;
            if (!isset($existingSignatures[$invoiceNo][$productId])) {
                $existingSignatures[$invoiceNo][$productId] = 0;
            }

            $existingSignatures[$invoiceNo][$productId] += (int) $detail->jumlah;
        }

        $insertable = [];
        $alreadySame = [];
        $conflicts = [];

        foreach ($preparedInvoices as $invoice) {
            $invoiceNo = (string) $invoice['no_faktur'];
            $existingHeader = $existingHeaders->get($invoiceNo);
            if (!$existingHeader) {
                $insertable[] = $invoice;
                continue;
            }

            $newSignature = $this->buildInvoiceSignature($invoice['details']);
            $oldSignature = $existingSignatures[$invoiceNo] ?? [];

            if ((int) $existingHeader->id_supplier === (int) $invoice['id_supplier']
                && $this->signaturesEqual($newSignature, $oldSignature)) {
                $alreadySame[] = [
                    'no_faktur' => $invoiceNo,
                    'id_pembelian' => (int) $existingHeader->id_pembelian,
                    'status' => 'already_imported_same_signature',
                ];
                continue;
            }

            $conflicts[] = [
                'type' => 'existing_invoice_conflict',
                'no_faktur' => $invoiceNo,
                'id_pembelian' => (int) $existingHeader->id_pembelian,
                'message' => 'Nomor faktur sudah ada dengan detail/supplier berbeda. Dihentikan untuk cegah duplikasi salah.',
            ];
        }

        return [
            'insertable' => $insertable,
            'already_same' => $alreadySame,
            'conflicts' => $conflicts,
        ];
    }

    private function buildInvoiceSignature(array $details): array
    {
        $signature = [];
        foreach ($details as $detail) {
            $productId = (int) $detail['id_produk'];
            if (!isset($signature[$productId])) {
                $signature[$productId] = 0;
            }
            $signature[$productId] += (int) $detail['jumlah'];
        }

        ksort($signature);

        return $signature;
    }

    private function signaturesEqual(array $left, array $right): bool
    {
        ksort($left);
        ksort($right);

        return $left === $right;
    }

    private function buildSupplierIndex(): void
    {
        $this->supplierRows = DB::table('supplier')
            ->select('id_supplier', 'nama')
            ->orderBy('id_supplier', 'asc')
            ->get()
            ->map(function ($row): array {
                return [
                    'id_supplier' => (int) $row->id_supplier,
                    'nama' => trim((string) $row->nama),
                    'normalized' => $this->normalizeCompanyName((string) $row->nama),
                ];
            })
            ->all();

        $index = [];
        foreach ($this->supplierRows as $row) {
            $norm = $row['normalized'];
            if ($norm === '') {
                continue;
            }

            if (!isset($index[$norm])) {
                $index[$norm] = [];
            }
            $index[$norm][] = $row;
        }

        $this->supplierIndex = $index;
    }

    private function buildProductIndex(): void
    {
        $this->productRows = DB::table('produk')
            ->select('id_produk', 'nama_produk')
            ->orderBy('id_produk', 'asc')
            ->get()
            ->map(function ($row): array {
                $productName = (string) $row->nama_produk;
                $normalizedName = $this->normalizeProductName((string) $row->nama_produk);
                $tokens = $this->extractSignificantProductTokens($normalizedName);
                return [
                    'id_produk' => (int) $row->id_produk,
                    'nama_produk' => trim($productName),
                    'normalized' => $normalizedName,
                    'tokens' => $tokens,
                    'token_key' => $this->buildTokenKey($tokens),
                    'measure_tokens' => $this->extractMeasureTokens($productName),
                    'pack_count_tokens' => $this->extractPackCountTokens($productName),
                ];
            })
            ->all();

        $index = [];
        $tokenIndex = [];
        $byId = [];
        foreach ($this->productRows as $row) {
            $norm = $row['normalized'];
            if ($norm === '') {
                continue;
            }

            if (!isset($index[$norm])) {
                $index[$norm] = [];
            }

            $index[$norm][] = $row;

            $tokenKey = $row['token_key'];
            if ($tokenKey !== '') {
                if (!isset($tokenIndex[$tokenKey])) {
                    $tokenIndex[$tokenKey] = [];
                }
                $tokenIndex[$tokenKey][] = $row;
            }

            $byId[(int) $row['id_produk']] = $row;
        }

        $this->productIndex = $index;
        $this->productTokenIndex = $tokenIndex;
        $this->productById = $byId;
    }

    private function loadProductAliases(string $aliasPath): void
    {
        $path = trim($aliasPath);
        if ($path === '') {
            $this->productAliasMap = [];
            return;
        }

        $resolved = $this->resolvePath($path);
        if ($resolved === null) {
            throw new \RuntimeException('File alias tidak ditemukan: ' . $path);
        }

        $raw = @file_get_contents($resolved);
        if ($raw === false) {
            throw new \RuntimeException('Gagal membaca file alias: ' . $resolved);
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Format alias JSON tidak valid.');
        }

        $aliasMap = [];
        foreach ($json as $sourceName => $target) {
            $sourceNorm = $this->normalizeProductName((string) $sourceName);
            if ($sourceNorm === '') {
                continue;
            }

            if ($target === null) {
                continue;
            }

            if (is_string($target) && trim($target) === '') {
                continue;
            }

            $targetRow = null;
            if (is_numeric($target)) {
                $targetId = (int) $target;
                $targetRow = $this->productById[$targetId] ?? null;
            } elseif (is_string($target)) {
                $targetNorm = $this->normalizeProductName($target);
                if ($targetNorm !== '' && isset($this->productIndex[$targetNorm]) && count($this->productIndex[$targetNorm]) === 1) {
                    $targetRow = $this->productIndex[$targetNorm][0];
                }
            }

            if ($targetRow === null) {
                throw new \RuntimeException('Alias produk tidak valid untuk sumber: ' . $sourceName);
            }

            $aliasMap[$sourceNorm] = [
                'id_produk' => (int) $targetRow['id_produk'],
                'nama_produk' => (string) $targetRow['nama_produk'],
            ];
        }

        $this->productAliasMap = $aliasMap;
    }

    private function resolveSupplier(string $supplierName): array
    {
        $normalized = $this->normalizeCompanyName($supplierName);
        if ($normalized === '') {
            return [
                'ok' => false,
                'message' => 'Nama supplier kosong.',
            ];
        }

        if (isset($this->supplierIndex[$normalized])) {
            $candidates = $this->supplierIndex[$normalized];
            if (count($candidates) === 1) {
                return [
                    'ok' => true,
                    'id_supplier' => $candidates[0]['id_supplier'],
                    'nama' => $candidates[0]['nama'],
                ];
            }

            return [
                'ok' => false,
                'message' => 'Supplier ambigu (multiple exact normalized match).',
            ];
        }

        $containsCandidates = [];
        foreach ($this->supplierRows as $row) {
            if ($row['normalized'] === '') {
                continue;
            }

            if (strpos($row['normalized'], $normalized) !== false || strpos($normalized, $row['normalized']) !== false) {
                $containsCandidates[] = $row;
            }
        }

        if (count($containsCandidates) === 1) {
            return [
                'ok' => true,
                'id_supplier' => $containsCandidates[0]['id_supplier'],
                'nama' => $containsCandidates[0]['nama'],
            ];
        }

        $best = $this->pickBestSimilarity($normalized, $this->supplierRows, 'normalized', 92.0, 3.0);
        if ($best !== null) {
            return [
                'ok' => true,
                'id_supplier' => $best['row']['id_supplier'],
                'nama' => $best['row']['nama'],
            ];
        }

        return [
            'ok' => false,
            'message' => 'Supplier tidak ditemukan di tabel supplier.',
        ];
    }

    private function resolveProduct(string $productName): array
    {
        $normalized = $this->normalizeProductName($productName);
        if ($normalized === '') {
            return [
                'ok' => false,
                'message' => 'Nama produk kosong.',
            ];
        }

        $queryMeasures = $this->extractMeasureTokens($productName);
        $queryPackCounts = $this->extractPackCountTokens($productName);

        if (isset($this->productAliasMap[$normalized])) {
            return [
                'ok' => true,
                'id_produk' => $this->productAliasMap[$normalized]['id_produk'],
                'nama_produk' => $this->productAliasMap[$normalized]['nama_produk'],
            ];
        }

        if (isset($this->productIndex[$normalized])) {
            $candidates = $this->preferFilteredRows($this->productIndex[$normalized], $queryMeasures, $queryPackCounts);
            if (count($candidates) === 1) {
                return [
                    'ok' => true,
                    'id_produk' => $candidates[0]['id_produk'],
                    'nama_produk' => $candidates[0]['nama_produk'],
                ];
            }

            return [
                'ok' => false,
                'message' => 'Produk ambigu (multiple exact normalized match).',
            ];
        }

        $queryTokens = $this->extractSignificantProductTokens($normalized);
        $queryTokenKey = $this->buildTokenKey($queryTokens);

        $preferredRows = $this->preferFilteredRows($this->productRows, $queryMeasures, $queryPackCounts);

        if ($queryTokenKey !== '' && isset($this->productTokenIndex[$queryTokenKey])) {
            $tokenCandidates = $this->preferFilteredRows($this->productTokenIndex[$queryTokenKey], $queryMeasures, $queryPackCounts);
            if (count($tokenCandidates) === 1) {
                return [
                    'ok' => true,
                    'id_produk' => $tokenCandidates[0]['id_produk'],
                    'nama_produk' => $tokenCandidates[0]['nama_produk'],
                ];
            }

            if (count($tokenCandidates) > 1) {
                $bestToken = $this->pickBestSimilarity($normalized, $tokenCandidates, 'normalized', 82.0, 5.0);
                if ($bestToken !== null) {
                    return [
                        'ok' => true,
                        'id_produk' => $bestToken['row']['id_produk'],
                        'nama_produk' => $bestToken['row']['nama_produk'],
                    ];
                }
            }
        }

        if (!empty($queryTokens)) {
            $subsetCandidates = [];
            foreach ($preferredRows as $row) {
                $rowTokens = $row['tokens'] ?? [];
                if (empty($rowTokens)) {
                    continue;
                }

                $intersection = array_intersect($queryTokens, $rowTokens);
                if (count($intersection) === count($queryTokens)) {
                    $subsetCandidates[] = $row;
                }
            }

            if (count($subsetCandidates) === 1) {
                return [
                    'ok' => true,
                    'id_produk' => $subsetCandidates[0]['id_produk'],
                    'nama_produk' => $subsetCandidates[0]['nama_produk'],
                ];
            }

            if (count($subsetCandidates) > 1) {
                $bestSubset = $this->pickBestSimilarity($normalized, $subsetCandidates, 'normalized', 80.0, 5.0);
                if ($bestSubset !== null) {
                    return [
                        'ok' => true,
                        'id_produk' => $bestSubset['row']['id_produk'],
                        'nama_produk' => $bestSubset['row']['nama_produk'],
                    ];
                }
            }
        }

        $best = $this->pickBestSimilarity($normalized, $preferredRows, 'normalized', 85.0, 6.0);
        if ($best !== null) {
            return [
                'ok' => true,
                'id_produk' => $best['row']['id_produk'],
                'nama_produk' => $best['row']['nama_produk'],
            ];
        }

        return [
            'ok' => false,
            'message' => 'Produk tidak ditemukan di tabel produk.',
        ];
    }

    private function pickBestSimilarity(string $needle, array $rows, string $field, float $minScore, float $minGap): ?array
    {
        $best = null;
        $second = null;

        foreach ($rows as $row) {
            $candidate = trim((string) ($row[$field] ?? ''));
            if ($candidate === '') {
                continue;
            }

            similar_text($needle, $candidate, $percent);
            $score = (float) $percent;

            if ($best === null || $score > $best['score']) {
                $second = $best;
                $best = ['row' => $row, 'score' => $score];
                continue;
            }

            if ($second === null || $score > $second['score']) {
                $second = ['row' => $row, 'score' => $score];
            }
        }

        if ($best === null || $best['score'] < $minScore) {
            return null;
        }

        if ($second !== null && ($best['score'] - $second['score']) < $minGap) {
            return null;
        }

        return $best;
    }

    private function writeReport(array $report): string
    {
        $outputPath = trim((string) $this->option('report'));
        if ($outputPath === '') {
            $outputPath = base_path('post_cutoff_purchase_reinput_report_' . Carbon::now()->format('Ymd_His') . '.json');
        }

        $resolvedOutputPath = $this->resolveOutputPath($outputPath);

        file_put_contents(
            $resolvedOutputPath,
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $resolvedOutputPath;
    }

    private function writeAliasTemplateIfNeeded(array $unresolvedProductNames, array $aliasSuggestions): ?string
    {
        $targetPath = trim((string) $this->option('alias-template'));
        if ($targetPath === '') {
            return null;
        }

        $resolvedTarget = $this->resolveOutputPath($targetPath);
        $aliasPath = trim((string) $this->option('alias'));
        if ($aliasPath !== '') {
            $resolvedAlias = $this->resolveOutputPath($aliasPath);
            if ($this->pathsEqual($resolvedAlias, $resolvedTarget)) {
                $this->warn('Alias template tidak ditulis karena path sama dengan --alias. Gunakan file output template yang berbeda agar alias aktif tidak tertimpa.');
                return null;
            }
        }

        $autofill = (bool) $this->option('alias-autofill');
        $template = [];

        foreach ($unresolvedProductNames as $name) {
            $key = (string) $name;
            if ($autofill && isset($aliasSuggestions[$key]) && ($aliasSuggestions[$key]['autofill_safe'] ?? false) === true) {
                $template[$key] = (int) $aliasSuggestions[$key]['suggested_id_produk'];
                continue;
            }

            $template[$key] = null;
        }

        file_put_contents(
            $resolvedTarget,
            json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $resolvedTarget;
    }

    private function writeAliasSuggestionsIfNeeded(array $aliasSuggestions): ?string
    {
        $targetPath = trim((string) $this->option('alias-suggestions'));
        if ($targetPath === '') {
            return null;
        }

        $resolvedTarget = $this->resolveOutputPath($targetPath);
        file_put_contents(
            $resolvedTarget,
            json_encode($aliasSuggestions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $resolvedTarget;
    }

    private function buildAliasSuggestions(array $unresolvedProductNames): array
    {
        $result = [];

        foreach ($unresolvedProductNames as $rawName) {
            $name = (string) $rawName;
            $result[$name] = $this->suggestProductAlias($name);
        }

        return $result;
    }

    private function suggestProductAlias(string $rawName): array
    {
        $normalized = $this->normalizeProductName($rawName);
        if ($normalized === '') {
            return [
                'suggested_id_produk' => null,
                'suggested_nama_produk' => null,
                'score' => null,
                'similarity' => null,
                'token_overlap' => null,
                'gap' => null,
                'autofill_safe' => false,
                'top_candidates' => [],
                'reason' => 'empty_name',
            ];
        }

        if (isset($this->productIndex[$normalized]) && count($this->productIndex[$normalized]) === 1) {
            $row = $this->productIndex[$normalized][0];
            return [
                'suggested_id_produk' => (int) $row['id_produk'],
                'suggested_nama_produk' => (string) $row['nama_produk'],
                'score' => 100.0,
                'similarity' => 100.0,
                'token_overlap' => 100.0,
                'gap' => 100.0,
                'autofill_safe' => true,
                'top_candidates' => [[
                    'id_produk' => (int) $row['id_produk'],
                    'nama_produk' => (string) $row['nama_produk'],
                    'score' => 100.0,
                    'similarity' => 100.0,
                    'token_overlap' => 100.0,
                ]],
                'reason' => 'exact_normalized_match',
            ];
        }

        $queryTokens = $this->extractSignificantProductTokens($normalized);
        $queryMeasures = $this->extractMeasureTokens($rawName);
        $queryPackCounts = $this->extractPackCountTokens($rawName);

        $candidates = [];
        foreach ($this->productRows as $row) {
            $candidateNormalized = (string) ($row['normalized'] ?? '');
            if ($candidateNormalized === '') {
                continue;
            }

            if (!empty($queryMeasures)) {
                $candidateMeasures = $row['measure_tokens'] ?? [];
                $missingMeasure = false;
                foreach ($queryMeasures as $measure) {
                    if (!in_array($measure, $candidateMeasures, true)) {
                        $missingMeasure = true;
                        break;
                    }
                }
                if ($missingMeasure) {
                    continue;
                }
            }

            if (!empty($queryPackCounts)) {
                $candidatePackCounts = $row['pack_count_tokens'] ?? [];
                $missingPackCount = false;
                foreach ($queryPackCounts as $packCount) {
                    if (!in_array($packCount, $candidatePackCounts, true)) {
                        $missingPackCount = true;
                        break;
                    }
                }
                if ($missingPackCount) {
                    continue;
                }
            }

            $rowTokens = $row['tokens'] ?? [];
            $shared = array_values(array_intersect($queryTokens, $rowTokens));
            $queryTokenCount = count($queryTokens);
            $sharedCount = count($shared);
            $overlapRatio = $queryTokenCount > 0 ? ($sharedCount / $queryTokenCount) : 0.0;

            if ($queryTokenCount > 0 && $sharedCount === 0) {
                continue;
            }

            similar_text($normalized, $candidateNormalized, $similarity);
            $similarity = (float) $similarity;
            if ($similarity < 55.0) {
                continue;
            }

            if ($queryTokenCount > 0 && $overlapRatio < 0.34) {
                continue;
            }

            $tokenOverlapPercent = $overlapRatio * 100.0;
            $score = ($similarity * 0.7) + ($tokenOverlapPercent * 0.3);

            $candidates[] = [
                'row' => $row,
                'score' => $score,
                'similarity' => $similarity,
                'token_overlap' => $tokenOverlapPercent,
            ];
        }

        if (empty($candidates)) {
            return [
                'suggested_id_produk' => null,
                'suggested_nama_produk' => null,
                'score' => null,
                'similarity' => null,
                'token_overlap' => null,
                'gap' => null,
                'autofill_safe' => false,
                'top_candidates' => [],
                'reason' => 'no_candidate',
            ];
        }

        usort($candidates, function (array $left, array $right): int {
            return $right['score'] <=> $left['score'];
        });

        $best = $candidates[0];
        $secondScore = $candidates[1]['score'] ?? 0.0;
        $gap = (float) ($best['score'] - $secondScore);

        $topCandidates = array_map(function (array $candidate): array {
            return [
                'id_produk' => (int) $candidate['row']['id_produk'],
                'nama_produk' => (string) $candidate['row']['nama_produk'],
                'score' => round((float) $candidate['score'], 2),
                'similarity' => round((float) $candidate['similarity'], 2),
                'token_overlap' => round((float) $candidate['token_overlap'], 2),
            ];
        }, array_slice($candidates, 0, 3));

        $accepted = $best['score'] >= 78.0 && $best['similarity'] >= 70.0 && $gap >= 8.0;
        $autofillSafe = $best['score'] >= 92.0 && $best['similarity'] >= 90.0 && $gap >= 12.0;

        if (!$accepted) {
            return [
                'suggested_id_produk' => null,
                'suggested_nama_produk' => null,
                'score' => round((float) $best['score'], 2),
                'similarity' => round((float) $best['similarity'], 2),
                'token_overlap' => round((float) $best['token_overlap'], 2),
                'gap' => round($gap, 2),
                'autofill_safe' => false,
                'top_candidates' => $topCandidates,
                'reason' => 'needs_review',
            ];
        }

        return [
            'suggested_id_produk' => (int) $best['row']['id_produk'],
            'suggested_nama_produk' => (string) $best['row']['nama_produk'],
            'score' => round((float) $best['score'], 2),
            'similarity' => round((float) $best['similarity'], 2),
            'token_overlap' => round((float) $best['token_overlap'], 2),
            'gap' => round($gap, 2),
            'autofill_safe' => $autofillSafe,
            'top_candidates' => $topCandidates,
            'reason' => $autofillSafe ? 'high_confidence_similarity' : 'needs_review',
        ];
    }

    private function extractMeasureTokens(string $value): array
    {
        $text = mb_strtolower($value);
        preg_match_all('/\b[0-9]+(?:[\.,][0-9]+)?\s*(?:mg|ml|gr|g|mcg|%)\b/u', $text, $matches);
        $tokens = $matches[0] ?? [];
        $tokens = array_map(function ($item) {
            $item = str_replace(',', '.', (string) $item);
            $item = preg_replace('/\s+/', '', $item);
            return trim((string) $item);
        }, $tokens);

        $tokens = array_values(array_unique(array_filter($tokens, function ($item) {
            return $item !== '';
        })));
        sort($tokens);

        return $tokens;
    }

    private function extractPackCountTokens(string $value): array
    {
        $text = mb_strtolower($value);
        $tokens = [];

        preg_match_all('/\b([0-9]{1,4})\s*\'?s\b/u', $text, $apostropheMatches);
        foreach (($apostropheMatches[1] ?? []) as $packCount) {
            $tokens[] = (string) ((int) $packCount);
        }

        preg_match_all('/\b([0-9]{1,4})\s*(?:tab|tabs|tablet|kap|kapl|kaplet|caps|cap|pcs|pc|strip|sach|sachet)\b/u', $text, $unitMatches);
        foreach (($unitMatches[1] ?? []) as $packCount) {
            $tokens[] = (string) ((int) $packCount);
        }

        $tokens = array_values(array_unique(array_filter($tokens, function ($token) {
            return $token !== '' && $token !== '0';
        })));
        sort($tokens, SORT_NUMERIC);

        return $tokens;
    }

    private function preferFilteredRows(array $rows, array $queryMeasures, array $queryPackCounts): array
    {
        $measureFiltered = $this->filterRowsByMeasureTokens($rows, $queryMeasures);
        if (!empty($measureFiltered)) {
            $rows = $measureFiltered;
        }

        $packFiltered = $this->filterRowsByPackCountTokens($rows, $queryPackCounts);
        if (!empty($packFiltered)) {
            $rows = $packFiltered;
        }

        return $rows;
    }

    private function filterRowsByMeasureTokens(array $rows, array $queryMeasures): array
    {
        if (empty($rows) || empty($queryMeasures)) {
            return $rows;
        }

        $filtered = [];
        foreach ($rows as $row) {
            $candidateMeasures = $row['measure_tokens'] ?? [];
            $missingMeasure = false;
            foreach ($queryMeasures as $measure) {
                if (!in_array($measure, $candidateMeasures, true)) {
                    $missingMeasure = true;
                    break;
                }
            }

            if (!$missingMeasure) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    private function filterRowsByPackCountTokens(array $rows, array $queryPackCounts): array
    {
        if (empty($rows) || empty($queryPackCounts)) {
            return $rows;
        }

        $filtered = [];
        foreach ($rows as $row) {
            $candidatePackCounts = $row['pack_count_tokens'] ?? [];
            $missingPack = false;
            foreach ($queryPackCounts as $packCount) {
                if (!in_array($packCount, $candidatePackCounts, true)) {
                    $missingPack = true;
                    break;
                }
            }

            if (!$missingPack) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    private function resolveOutputPath(string $path): string
    {
        if ($path === '') {
            return base_path('post_cutoff_purchase_reinput_report_' . Carbon::now()->format('Ymd_His') . '.json');
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:\\\\/', $path) === 1 || strpos($path, '/') === 0;
    }

    private function pathsEqual(string $left, string $right): bool
    {
        $normalize = function (string $path): string {
            $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            $path = rtrim($path, "\\/");
            if (preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
                return strtolower($path);
            }
            return $path;
        };

        return $normalize($left) === $normalize($right);
    }

    private function normalizeProductName(string $value): string
    {
        $value = mb_strtolower($value);
        $value = str_replace(['&', "'", '"', '`'], ' ', $value);
        $value = preg_replace('/\([^\)]*\)/', ' ', $value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        $value = trim((string) $value);

        $replace = [
            'caps' => 'kap',
            'capl' => 'kapl',
            'kaplet' => 'kapl',
            'tablet' => 'tab',
            'syrup' => 'syr',
            'suspensi' => 'susp',
            'suspension' => 'susp',
        ];

        foreach ($replace as $from => $to) {
            $value = preg_replace('/\b' . preg_quote($from, '/') . '\b/u', $to, $value);
        }

        $value = preg_replace('/\s+/', ' ', $value);

        return trim((string) $value);
    }

    private function extractSignificantProductTokens(string $normalizedProductName): array
    {
        $rawTokens = array_values(array_filter(explode(' ', $normalizedProductName), function ($token) {
            return trim((string) $token) !== '';
        }));

        $stopWords = [
            'mg', 'ml', 'gr', 'g', 'mcg', 'tab', 'tabs', 'kap', 'kapl', 'kaplet', 'caps',
            'syr', 'susp', 'cr', 'cream', 'gel', 'drop', 'drops', 'liq', 'liquid', 'inj',
            'box', 'botol', 'btl', 'tube', 'dus', 'strip', 'slop', 'lbr', 'sach', 'sachet',
            'new', 'adult', 'anak', 'baby', 'small', 'strong', 'plus', 'forte', 'exp',
            'od', 'dx', 'hj', 'kng', 'ifi', 'nova', 'hexp', 'gdn', 'libi', 'dz', 'dd',
            'cc', 'ee', 'ff', 'fr', 'php', 'kc', 'straw', 'orange', 'jeruk', 'madu',
            's', 'x', 'ds', 'isi', 'no', 'nomor',
        ];

        $result = [];
        foreach ($rawTokens as $token) {
            if (mb_strlen($token) < 2) {
                continue;
            }
            $token = trim((string) $token);
            if ($token === '' || in_array($token, $stopWords, true)) {
                continue;
            }

            if (preg_match('/^[0-9]+$/', $token)) {
                continue;
            }

            $result[] = $token;
        }

        $result = array_values(array_unique($result));
        sort($result);

        return $result;
    }

    private function buildTokenKey(array $tokens): string
    {
        $clean = array_values(array_filter(array_map(function ($token) {
            return trim((string) $token);
        }, $tokens), function ($token) {
            return $token !== '';
        }));

        if (empty($clean)) {
            return '';
        }

        sort($clean);
        return implode(' ', $clean);
    }

    private function normalizeCompanyName(string $value): string
    {
        $value = mb_strtolower($value);
        $value = str_replace(['&', '.', ',', '-', '/', '"', "'"], ' ', $value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        $value = trim((string) $value);

        $value = preg_replace('/\b(pt|apt|apotek|apotik|grup)\b/u', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        return trim((string) $value);
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return trim((string) $value);
    }

    private function normalizeTitleCase(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));
        return $value === '' ? '' : $value;
    }
}
