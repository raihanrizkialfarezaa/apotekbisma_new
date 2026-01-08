<?php

/**
 * SKRIP PERBAIKAN STOK & VERIFIKASI - ALL IN ONE (VERSI FINAL CSV BARU)
 * Sumber Data: REKAMAN STOK FINAL 31 DESEMBER 2025.csv
 */

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Konfigurasi Server
ini_set('max_execution_time', 1200); 
ini_set('memory_limit', '1024M');

class StockFixerFinalV2
{
    private $cutoffDate = '2025-12-31';
    private $cutoffDateTime = '2025-12-31 23:59:59';
    private $csvPath;
    private $opnameData = [];
    
    // Stats
    private $stats = [
        'processed' => 0,
        'updated' => 0,
        'rekaman_deleted' => 0,
        'rekaman_created' => 0,
    ];

    public function __construct($csvFilename)
    {
        $this->csvPath = __DIR__ . '/' . $csvFilename;
    }

    public function run()
    {
        $startTime = microtime(true);
        echo "=================================================================\n";
        echo "   STOCK RECOVERY & VERIFICATION (NEW FINAL CSV SOURCE)\n";
        echo "=================================================================\n";
        echo "Start Time : " . date('Y-m-d H:i:s') . "\n";
        echo "Source CSV : REKAMAN STOK FINAL 31 DESEMBER 2025.csv\n\n";

        if (!file_exists($this->csvPath)) {
            die("[FATAL] File CSV tidak ditemukan: {$this->csvPath}\n");
        }

        try {
            $this->loadCsvData();

            DB::beginTransaction();
            echo "STEP 2: Sinkronisasi Database...\n";
            $this->syncDatabase();
            DB::commit(); 
            echo "   [OK] Sinkronisasi selesai.\n\n";

            echo "STEP 3: Verifikasi Data...\n";
            $verificationResult = $this->verifyData();

            $duration = round(microtime(true) - $startTime, 2);
            $this->printSummary($duration, $verificationResult);

        } catch (\Exception $e) {
            DB::rollBack();
            echo "\n[ERROR] " . $e->getMessage() . "\n";
        }
    }

    private function loadCsvData()
    {
        echo "STEP 1: Membaca CSV...\n";
        $handle = fopen($this->csvPath, 'r');
        $header = fgetcsv($handle);
        
        $idIdx = array_search('produk_id_produk', $header);
        $stokIdx = array_search('produk_stok', $header);

        if ($idIdx === false || $stokIdx === false) {
            throw new \Exception("Kolom 'produk_id_produk' atau 'produk_stok' tidak ditemukan.");
        }

        while (($row = fgetcsv($handle)) !== false) {
            if (isset($row[$idIdx]) && $row[$idIdx] !== '') {
                $pid = intval($row[$idIdx]);
                $qty = intval($row[$stokIdx]);
                // Ensure non-negative from source
                $qty = max(0, $qty);
                $this->opnameData[$pid] = $qty;
            }
        }
        fclose($handle);
        echo "   [OK] " . count($this->opnameData) . " produk dimuat.\n\n";
    }

    private function syncDatabase()
    {
        $total = count($this->opnameData);
        $count = 0;

        foreach ($this->opnameData as $productId => $opnameStock) {
            $count++;
            if ($count % 100 === 0) echo "   Processing {$count}/{$total}...\r";

            // 1. Get Transactions After Cutoff
            $salesQty = DB::table('penjualan_detail as pd')
                ->join('penjualan as p', 'pd.id_penjualan', '=', 'p.id_penjualan')
                ->where('pd.id_produk', $productId)
                ->where('p.created_at', '>', $this->cutoffDateTime)
                ->sum('pd.jumlah') ?? 0;

            $purchaseQty = DB::table('pembelian_detail as pd')
                ->join('pembelian as p', 'pd.id_pembelian', '=', 'p.id_pembelian')
                ->where('pd.id_produk', $productId)
                ->where('p.created_at', '>', $this->cutoffDateTime)
                ->sum('pd.jumlah') ?? 0;

            // 2. Calculate Final Stock
            $finalStock = $opnameStock + intval($purchaseQty) - intval($salesQty);
            if ($finalStock < 0) $finalStock = 0;

            // 3. Update Product Master
            $currentStock = DB::table('produk')->where('id_produk', $productId)->value('stok');
            if (intval($currentStock) !== $finalStock) {
                DB::table('produk')
                    ->where('id_produk', $productId)
                    ->update(['stok' => $finalStock, 'updated_at' => now()]);
                $this->stats['updated']++;
            }

            // 4. Clean Old Records After Cutoff
            $deleted = DB::table('rekaman_stoks')
                ->where('id_produk', $productId)
                ->where('waktu', '>', $this->cutoffDateTime)
                ->delete();
            $this->stats['rekaman_deleted'] += $deleted;

            // 5. Rebuild Records (Only if transactions exist)
            if ($salesQty > 0 || $purchaseQty > 0) {
                $this->rebuildStockRecords($productId, $opnameStock);
            }

            $this->stats['processed']++;
        }
        echo "   Processing {$total}/{$total}... DONE.\n";
    }

    private function rebuildStockRecords($productId, $initialStock)
    {
        $transactions = [];

        $purchases = DB::table('pembelian_detail as pd')
            ->join('pembelian as p', 'pd.id_pembelian', '=', 'p.id_pembelian')
            ->where('pd.id_produk', $productId)
            ->where('p.created_at', '>', $this->cutoffDateTime)
            ->select('pd.id_pembelian', DB::raw('SUM(pd.jumlah) as qty'), DB::raw('MIN(p.created_at) as waktu'))
            ->groupBy('pd.id_pembelian')
            ->get();

        $sales = DB::table('penjualan_detail as pd')
            ->join('penjualan as p', 'pd.id_penjualan', '=', 'p.id_penjualan')
            ->where('pd.id_produk', $productId)
            ->where('p.created_at', '>', $this->cutoffDateTime)
            ->select('pd.id_penjualan', DB::raw('SUM(pd.jumlah) as qty'), DB::raw('MIN(p.created_at) as waktu'))
            ->groupBy('pd.id_penjualan')
            ->get();

        foreach ($purchases as $p) $transactions[] = ['type' => 'in', 'id' => $p->id_pembelian, 'qty' => intval($p->qty), 'waktu' => $p->waktu];
        foreach ($sales as $s) $transactions[] = ['type' => 'out', 'id' => $s->id_penjualan, 'qty' => intval($s->qty), 'waktu' => $s->waktu];

        usort($transactions, function($a, $b) {
            $t1 = strtotime($a['waktu']);
            $t2 = strtotime($b['waktu']);
            if ($t1 == $t2) return ($a['type'] === 'in') ? -1 : 1;
            return $t1 - $t2;
        });

        $runningStock = $initialStock;

        foreach ($transactions as $tx) {
            $stokAwal = $runningStock;
            $stokMasuk = ($tx['type'] === 'in') ? $tx['qty'] : 0;
            $stokKeluar = ($tx['type'] === 'out') ? $tx['qty'] : 0;
            $stokSisa = $stokAwal + $stokMasuk - $stokKeluar;
            
            // Keep negative logic consistent as requested (clamp final sisa to 0 for next iteration preference or just allow math)
            // User requested robust logic. We will clamp 0 for consistency with Product Master.
            if ($stokSisa < 0) $stokSisa = 0;

            DB::table('rekaman_stoks')->insert([
                'id_produk' => $productId,
                'id_penjualan' => ($tx['type'] === 'out') ? $tx['id'] : null,
                'id_pembelian' => ($tx['type'] === 'in') ? $tx['id'] : null,
                'waktu' => $tx['waktu'],
                'stok_awal' => $stokAwal,
                'stok_masuk' => $stokMasuk,
                'stok_keluar' => $stokKeluar,
                'stok_sisa' => $stokSisa,
                'keterangan' => ($tx['type'] === 'in') ? 'Pembelian' : 'Penjualan',
                'created_at' => now(),
                'updated_at' => now()
            ]);
            $this->stats['rekaman_created']++;
            
            $runningStock = $stokSisa;
        }
    }

    private function verifyData()
    {
        $passed = 0;
        $issues = [];
        
        foreach ($this->opnameData as $productId => $opnameStock) {
            $sales = DB::table('penjualan_detail as pd')
                ->join('penjualan as p', 'pd.id_penjualan', '=', 'p.id_penjualan')
                ->where('pd.id_produk', $productId)
                ->where('p.created_at', '>', $this->cutoffDateTime)
                ->sum('pd.jumlah') ?? 0;

            $purchases = DB::table('pembelian_detail as pd')
                ->join('pembelian as p', 'pd.id_pembelian', '=', 'p.id_pembelian')
                ->where('pd.id_produk', $productId)
                ->where('p.created_at', '>', $this->cutoffDateTime)
                ->sum('pd.jumlah') ?? 0;

            $expected = $opnameStock + intval($purchases) - intval($sales);
            if ($expected < 0) $expected = 0;

            $actual = DB::table('produk')->where('id_produk', $productId)->value('stok');

            if (intval($actual) === $expected) {
                $passed++;
            } else {
                $issues[] = "Prod #{$productId}: Exp {$expected} vs Act {$actual}";
            }
        }
        return ['passed' => $passed, 'issues' => $issues];
    }

    private function printSummary($duration, $verificationParams)
    {
        $logFile = 'final_sync_log_' . date('dmY_His') . '.txt';
        $content = "LAPORAN FINAL CSV RECOVERY\n";
        $content .= "==========================================\n";
        $content .= "Tanggal     : " . date('d M Y H:i:s') . "\n";
        $content .= "Durasi      : {$duration}s\n";
        $content .= "CSV Source  : REKAMAN STOK FINAL 31 DESEMBER 2025.csv\n\n";
        
        $content .= "HASIL:\n";
        $content .= "- Produk: " . number_format($this->stats['processed']) . "\n";
        $content .= "- Update: " . number_format($this->stats['updated']) . "\n";
        $content .= "- Valid : " . number_format($verificationParams['passed']) . "\n";
        $content .= "- Error : " . count($verificationParams['issues']) . "\n";
        
        file_put_contents(__DIR__ . '/' . $logFile, $content);
        
        echo "\nRINGKASAN:\n";
        echo "Validasi: " . (count($verificationParams['issues']) === 0 ? "PASSED (100%)" : "FAILED") . "\n";
        echo "Log File: {$logFile}\n";
    }
}

$fixer = new StockFixerFinalV2('REKAMAN STOK FINAL 31 DESEMBER 2025.csv');
$fixer->run();
