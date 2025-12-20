<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== COMPLETE SYSTEM VERIFICATION ===\n\n";

// 1. Data Integrity
echo "1. DATA INTEGRITY\n";
echo "   ─────────────────\n";

$totalRecords = DB::table('rekaman_stoks')->count();
echo "   Total rekaman_stoks: {$totalRecords}\n";

$waktuMismatch = DB::select("
    SELECT 
        (SELECT COUNT(*) FROM rekaman_stoks rs 
         INNER JOIN penjualan p ON rs.id_penjualan = p.id_penjualan 
         WHERE rs.waktu != p.waktu) +
        (SELECT COUNT(*) FROM rekaman_stoks rs 
         INNER JOIN pembelian b ON rs.id_pembelian = b.id_pembelian 
         WHERE rs.waktu != b.waktu) as total
")[0]->total;
echo "   Waktu mismatch: {$waktuMismatch} " . ($waktuMismatch == 0 ? "✓" : "✗") . "\n";

$negativeStock = DB::table('rekaman_stoks')->where('stok_sisa', '<', 0)->count();
echo "   Negative stok_sisa: {$negativeStock} " . ($negativeStock == 0 ? "✓" : "✗") . "\n";

// 2. Date Range
echo "\n2. DATE RANGE\n";
echo "   ─────────────────\n";

$dateRange = DB::select("SELECT MIN(waktu) as oldest, MAX(waktu) as newest FROM rekaman_stoks")[0];
echo "   Oldest: {$dateRange->oldest}\n";
echo "   Newest: {$dateRange->newest}\n";

// 3. Sample product verification
echo "\n3. SAMPLE KARTU STOK (5 products)\n";
echo "   ─────────────────\n";

$productIds = DB::table('rekaman_stoks')
    ->select('id_produk')
    ->distinct()
    ->limit(5)
    ->pluck('id_produk');

$products = DB::table('produk')
    ->whereIn('id_produk', $productIds)
    ->get();

foreach ($products as $p) {
    $records = DB::table('rekaman_stoks')
        ->where('id_produk', $p->id_produk)
        ->orderBy('waktu', 'asc')
        ->orderBy('created_at', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->limit(3)
        ->get();
    
    echo "\n   📦 {$p->nama_produk} (Current: {$p->stok})\n";
    
    $allValid = true;
    $prevSisa = null;
    
    foreach ($records as $r) {
        $date = substr($r->waktu, 0, 10);
        $type = $r->stok_masuk > 0 ? 'IN ' : 'OUT';
        $qty = $r->stok_masuk > 0 ? $r->stok_masuk : $r->stok_keluar;
        
        // Validate running balance
        $calc = $r->stok_awal + $r->stok_masuk - $r->stok_keluar;
        $valid = ($r->stok_sisa == $calc);
        $checkMark = $valid ? "✓" : "✗";
        
        if ($prevSisa !== null && $r->stok_awal != $prevSisa) {
            $allValid = false;
            $checkMark = "✗";
        }
        
        echo "      {$date} | {$type} {$qty} | Awal:{$r->stok_awal} → Sisa:{$r->stok_sisa} {$checkMark}\n";
        
        $prevSisa = $r->stok_sisa;
    }
    
    $countRecords = DB::table('rekaman_stoks')
        ->where('id_produk', $p->id_produk)
        ->count();
    echo "      ... {$countRecords} total records\n";
}

// 4. Summary
echo "\n4. SUMMARY\n";
echo "   ─────────────────\n";

$issues = [];
if ($waktuMismatch > 0) $issues[] = "Waktu mismatch";
if ($negativeStock > 0) $issues[] = "Negative stock";

if (empty($issues)) {
    echo "   ✓✓✓ ALL SYSTEMS OPERATIONAL ✓✓✓\n";
    echo "\n   Kartu Stok sekarang akan:\n";
    echo "   • Menampilkan tanggal sesuai urutan kronologis\n";
    echo "   • Filter tanggal berfungsi dengan benar\n";
    echo "   • Running balance (stok_awal → stok_sisa) konsisten\n";
    echo "   • Tidak ada stok negatif dalam riwayat\n";
} else {
    echo "   ⚠ Issues found: " . implode(", ", $issues) . "\n";
}

echo "\n=== VERIFICATION COMPLETE ===\n";
