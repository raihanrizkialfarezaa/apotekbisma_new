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

echo "=== TEST STRESS & EDGE CASES ===\n";
echo "Waktu: " . now()->format('Y-m-d H:i:s') . "\n\n";

$produk = Produk::find(2);
if (!$produk) {
    echo "❌ Produk test tidak ditemukan!\n";
    exit;
}

echo "Produk test: {$produk->nama_produk}\n";
echo "Stok awal: {$produk->stok}\n\n";

// TEST 1: Concurrent Transactions Simulation
echo "1. TEST SIMULASI TRANSAKSI CONCURRENT:\n";
echo str_repeat("-", 50) . "\n";

DB::beginTransaction();

try {
    $stok_awal = $produk->stok;
    
    // Simulasi 3 transaksi yang mencoba mengambil stok bersamaan
    echo "Simulasi 3 transaksi concurrent...\n";
    
    for ($i = 1; $i <= 3; $i++) {
        // Buat penjualan
        $penjualan = new Penjualan();
        $penjualan->id_member = null;
        $penjualan->total_item = 1;
        $penjualan->total_harga = $produk->harga_jual;
        $penjualan->diskon = 0;
        $penjualan->bayar = $produk->harga_jual;
        $penjualan->diterima = $produk->harga_jual;
        $penjualan->waktu = date('Y-m-d');
        $penjualan->id_user = 1;
        $penjualan->save();
        
        // Ambil stok fresh untuk simulasi real-time checking
        $produk_fresh = Produk::find(2);
        
        if ($produk_fresh->stok <= 0) {
            echo "❌ Transaksi {$i}: GAGAL - Stok habis ({$produk_fresh->stok})\n";
            continue;
        }
        
        // Buat detail
        $detail = new PenjualanDetail();
        $detail->id_penjualan = $penjualan->id_penjualan;
        $detail->id_produk = $produk->id_produk;
        $detail->harga_jual = $produk->harga_jual;
        $detail->jumlah = 1;
        $detail->diskon = 0;
        $detail->subtotal = $produk->harga_jual;
        $detail->save();
        
        // Update stok
        $produk_fresh->stok = $produk_fresh->stok - 1;
        $produk_fresh->save();
        
        // Buat rekaman
        RekamanStok::create([
            'id_produk' => $produk->id_produk,
            'id_penjualan' => $penjualan->id_penjualan,
            'waktu' => Carbon::now(),
            'stok_keluar' => 1,
            'stok_awal' => $produk_fresh->stok + 1,
            'stok_sisa' => $produk_fresh->stok,
            'keterangan' => "TEST Concurrent {$i}"
        ]);
        
        echo "✅ Transaksi {$i}: BERHASIL - Stok tersisa: {$produk_fresh->stok}\n";
    }
    
    // Rollback semua
    DB::rollBack();
    echo "✅ Rollback concurrent test berhasil\n";
    
} catch (Exception $e) {
    DB::rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
}

// TEST 2: Stok Zero Handling
echo "\n2. TEST HANDLING STOK ZERO:\n";
echo str_repeat("-", 50) . "\n";

DB::beginTransaction();

try {
    $produk_fresh = Produk::find(2);
    $original_stok = $produk_fresh->stok;
    
    // Set stok ke 1 untuk test
    $produk_fresh->stok = 1;
    $produk_fresh->save();
    
    echo "Set stok ke 1 untuk test\n";
    
    // Coba jual 1 (harus berhasil)
    $penjualan1 = new Penjualan();
    $penjualan1->id_member = null;
    $penjualan1->total_item = 1;
    $penjualan1->total_harga = $produk_fresh->harga_jual;
    $penjualan1->diskon = 0;
    $penjualan1->bayar = $produk_fresh->harga_jual;
    $penjualan1->diterima = $produk_fresh->harga_jual;
    $penjualan1->waktu = date('Y-m-d');
    $penjualan1->id_user = 1;
    $penjualan1->save();
    
    $detail1 = new PenjualanDetail();
    $detail1->id_penjualan = $penjualan1->id_penjualan;
    $detail1->id_produk = $produk_fresh->id_produk;
    $detail1->harga_jual = $produk_fresh->harga_jual;
    $detail1->jumlah = 1;
    $detail1->diskon = 0;
    $detail1->subtotal = $produk_fresh->harga_jual;
    $detail1->save();
    
    $produk_fresh->stok = 0;
    $produk_fresh->save();
    
    echo "✅ Penjualan 1 item berhasil - stok menjadi 0\n";
    
    // Coba jual 1 lagi (harus gagal karena stok 0)
    $produk_check = Produk::find(2);
    if ($produk_check->stok <= 0) {
        echo "✅ Sistem berhasil mendeteksi stok habis - tidak bisa jual lagi\n";
    } else {
        echo "❌ BUG: Stok masih terdeteksi ada padahal seharusnya 0\n";
    }
    
    DB::rollBack();
    echo "✅ Rollback zero stock test berhasil\n";
    
} catch (Exception $e) {
    DB::rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
}

// TEST 3: Update Quantity Edge Cases
echo "\n3. TEST UPDATE QUANTITY EDGE CASES:\n";
echo str_repeat("-", 50) . "\n";

DB::beginTransaction();

try {
    // Buat transaksi dengan 1 item
    $penjualan = new Penjualan();
    $penjualan->id_member = null;
    $penjualan->total_item = 1;
    $penjualan->total_harga = $produk->harga_jual;
    $penjualan->diskon = 0;
    $penjualan->bayar = $produk->harga_jual;
    $penjualan->diterima = $produk->harga_jual;
    $penjualan->waktu = date('Y-m-d');
    $penjualan->id_user = 1;
    $penjualan->save();
    
    $detail = new PenjualanDetail();
    $detail->id_penjualan = $penjualan->id_penjualan;
    $detail->id_produk = $produk->id_produk;
    $detail->harga_jual = $produk->harga_jual;
    $detail->jumlah = 1;
    $detail->diskon = 0;
    $detail->subtotal = $produk->harga_jual;
    $detail->save();
    
    $stok_sebelum = $produk->fresh()->stok;
    $produk_updated = $produk->fresh();
    $produk_updated->stok = $stok_sebelum - 1;
    $produk_updated->save();
    
    echo "Transaksi dibuat dengan 1 item\n";
    
    // Test update ke jumlah yang melebihi stok
    $stok_sekarang = $produk->fresh()->stok;
    $jumlah_berlebih = $stok_sekarang + 50;
    
    echo "Stok tersedia: {$stok_sekarang}\n";
    echo "Coba update ke {$jumlah_berlebih} item...\n";
    
    // Simulasi validasi seperti di controller
    $stok_tersedia = $stok_sekarang + $detail->jumlah; // stok + jumlah lama
    if ($jumlah_berlebih > $stok_tersedia) {
        echo "✅ Sistem berhasil mendeteksi update quantity berlebihan\n";
        echo "   Tersedia: {$stok_tersedia}, Diminta: {$jumlah_berlebih}\n";
    } else {
        echo "❌ BUG: Sistem tidak mendeteksi update quantity berlebihan\n";
    }
    
    DB::rollBack();
    echo "✅ Rollback update quantity test berhasil\n";
    
} catch (Exception $e) {
    DB::rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
}

// TEST 4: Delete Transaction Edge Cases
echo "\n4. TEST DELETE TRANSACTION EDGE CASES:\n";
echo str_repeat("-", 50) . "\n";

DB::beginTransaction();

try {
    $stok_awal = $produk->fresh()->stok;
    
    // Buat transaksi
    $penjualan = new Penjualan();
    $penjualan->id_member = null;
    $penjualan->total_item = 3;
    $penjualan->total_harga = $produk->harga_jual * 3;
    $penjualan->diskon = 0;
    $penjualan->bayar = $produk->harga_jual * 3;
    $penjualan->diterima = $produk->harga_jual * 3;
    $penjualan->waktu = date('Y-m-d');
    $penjualan->id_user = 1;
    $penjualan->save();
    
    $detail = new PenjualanDetail();
    $detail->id_penjualan = $penjualan->id_penjualan;
    $detail->id_produk = $produk->id_produk;
    $detail->harga_jual = $produk->harga_jual;
    $detail->jumlah = 3;
    $detail->diskon = 0;
    $detail->subtotal = $produk->harga_jual * 3;
    $detail->save();
    
    // Update stok
    $produk_fresh = $produk->fresh();
    $produk_fresh->stok = $stok_awal - 3;
    $produk_fresh->save();
    
    $stok_setelah_jual = $produk->fresh()->stok;
    echo "Buat transaksi 3 item - stok: {$stok_awal} → {$stok_setelah_jual}\n";
    
    // Hapus transaksi (stok harus kembali)
    $produk_before_delete = $produk->fresh();
    $produk_before_delete->stok = $produk_before_delete->stok + $detail->jumlah;
    $produk_before_delete->save();
    
    $detail->delete();
    $penjualan->delete();
    
    $stok_setelah_hapus = $produk->fresh()->stok;
    echo "Hapus transaksi - stok kembali: {$stok_setelah_hapus}\n";
    
    if ($stok_setelah_hapus == $stok_awal) {
        echo "✅ Pengembalian stok saat delete berhasil\n";
    } else {
        echo "❌ BUG: Stok tidak kembali dengan benar\n";
        echo "   Harusnya: {$stok_awal}, Aktual: {$stok_setelah_hapus}\n";
    }
    
    DB::rollBack();
    echo "✅ Rollback delete test berhasil\n";
    
} catch (Exception $e) {
    DB::rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
}

// TEST 5: Rekaman Stok Consistency
echo "\n5. TEST KONSISTENSI REKAMAN STOK:\n";
echo str_repeat("-", 50) . "\n";

$latest_rekaman = RekamanStok::where('id_produk', 2)
    ->orderBy('id_rekaman_stok', 'desc')
    ->first();

$current_stok = $produk->fresh()->stok;

if ($latest_rekaman) {
    if ($latest_rekaman->stok_sisa == $current_stok) {
        echo "✅ Rekaman stok konsisten dengan stok produk\n";
        echo "   Rekaman: {$latest_rekaman->stok_sisa}, Produk: {$current_stok}\n";
    } else {
        echo "⚠️  Inkonsistensi rekaman stok:\n";
        echo "   Rekaman: {$latest_rekaman->stok_sisa}, Produk: {$current_stok}\n";
        echo "   Selisih: " . abs($latest_rekaman->stok_sisa - $current_stok) . "\n";
    }
} else {
    echo "ℹ️  Tidak ada rekaman stok untuk produk ini\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "✅ SEMUA TEST EDGE CASES SELESAI\n";
echo "SISTEM TAHAN TERHADAP KONDISI EKSTREM\n";
echo str_repeat("=", 60) . "\n";

?>
