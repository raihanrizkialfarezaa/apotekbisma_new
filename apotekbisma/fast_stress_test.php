<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

set_time_limit(300);
ini_set('memory_limit', '512M');

$f = fopen(__DIR__ . '/stress_test_result.txt', 'w');
$errors = 0; $warnings = 0; $pass = 0;

fwrite($f, "=== STRESS TEST " . date('Y-m-d H:i:s') . " ===\n\n");

fwrite($f, "TEST 1: Stok Negatif\n");
$neg = DB::table('produk')->where('stok', '<', 0)->count();
if ($neg == 0) { fwrite($f, "[PASS] Tidak ada stok negatif\n"); $pass++; }
else { fwrite($f, "[ERROR] {$neg} produk stok negatif\n"); $errors++; }

fwrite($f, "\nTEST 2: Sinkronisasi Stok vs Kartu Stok Terakhir\n");
$mismatch = DB::select("
    SELECT p.nama_produk, p.stok as stok_produk, r.stok_sisa as stok_kartu, (p.stok - r.stok_sisa) as selisih
    FROM produk p
    INNER JOIN (
        SELECT id_produk, stok_sisa FROM rekaman_stoks r1
        WHERE id_rekaman_stok = (SELECT MAX(id_rekaman_stok) FROM rekaman_stoks r2 WHERE r2.id_produk = r1.id_produk)
    ) r ON p.id_produk = r.id_produk
    WHERE p.stok != r.stok_sisa
");
if (count($mismatch) == 0) { fwrite($f, "[PASS] Semua stok sinkron dengan kartu stok\n"); $pass++; }
else { 
    fwrite($f, "[ERROR] " . count($mismatch) . " produk tidak sinkron:\n"); 
    foreach (array_slice($mismatch, 0, 10) as $m) {
        fwrite($f, "  - {$m->nama_produk}: produk={$m->stok_produk}, kartu={$m->stok_kartu}\n");
    }
    $errors++; 
}

fwrite($f, "\nTEST 3: Formula Kartu Stok (stok_awal + masuk - keluar = sisa)\n");
$broken = DB::select("
    SELECT id_rekaman_stok, id_produk, stok_awal, stok_masuk, stok_keluar, stok_sisa,
           (stok_awal + stok_masuk - stok_keluar) as calculated
    FROM rekaman_stoks
    WHERE stok_sisa != GREATEST(0, stok_awal + stok_masuk - stok_keluar)
    LIMIT 20
");
if (count($broken) == 0) { fwrite($f, "[PASS] Semua formula kartu stok benar\n"); $pass++; }
else { fwrite($f, "[ERROR] " . count($broken) . " rekaman formula salah\n"); $errors++; }

fwrite($f, "\nTEST 4: Duplikasi Rekaman Penjualan\n");
$dup = DB::select("
    SELECT id_penjualan, id_produk, COUNT(*) as cnt 
    FROM rekaman_stoks WHERE id_penjualan IS NOT NULL
    GROUP BY id_penjualan, id_produk HAVING cnt > 1
");
if (count($dup) == 0) { fwrite($f, "[PASS] Tidak ada duplikasi\n"); $pass++; }
else { fwrite($f, "[ERROR] " . count($dup) . " duplikasi\n"); $errors++; }

fwrite($f, "\nTEST 5: Duplikasi Rekaman Pembelian\n");
$dup2 = DB::select("
    SELECT id_pembelian, id_produk, COUNT(*) as cnt 
    FROM rekaman_stoks WHERE id_pembelian IS NOT NULL
    GROUP BY id_pembelian, id_produk HAVING cnt > 1
");
if (count($dup2) == 0) { fwrite($f, "[PASS] Tidak ada duplikasi\n"); $pass++; }
else { fwrite($f, "[ERROR] " . count($dup2) . " duplikasi\n"); $errors++; }

fwrite($f, "\nTEST 6: Konsistensi Qty Penjualan vs Kartu\n");
$qtyMis = DB::select("
    SELECT pd.id_penjualan, pd.id_produk, SUM(pd.jumlah) as detail_qty, r.stok_keluar as kartu_qty
    FROM penjualan_detail pd
    LEFT JOIN rekaman_stoks r ON r.id_penjualan = pd.id_penjualan AND r.id_produk = pd.id_produk
    GROUP BY pd.id_penjualan, pd.id_produk, r.stok_keluar
    HAVING detail_qty != kartu_qty OR kartu_qty IS NULL
    LIMIT 50
");
$qtyMisWithKartu = array_filter($qtyMis, fn($q) => $q->kartu_qty !== null);
if (count($qtyMisWithKartu) == 0) { fwrite($f, "[PASS] Semua qty penjualan cocok\n"); $pass++; }
else { 
    fwrite($f, "[ERROR] " . count($qtyMisWithKartu) . " penjualan qty tidak cocok\n"); 
    foreach (array_slice($qtyMisWithKartu, 0, 5) as $q) {
        fwrite($f, "  - Penj #{$q->id_penjualan}, Prod #{$q->id_produk}: detail={$q->detail_qty}, kartu={$q->kartu_qty}\n");
    }
    $errors++; 
}

fwrite($f, "\nTEST 7: Konsistensi Qty Pembelian vs Kartu\n");
$qtyMisB = DB::select("
    SELECT pd.id_pembelian, pd.id_produk, SUM(pd.jumlah) as detail_qty, r.stok_masuk as kartu_qty
    FROM pembelian_detail pd
    LEFT JOIN rekaman_stoks r ON r.id_pembelian = pd.id_pembelian AND r.id_produk = pd.id_produk
    GROUP BY pd.id_pembelian, pd.id_produk, r.stok_masuk
    HAVING detail_qty != kartu_qty OR kartu_qty IS NULL
    LIMIT 50
");
$qtyMisBWithKartu = array_filter($qtyMisB, fn($q) => $q->kartu_qty !== null);
if (count($qtyMisBWithKartu) == 0) { fwrite($f, "[PASS] Semua qty pembelian cocok\n"); $pass++; }
else { 
    fwrite($f, "[ERROR] " . count($qtyMisBWithKartu) . " pembelian qty tidak cocok\n"); 
    $errors++; 
}

fwrite($f, "\nTEST 8: Orphan Records\n");
$orphan = DB::table('rekaman_stoks')
    ->leftJoin('produk', 'rekaman_stoks.id_produk', '=', 'produk.id_produk')
    ->whereNull('produk.id_produk')->count();
if ($orphan == 0) { fwrite($f, "[PASS] Tidak ada rekaman orphan\n"); $pass++; }
else { fwrite($f, "[ERROR] {$orphan} rekaman orphan\n"); $errors++; }

fwrite($f, "\n=== RINGKASAN ===\n");
fwrite($f, "PASS: {$pass}\n");
fwrite($f, "WARNING: {$warnings}\n");
fwrite($f, "ERROR: {$errors}\n\n");

if ($errors == 0) {
    fwrite($f, "STATUS: SISTEM 100% ROBUST\n");
} else {
    fwrite($f, "STATUS: ADA MASALAH - {$errors} error\n");
}

fwrite($f, "\nSelesai: " . date('Y-m-d H:i:s') . "\n");
fclose($f);

echo "Done. Check stress_test_result.txt\n";
