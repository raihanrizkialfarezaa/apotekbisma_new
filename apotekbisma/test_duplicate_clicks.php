<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;

echo "=======================================================\n";
echo "   TEST: SIMULASI KLIK BERULANG PADA PRODUK YANG SAMA\n";
echo "   Tanggal: " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================\n\n";

$testProduct = Produk::where('stok', '>=', 50)->orderBy('nama_produk')->first();

if (!$testProduct) {
    echo "[ERROR] Tidak ada produk dengan stok >= 50 untuk test\n";
    exit(1);
}

echo "TEST PRODUCT: {$testProduct->nama_produk}\n";
echo "STOK AWAL: {$testProduct->stok}\n\n";

$initialStock = intval($testProduct->stok);

$penjualan = new Penjualan();
$penjualan->id_member = null;
$penjualan->total_item = 0;
$penjualan->total_harga = 0;
$penjualan->diskon = 0;
$penjualan->bayar = 0;
$penjualan->diterima = 0;
$penjualan->waktu = now();
$penjualan->id_user = 1;
$penjualan->save();

$testPenjualanId = $penjualan->id_penjualan;
echo "Created test penjualan ID: {$testPenjualanId}\n\n";

echo "Mensimulasikan 3x klik pada produk yang sama...\n\n";

for ($i = 1; $i <= 3; $i++) {
    echo "Klik ke-{$i}:\n";
    
    $stokSebelum = intval(Produk::find($testProduct->id_produk)->stok);
    
    DB::beginTransaction();
    try {
        $produk = Produk::where('id_produk', $testProduct->id_produk)->lockForUpdate()->first();
        
        $existingDetail = DB::table('penjualan_detail')
            ->where('id_penjualan', $testPenjualanId)
            ->where('id_produk', $produk->id_produk)
            ->first();
        
        if ($existingDetail) {
            $newJumlah = intval($existingDetail->jumlah) + 1;
            
            DB::table('penjualan_detail')
                ->where('id_penjualan_detail', $existingDetail->id_penjualan_detail)
                ->update([
                    'jumlah' => $newJumlah,
                    'subtotal' => $produk->harga_jual * $newJumlah,
                    'updated_at' => now()
                ]);
            
            $stokBaru = intval($produk->stok) - 1;
            if ($stokBaru < 0) $stokBaru = 0;
            
            DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stokBaru]);
            
            $existingRekaman = DB::table('rekaman_stoks')
                ->where('id_penjualan', $testPenjualanId)
                ->where('id_produk', $produk->id_produk)
                ->first();
            
            if ($existingRekaman) {
                $newStokKeluar = intval($existingRekaman->stok_keluar) + 1;
                $originalStokAwal = intval($existingRekaman->stok_awal);
                $newStokSisa = $originalStokAwal - $newStokKeluar;
                if ($newStokSisa < 0) $newStokSisa = 0;
                
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $existingRekaman->id_rekaman_stok)
                    ->update([
                        'stok_keluar' => $newStokKeluar,
                        'stok_sisa' => $newStokSisa,
                        'updated_at' => now()
                    ]);
                    
                echo "  Rekaman diupdate: stok_keluar={$newStokKeluar}\n";
            }
            
            echo "  Detail diupdate: jumlah={$newJumlah}\n";
            
        } else {
            DB::table('penjualan_detail')->insert([
                'id_penjualan' => $testPenjualanId,
                'id_produk' => $produk->id_produk,
                'harga_jual' => $produk->harga_jual,
                'jumlah' => 1,
                'diskon' => 0,
                'subtotal' => $produk->harga_jual,
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            $stokBaru = intval($produk->stok) - 1;
            if ($stokBaru < 0) $stokBaru = 0;
            
            DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stokBaru]);
            
            DB::table('rekaman_stoks')->insert([
                'id_produk' => $produk->id_produk,
                'id_penjualan' => $testPenjualanId,
                'waktu' => now(),
                'stok_masuk' => 0,
                'stok_keluar' => 1,
                'stok_awal' => intval($produk->stok),
                'stok_sisa' => $stokBaru,
                'keterangan' => 'TEST: Penjualan produk',
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            echo "  Detail baru dibuat\n";
            echo "  Rekaman baru dibuat\n";
        }
        
        DB::commit();
        
        $stokSetelah = intval(Produk::find($testProduct->id_produk)->stok);
        echo "  Stok: {$stokSebelum} -> {$stokSetelah}\n\n";
        
    } catch (\Exception $e) {
        DB::rollBack();
        echo "  [ERROR] " . $e->getMessage() . "\n\n";
    }
}

echo "=======================================================\n";
echo "   HASIL\n";
echo "=======================================================\n\n";

$productAfter = Produk::find($testProduct->id_produk);
$finalStock = intval($productAfter->stok);

echo "Stok awal: {$initialStock}\n";
echo "Stok akhir: {$finalStock}\n";
echo "Pengurangan: " . ($initialStock - $finalStock) . " (expected: 3)\n\n";

$rekamanCount = DB::table('rekaman_stoks')
    ->where('id_penjualan', $testPenjualanId)
    ->where('id_produk', $testProduct->id_produk)
    ->count();

$totalKeluar = DB::table('rekaman_stoks')
    ->where('id_penjualan', $testPenjualanId)
    ->where('id_produk', $testProduct->id_produk)
    ->sum('stok_keluar');

echo "Rekaman stok: {$rekamanCount} (expected: 1)\n";
echo "Total stok_keluar: {$totalKeluar} (expected: 3)\n\n";

if ($finalStock == ($initialStock - 3) && $rekamanCount == 1 && $totalKeluar == 3) {
    echo "[SUCCESS] Test berhasil! Tidak ada duplikat.\n";
} else {
    echo "[FAILED] Ada masalah!\n";
}

echo "\nCleanup...\n";
DB::table('rekaman_stoks')->where('id_penjualan', $testPenjualanId)->delete();
DB::table('penjualan_detail')->where('id_penjualan', $testPenjualanId)->delete();
DB::table('penjualan')->where('id_penjualan', $testPenjualanId)->delete();
DB::table('produk')->where('id_produk', $testProduct->id_produk)->update(['stok' => $initialStock]);
echo "Done.\n";
