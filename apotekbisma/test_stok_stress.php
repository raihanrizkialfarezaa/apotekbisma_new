<?php

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use App\Models\Produk;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "==============================================\n";
echo "STRESS TEST UPDATE STOK - MULTIPLE UPDATES\n";
echo "==============================================\n\n";

try {
    // Cari produk test
    $produk = Produk::where('nama_produk', 'LIKE', '%TEST STOK UPDATE%')->first();
    
    if (!$produk) {
        echo "✗ Produk test tidak ditemukan. Jalankan test_stok_update_robust.php dulu.\n";
        exit(1);
    }
    
    echo "Produk ID: {$produk->id_produk}\n";
    echo "Nama: {$produk->nama_produk}\n\n";
    
    // Reset stok
    DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => 500]);
    
    // Cleanup rekaman stress test lama
    DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->where('keterangan', 'LIKE', 'STRESS TEST:%')
        ->delete();
    
    echo "TEST: Multiple rapid updates (simulasi user spam-click)\n";
    echo "Melakukan 10 update stok secara berurutan...\n\n";
    
    $updates = [
        ['from' => 500, 'to' => 450],
        ['from' => 450, 'to' => 400],
        ['from' => 400, 'to' => 350],
        ['from' => 350, 'to' => 300],
        ['from' => 300, 'to' => 250],
        ['from' => 250, 'to' => 200],
        ['from' => 200, 'to' => 150],
        ['from' => 150, 'to' => 100],
        ['from' => 100, 'to' => 50],
        ['from' => 50, 'to' => 25],
    ];
    
    $allSuccess = true;
    
    foreach ($updates as $idx => $update) {
        $num = $idx + 1;
        echo "Update #{$num}: {$update['from']} → {$update['to']}... ";
        
        DB::beginTransaction();
        try {
            $stokLama = $update['from'];
            $stokBaru = $update['to'];
            
            DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stokBaru]);
            
            $currentTime = \Carbon\Carbon::now();
            $selisih = $stokBaru - $stokLama;
            
            DB::table('rekaman_stoks')->insert([
                'id_produk' => $produk->id_produk,
                'waktu' => $currentTime,
                'stok_awal' => $stokLama,
                'stok_masuk' => $selisih > 0 ? $selisih : 0,
                'stok_keluar' => $selisih < 0 ? abs($selisih) : 0,
                'stok_sisa' => $stokBaru,
                'keterangan' => "STRESS TEST: Update #{$num}",
                'created_at' => $currentTime,
                'updated_at' => $currentTime
            ]);
            
            DB::commit();
            
            // Verify immediately
            $currentStok = DB::table('produk')->where('id_produk', $produk->id_produk)->value('stok');
            
            if ($currentStok == $stokBaru) {
                echo "✓ OK (stok = {$currentStok})\n";
            } else {
                echo "✗ FAIL (expected {$stokBaru}, got {$currentStok})\n";
                $allSuccess = false;
            }
            
            usleep(100000); // 100ms delay
            
        } catch (\Exception $e) {
            DB::rollBack();
            echo "✗ ERROR: {$e->getMessage()}\n";
            $allSuccess = false;
        }
    }
    
    echo "\nVerifikasi akhir...\n";
    $finalStok = DB::table('produk')->where('id_produk', $produk->id_produk)->value('stok');
    echo "Stok akhir di database: {$finalStok}\n";
    echo "Expected: 25\n";
    
    if ($finalStok == 25) {
        echo "✓ FINAL CHECK PASS\n\n";
    } else {
        echo "✗ FINAL CHECK FAIL\n\n";
        $allSuccess = false;
    }
    
    // Verify chain
    echo "Verifikasi integrity chain...\n";
    $rekamanAll = DB::table('rekaman_stoks')
        ->where('id_produk', $produk->id_produk)
        ->where('keterangan', 'LIKE', 'STRESS TEST:%')
        ->orderBy('waktu', 'asc')
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();
    
    $chainValid = true;
    $prevStokSisa = null;
    
    foreach ($rekamanAll as $idx => $rekaman) {
        $calculated = $rekaman->stok_awal + $rekaman->stok_masuk - $rekaman->stok_keluar;
        
        if ($calculated != $rekaman->stok_sisa) {
            echo "   ✗ Formula error di rekaman " . ($idx + 1) . "\n";
            $chainValid = false;
        }
        
        if ($prevStokSisa !== null && $rekaman->stok_awal != $prevStokSisa) {
            echo "   ✗ Chain broken di rekaman " . ($idx + 1) . ": stok_awal={$rekaman->stok_awal}, prev_sisa={$prevStokSisa}\n";
            $chainValid = false;
        }
        
        $prevStokSisa = $rekaman->stok_sisa;
    }
    
    if ($chainValid) {
        echo "✓ Chain integrity VALID\n";
    } else {
        echo "✗ Chain integrity BROKEN\n";
        $allSuccess = false;
    }
    
    echo "\n==============================================\n";
    
    if ($allSuccess) {
        echo "🎉 STRESS TEST BERHASIL! SISTEM ROBUST! 🎉\n";
    } else {
        echo "⚠️ STRESS TEST GAGAL! ADA MASALAH! ⚠️\n";
    }
    
    echo "==============================================\n";
    
} catch (\Exception $e) {
    echo "\n✗ FATAL ERROR: {$e->getMessage()}\n";
}
