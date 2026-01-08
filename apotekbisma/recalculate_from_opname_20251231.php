<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

ini_set('max_execution_time', 600);
ini_set('memory_limit', '512M');

class StockRecalculationFromOpname
{
    private $cutoffDate = '2025-12-31';
    private $cutoffDateTime = '2025-12-31 23:59:59';
    private $csvPath;
    private $opnameData = [];
    private $transactionsAfterCutoff = [];
    private $results = [
        'products_processed' => 0,
        'products_updated' => 0,
        'records_created' => 0,
        'records_updated' => 0,
        'records_deleted' => 0,
        'errors' => [],
        'details' => []
    ];

    public function __construct($csvPath)
    {
        $this->csvPath = $csvPath;
    }

    public function execute()
    {
        echo "=================================================================\n";
        echo "STOCK RECALCULATION FROM OPNAME DATA - 31 DECEMBER 2025\n";
        echo "=================================================================\n";
        echo "Cutoff Date: {$this->cutoffDate}\n";
        echo "Script Started: " . date('Y-m-d H:i:s') . "\n\n";

        try {
            DB::beginTransaction();

            $this->loadOpnameData();
            $this->identifyTransactionsAfterCutoff();
            $this->processAllProducts();

            DB::commit();

            $this->printSummary();
            $this->saveResultsToFile();

            echo "\n[SUCCESS] Stock recalculation completed successfully!\n";

        } catch (\Exception $e) {
            DB::rollBack();
            $this->results['errors'][] = "FATAL ERROR: " . $e->getMessage();
            echo "\n[ERROR] " . $e->getMessage() . "\n";
            echo "Transaction rolled back.\n";
            $this->saveResultsToFile();
        }

        return $this->results;
    }

    private function loadOpnameData()
    {
        echo "Step 1: Loading Stock Opname Data from CSV...\n";

        if (!file_exists($this->csvPath)) {
            throw new \Exception("CSV file not found: {$this->csvPath}");
        }

        $handle = fopen($this->csvPath, 'r');
        if (!$handle) {
            throw new \Exception("Failed to open CSV file");
        }

        $headerRow = fgetcsv($handle);
        if (!$headerRow) {
            fclose($handle);
            throw new \Exception("CSV file is empty or malformed");
        }

        $productIdIndex = array_search('produk_id_produk', $headerRow);
        $stockIndex = array_search('produk_stok', $headerRow);

        if ($productIdIndex === false || $stockIndex === false) {
            fclose($handle);
            throw new \Exception("Required columns not found in CSV: produk_id_produk, produk_stok");
        }

        $count = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (isset($row[$productIdIndex]) && $row[$productIdIndex] !== '') {
                $productId = intval($row[$productIdIndex]);
                $stock = intval($row[$stockIndex]);
                $this->opnameData[$productId] = $stock;
                $count++;
            }
        }

        fclose($handle);

        echo "   - Loaded {$count} products from CSV\n";
        echo "   - Sample data: Product ID 2 => Stock " . ($this->opnameData[2] ?? 'N/A') . "\n\n";
    }

    private function identifyTransactionsAfterCutoff()
    {
        echo "Step 2: Identifying Transactions After Cutoff Date...\n";

        $salesAfterCutoff = DB::table('penjualan_detail as pd')
            ->join('penjualan as p', 'pd.id_penjualan', '=', 'p.id_penjualan')
            ->where('p.created_at', '>', $this->cutoffDateTime)
            ->select('pd.id_produk', 'pd.id_penjualan_detail', 'pd.id_penjualan', 'pd.jumlah', 'p.created_at as waktu')
            ->orderBy('p.created_at', 'asc')
            ->get();

        echo "   - Sales after cutoff: " . count($salesAfterCutoff) . " items\n";

        $purchasesAfterCutoff = DB::table('pembelian_detail as pd')
            ->join('pembelian as p', 'pd.id_pembelian', '=', 'p.id_pembelian')
            ->where('p.created_at', '>', $this->cutoffDateTime)
            ->select('pd.id_produk', 'pd.id_pembelian_detail', 'pd.id_pembelian', 'pd.jumlah', 'p.created_at as waktu')
            ->orderBy('p.created_at', 'asc')
            ->get();

        echo "   - Purchases after cutoff: " . count($purchasesAfterCutoff) . " items\n";

        foreach ($salesAfterCutoff as $sale) {
            if (!isset($this->transactionsAfterCutoff[$sale->id_produk])) {
                $this->transactionsAfterCutoff[$sale->id_produk] = ['sales' => [], 'purchases' => []];
            }
            $this->transactionsAfterCutoff[$sale->id_produk]['sales'][] = [
                'id' => $sale->id_penjualan_detail,
                'id_penjualan' => $sale->id_penjualan,
                'jumlah' => $sale->jumlah,
                'waktu' => $sale->waktu
            ];
        }

        foreach ($purchasesAfterCutoff as $purchase) {
            if (!isset($this->transactionsAfterCutoff[$purchase->id_produk])) {
                $this->transactionsAfterCutoff[$purchase->id_produk] = ['sales' => [], 'purchases' => []];
            }
            $this->transactionsAfterCutoff[$purchase->id_produk]['purchases'][] = [
                'id' => $purchase->id_pembelian_detail,
                'id_pembelian' => $purchase->id_pembelian,
                'jumlah' => $purchase->jumlah,
                'waktu' => $purchase->waktu
            ];
        }

        $affectedProducts = count($this->transactionsAfterCutoff);
        echo "   - Products with transactions after cutoff: {$affectedProducts}\n\n";
    }

    private function processAllProducts()
    {
        echo "Step 3: Processing All Products...\n";

        $allProducts = DB::table('produk')->get();
        $total = count($allProducts);
        $processed = 0;

        foreach ($allProducts as $product) {
            $productId = $product->id_produk;
            $processed++;

            if ($processed % 100 === 0) {
                echo "   Processing... {$processed}/{$total}\n";
            }

            $this->processProduct($productId, $product->nama_produk);
            $this->results['products_processed']++;
        }

        echo "   - Processed {$processed} products\n\n";
    }

    private function processProduct($productId, $productName)
    {
        $opnameStock = $this->opnameData[$productId] ?? null;

        if ($opnameStock === null) {
            $currentStock = DB::table('produk')->where('id_produk', $productId)->value('stok');
            $opnameStock = intval($currentStock);
        }

        $this->deleteRecordsAfterCutoff($productId);
        $this->ensureOpnameRecord($productId, $opnameStock);
        $this->rebuildRecordsAfterCutoff($productId, $opnameStock);
        $this->recalculateAllRecords($productId);
        $this->updateProductStock($productId);
    }

    private function deleteRecordsAfterCutoff($productId)
    {
        $deleted = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('waktu', '>', $this->cutoffDateTime)
            ->delete();

        $this->results['records_deleted'] += $deleted;
    }

    private function ensureOpnameRecord($productId, $opnameStock)
    {
        $opnameRecord = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->where('keterangan', 'LIKE', '%SO%')
            ->whereDate('waktu', $this->cutoffDate)
            ->first();

        if ($opnameRecord) {
            if (intval($opnameRecord->stok_sisa) !== $opnameStock) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $opnameRecord->id_rekaman_stok)
                    ->update([
                        'stok_sisa' => $opnameStock,
                        'updated_at' => now()
                    ]);
                $this->results['records_updated']++;
            }
        }
    }

    private function rebuildRecordsAfterCutoff($productId, $opnameStock)
    {
        if (!isset($this->transactionsAfterCutoff[$productId])) {
            return;
        }

        $transactions = $this->transactionsAfterCutoff[$productId];
        $allTransactions = [];

        foreach ($transactions['sales'] as $sale) {
            $allTransactions[] = [
                'type' => 'sale',
                'id_penjualan' => $sale['id_penjualan'],
                'id_pembelian' => null,
                'jumlah' => $sale['jumlah'],
                'waktu' => $sale['waktu']
            ];
        }

        foreach ($transactions['purchases'] as $purchase) {
            $allTransactions[] = [
                'type' => 'purchase',
                'id_penjualan' => null,
                'id_pembelian' => $purchase['id_pembelian'],
                'jumlah' => $purchase['jumlah'],
                'waktu' => $purchase['waktu']
            ];
        }

        usort($allTransactions, function($a, $b) {
            return strcmp($a['waktu'], $b['waktu']);
        });

        $aggregated = [];
        foreach ($allTransactions as $tx) {
            $key = $tx['type'] . '_' . ($tx['id_penjualan'] ?? $tx['id_pembelian']) . '_' . $tx['waktu'];
            if (!isset($aggregated[$key])) {
                $aggregated[$key] = $tx;
            } else {
                $aggregated[$key]['jumlah'] += $tx['jumlah'];
            }
        }

        $runningStock = $opnameStock;
        foreach ($aggregated as $tx) {
            $stokAwal = $runningStock;
            $stokMasuk = ($tx['type'] === 'purchase') ? $tx['jumlah'] : 0;
            $stokKeluar = ($tx['type'] === 'sale') ? $tx['jumlah'] : 0;
            $stokSisa = $stokAwal + $stokMasuk - $stokKeluar;

            if ($stokSisa < 0) $stokSisa = 0;

            $existingRecord = DB::table('rekaman_stoks')
                ->where('id_produk', $productId)
                ->where('id_penjualan', $tx['id_penjualan'] ?: null)
                ->where('id_pembelian', $tx['id_pembelian'] ?: null)
                ->first();

            if (!$existingRecord) {
                $keterangan = ($tx['type'] === 'sale') ? 'Penjualan' : 'Pembelian';
                
                DB::table('rekaman_stoks')->insert([
                    'id_produk' => $productId,
                    'id_penjualan' => $tx['id_penjualan'],
                    'id_pembelian' => $tx['id_pembelian'],
                    'waktu' => $tx['waktu'],
                    'stok_awal' => $stokAwal,
                    'stok_masuk' => $stokMasuk,
                    'stok_keluar' => $stokKeluar,
                    'stok_sisa' => $stokSisa,
                    'keterangan' => $keterangan,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                $this->results['records_created']++;
            }

            $runningStock = $stokSisa;
        }
    }

    private function recalculateAllRecords($productId)
    {
        $records = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->orderBy('waktu', 'asc')
            ->orderBy('created_at', 'asc')
            ->orderBy('id_rekaman_stok', 'asc')
            ->get();

        if ($records->isEmpty()) {
            return;
        }

        $firstRecord = $records->first();
        $runningStock = intval($firstRecord->stok_awal);

        foreach ($records as $record) {
            $expectedStokAwal = $runningStock;

            $stokMasuk = intval($record->stok_masuk);
            $stokKeluar = intval($record->stok_keluar);
            $calculatedSisa = $expectedStokAwal + $stokMasuk - $stokKeluar;
            if ($calculatedSisa < 0) $calculatedSisa = 0;

            $needsUpdate = false;
            $updateData = ['updated_at' => now()];

            if ($runningStock !== intval($firstRecord->stok_awal) && intval($record->stok_awal) !== $expectedStokAwal) {
                if ($record->id_rekaman_stok !== $firstRecord->id_rekaman_stok) {
                    $updateData['stok_awal'] = $expectedStokAwal;
                    $needsUpdate = true;
                }
            }

            if (intval($record->stok_sisa) !== $calculatedSisa) {
                $updateData['stok_sisa'] = $calculatedSisa;
                $needsUpdate = true;
            }

            if ($needsUpdate) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $record->id_rekaman_stok)
                    ->update($updateData);
                $this->results['records_updated']++;
            }

            $runningStock = $calculatedSisa;
        }
    }

    private function updateProductStock($productId)
    {
        $lastRecord = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->orderBy('waktu', 'desc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();

        if ($lastRecord) {
            $calculatedStock = max(0, intval($lastRecord->stok_sisa));
        } else {
            $calculatedStock = $this->opnameData[$productId] ?? 0;
        }

        $currentStock = DB::table('produk')->where('id_produk', $productId)->value('stok');

        if (intval($currentStock) !== $calculatedStock) {
            DB::table('produk')
                ->where('id_produk', $productId)
                ->update([
                    'stok' => $calculatedStock,
                    'updated_at' => now()
                ]);
            $this->results['products_updated']++;

            $this->results['details'][] = [
                'product_id' => $productId,
                'old_stock' => intval($currentStock),
                'new_stock' => $calculatedStock
            ];
        }
    }

    private function printSummary()
    {
        echo "\n=================================================================\n";
        echo "SUMMARY\n";
        echo "=================================================================\n";
        echo "Products Processed   : {$this->results['products_processed']}\n";
        echo "Products Updated     : {$this->results['products_updated']}\n";
        echo "Records Created      : {$this->results['records_created']}\n";
        echo "Records Updated      : {$this->results['records_updated']}\n";
        echo "Records Deleted      : {$this->results['records_deleted']}\n";
        echo "Errors               : " . count($this->results['errors']) . "\n";

        if (count($this->results['details']) > 0 && count($this->results['details']) <= 20) {
            echo "\nStock Changes:\n";
            foreach ($this->results['details'] as $detail) {
                $diff = $detail['new_stock'] - $detail['old_stock'];
                $sign = $diff >= 0 ? '+' : '';
                echo "  Product {$detail['product_id']}: {$detail['old_stock']} => {$detail['new_stock']} ({$sign}{$diff})\n";
            }
        } elseif (count($this->results['details']) > 20) {
            echo "\nStock Changes (showing first 20 of " . count($this->results['details']) . "):\n";
            for ($i = 0; $i < 20; $i++) {
                $detail = $this->results['details'][$i];
                $diff = $detail['new_stock'] - $detail['old_stock'];
                $sign = $diff >= 0 ? '+' : '';
                echo "  Product {$detail['product_id']}: {$detail['old_stock']} => {$detail['new_stock']} ({$sign}{$diff})\n";
            }
        }

        if (count($this->results['errors']) > 0) {
            echo "\nErrors:\n";
            foreach ($this->results['errors'] as $error) {
                echo "  - {$error}\n";
            }
        }
    }

    private function saveResultsToFile()
    {
        $filename = __DIR__ . '/opname_recalc_result_' . date('Y-m-d_His') . '.txt';
        
        $content = "STOCK RECALCULATION FROM OPNAME - RESULTS\n";
        $content .= "==========================================\n";
        $content .= "Executed at: " . date('Y-m-d H:i:s') . "\n";
        $content .= "Cutoff Date: {$this->cutoffDate}\n\n";
        $content .= "Products Processed: {$this->results['products_processed']}\n";
        $content .= "Products Updated: {$this->results['products_updated']}\n";
        $content .= "Records Created: {$this->results['records_created']}\n";
        $content .= "Records Updated: {$this->results['records_updated']}\n";
        $content .= "Records Deleted: {$this->results['records_deleted']}\n";
        $content .= "Errors: " . count($this->results['errors']) . "\n\n";

        if (count($this->results['details']) > 0) {
            $content .= "STOCK CHANGES:\n";
            foreach ($this->results['details'] as $detail) {
                $diff = $detail['new_stock'] - $detail['old_stock'];
                $sign = $diff >= 0 ? '+' : '';
                $content .= "Product {$detail['product_id']}: {$detail['old_stock']} => {$detail['new_stock']} ({$sign}{$diff})\n";
            }
        }

        if (count($this->results['errors']) > 0) {
            $content .= "\nERRORS:\n";
            foreach ($this->results['errors'] as $error) {
                $content .= "- {$error}\n";
            }
        }

        file_put_contents($filename, $content);
        echo "\nResults saved to: {$filename}\n";
    }
}

$csvPath = __DIR__ . '/kartu_stok_20251231.csv';
$recalculator = new StockRecalculationFromOpname($csvPath);
$recalculator->execute();
