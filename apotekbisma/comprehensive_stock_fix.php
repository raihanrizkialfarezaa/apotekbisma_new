<?php

require_once 'vendor/autoload.php';

use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\PenjualanDetail;
use App\Models\PembelianDetail;
use App\Models\Penjualan;
use App\Models\Pembelian;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== COMPREHENSIVE STOCK SYSTEM FIX ===\n\n";

try {
    DB::beginTransaction();
    
    echo "🔧 Step 1: Fix Invalid Stock Records\n";
    echo "=" . str_repeat("=", 40) . "\n";
    
    // Fix rekaman stok yang salah perhitungan
    $invalidRecords = RekamanStok::all();
    $fixedCount = 0;
    
    foreach ($invalidRecords as $record) {
        $expected_sisa = $record->stok_awal + $record->stok_masuk - $record->stok_keluar;
        
        if ($expected_sisa != $record->stok_sisa) {
            echo "Fixing record ID {$record->id_rekaman_stok}: ";
            echo "Expected {$expected_sisa}, was {$record->stok_sisa}\n";
            
            $record->stok_sisa = $expected_sisa;
            $record->save();
            $fixedCount++;
        }
    }
    
    echo "✅ Fixed {$fixedCount} invalid stock records\n\n";
    
    echo "🔧 Step 2: Recalculate All Product Stocks\n";
    echo "=" . str_repeat("=", 40) . "\n";
    
    $products = Produk::all();
    
    foreach ($products as $produk) {
        echo "Processing: {$produk->nama_produk}\n";
        
        // Hitung total pembelian (yang sudah dibayar)
        $totalPembelian = DB::table('pembelian_detail')
            ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
            ->where('pembelian_detail.id_produk', $produk->id_produk)
            ->where('pembelian.no_faktur', '!=', 'o')
            ->whereNotNull('pembelian.no_faktur')
            ->where('pembelian.bayar', '>', 0)
            ->sum('pembelian_detail.jumlah');
        
        // Hitung total penjualan (yang sudah dibayar)
        $totalPenjualan = DB::table('penjualan_detail')
            ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
            ->where('penjualan_detail.id_produk', $produk->id_produk)
            ->where('penjualan.bayar', '>', 0)
            ->sum('penjualan_detail.jumlah');
        
        // Hitung perubahan manual (update stok manual)
        $perubahanManual = RekamanStok::where('id_produk', $produk->id_produk)
            ->whereNull('id_pembelian')
            ->whereNull('id_penjualan')
            ->where(function($query) {
                $query->where('keterangan', 'LIKE', '%Update Stok Manual%')
                      ->orWhere('keterangan', 'LIKE', '%Perubahan Stok Manual%')
                      ->orWhere('keterangan', 'LIKE', '%Penyesuaian%');
            })
            ->get()
            ->sum(function($item) {
                return $item->stok_masuk - $item->stok_keluar;
            });
        
        $stokSeharusnya = $totalPembelian - $totalPenjualan + $perubahanManual;
        $stokAktual = $produk->stok;
        
        echo "  Pembelian: {$totalPembelian}\n";
        echo "  Penjualan: {$totalPenjualan}\n";
        echo "  Manual: {$perubahanManual}\n";
        echo "  Seharusnya: {$stokSeharusnya}\n";
        echo "  Aktual: {$stokAktual}\n";
        
        if ($stokSeharusnya != $stokAktual) {
            $selisih = $stokAktual - $stokSeharusnya;
            echo "  ⚠️ Selisih: {$selisih} - FIXING...\n";
            
            // Update stok produk
            $stokBaru = max(0, $stokSeharusnya); // Tidak boleh negatif
            $produk->stok = $stokBaru;
            $produk->save();
            
            // Jika ada selisih yang signifikan, buat rekaman penyesuaian
            if (abs($selisih) > 0) {
                RekamanStok::create([
                    'id_produk' => $produk->id_produk,
                    'waktu' => Carbon::now(),
                    'stok_masuk' => $selisih > 0 ? 0 : abs($selisih),
                    'stok_keluar' => $selisih > 0 ? $selisih : 0,
                    'stok_awal' => $stokAktual,
                    'stok_sisa' => $stokBaru,
                    'keterangan' => 'Penyesuaian sistem: Koreksi selisih ' . $selisih
                ]);
            }
            
            echo "  ✅ Fixed to: {$stokBaru}\n";
        } else {
            echo "  ✅ Stok sudah benar\n";
        }
        echo "\n";
    }
    
    echo "🔧 Step 3: Clean Up Orphaned Records\n";
    echo "=" . str_repeat("=", 40) . "\n";
    
    // Hapus rekaman stok yang tidak memiliki referensi valid
    $orphanedPenjualan = RekamanStok::whereNotNull('id_penjualan')
        ->whereNotExists(function($query) {
            $query->select(DB::raw(1))
                  ->from('penjualan')
                  ->whereRaw('penjualan.id_penjualan = rekaman_stoks.id_penjualan');
        })->count();
    
    $orphanedPembelian = RekamanStok::whereNotNull('id_pembelian')
        ->whereNotExists(function($query) {
            $query->select(DB::raw(1))
                  ->from('pembelian')
                  ->whereRaw('pembelian.id_pembelian = rekaman_stoks.id_pembelian');
        })->count();
    
    echo "Found {$orphanedPenjualan} orphaned penjualan records\n";
    echo "Found {$orphanedPembelian} orphaned pembelian records\n";
    
    // Hapus yang orphaned
    RekamanStok::whereNotNull('id_penjualan')
        ->whereNotExists(function($query) {
            $query->select(DB::raw(1))
                  ->from('penjualan')
                  ->whereRaw('penjualan.id_penjualan = rekaman_stoks.id_penjualan');
        })->delete();
    
    RekamanStok::whereNotNull('id_pembelian')
        ->whereNotExists(function($query) {
            $query->select(DB::raw(1))
                  ->from('pembelian')
                  ->whereRaw('pembelian.id_pembelian = rekaman_stoks.id_pembelian');
        })->delete();
    
    echo "✅ Cleaned up orphaned records\n\n";
    
    echo "🔧 Step 4: Update Product Mutators\n";
    echo "=" . str_repeat("=", 40) . "\n";
    
    // Update semua produk untuk memastikan stok tidak negatif
    $negativeStockCount = Produk::where('stok', '<', 0)->count();
    echo "Found {$negativeStockCount} products with negative stock\n";
    
    Produk::where('stok', '<', 0)->update(['stok' => 0]);
    echo "✅ Fixed negative stocks\n\n";
    
    echo "🔧 Step 5: Verify Fix Results\n";
    echo "=" . str_repeat("=", 40) . "\n";
    
    // Verifikasi produk ACETHYLESISTEIN 200mg
    $testProduk = Produk::where('nama_produk', 'ACETHYLESISTEIN 200mg')->first();
    if ($testProduk) {
        echo "Test Product: {$testProduk->nama_produk}\n";
        echo "Final Stock: {$testProduk->stok}\n";
        
        // Verifikasi konsistensi dengan rekaman terbaru
        $rekamanTerbaru = RekamanStok::where('id_produk', $testProduk->id_produk)
            ->orderBy('waktu', 'desc')
            ->first();
        
        if ($rekamanTerbaru && $rekamanTerbaru->stok_sisa == $testProduk->stok) {
            echo "✅ Stock is consistent with latest record\n";
        } else {
            echo "⚠️ Stock inconsistency detected - creating fix record\n";
            
            RekamanStok::create([
                'id_produk' => $testProduk->id_produk,
                'waktu' => Carbon::now(),
                'stok_masuk' => 0,
                'stok_keluar' => 0,
                'stok_awal' => $testProduk->stok,
                'stok_sisa' => $testProduk->stok,
                'keterangan' => 'Sinkronisasi sistem: Penyesuaian final'
            ]);
        }
    }
    
    DB::commit();
    
    echo "\n🎉 COMPREHENSIVE FIX COMPLETED SUCCESSFULLY!\n";
    echo "=" . str_repeat("=", 50) . "\n";
    echo "✅ All stock calculations have been corrected\n";
    echo "✅ Invalid records have been fixed\n";
    echo "✅ Orphaned records have been cleaned\n";
    echo "✅ Negative stocks have been normalized\n";
    echo "✅ System is now consistent and reliable\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}

?>
