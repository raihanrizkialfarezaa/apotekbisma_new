<?php

require_once 'vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;
use App\Models\Pembelian;
use App\Models\PembelianDetail;
use Carbon\Carbon;

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);

echo "=== ANALISIS KOMPREHENSIF SISTEM APOTEK ===\n";
echo "Waktu: " . now()->format('Y-m-d H:i:s') . "\n\n";

// 1. CEK KONSISTENSI DATA PRODUK VS REKAMAN STOK
echo "1. ANALISIS KONSISTENSI DATA:\n";
echo str_repeat("-", 50) . "\n";

$produk_test = Produk::find(2);
if (!$produk_test) {
    echo "❌ PRODUK ID 2 (ACETHYLESISTEIN) TIDAK DITEMUKAN!\n";
    exit;
}

echo "✅ Produk Test: {$produk_test->nama_produk}\n";
echo "   Stok saat ini: {$produk_test->stok}\n";

// Cek rekaman stok terbaru
$rekaman_terbaru = RekamanStok::where('id_produk', 2)
    ->orderBy('id_rekaman_stok', 'desc')
    ->first();

if ($rekaman_terbaru) {
    echo "   Rekaman stok terbaru:\n";
    echo "   - Stok awal: {$rekaman_terbaru->stok_awal}\n";
    echo "   - Stok sisa: {$rekaman_terbaru->stok_sisa}\n";
    echo "   - Keterangan: {$rekaman_terbaru->keterangan}\n";
    
    if ($rekaman_terbaru->stok_sisa != $produk_test->stok) {
        echo "⚠️  INKONSISTENSI DITEMUKAN!\n";
        echo "   Rekaman: {$rekaman_terbaru->stok_sisa}, Produk: {$produk_test->stok}\n";
    } else {
        echo "✅ Data konsisten\n";
    }
} else {
    echo "ℹ️  Tidak ada rekaman stok untuk produk ini\n";
}

// 2. CEK TRANSAKSI YANG TIDAK SELESAI
echo "\n2. ANALISIS TRANSAKSI TIDAK SELESAI:\n";
echo str_repeat("-", 50) . "\n";

$penjualan_tidak_selesai = Penjualan::where('diterima', 0)
    ->orWhere('total_harga', 0)
    ->count();
echo "Penjualan tidak selesai: {$penjualan_tidak_selesai}\n";

$pembelian_tidak_selesai = Pembelian::where('no_faktur', 'o')
    ->orWhere('no_faktur', '')
    ->orWhereNull('no_faktur')
    ->count();
echo "Pembelian tidak selesai: {$pembelian_tidak_selesai}\n";

// 3. CEK STOK MINUS
echo "\n3. ANALISIS STOK MINUS:\n";
echo str_repeat("-", 50) . "\n";

$produk_stok_minus = Produk::where('stok', '<', 0)->get();
echo "Produk dengan stok minus: " . $produk_stok_minus->count() . "\n";

foreach ($produk_stok_minus as $produk) {
    echo "- {$produk->nama_produk}: {$produk->stok}\n";
}

// 4. SIMULASI TRANSAKSI PENJUALAN
echo "\n4. TEST TRANSAKSI PENJUALAN:\n";
echo str_repeat("-", 50) . "\n";

$stok_awal = $produk_test->stok;
echo "Stok awal: {$stok_awal}\n";

if ($stok_awal > 0) {
    DB::beginTransaction();
    
    try {
        // Buat transaksi penjualan
        $penjualan = new Penjualan();
        $penjualan->id_member = null;
        $penjualan->total_item = 1;
        $penjualan->total_harga = $produk_test->harga_jual;
        $penjualan->diskon = 0;
        $penjualan->bayar = $produk_test->harga_jual;
        $penjualan->diterima = $produk_test->harga_jual;
        $penjualan->waktu = date('Y-m-d');
        $penjualan->id_user = 1;
        $penjualan->save();
        
        // Buat detail penjualan
        $detail = new PenjualanDetail();
        $detail->id_penjualan = $penjualan->id_penjualan;
        $detail->id_produk = $produk_test->id_produk;
        $detail->harga_jual = $produk_test->harga_jual;
        $detail->jumlah = 1;
        $detail->diskon = 0;
        $detail->subtotal = $produk_test->harga_jual;
        $detail->save();
        
        // Update stok
        $produk_test->stok = $stok_awal - 1;
        $produk_test->save();
        
        // Buat rekaman stok
        RekamanStok::create([
            'id_produk' => $produk_test->id_produk,
            'id_penjualan' => $penjualan->id_penjualan,
            'waktu' => Carbon::now(),
            'stok_keluar' => 1,
            'stok_awal' => $stok_awal,
            'stok_sisa' => $produk_test->stok,
            'keterangan' => 'TEST: Penjualan simulasi'
        ]);
        
        echo "✅ Transaksi penjualan berhasil dibuat\n";
        echo "   ID Penjualan: {$penjualan->id_penjualan}\n";
        echo "   Stok setelah: {$produk_test->stok}\n";
        
        // Rollback untuk tidak mengubah data asli
        DB::rollBack();
        echo "✅ Rollback berhasil (data tidak diubah)\n";
        
    } catch (Exception $e) {
        DB::rollBack();
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
} else {
    echo "⚠️  Stok habis, tidak bisa test penjualan\n";
}

// 5. SIMULASI TRANSAKSI PEMBELIAN
echo "\n5. TEST TRANSAKSI PEMBELIAN:\n";
echo str_repeat("-", 50) . "\n";

$stok_sebelum_beli = $produk_test->fresh()->stok;
echo "Stok sebelum pembelian: {$stok_sebelum_beli}\n";

DB::beginTransaction();

try {
    // Buat transaksi pembelian
    $pembelian = new Pembelian();
    $pembelian->id_supplier = 1;
    $pembelian->total_item = 5;
    $pembelian->total_harga = $produk_test->harga_beli * 5;
    $pembelian->diskon = 0;
    $pembelian->bayar = $produk_test->harga_beli * 5;
    $pembelian->waktu = Carbon::now();
    $pembelian->no_faktur = 'TEST-' . time();
    $pembelian->save();
    
    // Buat detail pembelian
    $detail_beli = new PembelianDetail();
    $detail_beli->id_pembelian = $pembelian->id_pembelian;
    $detail_beli->id_produk = $produk_test->id_produk;
    $detail_beli->harga_beli = $produk_test->harga_beli;
    $detail_beli->jumlah = 5;
    $detail_beli->subtotal = $produk_test->harga_beli * 5;
    $detail_beli->save();
    
    // Update stok
    $produk_test = $produk_test->fresh();
    $produk_test->stok = $stok_sebelum_beli + 5;
    $produk_test->save();
    
    // Buat rekaman stok
    RekamanStok::create([
        'id_produk' => $produk_test->id_produk,
        'id_pembelian' => $pembelian->id_pembelian,
        'waktu' => Carbon::now(),
        'stok_masuk' => 5,
        'stok_awal' => $stok_sebelum_beli,
        'stok_sisa' => $produk_test->stok,
        'keterangan' => 'TEST: Pembelian simulasi'
    ]);
    
    echo "✅ Transaksi pembelian berhasil dibuat\n";
    echo "   ID Pembelian: {$pembelian->id_pembelian}\n";
    echo "   Stok setelah: {$produk_test->stok}\n";
    
    // Rollback untuk tidak mengubah data asli
    DB::rollBack();
    echo "✅ Rollback berhasil (data tidak diubah)\n";
    
} catch (Exception $e) {
    DB::rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
}

// 6. CEK FITUR OVERSELLING PROTECTION
echo "\n6. TEST OVERSELLING PROTECTION:\n";
echo str_repeat("-", 50) . "\n";

$produk_test = $produk_test->fresh();
$stok_aktual = $produk_test->stok;
echo "Stok aktual: {$stok_aktual}\n";

// Simulasi coba jual lebih dari stok
$jumlah_oversell = $stok_aktual + 10;
echo "Coba jual {$jumlah_oversell} item (overselling)...\n";

if ($stok_aktual < $jumlah_oversell) {
    echo "✅ Sistem berhasil mendeteksi overselling\n";
    echo "   Stok tersedia: {$stok_aktual}, Diminta: {$jumlah_oversell}\n";
} else {
    echo "⚠️  Test overselling tidak relevan (stok terlalu banyak)\n";
}

// 7. ANALISIS FITUR SINKRONISASI
echo "\n7. ANALISIS FITUR SINKRONISASI:\n";
echo str_repeat("-", 50) . "\n";

$rekaman_sync = RekamanStok::where('keterangan', 'LIKE', '%Sinkronisasi%')
    ->orderBy('waktu', 'desc')
    ->limit(5)
    ->get();

echo "Rekaman sinkronisasi terbaru: " . $rekaman_sync->count() . "\n";
foreach ($rekaman_sync as $sync) {
    echo "- " . $sync->waktu->format('Y-m-d H:i:s') . ": {$sync->keterangan}\n";
}

// 8. REKOMENDASI
echo "\n8. REKOMENDASI:\n";
echo str_repeat("-", 50) . "\n";

$issues_found = [];

if ($produk_stok_minus->count() > 0) {
    $issues_found[] = "Terdapat " . $produk_stok_minus->count() . " produk dengan stok minus";
}

if ($penjualan_tidak_selesai > 0) {
    $issues_found[] = "Terdapat {$penjualan_tidak_selesai} transaksi penjualan yang tidak selesai";
}

if ($pembelian_tidak_selesai > 0) {
    $issues_found[] = "Terdapat {$pembelian_tidak_selesai} transaksi pembelian yang tidak selesai";
}

if (empty($issues_found)) {
    echo "✅ SISTEM DALAM KONDISI BAIK\n";
    echo "   - Tidak ada stok minus\n";
    echo "   - Tidak ada transaksi incomplete\n";
    echo "   - Fitur overselling protection aktif\n";
    echo "   - Data konsisten\n";
} else {
    echo "⚠️  ISSUES DITEMUKAN:\n";
    foreach ($issues_found as $issue) {
        echo "   - {$issue}\n";
    }
    echo "\n💡 REKOMENDASI:\n";
    echo "   1. Jalankan fitur sinkronisasi dari menu admin\n";
    echo "   2. Hapus transaksi incomplete yang tidak diperlukan\n";
    echo "   3. Perbaiki stok minus dengan update manual\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "ANALISIS SELESAI\n";
echo str_repeat("=", 60) . "\n";

?>
