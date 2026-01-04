<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;
use App\Models\Pembelian;
use App\Models\PembelianDetail;

$outputFile = __DIR__ . '/stress_test_result_' . date('Ymd_His') . '.txt';
$output = [];

function out($msg, &$output) {
    $output[] = $msg;
    echo $msg . "\n";
}

set_time_limit(3600);
ini_set('memory_limit', '2G');

out("=======================================================", $output);
out("   COMPREHENSIVE STRESS TEST & ROBUSTNESS VERIFICATION", $output);
out("   Tanggal: " . date('Y-m-d H:i:s'), $output);
out("=======================================================", $output);
out("", $output);

$allIssues = [];
$warningCount = 0;
$errorCount = 0;
$passCount = 0;

out("=======================================================", $output);
out("   TEST 1: INTEGRITAS TABEL PRODUK", $output);
out("=======================================================", $output);
out("", $output);

$allProducts = Produk::all();
$totalProducts = count($allProducts);
out("Total produk: {$totalProducts}", $output);

$negativeStock = [];
$nullStock = [];
$nonIntegerStock = [];

foreach ($allProducts as $produk) {
    $rawStok = DB::table('produk')->where('id_produk', $produk->id_produk)->value('stok');
    
    if ($rawStok === null) {
        $nullStock[] = $produk->nama_produk;
    } elseif ($rawStok < 0) {
        $negativeStock[] = ['nama' => $produk->nama_produk, 'stok' => $rawStok];
    } elseif ($rawStok != intval($rawStok)) {
        $nonIntegerStock[] = ['nama' => $produk->nama_produk, 'stok' => $rawStok];
    }
}

if (empty($negativeStock)) {
    out("[PASS] Tidak ada produk dengan stok negatif", $output);
    $passCount++;
} else {
    out("[ERROR] " . count($negativeStock) . " produk dengan stok negatif:", $output);
    foreach ($negativeStock as $p) {
        out("  - {$p['nama']}: {$p['stok']}", $output);
    }
    $errorCount++;
    $allIssues[] = "Produk dengan stok negatif: " . count($negativeStock);
}

if (empty($nullStock)) {
    out("[PASS] Tidak ada produk dengan stok NULL", $output);
    $passCount++;
} else {
    out("[ERROR] " . count($nullStock) . " produk dengan stok NULL", $output);
    $errorCount++;
    $allIssues[] = "Produk dengan stok NULL: " . count($nullStock);
}

if (empty($nonIntegerStock)) {
    out("[PASS] Semua stok adalah integer", $output);
    $passCount++;
} else {
    out("[ERROR] " . count($nonIntegerStock) . " produk dengan stok non-integer:", $output);
    foreach ($nonIntegerStock as $p) {
        out("  - {$p['nama']}: {$p['stok']}", $output);
    }
    $errorCount++;
    $allIssues[] = "Produk dengan stok non-integer: " . count($nonIntegerStock);
}

out("", $output);

out("=======================================================", $output);
out("   TEST 2: SINKRONISASI STOK PRODUK vs KARTU STOK", $output);
out("=======================================================", $output);
out("", $output);

$stokMismatch = [];
$noRekamanButHasStock = [];
$hasRekamanButNoStock = [];

foreach ($allProducts as $produk) {
    $lastRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $stokProduk = intval($produk->stok);
    
    if ($lastRekaman) {
        $stokRekaman = intval($lastRekaman->stok_sisa);
        if ($stokProduk !== $stokRekaman) {
            $stokMismatch[] = [
                'nama' => $produk->nama_produk,
                'stok_produk' => $stokProduk,
                'stok_rekaman' => $stokRekaman,
                'selisih' => $stokProduk - $stokRekaman
            ];
        }
    } else {
        if ($stokProduk > 0) {
            $noRekamanButHasStock[] = ['nama' => $produk->nama_produk, 'stok' => $stokProduk];
        }
    }
}

if (empty($stokMismatch)) {
    out("[PASS] Semua stok produk sinkron dengan kartu stok terakhir", $output);
    $passCount++;
} else {
    out("[ERROR] " . count($stokMismatch) . " produk TIDAK sinkron dengan kartu stok:", $output);
    usort($stokMismatch, fn($a, $b) => abs($b['selisih']) - abs($a['selisih']));
    foreach (array_slice($stokMismatch, 0, 20) as $m) {
        out("  - {$m['nama']}: produk={$m['stok_produk']}, kartu={$m['stok_rekaman']}, selisih={$m['selisih']}", $output);
    }
    if (count($stokMismatch) > 20) {
        out("  ... dan " . (count($stokMismatch) - 20) . " lainnya", $output);
    }
    $errorCount++;
    $allIssues[] = "Stok produk tidak sinkron dengan kartu: " . count($stokMismatch);
}

if (count($noRekamanButHasStock) > 0) {
    out("[WARNING] " . count($noRekamanButHasStock) . " produk punya stok tapi tidak ada kartu stok:", $output);
    foreach (array_slice($noRekamanButHasStock, 0, 10) as $p) {
        out("  - {$p['nama']}: stok={$p['stok']}", $output);
    }
    $warningCount++;
}

out("", $output);

out("=======================================================", $output);
out("   TEST 3: INTEGRITAS URUTAN KARTU STOK", $output);
out("=======================================================", $output);
out("", $output);

$kartuStokBroken = [];
$kartuStokNegative = [];
$kartuStokChainBroken = [];

$produkWithRekaman = DB::table('rekaman_stoks')->distinct()->pluck('id_produk');
out("Total produk dengan rekaman stok: " . count($produkWithRekaman), $output);

$checkedProducts = 0;
foreach ($produkWithRekaman as $produkId) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $produkId)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    $produkName = Produk::find($produkId)->nama_produk ?? "ID: {$produkId}";
    
    $prevSisa = null;
    foreach ($records as $idx => $r) {
        $calculatedSisa = intval($r->stok_awal) + intval($r->stok_masuk) - intval($r->stok_keluar);
        
        if (intval($r->stok_sisa) !== $calculatedSisa) {
            $kartuStokBroken[] = [
                'produk' => $produkName,
                'id_rekaman' => $r->id_rekaman_stok,
                'stok_awal' => $r->stok_awal,
                'masuk' => $r->stok_masuk,
                'keluar' => $r->stok_keluar,
                'stored_sisa' => $r->stok_sisa,
                'calculated_sisa' => $calculatedSisa
            ];
        }
        
        if (intval($r->stok_sisa) < 0) {
            $kartuStokNegative[] = [
                'produk' => $produkName,
                'id_rekaman' => $r->id_rekaman_stok,
                'stok_sisa' => $r->stok_sisa
            ];
        }
        
        if ($prevSisa !== null && $idx > 0) {
            $isManualAdjustment = (
                $r->id_penjualan === null && 
                $r->id_pembelian === null && 
                (strpos($r->keterangan ?? '', 'Stock Opname') !== false || 
                 strpos($r->keterangan ?? '', 'Penyesuaian') !== false ||
                 strpos($r->keterangan ?? '', 'Manual') !== false)
            );
            
            if (!$isManualAdjustment && intval($r->stok_awal) !== $prevSisa) {
                $kartuStokChainBroken[] = [
                    'produk' => $produkName,
                    'id_rekaman' => $r->id_rekaman_stok,
                    'expected_stok_awal' => $prevSisa,
                    'actual_stok_awal' => $r->stok_awal
                ];
            }
        }
        
        $prevSisa = intval($r->stok_sisa);
    }
    
    $checkedProducts++;
    if ($checkedProducts % 200 === 0) {
        echo "  Checked {$checkedProducts}/" . count($produkWithRekaman) . " products...\n";
    }
}

if (empty($kartuStokBroken)) {
    out("[PASS] Semua formula kartu stok benar (stok_awal + masuk - keluar = stok_sisa)", $output);
    $passCount++;
} else {
    out("[ERROR] " . count($kartuStokBroken) . " rekaman dengan formula salah:", $output);
    foreach (array_slice($kartuStokBroken, 0, 10) as $k) {
        out("  - {$k['produk']} (ID:{$k['id_rekaman']}): {$k['stok_awal']}+{$k['masuk']}-{$k['keluar']}={$k['calculated_sisa']}, stored={$k['stored_sisa']}", $output);
    }
    $errorCount++;
    $allIssues[] = "Kartu stok dengan formula salah: " . count($kartuStokBroken);
}

if (empty($kartuStokNegative)) {
    out("[PASS] Tidak ada kartu stok dengan stok_sisa negatif", $output);
    $passCount++;
} else {
    out("[WARNING] " . count($kartuStokNegative) . " rekaman dengan stok_sisa negatif", $output);
    $warningCount++;
}

if (empty($kartuStokChainBroken)) {
    out("[PASS] Rantai kartu stok terhubung dengan benar", $output);
    $passCount++;
} else {
    out("[WARNING] " . count($kartuStokChainBroken) . " rekaman dengan rantai terputus:", $output);
    foreach (array_slice($kartuStokChainBroken, 0, 10) as $k) {
        out("  - {$k['produk']} (ID:{$k['id_rekaman']}): expected_awal={$k['expected_stok_awal']}, actual={$k['actual_stok_awal']}", $output);
    }
    $warningCount++;
}

out("", $output);

out("=======================================================", $output);
out("   TEST 4: KONSISTENSI TRANSAKSI vs KARTU STOK", $output);
out("=======================================================", $output);
out("", $output);

$penjualanNoRekaman = [];
$penjualanQtyMismatch = [];

$allPenjualanDetail = DB::table('penjualan_detail')
    ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
    ->select('penjualan_detail.*', 'penjualan.waktu')
    ->get();

out("Total detail penjualan: " . count($allPenjualanDetail), $output);

$penjualanGrouped = [];
foreach ($allPenjualanDetail as $pd) {
    $key = $pd->id_penjualan . '_' . $pd->id_produk;
    if (!isset($penjualanGrouped[$key])) {
        $penjualanGrouped[$key] = [
            'id_penjualan' => $pd->id_penjualan,
            'id_produk' => $pd->id_produk,
            'total_qty' => 0
        ];
    }
    $penjualanGrouped[$key]['total_qty'] += intval($pd->jumlah);
}

foreach ($penjualanGrouped as $pg) {
    $rekaman = DB::table('rekaman_stoks')
        ->where('id_penjualan', $pg['id_penjualan'])
        ->where('id_produk', $pg['id_produk'])
        ->first();
    
    if (!$rekaman) {
        $penjualanNoRekaman[] = $pg;
    } else {
        if (intval($rekaman->stok_keluar) !== $pg['total_qty']) {
            $penjualanQtyMismatch[] = [
                'id_penjualan' => $pg['id_penjualan'],
                'id_produk' => $pg['id_produk'],
                'detail_qty' => $pg['total_qty'],
                'rekaman_qty' => intval($rekaman->stok_keluar)
            ];
        }
    }
}

if (empty($penjualanNoRekaman)) {
    out("[PASS] Semua penjualan punya kartu stok", $output);
    $passCount++;
} else {
    out("[WARNING] " . count($penjualanNoRekaman) . " penjualan tanpa kartu stok", $output);
    $warningCount++;
}

if (empty($penjualanQtyMismatch)) {
    out("[PASS] Semua qty penjualan cocok dengan kartu stok", $output);
    $passCount++;
} else {
    out("[ERROR] " . count($penjualanQtyMismatch) . " penjualan dengan qty tidak cocok:", $output);
    foreach (array_slice($penjualanQtyMismatch, 0, 10) as $m) {
        $produkName = Produk::find($m['id_produk'])->nama_produk ?? "ID:{$m['id_produk']}";
        out("  - Penjualan #{$m['id_penjualan']}, {$produkName}: detail={$m['detail_qty']}, kartu={$m['rekaman_qty']}", $output);
    }
    $errorCount++;
    $allIssues[] = "Penjualan dengan qty tidak cocok: " . count($penjualanQtyMismatch);
}

$pembelianNoRekaman = [];
$pembelianQtyMismatch = [];

$allPembelianDetail = DB::table('pembelian_detail')
    ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
    ->select('pembelian_detail.*', 'pembelian.waktu')
    ->get();

out("Total detail pembelian: " . count($allPembelianDetail), $output);

$pembelianGrouped = [];
foreach ($allPembelianDetail as $pd) {
    $key = $pd->id_pembelian . '_' . $pd->id_produk;
    if (!isset($pembelianGrouped[$key])) {
        $pembelianGrouped[$key] = [
            'id_pembelian' => $pd->id_pembelian,
            'id_produk' => $pd->id_produk,
            'total_qty' => 0
        ];
    }
    $pembelianGrouped[$key]['total_qty'] += intval($pd->jumlah);
}

foreach ($pembelianGrouped as $pg) {
    $rekaman = DB::table('rekaman_stoks')
        ->where('id_pembelian', $pg['id_pembelian'])
        ->where('id_produk', $pg['id_produk'])
        ->first();
    
    if (!$rekaman) {
        $pembelianNoRekaman[] = $pg;
    } else {
        if (intval($rekaman->stok_masuk) !== $pg['total_qty']) {
            $pembelianQtyMismatch[] = [
                'id_pembelian' => $pg['id_pembelian'],
                'id_produk' => $pg['id_produk'],
                'detail_qty' => $pg['total_qty'],
                'rekaman_qty' => intval($rekaman->stok_masuk)
            ];
        }
    }
}

if (empty($pembelianNoRekaman)) {
    out("[PASS] Semua pembelian punya kartu stok", $output);
    $passCount++;
} else {
    out("[WARNING] " . count($pembelianNoRekaman) . " pembelian tanpa kartu stok", $output);
    $warningCount++;
}

if (empty($pembelianQtyMismatch)) {
    out("[PASS] Semua qty pembelian cocok dengan kartu stok", $output);
    $passCount++;
} else {
    out("[ERROR] " . count($pembelianQtyMismatch) . " pembelian dengan qty tidak cocok:", $output);
    foreach (array_slice($pembelianQtyMismatch, 0, 10) as $m) {
        $produkName = Produk::find($m['id_produk'])->nama_produk ?? "ID:{$m['id_produk']}";
        out("  - Pembelian #{$m['id_pembelian']}, {$produkName}: detail={$m['detail_qty']}, kartu={$m['rekaman_qty']}", $output);
    }
    $errorCount++;
    $allIssues[] = "Pembelian dengan qty tidak cocok: " . count($pembelianQtyMismatch);
}

out("", $output);

out("=======================================================", $output);
out("   TEST 5: KALKULASI ABSOLUT (STOK AWAL + IN - OUT = NOW)", $output);
out("=======================================================", $output);
out("", $output);

$calculationMismatch = [];

foreach ($allProducts as $produk) {
    $firstRekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->first();
    
    if (!$firstRekaman) {
        continue;
    }
    
    $stokAwalPertama = intval($firstRekaman->stok_awal);
    
    $totalMasuk = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->sum('stok_masuk');
    
    $totalKeluar = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->sum('stok_keluar');
    
    $expectedStock = $stokAwalPertama + intval($totalMasuk) - intval($totalKeluar);
    if ($expectedStock < 0) $expectedStock = 0;
    
    $actualStock = intval($produk->stok);
    
    if ($expectedStock !== $actualStock) {
        $calculationMismatch[] = [
            'nama' => $produk->nama_produk,
            'stok_awal_pertama' => $stokAwalPertama,
            'total_masuk' => $totalMasuk,
            'total_keluar' => $totalKeluar,
            'expected' => $expectedStock,
            'actual' => $actualStock,
            'diff' => $actualStock - $expectedStock
        ];
    }
}

if (empty($calculationMismatch)) {
    out("[PASS] Semua stok produk = stok_awal_pertama + total_masuk - total_keluar", $output);
    $passCount++;
} else {
    out("[ERROR] " . count($calculationMismatch) . " produk dengan kalkulasi salah:", $output);
    usort($calculationMismatch, fn($a, $b) => abs($b['diff']) - abs($a['diff']));
    foreach (array_slice($calculationMismatch, 0, 20) as $c) {
        out("  - {$c['nama']}: {$c['stok_awal_pertama']}+{$c['total_masuk']}-{$c['total_keluar']}={$c['expected']}, actual={$c['actual']}, diff={$c['diff']}", $output);
    }
    $errorCount++;
    $allIssues[] = "Produk dengan kalkulasi absolut salah: " . count($calculationMismatch);
}

out("", $output);

out("=======================================================", $output);
out("   TEST 6: ORPHAN RECORDS (DATA YATIM)", $output);
out("=======================================================", $output);
out("", $output);

$orphanPenjualanDetail = DB::table('penjualan_detail')
    ->leftJoin('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
    ->whereNull('penjualan.id_penjualan')
    ->count();

$orphanPembelianDetail = DB::table('pembelian_detail')
    ->leftJoin('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
    ->whereNull('pembelian.id_pembelian')
    ->count();

$orphanRekamanPenjualan = DB::table('rekaman_stoks')
    ->whereNotNull('id_penjualan')
    ->leftJoin('penjualan', 'rekaman_stoks.id_penjualan', '=', 'penjualan.id_penjualan')
    ->whereNull('penjualan.id_penjualan')
    ->count();

$orphanRekamanPembelian = DB::table('rekaman_stoks')
    ->whereNotNull('id_pembelian')
    ->leftJoin('pembelian', 'rekaman_stoks.id_pembelian', '=', 'pembelian.id_pembelian')
    ->whereNull('pembelian.id_pembelian')
    ->count();

$orphanRekamanProduk = DB::table('rekaman_stoks')
    ->leftJoin('produk', 'rekaman_stoks.id_produk', '=', 'produk.id_produk')
    ->whereNull('produk.id_produk')
    ->count();

if ($orphanPenjualanDetail === 0) {
    out("[PASS] Tidak ada penjualan_detail yatim", $output);
    $passCount++;
} else {
    out("[WARNING] {$orphanPenjualanDetail} penjualan_detail yatim", $output);
    $warningCount++;
}

if ($orphanPembelianDetail === 0) {
    out("[PASS] Tidak ada pembelian_detail yatim", $output);
    $passCount++;
} else {
    out("[WARNING] {$orphanPembelianDetail} pembelian_detail yatim", $output);
    $warningCount++;
}

if ($orphanRekamanPenjualan === 0) {
    out("[PASS] Tidak ada rekaman_stoks dengan penjualan yatim", $output);
    $passCount++;
} else {
    out("[WARNING] {$orphanRekamanPenjualan} rekaman_stoks dengan penjualan yatim", $output);
    $warningCount++;
}

if ($orphanRekamanPembelian === 0) {
    out("[PASS] Tidak ada rekaman_stoks dengan pembelian yatim", $output);
    $passCount++;
} else {
    out("[WARNING] {$orphanRekamanPembelian} rekaman_stoks dengan pembelian yatim", $output);
    $warningCount++;
}

if ($orphanRekamanProduk === 0) {
    out("[PASS] Tidak ada rekaman_stoks dengan produk yatim", $output);
    $passCount++;
} else {
    out("[ERROR] {$orphanRekamanProduk} rekaman_stoks dengan produk yatim", $output);
    $errorCount++;
    $allIssues[] = "Rekaman stok dengan produk tidak ada: {$orphanRekamanProduk}";
}

out("", $output);

out("=======================================================", $output);
out("   TEST 7: DUPLICATE RECORDS", $output);
out("=======================================================", $output);
out("", $output);

$duplicatePenjualanRekaman = DB::table('rekaman_stoks')
    ->whereNotNull('id_penjualan')
    ->select('id_penjualan', 'id_produk', DB::raw('COUNT(*) as cnt'))
    ->groupBy('id_penjualan', 'id_produk')
    ->having('cnt', '>', 1)
    ->get();

$duplicatePembelianRekaman = DB::table('rekaman_stoks')
    ->whereNotNull('id_pembelian')
    ->select('id_pembelian', 'id_produk', DB::raw('COUNT(*) as cnt'))
    ->groupBy('id_pembelian', 'id_produk')
    ->having('cnt', '>', 1)
    ->get();

if (count($duplicatePenjualanRekaman) === 0) {
    out("[PASS] Tidak ada duplikasi rekaman penjualan", $output);
    $passCount++;
} else {
    out("[ERROR] " . count($duplicatePenjualanRekaman) . " duplikasi rekaman penjualan:", $output);
    foreach (array_slice($duplicatePenjualanRekaman->toArray(), 0, 10) as $d) {
        out("  - Penjualan #{$d->id_penjualan}, Produk #{$d->id_produk}: {$d->cnt} rekaman", $output);
    }
    $errorCount++;
    $allIssues[] = "Duplikasi rekaman penjualan: " . count($duplicatePenjualanRekaman);
}

if (count($duplicatePembelianRekaman) === 0) {
    out("[PASS] Tidak ada duplikasi rekaman pembelian", $output);
    $passCount++;
} else {
    out("[ERROR] " . count($duplicatePembelianRekaman) . " duplikasi rekaman pembelian:", $output);
    foreach (array_slice($duplicatePembelianRekaman->toArray(), 0, 10) as $d) {
        out("  - Pembelian #{$d->id_pembelian}, Produk #{$d->id_produk}: {$d->cnt} rekaman", $output);
    }
    $errorCount++;
    $allIssues[] = "Duplikasi rekaman pembelian: " . count($duplicatePembelianRekaman);
}

out("", $output);

out("=======================================================", $output);
out("   TEST 8: DATA TYPE VALIDATION", $output);
out("=======================================================", $output);
out("", $output);

$nonIntegerInRekaman = DB::select("
    SELECT id_rekaman_stok, id_produk, stok_awal, stok_masuk, stok_keluar, stok_sisa 
    FROM rekaman_stoks 
    WHERE stok_awal != FLOOR(stok_awal) 
       OR stok_masuk != FLOOR(stok_masuk) 
       OR stok_keluar != FLOOR(stok_keluar) 
       OR stok_sisa != FLOOR(stok_sisa)
    LIMIT 20
");

if (count($nonIntegerInRekaman) === 0) {
    out("[PASS] Semua nilai stok di rekaman_stoks adalah integer", $output);
    $passCount++;
} else {
    out("[ERROR] Ditemukan nilai non-integer di rekaman_stoks:", $output);
    foreach ($nonIntegerInRekaman as $r) {
        out("  - ID:{$r->id_rekaman_stok}: awal={$r->stok_awal}, masuk={$r->stok_masuk}, keluar={$r->stok_keluar}, sisa={$r->stok_sisa}", $output);
    }
    $errorCount++;
    $allIssues[] = "Nilai non-integer di rekaman_stoks";
}

out("", $output);

out("=======================================================", $output);
out("   TEST 9: TIMESTAMP CONSISTENCY", $output);
out("=======================================================", $output);
out("", $output);

$futureRekaman = DB::table('rekaman_stoks')
    ->where('waktu', '>', now()->addDay())
    ->count();

$nullWaktuRekaman = DB::table('rekaman_stoks')
    ->whereNull('waktu')
    ->count();

if ($futureRekaman === 0) {
    out("[PASS] Tidak ada rekaman dengan waktu di masa depan", $output);
    $passCount++;
} else {
    out("[WARNING] {$futureRekaman} rekaman dengan waktu di masa depan", $output);
    $warningCount++;
}

if ($nullWaktuRekaman === 0) {
    out("[PASS] Tidak ada rekaman dengan waktu NULL", $output);
    $passCount++;
} else {
    out("[WARNING] {$nullWaktuRekaman} rekaman dengan waktu NULL", $output);
    $warningCount++;
}

out("", $output);

out("=======================================================", $output);
out("   RINGKASAN HASIL", $output);
out("=======================================================", $output);
out("", $output);

$totalTests = $passCount + $warningCount + $errorCount;

out("Total tes: {$totalTests}", $output);
out("[PASS] Lulus: {$passCount}", $output);
out("[WARNING] Peringatan: {$warningCount}", $output);
out("[ERROR] Gagal: {$errorCount}", $output);
out("", $output);

if (!empty($allIssues)) {
    out("DAFTAR MASALAH KRITIS:", $output);
    foreach ($allIssues as $issue) {
        out("  ! {$issue}", $output);
    }
    out("", $output);
}

$isRobust = ($errorCount === 0);

out("=======================================================", $output);
if ($isRobust && $warningCount === 0) {
    out("   STATUS: SISTEM 100% ROBUST", $output);
    out("   Tidak ada error maupun warning.", $output);
} elseif ($isRobust) {
    out("   STATUS: SISTEM ROBUST DENGAN CATATAN", $output);
    out("   Tidak ada error, tapi ada {$warningCount} warning.", $output);
    out("   Warning tidak mengganggu integritas data.", $output);
} else {
    out("   STATUS: SISTEM BELUM ROBUST", $output);
    out("   Ditemukan {$errorCount} error yang perlu diperbaiki.", $output);
}
out("=======================================================", $output);
out("", $output);

out("Selesai pada: " . date('Y-m-d H:i:s'), $output);

file_put_contents($outputFile, implode("\n", $output));
echo "\nHasil tersimpan di: {$outputFile}\n";

exit($isRobust ? 0 : 1);
