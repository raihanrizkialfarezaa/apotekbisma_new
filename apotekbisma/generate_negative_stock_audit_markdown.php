<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$options = getopt('', ['cutoff::', 'until::', 'csv::', 'output::']);

$cutoff = (string) ($options['cutoff'] ?? config('stock.cutoff_datetime', '2025-12-31 23:59:59'));
$until = isset($options['until'])
    ? Carbon::parse((string) $options['until'])->format('Y-m-d H:i:s')
    : Carbon::now()->format('Y-m-d H:i:s');
$baselineCsvOption = $options['csv'] ?? config('stock.baseline_csv');
$baselineCsv = is_string($baselineCsvOption) ? $baselineCsvOption : config('stock.baseline_csv');
$baselineCsv = preg_match('/^[A-Za-z]:\\|^\\\\|^\//', $baselineCsv)
    ? $baselineCsv
    : base_path($baselineCsv);
$outputTimestamp = Carbon::parse($until)->format('Ymd_His');
$outputPath = isset($options['output'])
    ? (string) $options['output']
    : base_path('AUDIT_PRODUK_MINUS_PASCA_BASELINE_' . $outputTimestamp . '.md');
$csvDelimiter = detectCsvDelimiter($baselineCsv);

$baseline = [];
$handle = fopen($baselineCsv, 'r');
if ($handle === false) {
    throw new RuntimeException('Tidak dapat membuka baseline CSV: ' . $baselineCsv);
}

fgetcsv($handle, 0, $csvDelimiter);
while (($row = fgetcsv($handle, 0, $csvDelimiter)) !== false) {
    $productId = (int) normalizeCsvCell($row[0] ?? '0');
    if ($productId <= 0) {
        continue;
    }

    $baseline[$productId] = [
        'nama_produk' => trim(normalizeCsvCell($row[1] ?? '')),
        'stok' => (int) normalizeCsvCell($row[2] ?? '0'),
    ];
}
fclose($handle);

$excludedPatterns = array_map(static function ($pattern) {
    return mb_strtolower((string) $pattern);
}, config('stock.excluded_manual_keterangan_patterns', []));

$results = [];

foreach (array_keys($baseline) as $productId) {
    $exists = DB::table('produk')->where('id_produk', $productId)->exists();
    if (!$exists) {
        continue;
    }

    $purchaseRows = DB::table('pembelian_detail as pd')
        ->join('pembelian as p', 'pd.id_pembelian', '=', 'p.id_pembelian')
        ->where('pd.id_produk', $productId)
        ->whereRaw('COALESCE(p.waktu, p.created_at) > ?', [$cutoff])
        ->whereRaw('COALESCE(p.waktu, p.created_at) <= ?', [$until])
        ->orderByRaw('COALESCE(p.waktu, p.created_at) asc')
        ->get([
            'pd.id_pembelian',
            'pd.jumlah',
            'p.no_faktur',
            'p.total_harga',
            'p.bayar',
            DB::raw('COALESCE(p.waktu, p.created_at) as waktu'),
        ]);

    $validPurchaseRows = [];
    $invalidPurchaseRows = [];
    foreach ($purchaseRows as $row) {
        $isValid = ((int) $row->jumlah > 0)
            && $row->no_faktur !== null
            && $row->no_faktur !== ''
            && $row->no_faktur !== 'o'
            && (float) $row->total_harga > 0
            && (float) $row->bayar > 0;

        if ($isValid) {
            $validPurchaseRows[] = $row;
        } else {
            $invalidPurchaseRows[] = $row;
        }
    }

    $saleRows = DB::table('penjualan_detail as pd')
        ->join('penjualan as p', 'pd.id_penjualan', '=', 'p.id_penjualan')
        ->where('pd.id_produk', $productId)
        ->where('pd.jumlah', '>', 0)
        ->where('p.total_item', '>', 0)
        ->where('p.total_harga', '>', 0)
        ->where('p.bayar', '>', 0)
        ->where('p.diterima', '>', 0)
        ->whereRaw('COALESCE(p.waktu, p.created_at) > ?', [$cutoff])
        ->whereRaw('COALESCE(p.waktu, p.created_at) <= ?', [$until])
        ->orderByRaw('COALESCE(p.waktu, p.created_at) asc')
        ->get([
            'pd.id_penjualan',
            'pd.jumlah',
            DB::raw('COALESCE(p.waktu, p.created_at) as waktu'),
        ]);

    $manualRows = DB::table('rekaman_stoks')
        ->where('id_produk', $productId)
        ->whereNull('id_pembelian')
        ->whereNull('id_penjualan')
        ->where('waktu', '>', $cutoff)
        ->where('waktu', '<=', $until)
        ->orderBy('waktu', 'asc')
        ->get(['stok_masuk', 'stok_keluar', 'waktu', 'keterangan']);

    $positiveManual = 0;
    $negativeManual = 0;
    $events = [];

    foreach ($validPurchaseRows as $row) {
        $events[] = [
            'waktu' => (string) $row->waktu,
            'type_priority' => 10,
            'qty' => (int) $row->jumlah,
        ];
    }

    foreach ($saleRows as $row) {
        $events[] = [
            'waktu' => (string) $row->waktu,
            'type_priority' => 20,
            'qty' => -((int) $row->jumlah),
        ];
    }

    foreach ($manualRows as $row) {
        $needle = mb_strtolower(trim((string) ($row->keterangan ?? '')));
        $skip = false;
        foreach ($excludedPatterns as $pattern) {
            if ($pattern !== '' && str_contains($needle, $pattern)) {
                $skip = true;
                break;
            }
        }

        if ($skip) {
            continue;
        }

        $stokMasuk = max(0, (int) ($row->stok_masuk ?? 0));
        $stokKeluar = max(0, (int) ($row->stok_keluar ?? 0));
        if ($stokMasuk === 0 && $stokKeluar === 0) {
            continue;
        }

        $positiveManual += $stokMasuk;
        $negativeManual += $stokKeluar;
        $events[] = [
            'waktu' => (string) $row->waktu,
            'type_priority' => 30,
            'qty' => $stokMasuk - $stokKeluar,
        ];
    }

    usort($events, static function ($a, $b) {
        $timeCmp = strcmp($a['waktu'], $b['waktu']);
        if ($timeCmp !== 0) {
            return $timeCmp;
        }

        return $a['type_priority'] <=> $b['type_priority'];
    });

    $running = (int) $baseline[$productId]['stok'];
    $negativeEventCount = 0;
    $firstNegativeAt = null;

    foreach ($events as $event) {
        $running += (int) $event['qty'];
        if ($running < 0) {
            $negativeEventCount++;
            if ($firstNegativeAt === null) {
                $firstNegativeAt = $event['waktu'];
            }
        }
    }

    if ($negativeEventCount === 0) {
        continue;
    }

    $validPurchaseQty = array_sum(array_map(static function ($row) {
        return (int) $row->jumlah;
    }, $validPurchaseRows));
    $invalidPurchaseQty = array_sum(array_map(static function ($row) {
        return max(0, (int) $row->jumlah);
    }, $invalidPurchaseRows));
    $salesQty = (int) $saleRows->sum('jumlah');
    $firstPurchaseAt = count($validPurchaseRows) > 0 ? (string) $validPurchaseRows[0]->waktu : null;
    $lastPurchaseAt = count($validPurchaseRows) > 0 ? (string) $validPurchaseRows[count($validPurchaseRows) - 1]->waktu : null;

    $soldBeforeFirstPurchase = 0;
    if ($firstPurchaseAt !== null) {
        foreach ($saleRows as $saleRow) {
            if ((string) $saleRow->waktu < $firstPurchaseAt) {
                $soldBeforeFirstPurchase += (int) $saleRow->jumlah;
            }
        }
    } else {
        $soldBeforeFirstPurchase = $salesQty;
    }

    $finalGap = (int) $baseline[$productId]['stok'] + $validPurchaseQty + $positiveManual - $salesQty - $negativeManual;
    $classification = $finalGap < 0 ? 'Shortage total' : 'Minus sementara';
    $auditFocus = $finalGap < 0
        ? 'Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat'
        : 'Cek urutan atau tanggal input pembelian versus penjualan';

    $results[] = [
        'id_produk' => $productId,
        'nama_produk' => $baseline[$productId]['nama_produk'],
        'baseline_stock' => (int) $baseline[$productId]['stok'],
        'valid_purchase_rows' => count($validPurchaseRows),
        'valid_purchase_qty' => $validPurchaseQty,
        'invalid_purchase_rows' => count($invalidPurchaseRows),
        'invalid_purchase_qty' => $invalidPurchaseQty,
        'sales_rows' => $saleRows->count(),
        'sales_qty' => $salesQty,
        'positive_manual' => $positiveManual,
        'negative_manual' => $negativeManual,
        'final_gap' => $finalGap,
        'negative_event_count' => $negativeEventCount,
        'first_negative_at' => $firstNegativeAt,
        'first_purchase_at' => $firstPurchaseAt,
        'last_purchase_at' => $lastPurchaseAt,
        'sold_before_first_purchase' => $soldBeforeFirstPurchase,
        'classification' => $classification,
        'audit_focus' => $auditFocus,
    ];
}

usort($results, static function ($a, $b) {
    if ($a['classification'] !== $b['classification']) {
        return strcmp($a['classification'], $b['classification']);
    }
    if ($a['final_gap'] !== $b['final_gap']) {
        return $a['final_gap'] <=> $b['final_gap'];
    }

    return $a['id_produk'] <=> $b['id_produk'];
});

$shortage = array_values(array_filter($results, static function ($row) {
    return $row['classification'] === 'Shortage total';
}));
$temporary = array_values(array_filter($results, static function ($row) {
    return $row['classification'] === 'Minus sementara';
}));

$lines = [];
$lines[] = '# Audit Produk Minus Pasca Baseline';
$lines[] = '';
$lines[] = 'Tanggal analisis: ' . Carbon::now()->format('Y-m-d H:i:s');
$lines[] = 'Cutoff baseline: ' . $cutoff;
$lines[] = 'Until event: ' . $until;
$lines[] = 'Source of truth awal: ' . $baselineCsv;
$lines[] = 'Delimiter CSV terdeteksi: ' . $csvDelimiter;
$lines[] = 'Cakupan: hanya produk yang match antara database dan CSV baseline.';
$lines[] = '';
$lines[] = '## Ringkasan';
$lines[] = '';
$lines[] = '- Total produk dengan rantai stok sempat minus: ' . count($results);
$lines[] = '- Produk shortage total: ' . count($shortage);
$lines[] = '- Produk minus sementara: ' . count($temporary);
$lines[] = '- Catatan penting: pembelian invalid terdeteksi 0 pada cohort ini; pola dominan adalah pembelian belum tercatat, terlambat dicatat, atau total supply memang kalah dari penjualan.';
$lines[] = '';
$lines[] = 'Arti kolom:';
$lines[] = '- Beli Valid Qty: total pembelian yang lolos filter rebuild.';
$lines[] = '- Jual Qty: total penjualan valid pasca baseline.';
$lines[] = '- +Manual / -Manual: penyesuaian manual non-synthetic pasca baseline.';
$lines[] = '- Gap Final: baseline + pembelian valid + manual masuk - penjualan - manual keluar. Nilai negatif berarti total supply masih kurang.';
$lines[] = '- Sold < Beli1: total penjualan yang terjadi sebelum pembelian valid pertama tercatat.';
$lines[] = '';
$lines[] = '## Prioritas Tinggi: Shortage Total';
$lines[] = '';
$lines[] = '| No | ID Produk | Nama Produk | Baseline | Beli Valid Rows | Beli Valid Qty | Invalid Rows | Jual Rows | Jual Qty | +Manual | -Manual | Gap Final | Neg Event | Minus Pertama | Beli 1 | Beli Terakhir | Sold < Beli1 | Fokus Audit |';
$lines[] = '| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |';

foreach ($shortage as $index => $row) {
    $lines[] = sprintf(
        '| %d | %d | %s | %d | %d | %d | %d | %d | %d | %d | %d | %d | %d | %s | %s | %s | %d | %s |',
        $index + 1,
        $row['id_produk'],
        str_replace('|', '/', $row['nama_produk']),
        $row['baseline_stock'],
        $row['valid_purchase_rows'],
        $row['valid_purchase_qty'],
        $row['invalid_purchase_rows'],
        $row['sales_rows'],
        $row['sales_qty'],
        $row['positive_manual'],
        $row['negative_manual'],
        $row['final_gap'],
        $row['negative_event_count'],
        $row['first_negative_at'] ?? '-',
        $row['first_purchase_at'] ?? '-',
        $row['last_purchase_at'] ?? '-',
        $row['sold_before_first_purchase'],
        str_replace('|', '/', $row['audit_focus'])
    );
}

$lines[] = '';
$lines[] = '## Prioritas Sedang: Minus Sementara';
$lines[] = '';
$lines[] = '| No | ID Produk | Nama Produk | Baseline | Beli Valid Rows | Beli Valid Qty | Invalid Rows | Jual Rows | Jual Qty | Gap Final | Neg Event | Minus Pertama | Beli 1 | Beli Terakhir | Sold < Beli1 | Fokus Audit |';
$lines[] = '| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |';

foreach ($temporary as $index => $row) {
    $lines[] = sprintf(
        '| %d | %d | %s | %d | %d | %d | %d | %d | %d | %d | %d | %s | %s | %s | %d | %s |',
        $index + 1,
        $row['id_produk'],
        str_replace('|', '/', $row['nama_produk']),
        $row['baseline_stock'],
        $row['valid_purchase_rows'],
        $row['valid_purchase_qty'],
        $row['invalid_purchase_rows'],
        $row['sales_rows'],
        $row['sales_qty'],
        $row['final_gap'],
        $row['negative_event_count'],
        $row['first_negative_at'] ?? '-',
        $row['first_purchase_at'] ?? '-',
        $row['last_purchase_at'] ?? '-',
        $row['sold_before_first_purchase'],
        str_replace('|', '/', $row['audit_focus'])
    );
}

file_put_contents($outputPath, implode(PHP_EOL, $lines) . PHP_EOL);

echo 'File dibuat: ' . $outputPath . PHP_EOL;

function detectCsvDelimiter(string $path): string
{
    $sample = file_get_contents($path, false, null, 0, 2048);
    if ($sample === false) {
        throw new RuntimeException('Tidak dapat membaca sampel CSV: ' . $path);
    }

    $sample = preg_replace('/^\xEF\xBB\xBF/', '', $sample);
    $firstLine = strtok($sample, "\r\n") ?: '';

    $scores = [
        ';' => substr_count($firstLine, ';'),
        ',' => substr_count($firstLine, ','),
        "\t" => substr_count($firstLine, "\t"),
    ];

    arsort($scores);
    $delimiter = array_key_first($scores);

    return is_string($delimiter) ? $delimiter : ';';
}

function normalizeCsvCell($value): string
{
    return trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $value));
}