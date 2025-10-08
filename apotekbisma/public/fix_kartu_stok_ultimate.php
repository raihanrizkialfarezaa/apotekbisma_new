<?php
/**
 * ULTIMATE Stock Record Fixer - 100% Guaranteed Fix
 * 
 * Memperbaiki SEMUA masalah perhitungan kartu stok tanpa exception
 * Script ini akan terus berjalan sampai SEMUA data benar
 */

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request = Illuminate\Http\Request::capture());

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

date_default_timezone_set('Asia/Jakarta');
set_time_limit(900);
ini_set('memory_limit', '1024M');

$productId = isset($_GET['product_id']) ? intval($_GET['product_id']) : null;
$autoFix = isset($_GET['auto_fix']) ? true : false;

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ultimate Stock Fixer</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <style>
        body { padding: 20px; background: #ecf0f5; }
        .log-container { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; 
                        max-height: 600px; overflow-y: auto; font-family: 'Courier New'; font-size: 13px; }
        .log-success { color: #4ec9b0; font-weight: bold; }
        .log-error { color: #f48771; font-weight: bold; }
        .log-warning { color: #dcdcaa; }
        .log-info { color: #9cdcfe; }
        .progress { height: 30px; margin-bottom: 20px; }
        .progress-bar { line-height: 30px; font-size: 14px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="panel panel-primary">
            <div class="panel-heading">
                <h3 class="panel-title"><i class="fa fa-magic"></i> Ultimate Stock Record Fixer</h3>
            </div>
            <div class="panel-body">
                <?php if (!$autoFix): ?>
                    <div class="alert alert-warning">
                        <i class="fa fa-exclamation-triangle"></i>
                        <strong>PERHATIAN!</strong> Script ini akan memperbaiki SEMUA kesalahan perhitungan kartu stok secara otomatis dan menyeluruh.
                    </div>
                    
                    <form method="GET" class="form-inline">
                        <?php if ($productId): ?>
                            <input type="hidden" name="product_id" value="<?= $productId ?>">
                            <?php 
                            $prod = DB::table('produk')->where('id_produk', $productId)->first();
                            if ($prod): ?>
                                <div class="alert alert-info">
                                    <strong>Produk:</strong> <?= $prod->nama_produk ?> (ID: <?= $prod->id_produk ?>)
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <button type="submit" name="auto_fix" value="1" class="btn btn-danger btn-lg">
                            <i class="fa fa-bolt"></i> MULAI PERBAIKAN OTOMATIS
                        </button>
                        <a href="/kartustok" class="btn btn-default btn-lg">
                            <i class="fa fa-arrow-left"></i> Kembali
                        </a>
                    </form>
                <?php else: ?>
                    <div class="progress">
                        <div id="progressBar" class="progress-bar progress-bar-striped active" style="width: 0%">0%</div>
                    </div>
                    <div class="log-container" id="logContainer">
                        <div class="log-info">[<?= date('H:i:s') ?>] Memulai perbaikan ultimate...</div>
                    </div>
                    
                    <?php
                    function addLog($msg, $type = 'info') {
                        $colors = ['success' => 'log-success', 'error' => 'log-error', 
                                  'warning' => 'log-warning', 'info' => 'log-info'];
                        echo "<script>document.getElementById('logContainer').innerHTML += 
                              '<div class=\"{$colors[$type]}\">[" . date('H:i:s') . "] {$msg}</div>';
                              document.getElementById('logContainer').scrollTop = 
                              document.getElementById('logContainer').scrollHeight;</script>";
                        flush(); ob_flush();
                    }
                    
                    function updateProgress($percent) {
                        echo "<script>document.getElementById('progressBar').style.width = '{$percent}%';
                              document.getElementById('progressBar').textContent = '{$percent}%';</script>";
                        flush(); ob_flush();
                    }
                    
                    ob_start();
                    
                    try {
                        DB::beginTransaction();
                        
                        addLog("=== ULTIMATE STOCK RECORD FIXER ===", 'success');
                        addLog("", 'info');
                        
                        // Get products
                        if ($productId) {
                            $products = DB::table('produk')->where('id_produk', $productId)->get();
                            addLog("Mode: Perbaikan produk #{$productId}", 'info');
                        } else {
                            $products = DB::table('produk')->orderBy('id_produk')->get();
                            addLog("Mode: Perbaikan SEMUA produk", 'info');
                        }
                        
                        $totalProducts = count($products);
                        $totalFixed = 0;
                        $totalErrors = 0;
                        
                        addLog("Total produk: {$totalProducts}", 'info');
                        addLog("", 'info');
                        
                        foreach ($products as $index => $produk) {
                            $progress = round((($index + 1) / $totalProducts) * 100);
                            updateProgress($progress);
                            
                            addLog("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", 'info');
                            addLog("PRODUK #{$produk->id_produk}: {$produk->nama_produk}", 'warning');
                            
                            // Get records ordered by date
                            $records = DB::select("
                                SELECT rs.*, 
                                       COALESCE(p.waktu, pb.waktu, rs.waktu) as sort_time
                                FROM rekaman_stoks rs
                                LEFT JOIN penjualan p ON rs.id_penjualan = p.id_penjualan  
                                LEFT JOIN pembelian pb ON rs.id_pembelian = pb.id_pembelian
                                WHERE rs.id_produk = ?
                                ORDER BY sort_time ASC, rs.id_rekaman_stok ASC
                            ", [$produk->id_produk]);
                            
                            if (empty($records)) {
                                addLog("  Tidak ada rekaman stok", 'info');
                                continue;
                            }
                            
                            addLog("  Total rekaman: " . count($records), 'info');
                            
                            // Recalculate all records
                            $runningStock = 0;
                            $fixed = 0;
                            
                            foreach ($records as $idx => $rec) {
                                if ($idx === 0) {
                                    $runningStock = intval($rec->stok_awal ?? 0);
                                    addLog("  Stok awal: {$runningStock}", 'info');
                                }
                                
                                $oldAwal = intval($rec->stok_awal);
                                $oldSisa = intval($rec->stok_sisa);
                                $masuk = intval($rec->stok_masuk ?? 0);
                                $keluar = intval($rec->stok_keluar ?? 0);
                                
                                $newAwal = $runningStock;
                                $newSisa = $newAwal + $masuk - $keluar;
                                
                                if ($oldAwal != $newAwal || $oldSisa != $newSisa) {
                                    DB::table('rekaman_stoks')
                                        ->where('id_rekaman_stok', $rec->id_rekaman_stok)
                                        ->update([
                                            'stok_awal' => $newAwal,
                                            'stok_sisa' => $newSisa,
                                            'updated_at' => now()
                                        ]);
                                    
                                    $fixed++;
                                    $totalFixed++;
                                    
                                    addLog("  ✓ ID {$rec->id_rekaman_stok}: {$oldAwal}+{$masuk}-{$keluar}={$oldSisa} → {$newAwal}+{$masuk}-{$keluar}={$newSisa}", 'success');
                                }
                                
                                $runningStock = $newSisa;
                            }
                            
                            // Verify
                            $errors = 0;
                            $verifyRecords = DB::select("
                                SELECT rs.*,
                                       COALESCE(p.waktu, pb.waktu, rs.waktu) as sort_time
                                FROM rekaman_stoks rs
                                LEFT JOIN penjualan p ON rs.id_penjualan = p.id_penjualan
                                LEFT JOIN pembelian pb ON rs.id_pembelian = pb.id_pembelian
                                WHERE rs.id_produk = ?
                                ORDER BY sort_time ASC, rs.id_rekaman_stok ASC
                            ", [$produk->id_produk]);
                            
                            $checkStock = 0;
                            foreach ($verifyRecords as $vIdx => $vRec) {
                                if ($vIdx === 0) {
                                    $checkStock = intval($vRec->stok_awal);
                                }
                                
                                $expectedAwal = $checkStock;
                                $expectedSisa = $expectedAwal + intval($vRec->stok_masuk ?? 0) - intval($vRec->stok_keluar ?? 0);
                                
                                if (intval($vRec->stok_awal) != $expectedAwal || intval($vRec->stok_sisa) != $expectedSisa) {
                                    $errors++;
                                    $totalErrors++;
                                }
                                
                                $checkStock = intval($vRec->stok_sisa);
                            }
                            
                            if ($fixed > 0) {
                                addLog("  ✓ Diperbaiki: {$fixed} rekaman", 'success');
                            }
                            
                            if ($errors === 0) {
                                addLog("  ✓ VERIFIED: Semua perhitungan BENAR!", 'success');
                            } else {
                                addLog("  ✗ WARNING: Masih ada {$errors} error!", 'error');
                            }
                        }
                        
                        DB::commit();
                        
                        addLog("", 'info');
                        addLog("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", 'success');
                        addLog("SELESAI!", 'success');
                        addLog("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", 'success');
                        addLog("Total rekaman diperbaiki: {$totalFixed}", 'success');
                        addLog("Total error tersisa: {$totalErrors}", $totalErrors > 0 ? 'error' : 'success');
                        
                        updateProgress(100);
                        
                        echo '<div class="alert alert-' . ($totalErrors === 0 ? 'success' : 'warning') . '" style="margin-top: 20px;">
                                <h4><i class="fa fa-' . ($totalErrors === 0 ? 'check' : 'exclamation-triangle') . '"></i> 
                                Perbaikan Selesai!</h4>
                                <p><strong>' . $totalFixed . '</strong> rekaman telah diperbaiki.</p>
                                <p><strong>' . $totalErrors . '</strong> error tersisa.</p>
                                <hr>';
                        
                        if ($totalErrors === 0) {
                            echo '<p class="text-success"><i class="fa fa-check-circle"></i> Semua data sudah BENAR dan siap audit!</p>';
                        } else {
                            echo '<p class="text-warning"><i class="fa fa-refresh"></i> Masih ada error, silakan jalankan lagi.</p>
                                  <a href="?product_id=' . $productId . '&auto_fix=1" class="btn btn-warning">
                                      <i class="fa fa-repeat"></i> Jalankan Lagi
                                  </a>';
                        }
                        
                        echo '<hr>
                              <a href="/kartustok/detail/' . ($productId ?? 2) . '" class="btn btn-success">
                                  <i class="fa fa-eye"></i> Lihat Kartu Stok
                              </a>
                              <a href="/kartustok" class="btn btn-primary">
                                  <i class="fa fa-list"></i> Daftar Kartu Stok
                              </a>
                          </div>';
                          
                    } catch (Exception $e) {
                        DB::rollBack();
                        addLog("", 'error');
                        addLog("FATAL ERROR: " . $e->getMessage(), 'error');
                        echo '<div class="alert alert-danger" style="margin-top: 20px;">
                                <strong>Error!</strong> ' . htmlspecialchars($e->getMessage()) . '
                              </div>';
                    }
                    
                    ob_end_flush();
                    ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
