<?php
/**
 * INVESTIGASI MENDALAM: STOK BODREX = 0 (Seharusnya 9)
 * =====================================================
 * 
 * Problem:
 * - Stok BODREX saat ini: 0
 * - Stok di CSV cutoff 31 Des 2025: 9
 * - Ada keterangan "Stock Opname Cutoff 31 Desember 2025" di tanggal 23 Jan 2026
 * - Ada "BASELINE_OPNAME_31DES2025_V3" di tanggal 31 Des
 * 
 * Investigation:
 * 1. Cek stok produk BODREX saat ini
 * 2. Cek semua rekaman stoks BODREX
 * 3. Cek CSV baseline cutoff
 * 4. Trace perubahan stok dari cutoff sampai sekarang
 * 5. Identifikasi kapan dan kenapa stok jadi 0
 * 6. Cek produk lain dengan masalah serupa
 * 
 * Date: 2026-01-23
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use Carbon\Carbon;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

set_time_limit(0);

echo "=============================================================\n";
echo "  INVESTIGASI KRITIS: STOK BODREX = 0 (SEHARUSNYA 9)\n";
echo "=============================================================\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// ============================================================
// STEP 1: CEK DATA PRODUK BODREX
// ============================================================
echo "╔═══════════════════════════════════════════════════════════╗\n";
echo "║ STEP 1: DATA PRODUK BODREX (ID: 108)                     ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n\n";

$produk = Produk::find(108);

if (!$produk) {
    echo "❌ PRODUK TIDAK DITEMUKAN!\n";
    exit;
}

echo "Kode Produk : {$produk->kode_produk}\n";
echo "Nama Produk : {$produk->nama_produk}\n";
echo "Kategori    : {$produk->id_kategori}\n";
echo "Harga Beli  : Rp " . number_format($produk->harga_beli, 0, ',', '.') . "\n";
echo "Harga Jual  : Rp " . number_format($produk->harga_jual, 0, ',', '.') . "\n";
echo "🔴 STOK SAAT INI: {$produk->stok}\n";
echo "Updated At  : {$produk->updated_at}\n\n";

// ============================================================
// STEP 2: CEK CSV BASELINE CUTOFF 31 DESEMBER 2025
// ============================================================
echo "╔═══════════════════════════════════════════════════════════╗\n";
echo "║ STEP 2: CSV BASELINE CUTOFF (31 DESEMBER 2025)           ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n\n";

$csvFile = __DIR__ . '/REKAMAN STOK FINAL 31 DESEMBER 2025.csv';

if (file_exists($csvFile)) {
    $csvData = array_map('str_getcsv', file($csvFile));
    $header = array_shift($csvData);
    
    echo "CSV File: REKAMAN STOK FINAL 31 DESEMBER 2025.csv\n";
    echo "Total Records: " . count($csvData) . "\n\n";
    
    // Cari BODREX di CSV
    $bodrexFound = false;
    foreach ($csvData as $row) {
        if (count($row) >= 3) {
            $csvKode = trim($row[0]);
            $csvNama = trim($row[1]);
            $csvStok = trim($row[2]);
            
            if ($csvKode === $produk->kode_produk || 
                stripos($csvNama, 'BODREX') !== false) {
                echo "✅ DITEMUKAN DI CSV:\n";
                echo "   Kode: $csvKode\n";
                echo "   Nama: $csvNama\n";
                echo "   🟢 STOK CSV CUTOFF: $csvStok\n\n";
                $bodrexFound = true;
                break;
            }
        }
    }
    
    if (!$bodrexFound) {
        echo "⚠️  BODREX TIDAK DITEMUKAN DI CSV!\n\n";
    }
} else {
    echo "❌ CSV FILE TIDAK DITEMUKAN: $csvFile\n\n";
}

// ============================================================
// STEP 3: ANALISIS SEMUA REKAMAN STOKS BODREX
// ============================================================
echo "╔═══════════════════════════════════════════════════════════╗\n";
echo "║ STEP 3: SEMUA REKAMAN STOKS BODREX                       ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n\n";

$rekamans = DB::table('rekaman_stoks')
    ->where('id_produk', 108)
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

echo "Total Rekaman: {$rekamans->count()}\n\n";

if ($rekamans->count() > 0) {
    echo "NO | WAKTU               | MASUK | KELUAR | AWAL | SISA | KETERANGAN\n";
    echo "---+---------------------+-------+--------+------+------+-" . str_repeat('-', 50) . "\n";
    
    $no = 1;
    foreach ($rekamans as $r) {
        printf("%2d | %19s | %5s | %6s | %4s | %4s | %s\n",
            $no++,
            substr($r->waktu, 0, 19),
            $r->stok_masuk,
            $r->stok_keluar,
            $r->stok_awal,
            $r->stok_sisa,
            substr($r->keterangan, 0, 50)
        );
        
        // Highlight anomali
        $calculated = $r->stok_awal + $r->stok_masuk - $r->stok_keluar;
        if ($calculated != $r->stok_sisa) {
            echo "   ⚠️  ANOMALI: Formula salah! ({$r->stok_awal} + {$r->stok_masuk} - {$r->stok_keluar} = {$calculated}, bukan {$r->stok_sisa})\n";
        }
    }
    echo "\n";
} else {
    echo "❌ TIDAK ADA REKAMAN STOKS!\n\n";
}

// ============================================================
// STEP 4: TRACE PERUBAHAN STOK DARI CUTOFF
// ============================================================
echo "╔═══════════════════════════════════════════════════════════╗\n";
echo "║ STEP 4: TRACE PERUBAHAN STOK DARI CUTOFF                 ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n\n";

$cutoffDate = '2025-12-31';
$rekamansAfterCutoff = DB::table('rekaman_stoks')
    ->where('id_produk', 108)
    ->where('waktu', '>=', $cutoffDate)
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

echo "Rekaman setelah cutoff (>= 31 Des 2025): {$rekamansAfterCutoff->count()}\n\n";

if ($rekamansAfterCutoff->count() > 0) {
    $expectedStok = 9; // Dari CSV
    
    echo "Tracing dari CSV cutoff (stok awal: 9):\n";
    echo "---------------------------------------\n\n";
    
    foreach ($rekamansAfterCutoff as $r) {
        $isBaseline = (stripos($r->keterangan, 'BASELINE') !== false || 
                       stripos($r->keterangan, 'Stock Opname Cutoff 31 Desember 2025') !== false);
        
        if ($isBaseline) {
            echo "📍 BASELINE/CUTOFF RECORD:\n";
            echo "   Waktu: {$r->waktu}\n";
            echo "   Keterangan: {$r->keterangan}\n";
            echo "   Stok Awal: {$r->stok_awal} → Stok Sisa: {$r->stok_sisa}\n";
            
            if ($r->stok_sisa != 9) {
                echo "   ❌ ERROR: Stok sisa seharusnya 9 (dari CSV), tapi: {$r->stok_sisa}\n";
            } else {
                echo "   ✅ OK: Stok sisa = 9 (sesuai CSV)\n";
            }
            echo "\n";
            $expectedStok = $r->stok_sisa;
        } else {
            echo "{$r->waktu} | ";
            
            if ($r->stok_masuk > 0) {
                echo "MASUK: {$r->stok_masuk} ";
                $expectedStok += $r->stok_masuk;
            }
            if ($r->stok_keluar > 0) {
                echo "KELUAR: {$r->stok_keluar} ";
                $expectedStok -= $r->stok_keluar;
            }
            
            echo "| Expected: {$expectedStok}, Actual: {$r->stok_sisa}";
            
            if ($expectedStok != $r->stok_sisa) {
                echo " ❌ MISMATCH!";
            }
            
            echo " | " . substr($r->keterangan, 0, 40) . "\n";
        }
    }
    
    echo "\n";
    echo "Expected Stok Akhir: $expectedStok\n";
    echo "Actual Stok di Produk: {$produk->stok}\n";
    
    if ($expectedStok != $produk->stok) {
        echo "❌ KETIDAKSESUAIAN DETECTED!\n";
    } else {
        echo "✅ Stok konsisten\n";
    }
    echo "\n";
} else {
    echo "⚠️  TIDAK ADA REKAMAN SETELAH CUTOFF!\n";
    echo "Ini menjelaskan kenapa stok = 0 (tidak ada baseline record)\n\n";
}

// ============================================================
// STEP 5: CEK CHAIN INTEGRITY
// ============================================================
echo "╔═══════════════════════════════════════════════════════════╗\n";
echo "║ STEP 5: CHAIN INTEGRITY CHECK                            ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n\n";

$allRekamans = DB::table('rekaman_stoks')
    ->where('id_produk', 108)
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

$chainValid = true;
$prevSisa = null;
$errors = [];

foreach ($allRekamans as $idx => $r) {
    // Check formula
    $calculated = $r->stok_awal + $r->stok_masuk - $r->stok_keluar;
    if ($calculated != $r->stok_sisa) {
        $chainValid = false;
        $errors[] = "Record #{$idx}: Formula error at {$r->waktu} - {$r->keterangan}";
    }
    
    // Check chain (skip baseline records)
    if ($prevSisa !== null) {
        $isBaseline = (stripos($r->keterangan, 'BASELINE') !== false || 
                       stripos($r->keterangan, 'Stock Opname') !== false);
        
        if (!$isBaseline && $r->stok_awal != $prevSisa) {
            $chainValid = false;
            $errors[] = "Record #{$idx}: Chain break at {$r->waktu} - Expected awal: {$prevSisa}, got: {$r->stok_awal}";
        }
    }
    
    $prevSisa = $r->stok_sisa;
}

if ($chainValid) {
    echo "✅ Chain Integrity: VALID\n\n";
} else {
    echo "❌ Chain Integrity: BROKEN\n\n";
    echo "Errors detected:\n";
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
    echo "\n";
}

// ============================================================
// STEP 6: CEK PRODUK LAIN DENGAN MASALAH SERUPA
// ============================================================
echo "╔═══════════════════════════════════════════════════════════╗\n";
echo "║ STEP 6: CEK PRODUK LAIN DENGAN MASALAH SERUPA            ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n\n";

echo "Mencari produk dengan stok = 0 tapi seharusnya > 0 di CSV...\n\n";

if (file_exists($csvFile)) {
    $csvData = array_map('str_getcsv', file($csvFile));
    $header = array_shift($csvData);
    
    $problematicProducts = [];
    
    foreach ($csvData as $row) {
        if (count($row) >= 3) {
            $csvKode = trim($row[0]);
            $csvStok = intval(trim($row[2]));
            
            if ($csvStok > 0) {
                $produk = Produk::where('kode_produk', $csvKode)->first();
                
                if ($produk && $produk->stok == 0) {
                    $problematicProducts[] = [
                        'id' => $produk->id_produk,
                        'kode' => $produk->kode_produk,
                        'nama' => $produk->nama_produk,
                        'csv_stok' => $csvStok,
                        'actual_stok' => $produk->stok
                    ];
                }
            }
        }
    }
    
    echo "Produk dengan masalah serupa: " . count($problematicProducts) . "\n\n";
    
    if (count($problematicProducts) > 0) {
        echo "ID  | KODE PRODUK | NAMA PRODUK                           | CSV | ACTUAL\n";
        echo "----+-------------+---------------------------------------+-----+--------\n";
        
        foreach (array_slice($problematicProducts, 0, 20) as $p) {
            printf("%3d | %-11s | %-37s | %3d | %6d\n",
                $p['id'],
                $p['kode'],
                substr($p['nama'], 0, 37),
                $p['csv_stok'],
                $p['actual_stok']
            );
        }
        
        if (count($problematicProducts) > 20) {
            echo "\n... dan " . (count($problematicProducts) - 20) . " produk lainnya.\n";
        }
    } else {
        echo "✅ Tidak ada produk lain dengan masalah serupa.\n";
    }
}

echo "\n";

// ============================================================
// STEP 7: ROOT CAUSE ANALYSIS
// ============================================================
echo "╔═══════════════════════════════════════════════════════════╗\n";
echo "║ STEP 7: ROOT CAUSE ANALYSIS                              ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n\n";

echo "KEMUNGKINAN PENYEBAB:\n\n";

// Check 1: Apakah ada baseline record?
$baselineRecord = DB::table('rekaman_stoks')
    ->where('id_produk', 108)
    ->where(function($q) {
        $q->where('keterangan', 'LIKE', '%BASELINE%')
          ->orWhere('keterangan', 'LIKE', '%Stock Opname Cutoff 31 Desember 2025%');
    })
    ->first();

if (!$baselineRecord) {
    echo "❌ CAUSE 1: BASELINE RECORD TIDAK ADA\n";
    echo "   - Produk BODREX tidak memiliki baseline record dari cutoff 31 Des 2025\n";
    echo "   - Script create_so_baseline.php mungkin tidak dijalankan untuk produk ini\n";
    echo "   - Atau baseline record terhapus\n\n";
} else {
    echo "✅ CAUSE 1: BASELINE RECORD ADA\n";
    echo "   - Waktu: {$baselineRecord->waktu}\n";
    echo "   - Stok Sisa: {$baselineRecord->stok_sisa}\n";
    echo "   - Keterangan: {$baselineRecord->keterangan}\n\n";
}

// Check 2: Apakah ada transaksi yang mengurangi stok jadi 0?
$transactionsToZero = DB::table('rekaman_stoks')
    ->where('id_produk', 108)
    ->where('stok_sisa', 0)
    ->orderBy('waktu', 'desc')
    ->first();

if ($transactionsToZero) {
    echo "❌ CAUSE 2: ADA TRANSAKSI YANG MEMBUAT STOK = 0\n";
    echo "   - Waktu: {$transactionsToZero->waktu}\n";
    echo "   - Stok Masuk: {$transactionsToZero->stok_masuk}\n";
    echo "   - Stok Keluar: {$transactionsToZero->stok_keluar}\n";
    echo "   - Stok Awal: {$transactionsToZero->stok_awal}\n";
    echo "   - Keterangan: {$transactionsToZero->keterangan}\n\n";
} else {
    echo "✅ CAUSE 2: Tidak ada transaksi yang membuat stok = 0\n\n";
}

// Check 3: Apakah stok di-reset manual?
$manualReset = DB::table('rekaman_stoks')
    ->where('id_produk', 108)
    ->where('keterangan', 'LIKE', '%Stock Opname%')
    ->where('stok_sisa', 0)
    ->orderBy('waktu', 'desc')
    ->first();

if ($manualReset) {
    echo "❌ CAUSE 3: STOK DI-RESET MANUAL JADI 0\n";
    echo "   - Waktu: {$manualReset->waktu}\n";
    echo "   - Keterangan: {$manualReset->keterangan}\n\n";
} else {
    echo "✅ CAUSE 3: Tidak ada reset manual ke 0\n\n";
}

// ============================================================
// KESIMPULAN & REKOMENDASI
// ============================================================
echo "╔═══════════════════════════════════════════════════════════╗\n";
echo "║ KESIMPULAN & REKOMENDASI                                 ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n\n";

echo "KESIMPULAN:\n";
echo "-----------\n\n";

if (!$baselineRecord) {
    echo "🔴 MASALAH UTAMA: Baseline record cutoff 31 Des 2025 TIDAK ADA untuk BODREX\n\n";
    echo "REKOMENDASI:\n";
    echo "1. Jalankan script: php create_so_baseline.php\n";
    echo "2. Pastikan CSV 'REKAMAN STOK FINAL 31 DESEMBER 2025.csv' ada\n";
    echo "3. Verifikasi dengan: php ultra_verification.php\n";
} else {
    echo "🟡 Baseline record ADA, tapi stok sekarang = 0\n";
    echo "Kemungkinan ada transaksi yang salah atau recalculate yang error\n\n";
    echo "REKOMENDASI:\n";
    echo "1. Periksa transaksi setelah cutoff\n";
    echo "2. Jalankan: RekamanStok::recalculateStock(108) untuk rebuild\n";
    echo "3. Atau update manual stok jadi 9\n";
}

echo "\n=============================================================\n";
echo "Investigasi selesai: " . date('Y-m-d H:i:s') . "\n";
echo "=============================================================\n";
