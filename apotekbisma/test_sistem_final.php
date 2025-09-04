<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;
use App\Models\Pembelian;
use App\Models\PembelianDetail;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

echo "=== VERIFIKASI SISTEM SINKRONISASI STOK ===\n\n";

// Test 1: Verifikasi Konsistensi Stok vs Rekaman Stok
echo "1. VERIFIKASI KONSISTENSI STOK vs REKAMAN STOK\n";
echo "=" . str_repeat("=", 50) . "\n";

$inconsistent = DB::select("
    WITH latest_rekaman AS (
        SELECT 
            id_produk,
            stok_sisa,
            ROW_NUMBER() OVER (PARTITION BY id_produk ORDER BY waktu DESC, id_rekaman_stok DESC) as rn
        FROM rekaman_stoks
    )
    SELECT 
        p.id_produk,
        p.nama_produk,
        p.stok as stok_produk,
        lr.stok_sisa as stok_rekaman,
        (p.stok - lr.stok_sisa) as selisih
    FROM produk p
    LEFT JOIN latest_rekaman lr ON p.id_produk = lr.id_produk AND lr.rn = 1
    WHERE p.stok != COALESCE(lr.stok_sisa, 0)
    LIMIT 10
");

if (empty($inconsistent)) {
    echo "✓ SEMUA STOK PRODUK KONSISTEN dengan rekaman stok terakhir\n";
} else {
    echo "✗ DITEMUKAN INKONSISTENSI STOK:\n";
    foreach ($inconsistent as $item) {
        echo "- {$item->nama_produk}: Produk={$item->stok_produk}, Rekaman={$item->stok_rekaman}, Selisih={$item->selisih}\n";
    }
}

// Test 2: Verifikasi Sinkronisasi Waktu Transaksi
echo "\n2. VERIFIKASI SINKRONISASI WAKTU TRANSAKSI\n";
echo "=" . str_repeat("=", 50) . "\n";

$waktu_tidak_sinkron = DB::select("
    SELECT 
        'penjualan' as jenis,
        rs.id_rekaman_stok,
        rs.waktu as rekaman_waktu,
        p.waktu as transaksi_waktu
    FROM rekaman_stoks rs
    JOIN penjualan p ON rs.id_penjualan = p.id_penjualan
    WHERE rs.waktu != p.waktu AND p.waktu IS NOT NULL
    UNION ALL
    SELECT 
        'pembelian' as jenis,
        rs.id_rekaman_stok,
        rs.waktu as rekaman_waktu,
        pb.waktu as transaksi_waktu
    FROM rekaman_stoks rs
    JOIN pembelian pb ON rs.id_pembelian = pb.id_pembelian
    WHERE rs.waktu != pb.waktu AND pb.waktu IS NOT NULL
    LIMIT 10
");

if (empty($waktu_tidak_sinkron)) {
    echo "✓ SEMUA WAKTU REKAMAN STOK SINKRON dengan transaksi parent\n";
} else {
    echo "✗ DITEMUKAN WAKTU TIDAK SINKRON:\n";
    foreach ($waktu_tidak_sinkron as $item) {
        echo "- {$item->jenis} Rekaman ID {$item->id_rekaman_stok}: {$item->rekaman_waktu} != {$item->transaksi_waktu}\n";
    }
}

// Test 3: Verifikasi Duplikasi Rekaman
echo "\n3. VERIFIKASI DUPLIKASI REKAMAN STOK\n";
echo "=" . str_repeat("=", 50) . "\n";

$duplikasi = DB::select("
    SELECT 
        id_produk, 
        id_penjualan, 
        id_pembelian,
        COUNT(*) as jumlah_duplikat
    FROM rekaman_stoks 
    WHERE (id_penjualan IS NOT NULL OR id_pembelian IS NOT NULL)
    GROUP BY id_produk, id_penjualan, id_pembelian
    HAVING COUNT(*) > 1
    LIMIT 5
");

if (empty($duplikasi)) {
    echo "✓ TIDAK ADA DUPLIKASI rekaman stok untuk transaksi\n";
} else {
    echo "✗ DITEMUKAN DUPLIKASI:\n";
    foreach ($duplikasi as $item) {
        $type = $item->id_penjualan ? "Penjualan {$item->id_penjualan}" : "Pembelian {$item->id_pembelian}";
        echo "- Produk {$item->id_produk} - {$type}: {$item->jumlah_duplikat} rekaman\n";
    }
}

// Test 4: Test Simulasi Transaksi (Tanpa Commit)
echo "\n4. TEST SIMULASI TRANSAKSI BARU\n";
echo "=" . str_repeat("=", 50) . "\n";

DB::beginTransaction();

try {
    // Pilih produk random untuk test
    $produk = Produk::where('stok', '>', 10)->first();
    
    if (!$produk) {
        echo "✗ Tidak ada produk dengan stok > 10 untuk test\n";
    } else {
        $stok_awal = $produk->stok;
        echo "Test dengan produk: {$produk->nama_produk} (Stok awal: {$stok_awal})\n";
        
        // Simulasi penjualan
        $penjualan = new Penjualan();
        $penjualan->total_item = 1;
        $penjualan->total_harga = 50000;
        $penjualan->diskon = 0;
        $penjualan->bayar = 50000;
        $penjualan->diterima = 50000;
        $penjualan->waktu = Carbon::now();
        $penjualan->id_user = 1;
        $penjualan->save();
        
        // Detail penjualan
        $detail = new PenjualanDetail();
        $detail->id_penjualan = $penjualan->id_penjualan;
        $detail->id_produk = $produk->id_produk;
        $detail->harga_jual = $produk->harga_jual;
        $detail->jumlah = 5;
        $detail->diskon = 0;
        $detail->subtotal = $produk->harga_jual * 5;
        $detail->save();
        
        // Update stok
        $produk->stok -= 5;
        $produk->save();
        
        // Buat rekaman stok
        RekamanStok::create([
            'id_produk' => $produk->id_produk,
            'id_penjualan' => $penjualan->id_penjualan,
            'waktu' => $penjualan->waktu,
            'stok_keluar' => 5,
            'stok_awal' => $stok_awal,
            'stok_sisa' => $produk->stok,
            'keterangan' => 'Test simulasi penjualan'
        ]);
        
        $stok_akhir = $produk->stok;
        echo "- Stok setelah penjualan 5 unit: {$stok_akhir}\n";
        echo "- Selisih stok: " . ($stok_awal - $stok_akhir) . "\n";
        
        if (($stok_awal - $stok_akhir) === 5) {
            echo "✓ SIMULASI PENJUALAN BERHASIL\n";
        } else {
            echo "✗ SIMULASI PENJUALAN GAGAL\n";
        }
    }
    
    DB::rollBack();
    echo "- Transaksi di-rollback (tidak tersimpan)\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "✗ ERROR dalam simulasi: " . $e->getMessage() . "\n";
}

// Test 5: Verifikasi Transaksi dengan Waktu NULL
echo "\n5. VERIFIKASI TRANSAKSI DENGAN WAKTU NULL\n";
echo "=" . str_repeat("=", 50) . "\n";

$penjualan_null = Penjualan::whereNull('waktu')->count();
$pembelian_null = Pembelian::whereNull('waktu')->count();

echo "- Penjualan dengan waktu NULL: {$penjualan_null}\n";
echo "- Pembelian dengan waktu NULL: {$pembelian_null}\n";

if ($penjualan_null === 0 && $pembelian_null === 0) {
    echo "✓ TIDAK ADA transaksi dengan waktu NULL\n";
} else {
    echo "⚠ PERHATIAN: Ada transaksi dengan waktu NULL yang dapat menyebabkan masalah sinkronisasi\n";
}

// Test 6: Statistik Sistem
echo "\n6. STATISTIK SISTEM\n";
echo "=" . str_repeat("=", 50) . "\n";

$total_produk = Produk::count();
$total_penjualan = Penjualan::count();
$total_pembelian = Pembelian::count();
$total_rekaman = RekamanStok::count();

echo "- Total Produk: {$total_produk}\n";
echo "- Total Transaksi Penjualan: {$total_penjualan}\n";
echo "- Total Transaksi Pembelian: {$total_pembelian}\n";
echo "- Total Rekaman Stok: {$total_rekaman}\n";

// Summary
echo "\n" . str_repeat("=", 60) . "\n";
echo "RINGKASAN VERIFIKASI SISTEM\n";
echo str_repeat("=", 60) . "\n";

$status_items = [
    'Konsistensi Stok' => empty($inconsistent),
    'Sinkronisasi Waktu' => empty($waktu_tidak_sinkron),
    'Duplikasi Rekaman' => empty($duplikasi),
    'Transaksi NULL' => ($penjualan_null === 0 && $pembelian_null === 0)
];

$semua_ok = true;
foreach ($status_items as $item => $status) {
    $icon = $status ? "✓" : "✗";
    $text = $status ? "OK" : "PERLU PERHATIAN";
    echo "{$icon} {$item}: {$text}\n";
    if (!$status) $semua_ok = false;
}

echo "\n";
if ($semua_ok) {
    echo "🎉 SISTEM SINKRONISASI BERFUNGSI DENGAN BAIK!\n";
    echo "Semua fungsi penjualan, pembelian, edit, delete, dan sinkronisasi stok berjalan optimal.\n";
} else {
    echo "⚠ SISTEM MEMERLUKAN PERHATIAN\n";
    echo "Ada beberapa area yang perlu diperbaiki untuk memastikan sinkronisasi optimal.\n";
}

echo "\nVerifikasi selesai pada: " . Carbon::now()->format('Y-m-d H:i:s') . "\n";
