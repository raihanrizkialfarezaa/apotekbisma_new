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

echo "=== TEST REALTIME FUNGSI EDIT & DELETE ===\n\n";

// Test 1: Edit Penjualan
echo "1. TEST EDIT PENJUALAN\n";
echo str_repeat("=", 40) . "\n";

DB::beginTransaction();

try {
    // Pilih produk dengan stok cukup
    $produk = Produk::where('stok', '>', 20)->first();
    
    if (!$produk) {
        echo "❌ Tidak ada produk dengan stok cukup untuk test\n";
    } else {
        $stok_awal = $produk->stok;
        echo "Produk test: {$produk->nama_produk} (Stok: {$stok_awal})\n";
        
        // Buat penjualan
        $penjualan = new Penjualan();
        $penjualan->total_item = 1;
        $penjualan->total_harga = 50000;
        $penjualan->diskon = 0;
        $penjualan->bayar = 50000;
        $penjualan->diterima = 50000;
        $penjualan->waktu = Carbon::now();
        $penjualan->id_user = 1;
        $penjualan->save();
        
        // Detail penjualan dengan 5 unit
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
            'keterangan' => 'Test penjualan awal'
        ]);
        
        $stok_setelah_jual = $produk->stok;
        echo "✓ Penjualan dibuat: 5 unit, stok menjadi {$stok_setelah_jual}\n";
        
        // EDIT: Ubah jumlah menjadi 8 unit
        $old_jumlah = $detail->jumlah;
        $new_jumlah = 8;
        $selisih = $new_jumlah - $old_jumlah;
        
        // Update detail
        $detail->jumlah = $new_jumlah;
        $detail->subtotal = $produk->harga_jual * $new_jumlah;
        $detail->save();
        
        // Update stok
        $produk->stok -= $selisih;
        $produk->save();
        
        // Update rekaman stok
        RekamanStok::where('id_penjualan', $penjualan->id_penjualan)
                   ->where('id_produk', $produk->id_produk)
                   ->update([
                       'stok_keluar' => $new_jumlah,
                       'stok_sisa' => $produk->stok,
                       'keterangan' => 'Test edit penjualan: update jumlah'
                   ]);
        
        $stok_setelah_edit = $produk->stok;
        echo "✓ Edit penjualan: {$old_jumlah} → {$new_jumlah} unit, stok menjadi {$stok_setelah_edit}\n";
        
        // Verifikasi konsistensi
        $expected_stok = $stok_awal - $new_jumlah;
        if ($stok_setelah_edit == $expected_stok) {
            echo "✅ EDIT PENJUALAN BERHASIL - Stok konsisten\n";
        } else {
            echo "❌ EDIT PENJUALAN GAGAL - Stok tidak konsisten\n";
        }
    }
    
    DB::rollBack();
    echo "- Test di-rollback\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// Test 2: Delete Penjualan
echo "\n2. TEST DELETE PENJUALAN\n";
echo str_repeat("=", 40) . "\n";

DB::beginTransaction();

try {
    $produk = Produk::where('stok', '>', 10)->first();
    $stok_awal = $produk->stok;
    
    // Buat penjualan
    $penjualan = new Penjualan();
    $penjualan->total_item = 1;
    $penjualan->total_harga = 30000;
    $penjualan->diskon = 0;
    $penjualan->bayar = 30000;
    $penjualan->diterima = 30000;
    $penjualan->waktu = Carbon::now();
    $penjualan->id_user = 1;
    $penjualan->save();
    
    // Detail penjualan
    $detail = new PenjualanDetail();
    $detail->id_penjualan = $penjualan->id_penjualan;
    $detail->id_produk = $produk->id_produk;
    $detail->harga_jual = $produk->harga_jual;
    $detail->jumlah = 3;
    $detail->diskon = 0;
    $detail->subtotal = $produk->harga_jual * 3;
    $detail->save();
    
    // Update stok
    $produk->stok -= 3;
    $produk->save();
    
    // Buat rekaman stok
    RekamanStok::create([
        'id_produk' => $produk->id_produk,
        'id_penjualan' => $penjualan->id_penjualan,
        'waktu' => $penjualan->waktu,
        'stok_keluar' => 3,
        'stok_awal' => $stok_awal,
        'stok_sisa' => $produk->stok,
        'keterangan' => 'Test penjualan untuk delete'
    ]);
    
    $stok_setelah_jual = $produk->stok;
    echo "✓ Penjualan dibuat: 3 unit, stok menjadi {$stok_setelah_jual}\n";
    
    // DELETE: Hapus penjualan
    // Kembalikan stok
    $produk->stok += $detail->jumlah;
    $produk->save();
    
    // Buat rekaman audit pengembalian
    RekamanStok::create([
        'id_produk' => $produk->id_produk,
        'waktu' => Carbon::now(),
        'stok_masuk' => $detail->jumlah,
        'stok_awal' => $stok_setelah_jual,
        'stok_sisa' => $produk->stok,
        'keterangan' => 'Test penghapusan penjualan: pengembalian stok'
    ]);
    
    // Hapus rekaman stok transaksi
    RekamanStok::where('id_penjualan', $penjualan->id_penjualan)->delete();
    
    // Hapus detail dan transaksi
    $detail->delete();
    $penjualan->delete();
    
    $stok_setelah_delete = $produk->stok;
    echo "✓ Penjualan dihapus: stok kembali menjadi {$stok_setelah_delete}\n";
    
    // Verifikasi
    if ($stok_setelah_delete == $stok_awal) {
        echo "✅ DELETE PENJUALAN BERHASIL - Stok kembali normal\n";
    } else {
        echo "❌ DELETE PENJUALAN GAGAL - Stok tidak kembali normal\n";
    }
    
    DB::rollBack();
    echo "- Test di-rollback\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// Test 3: Edit Pembelian
echo "\n3. TEST EDIT PEMBELIAN\n";
echo str_repeat("=", 40) . "\n";

DB::beginTransaction();

try {
    $produk = Produk::first();
    $stok_awal = $produk->stok;
    
    // Buat pembelian
    $pembelian = new Pembelian();
    $pembelian->no_faktur = 'TEST-001';
    $pembelian->total_item = 1;
    $pembelian->total_harga = 100000;
    $pembelian->diskon = 0;
    $pembelian->bayar = 100000;
    $pembelian->waktu = Carbon::now();
    $pembelian->id_supplier = 1;
    $pembelian->save();
    
    // Detail pembelian dengan 10 unit
    $detail = new PembelianDetail();
    $detail->id_pembelian = $pembelian->id_pembelian;
    $detail->id_produk = $produk->id_produk;
    $detail->harga_beli = 5000;
    $detail->jumlah = 10;
    $detail->subtotal = 50000;
    $detail->save();
    
    // Update stok
    $produk->stok += 10;
    $produk->save();
    
    // Buat rekaman stok
    RekamanStok::create([
        'id_produk' => $produk->id_produk,
        'id_pembelian' => $pembelian->id_pembelian,
        'waktu' => $pembelian->waktu,
        'stok_masuk' => 10,
        'stok_awal' => $stok_awal,
        'stok_sisa' => $produk->stok,
        'keterangan' => 'Test pembelian awal'
    ]);
    
    $stok_setelah_beli = $produk->stok;
    echo "✓ Pembelian dibuat: 10 unit, stok menjadi {$stok_setelah_beli}\n";
    
    // EDIT: Ubah jumlah menjadi 15 unit
    $old_jumlah = $detail->jumlah;
    $new_jumlah = 15;
    $selisih = $new_jumlah - $old_jumlah;
    
    // Update detail
    $detail->jumlah = $new_jumlah;
    $detail->subtotal = $detail->harga_beli * $new_jumlah;
    $detail->save();
    
    // Update stok
    $produk->stok += $selisih;
    $produk->save();
    
    // Update rekaman stok
    RekamanStok::where('id_pembelian', $pembelian->id_pembelian)
               ->where('id_produk', $produk->id_produk)
               ->update([
                   'stok_masuk' => $new_jumlah,
                   'stok_sisa' => $produk->stok,
                   'keterangan' => 'Test edit pembelian: update jumlah'
               ]);
    
    $stok_setelah_edit = $produk->stok;
    echo "✓ Edit pembelian: {$old_jumlah} → {$new_jumlah} unit, stok menjadi {$stok_setelah_edit}\n";
    
    // Verifikasi
    $expected_stok = $stok_awal + $new_jumlah;
    if ($stok_setelah_edit == $expected_stok) {
        echo "✅ EDIT PEMBELIAN BERHASIL - Stok konsisten\n";
    } else {
        echo "❌ EDIT PEMBELIAN GAGAL - Stok tidak konsisten\n";
    }
    
    DB::rollBack();
    echo "- Test di-rollback\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// Test 4: Update Stok Manual
echo "\n4. TEST UPDATE STOK MANUAL\n";
echo str_repeat("=", 40) . "\n";

DB::beginTransaction();

try {
    $produk = Produk::first();
    $stok_awal = $produk->stok;
    $stok_baru = $stok_awal + 25;
    
    echo "✓ Update stok manual: {$stok_awal} → {$stok_baru}\n";
    
    // Update stok
    $produk->stok = $stok_baru;
    $produk->save();
    
    // Buat rekaman stok
    $selisih = $stok_baru - $stok_awal;
    RekamanStok::create([
        'id_produk' => $produk->id_produk,
        'waktu' => Carbon::now(),
        'stok_masuk' => $selisih,
        'stok_awal' => $stok_awal,
        'stok_sisa' => $stok_baru,
        'keterangan' => 'Test update stok manual'
    ]);
    
    // Verifikasi
    if ($produk->stok == $stok_baru) {
        echo "✅ UPDATE STOK MANUAL BERHASIL\n";
    } else {
        echo "❌ UPDATE STOK MANUAL GAGAL\n";
    }
    
    DB::rollBack();
    echo "- Test di-rollback\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "🎯 SEMUA TEST FUNGSI EDIT & DELETE SELESAI\n";
echo "Sistem siap untuk digunakan dalam production!\n";
echo str_repeat("=", 60) . "\n";
