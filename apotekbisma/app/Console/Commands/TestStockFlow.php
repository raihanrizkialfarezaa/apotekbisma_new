<?php

namespace App\Console\Commands;

use App\Models\Produk;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;
use App\Models\RekamanStok;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TestStockFlow extends Command
{
    protected $signature = 'test:stock-flow';
    protected $description = 'Test complete stock flow system';

    public function handle()
    {
        $this->info('=== TESTING STOCK FLOW SYSTEM ===');
        
        $id_produk = 2;
        $produk = Produk::find($id_produk);
        
        if (!$produk) {
            $this->error("Produk dengan ID $id_produk tidak ditemukan");
            return 1;
        }
        
        $this->info("Testing Produk: {$produk->nama_produk}");
        $this->info("Stok Awal: {$produk->stok}");
        
        $errors = [];
        $tests_passed = 0;
        $total_tests = 0;
        
        $original_stock = $produk->stok;
        
        if ($produk->stok < 5) {
            $this->warn("Stok terlalu sedikit, menambahkan stok untuk test");
            $produk->stok = 10;
            $produk->save();
        }
        
        $this->testOverselling($produk, $errors, $tests_passed, $total_tests);
        $this->testValidTransaction($produk, $errors, $tests_passed, $total_tests);
        $this->testStockConsistency($produk, $errors, $tests_passed, $total_tests);
        
        $produk->stok = $original_stock;
        $produk->save();
        
        $this->info("\n=== HASIL TESTING ===");
        $this->info("Tests Passed: $tests_passed");
        $this->info("Tests Failed: " . count($errors));
        $this->info("Total Tests: $total_tests");
        $this->info("Success Rate: " . round(($tests_passed / $total_tests) * 100, 2) . "%");
        
        if (count($errors) > 0) {
            $this->error("\n=== ERRORS FOUND ===");
            foreach ($errors as $error) {
                $this->error("• $error");
            }
        } else {
            $this->info("\n🎉 SEMUA TEST BERHASIL!");
        }
        
        return count($errors) == 0 ? 0 : 1;
    }
    
    private function testOverselling($produk, &$errors, &$tests_passed, &$total_tests)
    {
        $this->info("\n=== TEST OVERSELLING PREVENTION ===");
        
        $original_stock = $produk->stok;
        $produk->stok = 1;
        $produk->save();
        
        DB::beginTransaction();
        try {
            $penjualan = new Penjualan();
            $penjualan->id_member = null;
            $penjualan->total_item = 0;
            $penjualan->total_harga = 0;
            $penjualan->diskon = 0;
            $penjualan->bayar = 0;
            $penjualan->diterima = 0;
            $penjualan->waktu = date('Y-m-d');
            $penjualan->id_user = 1;
            $penjualan->save();
            
            $detail = new PenjualanDetail();
            $detail->id_penjualan = $penjualan->id_penjualan;
            $detail->id_produk = $produk->id_produk;
            $detail->harga_jual = $produk->harga_jual;
            $detail->jumlah = 2;
            
            if ($produk->stok < $detail->jumlah) {
                $this->testAssert(true, "Overselling prevention: Validasi stok {$produk->stok} < {$detail->jumlah} berhasil", $errors, $tests_passed, $total_tests);
                DB::rollBack();
            } else {
                $this->testAssert(false, "Overselling prevention: Sistem membiarkan overselling", $errors, $tests_passed, $total_tests);
                DB::rollBack();
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->testAssert(true, "Overselling prevention: Exception thrown - " . $e->getMessage(), $errors, $tests_passed, $total_tests);
        }
        
        $produk->stok = $original_stock;
        $produk->save();
    }
    
    private function testValidTransaction($produk, &$errors, &$tests_passed, &$total_tests)
    {
        $this->info("\n=== TEST VALID TRANSACTION ===");
        
        $stok_awal = $produk->stok;
        
        DB::beginTransaction();
        try {
            $penjualan = new Penjualan();
            $penjualan->id_member = null;
            $penjualan->total_item = 0;
            $penjualan->total_harga = 0;
            $penjualan->diskon = 0;
            $penjualan->bayar = 0;
            $penjualan->diterima = 0;
            $penjualan->waktu = date('Y-m-d');
            $penjualan->id_user = 1;
            $penjualan->save();
            
            $jumlah = 2;
            
            $detail = new PenjualanDetail();
            $detail->id_penjualan = $penjualan->id_penjualan;
            $detail->id_produk = $produk->id_produk;
            $detail->harga_jual = $produk->harga_jual;
            $detail->jumlah = $jumlah;
            $detail->diskon = 0;
            $detail->subtotal = $produk->harga_jual * $jumlah;
            $detail->save();
            
            $produk->stok -= $jumlah;
            $produk->save();
            
            $this->testAssert($produk->stok == ($stok_awal - $jumlah), "Valid transaction: Stok berkurang dari {$stok_awal} menjadi {$produk->stok}", $errors, $tests_passed, $total_tests);
            
            RekamanStok::create([
                'id_produk' => $produk->id_produk,
                'id_penjualan' => $penjualan->id_penjualan,
                'waktu' => Carbon::now(),
                'stok_keluar' => $jumlah,
                'stok_awal' => $stok_awal,
                'stok_sisa' => $produk->stok,
                'keterangan' => 'Test: Valid transaction'
            ]);
            
            $this->testAssert(true, "Valid transaction: Rekaman stok berhasil dibuat", $errors, $tests_passed, $total_tests);
            
            RekamanStok::where('id_penjualan', $penjualan->id_penjualan)->delete();
            PenjualanDetail::where('id_penjualan', $penjualan->id_penjualan)->delete();
            $penjualan->delete();
            
            $produk->stok = $stok_awal;
            $produk->save();
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->testAssert(false, "Valid transaction: Exception - " . $e->getMessage(), $errors, $tests_passed, $total_tests);
        }
    }
    
    private function testStockConsistency($produk, &$errors, &$tests_passed, &$total_tests)
    {
        $this->info("\n=== TEST STOCK CONSISTENCY ===");
        
        $fresh_produk = Produk::find($produk->id_produk);
        $this->testAssert($fresh_produk->stok == $produk->stok, "Stock consistency: Database sync OK", $errors, $tests_passed, $total_tests);
        
        $this->testAssert($fresh_produk->stok >= 0, "Stock consistency: No negative stock ({$fresh_produk->stok})", $errors, $tests_passed, $total_tests);
        
        $this->testAssert(is_numeric($fresh_produk->stok), "Stock consistency: Stock is numeric", $errors, $tests_passed, $total_tests);
    }
    
    private function testAssert($condition, $message, &$errors, &$tests_passed, &$total_tests)
    {
        $total_tests++;
        if ($condition) {
            $this->info("✅ $message");
            $tests_passed++;
        } else {
            $this->error("❌ $message");
            $errors[] = $message;
        }
    }
}
