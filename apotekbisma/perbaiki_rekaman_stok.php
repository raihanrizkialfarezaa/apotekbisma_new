<?php
/**
 * SCRIPT PERBAIKAN REKAMAN STOK
 * 
 * Script ini memperbaiki perhitungan stok_awal dan stok_sisa pada tabel rekaman_stoks
 * tanpa mengubah stok realtime pada tabel produk.
 * 
 * Logika:
 * 1. Ambil semua rekaman stok per produk, urutkan berdasarkan waktu (ASC)
 * 2. Untuk setiap produk:
 *    - Rekaman pertama: stok_awal = 0 (atau stok awal yang valid)
 *    - Rekaman berikutnya: stok_awal = stok_sisa dari rekaman sebelumnya
 *    - stok_sisa = stok_awal + stok_masuk - stok_keluar
 * 3. Update database dengan perhitungan yang benar
 */

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

// Set mode web output
$is_web_mode = php_sapi_name() != 'cli';
$output_buffer = [];

function output($message, $is_web = false) {
    global $output_buffer;
    if ($is_web) {
        $output_buffer[] = $message;
        echo $message . "<br>\n";
        flush();
        ob_flush();
    } else {
        echo $message . "\n";
    }
}

function start_html() {
    global $is_web_mode;
    if ($is_web_mode) {
        ob_start();
        echo '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perbaikan Rekaman Stok</title>
    <style>
        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .container {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }
        h1 {
            color: #667eea;
            border-bottom: 3px solid #667eea;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        h2 {
            color: #764ba2;
            margin-top: 30px;
            padding: 10px;
            background: #f0f0f0;
            border-left: 5px solid #764ba2;
        }
        .success {
            color: #28a745;
            font-weight: bold;
        }
        .error {
            color: #dc3545;
            font-weight: bold;
        }
        .warning {
            color: #ffc107;
            font-weight: bold;
        }
        .info {
            color: #17a2b8;
            font-weight: bold;
        }
        .stat-box {
            display: inline-block;
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 5px;
            padding: 15px 25px;
            margin: 10px 10px 10px 0;
            min-width: 200px;
        }
        .stat-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #495057;
            margin-top: 5px;
        }
        .progress-bar {
            width: 100%;
            height: 30px;
            background: #e9ecef;
            border-radius: 15px;
            overflow: hidden;
            margin: 20px 0;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        .detail-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 14px;
        }
        .detail-table th,
        .detail-table td {
            padding: 10px;
            text-align: left;
            border: 1px solid #dee2e6;
        }
        .detail-table th {
            background: #667eea;
            color: white;
            font-weight: bold;
        }
        .detail-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        .back-button {
            display: inline-block;
            margin-top: 30px;
            padding: 12px 30px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            transition: background 0.3s;
        }
        .back-button:hover {
            background: #764ba2;
        }
        .timestamp {
            color: #6c757d;
            font-size: 12px;
            font-style: italic;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 PERBAIKAN REKAMAN STOK</h1>
        <p><strong>Proses:</strong> Menghitung ulang stok_awal dan stok_sisa untuk semua rekaman stok</p>
        <hr>
';
        flush();
        ob_flush();
    }
}

function end_html() {
    global $is_web_mode;
    if ($is_web_mode) {
        echo '
        <hr>
        <div class="timestamp">
            <p>Dijalankan pada: ' . date('d-m-Y H:i:s') . '</p>
        </div>
        <a href="javascript:history.back()" class="back-button">← Kembali</a>
    </div>
</body>
</html>';
        ob_end_flush();
    }
}

// Mulai output HTML jika web mode
start_html();

output("", $is_web_mode);
output("<h2>📊 FASE 1: ANALISIS DATA</h2>", $is_web_mode);

DB::beginTransaction();

try {
    // Statistik awal
    $total_products = Produk::count();
    $total_records = RekamanStok::count();
    
    output("<div class='stat-box'>", $is_web_mode);
    output("<div class='stat-label'>Total Produk</div>", $is_web_mode);
    output("<div class='stat-value'>{$total_products}</div>", $is_web_mode);
    output("</div>", $is_web_mode);
    
    output("<div class='stat-box'>", $is_web_mode);
    output("<div class='stat-label'>Total Rekaman Stok</div>", $is_web_mode);
    output("<div class='stat-value'>{$total_records}</div>", $is_web_mode);
    output("</div>", $is_web_mode);
    
    output("", $is_web_mode);
    output("<h2>🔄 FASE 2: PROSES PERBAIKAN</h2>", $is_web_mode);
    
    // Ambil semua produk yang memiliki rekaman stok
    $products_with_records = DB::select("
        SELECT DISTINCT id_produk
        FROM rekaman_stoks
        ORDER BY id_produk ASC
    ");
    
    $total_products_to_fix = count($products_with_records);
    $products_fixed = 0;
    $records_updated = 0;
    $inconsistencies_found = 0;
    
    output("<p>Memproses <strong>{$total_products_to_fix}</strong> produk...</p>", $is_web_mode);
    output("<div class='progress-bar'><div class='progress-fill' id='progress' style='width: 0%'>0%</div></div>", $is_web_mode);
    
    if ($is_web_mode) {
        echo "<script>
            function updateProgress(percent) {
                document.getElementById('progress').style.width = percent + '%';
                document.getElementById('progress').textContent = percent + '%';
            }
        </script>";
    }
    
    // Loop setiap produk
    foreach ($products_with_records as $index => $product_data) {
        $product_id = $product_data->id_produk;
        $produk = Produk::find($product_id);
        
        if (!$produk) {
            continue;
        }
        
        // Ambil semua rekaman stok untuk produk ini, urutkan berdasarkan waktu
        $records = RekamanStok::where('id_produk', $product_id)
                             ->orderBy('waktu', 'asc')
                             ->orderBy('id_rekaman_stok', 'asc')
                             ->get();
        
        if ($records->isEmpty()) {
            continue;
        }
        
        $has_changes = false;
        $previous_stok_sisa = 0; // Stok sisa dari rekaman sebelumnya
        
        // Proses setiap rekaman secara berurutan
        foreach ($records as $record_index => $record) {
            $old_stok_awal = $record->stok_awal;
            $old_stok_sisa = $record->stok_sisa;
            
            // Hitung stok_awal yang benar
            // Rekaman pertama: stok_awal bisa dari stok awal produk atau 0
            // Rekaman selanjutnya: stok_awal = stok_sisa dari rekaman sebelumnya
            $new_stok_awal = ($record_index == 0) ? $old_stok_awal : $previous_stok_sisa;
            
            // Hitung stok_sisa yang benar
            $stok_masuk = $record->stok_masuk ?? 0;
            $stok_keluar = $record->stok_keluar ?? 0;
            $new_stok_sisa = $new_stok_awal + $stok_masuk - $stok_keluar;
            
            // Cek apakah ada perubahan
            if ($new_stok_awal != $old_stok_awal || $new_stok_sisa != $old_stok_sisa) {
                // Update record dengan menonaktifkan mutator
                RekamanStok::$skipMutators = true;
                
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $record->id_rekaman_stok)
                    ->update([
                        'stok_awal' => $new_stok_awal,
                        'stok_sisa' => $new_stok_sisa,
                        'updated_at' => Carbon::now()
                    ]);
                
                RekamanStok::$skipMutators = false;
                
                $has_changes = true;
                $records_updated++;
                
                // Log perubahan untuk debugging
                if ($new_stok_awal != $old_stok_awal || $new_stok_sisa != $old_stok_sisa) {
                    $inconsistencies_found++;
                }
            }
            
            // Simpan stok_sisa untuk rekaman berikutnya
            $previous_stok_sisa = $new_stok_sisa;
        }
        
        if ($has_changes) {
            $products_fixed++;
        }
        
        // Update progress
        $progress_percent = round((($index + 1) / $total_products_to_fix) * 100);
        if ($is_web_mode && ($index % 5 == 0 || $index == $total_products_to_fix - 1)) {
            echo "<script>updateProgress({$progress_percent});</script>";
            flush();
            ob_flush();
        }
    }
    
    // Commit semua perubahan
    DB::commit();
    
    output("", $is_web_mode);
    output("<h2>✅ FASE 3: HASIL PERBAIKAN</h2>", $is_web_mode);
    
    output("<div class='stat-box'>", $is_web_mode);
    output("<div class='stat-label'>Produk Diperbaiki</div>", $is_web_mode);
    output("<div class='stat-value success'>{$products_fixed}</div>", $is_web_mode);
    output("</div>", $is_web_mode);
    
    output("<div class='stat-box'>", $is_web_mode);
    output("<div class='stat-label'>Rekaman Diupdate</div>", $is_web_mode);
    output("<div class='stat-value info'>{$records_updated}</div>", $is_web_mode);
    output("</div>", $is_web_mode);
    
    output("<div class='stat-box'>", $is_web_mode);
    output("<div class='stat-label'>Inkonsistensi Ditemukan</div>", $is_web_mode);
    output("<div class='stat-value warning'>{$inconsistencies_found}</div>", $is_web_mode);
    output("</div>", $is_web_mode);
    
    output("", $is_web_mode);
    output("<h2>🔍 FASE 4: VERIFIKASI</h2>", $is_web_mode);
    
    // Verifikasi: Cek apakah masih ada inkonsistensi
    $verification_query = "
        SELECT 
            id_rekaman_stok,
            id_produk,
            waktu,
            stok_awal,
            stok_masuk,
            stok_keluar,
            stok_sisa,
            (stok_awal + COALESCE(stok_masuk, 0) - COALESCE(stok_keluar, 0)) as calculated_sisa
        FROM rekaman_stoks
        WHERE (stok_awal + COALESCE(stok_masuk, 0) - COALESCE(stok_keluar, 0)) != stok_sisa
        LIMIT 10
    ";
    
    $remaining_issues = DB::select($verification_query);
    
    if (empty($remaining_issues)) {
        output("<p class='success'>✅ SEMPURNA! Tidak ada inkonsistensi yang tersisa.</p>", $is_web_mode);
        output("<p class='success'>Semua rekaman stok telah dihitung ulang dengan benar.</p>", $is_web_mode);
    } else {
        output("<p class='warning'>⚠️ Ditemukan " . count($remaining_issues) . " inkonsistensi yang tersisa (menampilkan 10 pertama):</p>", $is_web_mode);
        
        if ($is_web_mode) {
            output("<table class='detail-table'>", $is_web_mode);
            output("<tr><th>ID Rekaman</th><th>ID Produk</th><th>Waktu</th><th>Stok Awal</th><th>Masuk</th><th>Keluar</th><th>Sisa (DB)</th><th>Sisa (Hitung)</th></tr>", $is_web_mode);
            
            foreach ($remaining_issues as $issue) {
                output("<tr>", $is_web_mode);
                output("<td>{$issue->id_rekaman_stok}</td>", $is_web_mode);
                output("<td>{$issue->id_produk}</td>", $is_web_mode);
                output("<td>{$issue->waktu}</td>", $is_web_mode);
                output("<td>{$issue->stok_awal}</td>", $is_web_mode);
                output("<td>{$issue->stok_masuk}</td>", $is_web_mode);
                output("<td>{$issue->stok_keluar}</td>", $is_web_mode);
                output("<td class='error'>{$issue->stok_sisa}</td>", $is_web_mode);
                output("<td class='success'>{$issue->calculated_sisa}</td>", $is_web_mode);
                output("</tr>", $is_web_mode);
            }
            
            output("</table>", $is_web_mode);
        }
    }
    
    output("", $is_web_mode);
    output("<h2>📋 RINGKASAN</h2>", $is_web_mode);
    output("<ul>", $is_web_mode);
    output("<li>✅ Proses perbaikan telah selesai</li>", $is_web_mode);
    output("<li>✅ Database telah dicommit (perubahan permanen)</li>", $is_web_mode);
    output("<li>✅ Stok realtime pada tabel produk TIDAK DIUBAH</li>", $is_web_mode);
    output("<li>✅ Hanya data rekaman_stoks yang diperbaiki</li>", $is_web_mode);
    output("<li>✅ Silakan refresh halaman kartu stok untuk melihat perubahan</li>", $is_web_mode);
    output("</ul>", $is_web_mode);
    
    output("", $is_web_mode);
    output("<p class='success' style='font-size: 18px; text-align: center; padding: 20px; background: #d4edda; border: 2px solid #c3e6cb; border-radius: 5px;'>", $is_web_mode);
    output("🎉 PERBAIKAN REKAMAN STOK BERHASIL DISELESAIKAN! 🎉", $is_web_mode);
    output("</p>", $is_web_mode);
    
} catch (\Exception $e) {
    DB::rollBack();
    
    output("", $is_web_mode);
    output("<h2 class='error'>❌ ERROR</h2>", $is_web_mode);
    output("<p class='error'>Terjadi kesalahan saat memperbaiki rekaman stok:</p>", $is_web_mode);
    output("<p class='error'>" . $e->getMessage() . "</p>", $is_web_mode);
    output("<p class='error'>File: " . $e->getFile() . "</p>", $is_web_mode);
    output("<p class='error'>Line: " . $e->getLine() . "</p>", $is_web_mode);
    output("<p class='warning'>Semua perubahan telah di-rollback.</p>", $is_web_mode);
}

// Akhiri output HTML jika web mode
end_html();
