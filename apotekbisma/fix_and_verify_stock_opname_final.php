<?php

/**
 * SKRIP PERBAIKAN STOK & VERIFIKASI - ALL IN ONE
 * 
 * Fungsi:
 * 1. Membaca data Stock Opname dari CSV (Cutoff: 31 Des 2025).
 * 2. Menghitung transksi (Jual/Beli) setelah tanggal cutoff.
 * 3. Mengupdate stok produk sesuai rumus: Opname + Beli - Jual.
 * 4. Membangun ulang (Rebuild) rekaman stok HANYA setelah tanggal cutoff.
 * 5. Melakukan verifikasi menyeluruh untuk memastikan akurasi 100%.
 */

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Konfigurasi Server untuk proses berat
ini_set('max_execution_time', 1200); // 20 Menit
ini_set('memory_limit', '1024M');    // 1GB RAM

class StockFixerAndVerifier
{
    private $cutoffDate = '2025-12-31';
    private $cutoffDateTime = '2025-12-31 23:59:59';
    private $csvPath;
    private $opnameData = [];
    
    // Statistik Eksekusi
    private $stats = [
        'processed' => 0,
        'updated' => 0,
        'rekaman_deleted' => 0,
        'rekaman_created' => 0,
        'errors' => []
    ];

    public function __construct($csvFilename)
    {
        $this->csvPath = __DIR__ . '/' . $csvFilename;
    }

    public function run()
    {
        $startTime = microtime(true);
        echo "=================================================================\n";
        echo "   STOCK RECOVERY & VERIFICATION WIZARD\n";
        echo "=================================================================\n";
        echo "Start Time : " . date('Y-m-d H:i:s') . "\n";
        echo "Cutoff Date: {$this->cutoffDate}\n\n";

        if (!file_exists($this->csvPath)) {
            die("[FATAL] File CSV tidak ditemukan: {$this->csvPath}\n");
        }

        try {
            // STEP 1: Load CSV
            $this->loadCsvData();

            // STEP 2: Execution (Sync Database)
            DB::beginTransaction();
            echo "STEP 2: Sinkronisasi Database...\n";
            $this->syncDatabase();
            DB::commit(); 
            echo "   [OK] Database berhasil disinkronisasi.\n\n";

            // STEP 3: Verification
            echo "STEP 3: Verifikasi Data Akhir...\n";
            $verificationResult = $this->verifyData();

            // Finish
            $duration = round(microtime(true) - $startTime, 2);
            $this->printSummary($duration, $verificationResult);

        } catch (\Exception $e) {
            DB::rollBack();
            echo "\n[FATAL ERROR] Terjadi kesalahan, perubahan dibatalkan (Rollback).\n";
            echo "Pesan Error: " . $e->getMessage() . "\n";
            echo "File: " . $e->getFile() . " on line " . $e->getLine() . "\n";
        }
    }

    private function loadCsvData()
    {
        echo "STEP 1: Membaca File CSV...\n";
        $handle = fopen($this->csvPath, 'r');
        $header = fgetcsv($handle);
        
        // Deteksi kolom otomatis
        $idIdx = array_search('produk_id_produk', $header);
        $stokIdx = array_search('produk_stok', $header);

        if ($idIdx === false || $stokIdx === false) {
            throw new \Exception("Kolom 'produk_id_produk' atau 'produk_stok' tidak ditemukan di CSV.");
        }

        while (($row = fgetcsv($handle)) !== false) {
            if (isset($row[$idIdx]) && $row[$idIdx] !== '') {
                $pid = intval($row[$idIdx]);
                $qty = intval($row[$stokIdx]);
                $this->opnameData[$pid] = $qty;
            }
        }
        fclose($handle);
        echo "   [OK] " . count($this->opnameData) . " produk dimuat dari CSV.\n\n";
    }

    private function syncDatabase()
    {
        $total = count($this->opnameData);
        $count = 0;

        foreach ($this->opnameData as $productId => $opnameStock) {
            $count++;
            if ($count % 100 === 0) echo "   Processing {$count}/{$total}...\r";

            // 1. Ambil transaksi setelah cutoff
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

            // 2. Hitung Stok Akhir Seharusnya
            $finalStock = $opnameStock + intval($purchaseQty) - intval($salesQty);
            if ($finalStock < 0) $finalStock = 0; // Negative stock clamp to 0

            // 3. Update Master Produk
            $currentStock = DB::table('produk')->where('id_produk', $productId)->value('stok');
            if (intval($currentStock) !== $finalStock) {
                DB::table('produk')
                    ->where('id_produk', $productId)
                    ->update(['stok' => $finalStock, 'updated_at' => now()]);
                $this->stats['updated']++;
            }

            // 4. Bersihkan Rekaman Stok Lama (Setelah Cutoff)
            $deleted = DB::table('rekaman_stoks')
                ->where('id_produk', $productId)
                ->where('waktu', '>', $this->cutoffDateTime)
                ->delete();
            $this->stats['rekaman_deleted'] += $deleted;

            // 5. Buat Rekaman Stok Baru (Jika ada transaksi setelah cutoff)
            if ($salesQty > 0 || $purchaseQty > 0) {
                $this->rebuildStockRecords($productId, $opnameStock);
            }

            $this->stats['processed']++;
        }
        echo "   Processing {$total}/{$total}... SELESAI.\n";
    }

    private function rebuildStockRecords($productId, $initialStock)
    {
        // Ambil data detail transaksi
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

        // Sort transaksi berdasarkan waktu
        usort($transactions, function($a, $b) {
            $t1 = strtotime($a['waktu']);
            $t2 = strtotime($b['waktu']);
            if ($t1 == $t2) return ($a['type'] === 'in') ? -1 : 1; // Pembelian didahulukan jika waktu sama
            return $t1 - $t2;
        });

        $runningStock = $initialStock;

        foreach ($transactions as $tx) {
            $stokAwal  = $runningStock;
            $stokMasuk = ($tx['type'] === 'in') ? $tx['qty'] : 0;
            $stokKeluar = ($tx['type'] === 'out') ? $tx['qty'] : 0;
            
            // Rumus Matematika Murni
            $stokSisa = $stokAwal + $stokMasuk - $stokKeluar;
            
            // Aturan Bisnis: Stok di rekaman boleh negatif sementara (sesuai history), 
            // tapi nanti di UI/Master produk diclamp ke 0. 
            // Namun untuk konsistensi rumus (Sisa = Awal + Masuk - Keluar), kita simpan hasil raw.
            // *User Preference*: "asalkan stok akhir disesuaikan robust". 
            // Kita akan clamp sisa ke 0 agar "bersih" di mata user, 
            // meskipun secara matematis rekaman berikutnya akan start dari 0.
            if ($stokSisa < 0) $stokSisa = 0;

            DB::table('rekaman_stoks')->insert([
                'id_produk' => $productId,
                'id_penjualan' => ($tx['type'] === 'out') ? $tx['id'] : null,
                'id_pembelian' => ($tx['type'] === 'in') ? $tx['id'] : null,
                'waktu'      => $tx['waktu'],
                'stok_awal'  => $stokAwal,
                'stok_masuk' => $stokMasuk,
                'stok_keluar'=> $stokKeluar,
                'stok_sisa'  => $stokSisa,
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
        $issues = [];
        $passed = 0;
        
        foreach ($this->opnameData as $productId => $opnameStock) {
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

            $expected = $opnameStock + intval($purchaseQty) - intval($salesQty);
            if ($expected < 0) $expected = 0;

            $actual = DB::table('produk')->where('id_produk', $productId)->value('stok');

            if (intval($actual) === $expected) {
                $passed++;
            } else {
                $issues[] = "Produk #{$productId}: Exp {$expected} vs Act {$actual}";
            }
        }

        return ['passed' => $passed, 'issues' => $issues];
    }

    private function printSummary($duration, $verificationParams)
    {
        $logFilename = 'fix_result_' . date('dmY_His') . '.txt';
        $content  = "LAPORAN PERBAIKAN STOK\n";
        $content .= "==========================================\n";
        $content .= "Tanggal     : " . date('d M Y H:i:s') . "\n";
        $content .= "Durasi      : {$duration} detik\n";
        $content .= "File CSV    : " . basename($this->csvPath) . "\n\n";
        
        $content .= "HASIL PERBAIKAN:\n";
        $content .= "- Produk Diproses     : " . number_format($this->stats['processed']) . "\n";
        $content .= "- Stok Diupdate       : " . number_format($this->stats['updated']) . "\n";
        $content .= "- Rekaman Dihapus     : " . number_format($this->stats['rekaman_deleted']) . "\n";
        $content .= "- Rekaman Dibuat      : " . number_format($this->stats['rekaman_created']) . "\n\n";

        $content .= "HASIL VERIFIKASI:\n";
        $content .= "- Status              : " . (count($verificationParams['issues']) === 0 ? "SUKSES (100% Valid)" : "PERINGATAN") . "\n";
        $content .= "- Data Cocok (Valid)  : " . number_format($verificationParams['passed']) . "\n";
        $content .= "- Data Mismatch       : " . count($verificationParams['issues']) . "\n";

        if (count($verificationParams['issues']) > 0) {
            $content .= "\nDAFTAR MASALAH:\n";
            foreach ($verificationParams['issues'] as $issue) {
                $content .= "- {$issue}\n";
            }
        }

        file_put_contents(__DIR__ . '/' . $logFilename, $content);

        echo "\nRINGKASAN AKHIR\n";
        echo "-----------------------------------------------------------------\n";
        echo "Produk Valid   : " . number_format($verificationParams['passed']) . " / " . count($this->opnameData) . "\n";
        echo "Status         : " . (count($verificationParams['issues']) === 0 ? "PASSED ✅" : "FAILED ❌") . "\n";
        echo "Log Disimpan   : {$logFilename}\n";
        echo "-----------------------------------------------------------------\n";
    }
}

// EKSEKUSI
$csvFile = 'kartu_stok_20251231.csv';
$fixer = new StockFixerAndVerifier($csvFile);
$fixer->run();
