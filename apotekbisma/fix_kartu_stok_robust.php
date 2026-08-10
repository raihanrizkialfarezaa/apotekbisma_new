<?php
/**
 * Script Perbaikan Robust untuk Rekaman Stok (Kartu Stok)
 * 
 * Fungsi:
 * - Memperbaiki perhitungan stok_awal dan stok_sisa pada setiap rekaman stok
 * - Mengurutkan data berdasarkan waktu transaksi yang benar
 * - Memastikan konsistensi perhitungan tanpa mengubah stok realtime produk
 * - Menghitung ulang dari awal untuk setiap produk
 * 
 * PENTING: Script ini HANYA memperbaiki data rekaman_stoks table
 * TIDAK mengubah stok pada table produk sama sekali!
 */

// Bootstrap Laravel
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Set time limit untuk script yang mungkin berjalan lama
set_time_limit(600); // 10 menit
ini_set('memory_limit', '512M');

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perbaikan Robust Rekaman Stok</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <style>
        body {
            padding: 20px;
            background: #ecf0f5;
            font-family: 'Source Sans Pro', 'Helvetica Neue', Helvetica, Arial, sans-serif;
        }
        .panel {
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
            border: none;
        }
        .panel-primary > .panel-heading {
            background-color: #3c8dbc;
            border-color: #3c8dbc;
        }
        .log-container {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 15px;
            border-radius: 4px;
            max-height: 500px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.6;
        }
        .log-success { color: #4ec9b0; font-weight: 500; }
        .log-error { color: #f48771; font-weight: 500; }
        .log-warning { color: #dcdcaa; }
        .log-info { color: #9cdcfe; }
        .progress {
            height: 30px;
            margin-bottom: 20px;
            border-radius: 4px;
            box-shadow: inset 0 1px 2px rgba(0,0,0,.1);
        }
        .progress-bar {
            line-height: 30px;
            font-size: 14px;
            font-weight: bold;
        }
        .progress-bar-animated {
            animation: progress-bar-stripes 1s linear infinite;
        }
        .stat-box {
            background: white;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
        }
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #3c8dbc;
        }
        .alert {
            border-radius: 4px;
            border: none;
        }
        .alert-info {
            background-color: #d9edf7;
            border-left: 4px solid #31708f;
        }
        .alert-success {
            background-color: #dff0d8;
            border-left: 4px solid #3c763d;
        }
        .alert-danger {
            background-color: #f2dede;
            border-left: 4px solid #a94442;
        }
        .btn {
            border-radius: 3px;
            transition: all 0.2s;
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        .form-group label {
            font-weight: 600;
        }
        @keyframes progress-bar-stripes {
            from { background-position: 40px 0; }
            to { background-position: 0 0; }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="panel panel-primary">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            <i class="fa fa-wrench"></i> Perbaikan Robust Rekaman Stok (Kartu Stok)
                        </h3>
                    </div>
                    <div class="panel-body">
                        <div class="alert alert-info">
                            <i class="fa fa-info-circle"></i>
                            <strong>Informasi Penting untuk Audit:</strong> 
                            <ul style="margin-top: 10px; margin-bottom: 0;">
                                <li>Script ini akan memproses <strong>semua rekaman stok berdasarkan urutan TANGGAL TRANSAKSI</strong> (kronologis)</li>
                                <li>Menghitung ulang <strong>stok_awal</strong> dan <strong>stok_sisa</strong> mengikuti timeline transaksi yang sebenarnya</li>
                                <li>Memastikan <strong>kontinuitas perhitungan</strong> dari transaksi paling lama ke terbaru</li>
                                <li><strong class="text-danger">TIDAK mengubah tanggal transaksi</strong> - tanggal tetap sesuai aslinya di database</li>
                                <li><strong class="text-danger">TIDAK mengubah stok realtime produk</strong> - hanya data historis rekaman stok</li>
                                <li>Hasil akan <strong>akurat secara kronologis</strong>, siap untuk audit</li>
                            </ul>
                        </div>

                        <?php if (!isset($_POST['execute']) && !isset($_GET['product_id'])): ?>
                            <form method="POST" id="fixForm">
                                <input type="hidden" name="execute" value="1">
                                
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" name="specific_product" id="specificProductCheck"> 
                                        Perbaiki produk tertentu saja
                                    </label>
                                    <input type="number" name="product_id" id="productId" class="form-control" 
                                           placeholder="Masukkan ID Produk" style="display:none; max-width: 300px;">
                                </div>

                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="confirm" required> 
                                        Saya memahami bahwa proses ini akan memperbaiki data rekaman stok
                                    </label>
                                </div>

                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fa fa-play"></i> Mulai Perbaikan
                                </button>
                                <a href="/kartustok" class="btn btn-default btn-lg">
                                    <i class="fa fa-arrow-left"></i> Kembali
                                </a>
                            </form>

                            <script>
                                document.getElementById('specificProductCheck').addEventListener('change', function() {
                                    document.getElementById('productId').style.display = this.checked ? 'block' : 'none';
                                });
                            </script>
                        <?php elseif (isset($_GET['product_id']) && !isset($_POST['execute'])): ?>
                            <form method="POST" id="fixForm">
                                <input type="hidden" name="execute" value="1">
                                <input type="hidden" name="specific_product" value="1">
                                <input type="hidden" name="product_id" value="<?= htmlspecialchars($_GET['product_id']) ?>">

                                <?php
                                $productInfo = DB::table('produk')->where('id_produk', $_GET['product_id'])->first();
                                if ($productInfo): ?>
                                    <div class="alert alert-info">
                                        <strong>Produk yang akan diperbaiki:</strong><br>
                                        ID: <?= $productInfo->id_produk ?><br>
                                        Nama: <?= $productInfo->nama_produk ?><br>
                                        Stok Saat Ini: <?= $productInfo->stok ?>
                                    </div>
                                <?php endif; ?>

                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="confirm" required> 
                                        Saya memahami bahwa proses ini akan memperbaiki data rekaman stok produk ini
                                    </label>
                                </div>

                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fa fa-play"></i> Mulai Perbaikan
                                </button>
                                <a href="/kartustok/detail/<?= $_GET['product_id'] ?>" class="btn btn-default btn-lg">
                                    <i class="fa fa-arrow-left"></i> Kembali
                                </a>
                            </form>
                        <?php else: ?>
                            <div id="progressSection">
                                <div class="progress">
                                    <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" 
                                         role="progressbar" style="width: 0%">0%</div>
                                </div>
                                <div class="log-container" id="logContainer">
                                    <div class="log-info">Memulai proses perbaikan...</div>
                                </div>
                            </div>

                            <?php
                            // Fungsi untuk menambahkan log
                            function addLog($message, $type = 'info') {
                                $colors = [
                                    'success' => 'log-success',
                                    'error' => 'log-error',
                                    'warning' => 'log-warning',
                                    'info' => 'log-info'
                                ];
                                $class = $colors[$type] ?? 'log-info';
                                echo "<script>
                                    document.getElementById('logContainer').innerHTML += '<div class=\"{$class}\">[" . date('H:i:s') . "] {$message}</div>';
                                    document.getElementById('logContainer').scrollTop = document.getElementById('logContainer').scrollHeight;
                                </script>";
                                flush();
                                ob_flush();
                            }

                            // Fungsi untuk update progress bar
                            function updateProgress($percent) {
                                echo "<script>
                                    document.getElementById('progressBar').style.width = '{$percent}%';
                                    document.getElementById('progressBar').textContent = '{$percent}%';
                                </script>";
                                flush();
                                ob_flush();
                            }

                            // Mulai output buffering
                            ob_start();

                            try {
                                DB::beginTransaction();

                                addLog("Memulai proses perbaikan rekaman stok...", 'info');
                                
                                $specificProduct = isset($_POST['specific_product']) && $_POST['product_id'];
                                $productId = $specificProduct ? intval($_POST['product_id']) : null;

                                // Get products to process
                                if ($specificProduct) {
                                    $products = DB::table('produk')->where('id_produk', $productId)->get();
                                    addLog("Mode: Perbaikan produk tertentu (ID: {$productId})", 'info');
                                } else {
                                    $products = DB::table('produk')->orderBy('id_produk')->get();
                                    addLog("Mode: Perbaikan semua produk", 'info');
                                }

                                if ($products->isEmpty()) {
                                    addLog("Tidak ada produk yang ditemukan!", 'error');
                                    throw new Exception("Produk tidak ditemukan");
                                }

                                addLog("Total produk yang akan diproses: " . count($products), 'info');
                                addLog("----------------------------------------", 'info');

                                $totalProducts = count($products);
                                $processedProducts = 0;
                                $totalRecordsFixed = 0;
                                $productsWithIssues = [];

                                foreach ($products as $index => $produk) {
                                    $processedProducts++;
                                    $progress = round(($processedProducts / $totalProducts) * 100);
                                    updateProgress($progress);

                                    addLog("", 'info');
                                    addLog("Memproses produk #{$produk->id_produk}: {$produk->nama_produk}", 'warning');

                                    // CRITICAL: Get all stock records ordered by DATE (chronological)
                                    // This ensures calculations follow the actual timeline of transactions
                                    // We use actual transaction time from penjualan/pembelian tables
                                    $records = DB::select("
                                        SELECT 
                                            rs.*,
                                            COALESCE(p.waktu, pb.waktu, rs.waktu) as actual_time,
                                            CASE 
                                                WHEN p.id_penjualan IS NOT NULL THEN 'penjualan'
                                                WHEN pb.id_pembelian IS NOT NULL THEN 'pembelian'
                                                ELSE 'manual'
                                            END as transaction_type
                                        FROM rekaman_stoks rs
                                        LEFT JOIN penjualan p ON rs.id_penjualan = p.id_penjualan
                                        LEFT JOIN pembelian pb ON rs.id_pembelian = pb.id_pembelian
                                        WHERE rs.id_produk = ?
                                        ORDER BY COALESCE(p.waktu, pb.waktu, rs.waktu) ASC, rs.id_rekaman_stok ASC
                                    ", [$produk->id_produk]);

                                    if (empty($records)) {
                                        addLog("  → Tidak ada rekaman stok untuk produk ini", 'info');
                                        continue;
                                    }

                                    addLog("  → Ditemukan " . count($records) . " rekaman stok", 'info');

                                    // First pass: Analyze issues based on chronological order
                                    $issues = [];
                                    $tempRunningStock = 0;
                                    
                                    foreach ($records as $recordIndex => $record) {
                                        if ($recordIndex === 0) {
                                            $tempRunningStock = $record->stok_awal ?? 0;
                                        }
                                        
                                        $expectedStokAwal = $tempRunningStock;
                                        $expectedStokSisa = $expectedStokAwal + ($record->stok_masuk ?? 0) - ($record->stok_keluar ?? 0);
                                        
                                        if ($record->stok_awal != $expectedStokAwal || $record->stok_sisa != $expectedStokSisa) {
                                            $issues[] = [
                                                'record_id' => $record->id_rekaman_stok,
                                                'chrono_no' => $recordIndex + 1,
                                                'current_awal' => $record->stok_awal,
                                                'expected_awal' => $expectedStokAwal,
                                                'current_sisa' => $record->stok_sisa,
                                                'expected_sisa' => $expectedStokSisa,
                                                'difference' => abs($record->stok_sisa - $expectedStokSisa),
                                                'date' => date('d/m/Y H:i', strtotime($record->actual_time))
                                            ];
                                        }
                                        
                                        $tempRunningStock = $expectedStokSisa;
                                    }
                                    
                                    if (!empty($issues)) {
                                        addLog("  ⚠ Ditemukan " . count($issues) . " rekaman dengan masalah perhitungan (urutan kronologis)", 'warning');
                                        if (count($issues) <= 3) {
                                            foreach ($issues as $issue) {
                                                addLog("    • Urutan #{$issue['chrono_no']} (ID {$issue['record_id']}) [{$issue['date']}]: Selisih {$issue['difference']} unit", 'warning');
                                            }
                                        }
                                    }

                                    // Process each record and recalculate stock
                                    $runningStock = 0; // Starting stock for first record
                                    $recordsFixed = 0;
                                    $hasIssues = false;

                                    foreach ($records as $recordIndex => $record) {
                                        $isFirstRecord = ($recordIndex === 0);
                                        
                                        // For first record, use its current stok_awal as starting point
                                        // This preserves the initial stock state
                                        if ($isFirstRecord) {
                                            $runningStock = $record->stok_awal ?? 0;
                                            addLog("    → Stok awal produk: {$runningStock}", 'info');
                                        }

                                        // Calculate new values based on running stock
                                        $newStokAwal = $runningStock;
                                        $stokMasuk = intval($record->stok_masuk ?? 0);
                                        $stokKeluar = intval($record->stok_keluar ?? 0);
                                        $newStokSisa = $newStokAwal + $stokMasuk - $stokKeluar;

                                        // Check if update is needed
                                        $needsUpdate = false;
                                        if ($record->stok_awal != $newStokAwal || $record->stok_sisa != $newStokSisa) {
                                            $needsUpdate = true;
                                            $hasIssues = true;
                                        }

                                        if ($needsUpdate) {
                                            // Update stock calculations based on chronological order
                                            // DO NOT change waktu - dates stay as they are in database
                                            $updateData = [
                                                'stok_awal' => $newStokAwal,
                                                'stok_sisa' => $newStokSisa,
                                                'updated_at' => now()
                                            ];

                                            // Update the record
                                            DB::table('rekaman_stoks')
                                                ->where('id_rekaman_stok', $record->id_rekaman_stok)
                                                ->update($updateData);

                                            $recordsFixed++;
                                            
                                            $formula = "{$newStokAwal} + {$stokMasuk} - {$stokKeluar} = {$newStokSisa}";
                                            addLog("    • ID #{$record->id_rekaman_stok} [" . date('d/m/Y', strtotime($record->actual_time)) . "]", 'success');
                                            addLog("      Sebelum: Awal={$record->stok_awal}, Sisa={$record->stok_sisa}", 'warning');
                                            addLog("      Sesudah: Awal={$newStokAwal}, Sisa={$newStokSisa} | Formula: {$formula}", 'success');
                                        }

                                        // Update running stock for next iteration
                                        // CRITICAL: Always use the calculated value, not the old one
                                        $runningStock = $newStokSisa;
                                    }

                                    if ($hasIssues) {
                                        $productsWithIssues[] = [
                                            'id' => $produk->id_produk,
                                            'nama' => $produk->nama_produk,
                                            'fixed' => $recordsFixed
                                        ];
                                    }

                                    $totalRecordsFixed += $recordsFixed;

                                    if ($recordsFixed > 0) {
                                        addLog("  ✓ Berhasil memperbaiki {$recordsFixed} rekaman untuk produk ini", 'success');
                                        
                                        // Verify the fixes
                                        addLog("  → Memverifikasi hasil perbaikan...", 'info');
                                        $verifyRecords = DB::select("
                                            SELECT 
                                                rs.*,
                                                COALESCE(p.waktu, pb.waktu, rs.waktu) as actual_time
                                            FROM rekaman_stoks rs
                                            LEFT JOIN penjualan p ON rs.id_penjualan = p.id_penjualan
                                            LEFT JOIN pembelian pb ON rs.id_pembelian = pb.id_pembelian
                                            WHERE rs.id_produk = ?
                                            ORDER BY COALESCE(p.waktu, pb.waktu, rs.waktu) ASC, rs.id_rekaman_stok ASC
                                        ", [$produk->id_produk]);
                                        
                                        $verifyRunning = 0;
                                        $verifyErrors = 0;
                                        $errorDetails = [];
                                        
                                        foreach ($verifyRecords as $vIndex => $vRecord) {
                                            if ($vIndex === 0) {
                                                $verifyRunning = intval($vRecord->stok_awal ?? 0);
                                            }
                                            
                                            $expectedAwal = $verifyRunning;
                                            $vMasuk = intval($vRecord->stok_masuk ?? 0);
                                            $vKeluar = intval($vRecord->stok_keluar ?? 0);
                                            $expectedSisa = $expectedAwal + $vMasuk - $vKeluar;
                                            
                                            $actualAwal = intval($vRecord->stok_awal);
                                            $actualSisa = intval($vRecord->stok_sisa);
                                            
                                            if ($actualAwal != $expectedAwal || $actualSisa != $expectedSisa) {
                                                $verifyErrors++;
                                                $errorDetails[] = [
                                                    'id' => $vRecord->id_rekaman_stok,
                                                    'expected_awal' => $expectedAwal,
                                                    'actual_awal' => $actualAwal,
                                                    'expected_sisa' => $expectedSisa,
                                                    'actual_sisa' => $actualSisa
                                                ];
                                            }
                                            
                                            $verifyRunning = $actualSisa; // Use actual for next iteration
                                        }
                                        
                                        if ($verifyErrors === 0) {
                                            addLog("  ✓ Verifikasi berhasil! Semua perhitungan sudah benar secara kronologis", 'success');
                                        } else {
                                            addLog("  ✗ Verifikasi: Masih ada {$verifyErrors} rekaman dengan masalah", 'error');
                                            foreach ($errorDetails as $idx => $err) {
                                                if ($idx < 3) { // Show first 3 errors only
                                                    addLog("    • ID #{$err['id']}: Expected Awal={$err['expected_awal']}, Actual={$err['actual_awal']} | Expected Sisa={$err['expected_sisa']}, Actual={$err['actual_sisa']}", 'error');
                                                }
                                            }
                                        }
                                    } else {
                                        addLog("  ✓ Semua rekaman sudah benar untuk produk ini", 'success');
                                    }

                                    // Verify final stock doesn't need to match current product stock
                                    // We only fix historical records, not real-time stock
                                    if (!empty($records)) {
                                        $lastRecord = end($records);
                                        if ($lastRecord) {
                                            $finalCalculatedStock = $lastRecord->stok_awal + 
                                                                  ($lastRecord->stok_masuk ?? 0) - 
                                                                  ($lastRecord->stok_keluar ?? 0);
                                            
                                            if ($finalCalculatedStock != $produk->stok) {
                                                addLog("  ℹ Stok akhir rekaman ({$finalCalculatedStock}) berbeda dengan stok realtime ({$produk->stok})", 'warning');
                                                addLog("    Ini normal jika ada transaksi baru setelah rekaman terakhir", 'info');
                                            }
                                        }
                                    }
                                }

                                DB::commit();

                                addLog("", 'info');
                                addLog("========================================", 'success');
                                addLog("PERBAIKAN SELESAI!", 'success');
                                addLog("========================================", 'success');
                                addLog("Total produk diproses: {$processedProducts}", 'success');
                                addLog("Total rekaman diperbaiki: {$totalRecordsFixed}", 'success');
                                addLog("Produk dengan masalah: " . count($productsWithIssues), 'success');

                                if (!empty($productsWithIssues)) {
                                    addLog("", 'info');
                                    addLog("Detail produk yang diperbaiki:", 'warning');
                                    foreach ($productsWithIssues as $product) {
                                        addLog("  • ID {$product['id']}: {$product['nama']} ({$product['fixed']} rekaman)", 'warning');
                                    }
                                }
                                
                                // Final system-wide verification
                                addLog("", 'info');
                                addLog("Melakukan verifikasi sistem secara menyeluruh...", 'info');
                                
                                $allRecords = DB::table('rekaman_stoks')
                                    ->orderBy('id_produk')
                                    ->orderBy('waktu', 'asc')
                                    ->get();
                                    
                                $totalInconsistencies = 0;
                                $currentProductId = null;
                                $runningStock = 0;
                                
                                foreach ($allRecords as $rec) {
                                    if ($currentProductId !== $rec->id_produk) {
                                        $currentProductId = $rec->id_produk;
                                        $runningStock = $rec->stok_awal ?? 0;
                                    }
                                    
                                    $expected = $runningStock + ($rec->stok_masuk ?? 0) - ($rec->stok_keluar ?? 0);
                                    if ($rec->stok_sisa != $expected) {
                                        $totalInconsistencies++;
                                    }
                                    $runningStock = $rec->stok_sisa;
                                }
                                
                                if ($totalInconsistencies === 0) {
                                    addLog("✓ VERIFIKASI SISTEM: Semua " . count($allRecords) . " rekaman stok konsisten!", 'success');
                                } else {
                                    addLog("✗ VERIFIKASI SISTEM: Ditemukan {$totalInconsistencies} inkonsistensi dari " . count($allRecords) . " rekaman", 'error');
                                    addLog("  Silakan jalankan perbaikan lagi untuk memperbaiki masalah ini", 'warning');
                                }

                                updateProgress(100);

                                echo '<div class="alert alert-success" style="margin-top: 20px;">
                                    <i class="fa fa-check-circle"></i> 
                                    <strong>✓ Perbaikan Berhasil Diselesaikan!</strong><br><br>
                                    
                                    <div class="row" style="margin-top: 15px;">
                                        <div class="col-md-3 col-sm-6">
                                            <div class="stat-box text-center">
                                                <div class="stat-number text-primary">' . $totalRecordsFixed . '</div>
                                                <div class="text-muted">Rekaman Diperbaiki</div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-sm-6">
                                            <div class="stat-box text-center">
                                                <div class="stat-number text-success">' . $processedProducts . '</div>
                                                <div class="text-muted">Produk Diproses</div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-sm-6">
                                            <div class="stat-box text-center">
                                                <div class="stat-number text-' . ($totalInconsistencies > 0 ? 'warning' : 'success') . '">' . $totalInconsistencies . '</div>
                                                <div class="text-muted">Inkonsistensi Tersisa</div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-sm-6">
                                            <div class="stat-box text-center">
                                                <div class="stat-number text-info">' . count($productsWithIssues) . '</div>
                                                <div class="text-muted">Produk Bermasalah</div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <hr>
                                    
                                    <strong>Contoh Perhitungan yang Benar:</strong><br>
                                    <div style="background: #f9f9f9; padding: 10px; border-radius: 4px; margin: 10px 0; font-family: monospace;">
                                        Record 1: Stok Awal = 50, Masuk = 0, Keluar = 0 → <strong>Stok Akhir = 50</strong><br>
                                        Record 2: Stok Awal = 50, Masuk = 0, Keluar = 10 → <strong>Stok Akhir = 40</strong> ✓<br>
                                        Record 3: Stok Awal = 40, Masuk = 0, Keluar = 10 → <strong>Stok Akhir = 30</strong> ✓<br>
                                        <em>Formula: Stok Akhir = Stok Awal + Stok Masuk - Stok Keluar</em>
                                    </div>
                                    
                                    <strong>Status untuk Audit:</strong><br>
                                    ' . ($totalInconsistencies === 0 ? 
                                        '<span class="text-success"><i class="fa fa-check"></i> Semua data sudah terurut dan akurat, siap untuk audit!</span>' :
                                        '<span class="text-warning"><i class="fa fa-exclamation-triangle"></i> Masih ada inkonsistensi, disarankan jalankan perbaikan sekali lagi.</span>') . '
                                    <br><br>
                                    
                                    <a href="/kartustok/detail/' . ($productId ?? '2') . '" class="btn btn-success">
                                        <i class="fa fa-eye"></i> Lihat Hasil Kartu Stok
                                    </a>
                                    <a href="/kartustok" class="btn btn-primary">
                                        <i class="fa fa-list"></i> Daftar Semua Kartu Stok
                                    </a>';
                                    
                                if ($totalInconsistencies > 0) {
                                    echo '<a href="javascript:location.reload()" class="btn btn-warning">
                                        <i class="fa fa-refresh"></i> Jalankan Perbaikan Lagi
                                    </a>';
                                }
                                
                                echo '</div>';

                            } catch (Exception $e) {
                                DB::rollBack();
                                addLog("", 'error');
                                addLog("ERROR: " . $e->getMessage(), 'error');
                                addLog("Trace: " . $e->getTraceAsString(), 'error');
                                
                                echo '<div class="alert alert-danger" style="margin-top: 20px;">
                                    <i class="fa fa-times-circle"></i> 
                                    <strong>Perbaikan Gagal!</strong><br>
                                    Error: ' . htmlspecialchars($e->getMessage()) . '<br>
                                    <a href="javascript:history.back()" class="btn btn-sm btn-danger" style="margin-top: 10px;">
                                        <i class="fa fa-arrow-left"></i> Kembali
                                    </a>
                                </div>';
                            }

                            ob_end_flush();
                            ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
</body>
</html>
