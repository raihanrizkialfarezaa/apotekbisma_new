<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== ANALISIS SISTEM SINKRONISASI STOK ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Check data inconsistent
$inconsistentCount = DB::table('rekaman_stoks as rs')
    ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
    ->where(function($query) {
        $query->whereRaw('rs.stok_awal != p.stok')
              ->orWhereRaw('rs.stok_sisa != p.stok');
    })
    ->whereIn('rs.id_rekaman_stok', function($query) {
        $query->select(DB::raw('MAX(id_rekaman_stok)'))
              ->from('rekaman_stoks')
              ->groupBy('id_produk');
    })
    ->count();

echo "1. STATUS KONSISTENSI DATA:\n";
echo "   Rekaman tidak konsisten: $inconsistentCount\n";

if ($inconsistentCount > 0) {
    echo "   Status: ❌ PERLU SINKRONISASI\n";
} else {
    echo "   Status: ✅ SEMUA DATA KONSISTEN\n";
}

// 2. Check stok minus
$stokMinus = DB::table('produk')->where('stok', '<', 0)->count();
$rekamanAwalMinus = DB::table('rekaman_stoks')->where('stok_awal', '<', 0)->count();
$rekamanSisaMinus = DB::table('rekaman_stoks')->where('stok_sisa', '<', 0)->count();

echo "\n2. STATUS STOK MINUS:\n";
echo "   Produk stok minus: $stokMinus\n";
echo "   Rekaman stok_awal minus: $rekamanAwalMinus\n";
echo "   Rekaman stok_sisa minus: $rekamanSisaMinus\n";

$totalMinus = $stokMinus + $rekamanAwalMinus + $rekamanSisaMinus;
if ($totalMinus > 0) {
    echo "   Status: ❌ ADA STOK MINUS\n";
} else {
    echo "   Status: ✅ TIDAK ADA STOK MINUS\n";
}

// 3. Check transaksi terbaru
echo "\n3. TRANSAKSI TERBARU:\n";

$penjualanTerbaru = DB::table('penjualan')->orderBy('created_at', 'desc')->first();
$pembelianTerbaru = DB::table('pembelian')->orderBy('created_at', 'desc')->first();

if ($penjualanTerbaru) {
    echo "   Penjualan terakhir: " . $penjualanTerbaru->created_at . " (ID: {$penjualanTerbaru->id_penjualan})\n";
}
if ($pembelianTerbaru) {
    echo "   Pembelian terakhir: " . $pembelianTerbaru->created_at . " (ID: {$pembelianTerbaru->id_pembelian})\n";
}

// 4. Check rekaman stok terbaru
$rekamanTerbaru = DB::table('rekaman_stoks as rs')
    ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
    ->select('rs.*', 'p.nama_produk')
    ->orderBy('rs.created_at', 'desc')
    ->limit(5)
    ->get();

echo "\n4. REKAMAN STOK TERBARU (5 data):\n";
foreach ($rekamanTerbaru as $rekaman) {
    echo "   {$rekaman->nama_produk}: Awal={$rekaman->stok_awal}, Masuk={$rekaman->stok_masuk}, Keluar={$rekaman->stok_keluar}, Sisa={$rekaman->stok_sisa}\n";
}

// 5. Check Observer dan Trigger
echo "\n5. SISTEM OTOMATIS:\n";

// Check apakah ada ProdukObserver
$observerFile = app_path('Observers/ProdukObserver.php');
if (file_exists($observerFile)) {
    echo "   ✅ ProdukObserver ada\n";
} else {
    echo "   ❌ ProdukObserver tidak ditemukan\n";
}

// Check model Produk untuk Observer registration
$produkFile = app_path('Models/Produk.php');
if (file_exists($produkFile)) {
    $content = file_get_contents($produkFile);
    if (strpos($content, 'observe') !== false) {
        echo "   ✅ Observer terdaftar di model\n";
    } else {
        echo "   ❌ Observer belum terdaftar\n";
    }
}

// 6. Test sample data consistency
echo "\n6. SAMPLE CONSISTENCY CHECK:\n";
$sampleData = DB::table('rekaman_stoks as rs')
    ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
    ->select('p.nama_produk', 'p.stok as current_stok', 'rs.stok_awal', 'rs.stok_sisa')
    ->whereIn('rs.id_rekaman_stok', function($query) {
        $query->select(DB::raw('MAX(id_rekaman_stok)'))
              ->from('rekaman_stoks')
              ->groupBy('id_produk');
    })
    ->limit(10)
    ->get();

$consistent = 0;
$inconsistent = 0;

foreach ($sampleData as $data) {
    if ($data->current_stok == $data->stok_awal && $data->current_stok == $data->stok_sisa) {
        $consistent++;
    } else {
        $inconsistent++;
        echo "   ❌ {$data->nama_produk}: Stock={$data->current_stok}, Awal={$data->stok_awal}, Sisa={$data->stok_sisa}\n";
    }
}

echo "   Sample check: {$consistent} konsisten, {$inconsistent} tidak konsisten\n";

// 7. REKOMENDASI
echo "\n7. REKOMENDASI:\n";

if ($inconsistentCount > 0) {
    echo "   🔧 PERLU SINKRONISASI: Jalankan sinkronisasi untuk memperbaiki {$inconsistentCount} data\n";
}

if ($totalMinus > 0) {
    echo "   🔧 PERLU PERBAIKAN: Ada {$totalMinus} data dengan stok minus\n";
}

if ($inconsistentCount == 0 && $totalMinus == 0) {
    echo "   ✅ SISTEM SEHAT: Semua data konsisten dan tidak ada stok minus\n";
}

echo "\n=== ANALISIS SELESAI ===\n";
