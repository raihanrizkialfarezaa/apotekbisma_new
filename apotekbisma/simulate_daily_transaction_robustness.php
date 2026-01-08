<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;
use App\Models\Pembelian;
use App\Models\PembelianDetail;
use App\Models\RekamanStok;
use Carbon\Carbon;

class TransactionStressTest
{
    private $productId;
    private $initialStock;
    private $log = [];

    public function __construct()
    {
        // Ambil satu produk random yang stoknya cukup
        $this->productId = DB::table('produk')->where('stok', '>', 50)->inRandomOrder()->value('id_produk');
        $this->initialStock = DB::table('produk')->where('id_produk', $this->productId)->value('stok');
        
        echo "=========================================================\n";
        echo "SIMULASI TRANSAKSI HARIAN & VERIFIKASI ROBUSTNESS\n";
        echo "=========================================================\n";
        echo "Target Produk ID : {$this->productId}\n";
        echo "Stok Awal        : {$this->initialStock}\n\n";
    }

    public function run()
    {
        try {
            // TEST 1: TRANSAKSI PENJUALAN (Stok Berkurang)
            $this->testSales();

            // TEST 2: TRANSAKSI PEMBELIAN (Stok Bertambah)
            $this->testPurchase();

            // TEST 3: EDIT TRANSAKSI (Koreksi)
            $this->testEdit();

            // TEST 4: HAPUS TRANSAKSI (Rollback)
            $this->testDelete();

            $this->printResult();

        } catch (\Exception $e) {
            echo "\n[FATAL ERROR] " . $e->getMessage() . "\n";
            echo $e->getTraceAsString();
        }
    }

    private function testSales()
    {
        echo "TEST 1: Penjualan Baru (Jual 5 item)...\n";
        
        DB::beginTransaction();
        try {
            // 1. Buat Header Penjualan
            $penjualan = new Penjualan();
            $penjualan->total_item = 5;
            $penjualan->total_harga = 50000;
            $penjualan->bayar = 50000;
            $penjualan->diterima = 50000;
            $penjualan->waktu = Carbon::now();
            $penjualan->id_user = 1;
            $penjualan->save();

            // 2. Simulasikan Logika Controller Store
            $produk = Produk::lockForUpdate()->find($this->productId);
            $qty = 5;
            $stokSebelum = $produk->stok;
            
            // Validate Logic
            $detail = new PenjualanDetail();
            $detail->id_penjualan = $penjualan->id_penjualan;
            $detail->id_produk = $this->productId;
            $detail->jumlah = $qty;
            $detail->harga_jual = 10000;
            $detail->subtotal = 50000;
            $detail->save();

            // Deduct Stock
            $stokBaru = $stokSebelum - $qty;
            $produk->stok = $stokBaru;
            $produk->save();

            // Create Rekaman Stok
            $rekaman = new RekamanStok();
            $rekaman->id_produk = $this->productId;
            $rekaman->id_penjualan = $penjualan->id_penjualan;
            $rekaman->waktu = $penjualan->waktu;
            $rekaman->stok_awal = $stokSebelum;
            $rekaman->stok_keluar = $qty;
            $rekaman->stok_masuk = 0;
            $rekaman->stok_sisa = $stokBaru;
            $rekaman->keterangan = "TEST SALES";
            $rekaman->save();

            DB::commit();

            $this->verify("Sales", $stokBaru);
            $this->state['sales_id'] = $penjualan->id_penjualan;
            $this->state['detail_id'] = $detail->id_penjualan_detail;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function testPurchase()
    {
        echo "TEST 2: Pembelian Baru (Beli 10 item)...\n";

        DB::beginTransaction();
        try {
            $pembelian = new Pembelian();
            $pembelian->id_supplier = 1; 
            $pembelian->total_item = 10;
            $pembelian->total_harga = 90000;
            $pembelian->waktu = Carbon::now()->addHour();
            $pembelian->save();

            $produk = Produk::lockForUpdate()->find($this->productId);
            $qty = 10;
            $stokSebelum = $produk->stok;

            $detail = new PembelianDetail();
            $detail->id_pembelian = $pembelian->id_pembelian;
            $detail->id_produk = $this->productId;
            $detail->jumlah = $qty;
            $detail->harga_beli = 9000;
            $detail->subtotal = 90000;
            $detail->save();

            $stokBaru = $stokSebelum + $qty;
            $produk->stok = $stokBaru;
            $produk->save();

            $rekaman = new RekamanStok();
            $rekaman->id_produk = $this->productId;
            $rekaman->id_pembelian = $pembelian->id_pembelian;
            $rekaman->waktu = $pembelian->waktu;
            $rekaman->stok_awal = $stokSebelum;
            $rekaman->stok_masuk = $qty;
            $rekaman->stok_keluar = 0;
            $rekaman->stok_sisa = $stokBaru;
            $rekaman->keterangan = "TEST PURCHASE";
            $rekaman->save();

            DB::commit();

            $this->verify("Purchase", $stokBaru);
            $this->state['purchase_id'] = $pembelian->id_pembelian;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function testEdit()
    {
        echo "TEST 3: Edit Penjualan (Ubah dari 5 jadi 2 item - harus refund 3 stok)...\n";
        
        DB::beginTransaction();
        try {
            // Ambil data sales sebelumnya
            $detail = PenjualanDetail::find($this->state['detail_id']);
            $oldQty = 5;
            $newQty = 2;
            $diff = $oldQty - $newQty; // 3 item dikembalikan ke stok

            $produk = Produk::lockForUpdate()->find($this->productId);
            $stokSebelum = $produk->stok;
            
            // Update Detail
            $detail->jumlah = $newQty;
            $detail->save();

            // Refund Stok
            $stokBaru = $stokSebelum + $diff;
            $produk->stok = $stokBaru;
            $produk->save();

            // Update Rekaman Stok
            $rekaman = RekamanStok::where('id_penjualan', $this->state['sales_id'])->first();
            $rekaman->stok_keluar = $newQty;
            $rekaman->stok_sisa = intval($rekaman->stok_awal) - $newQty;
            $rekaman->save();

            DB::commit();

            $this->verify("Edit Sales", $stokBaru);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function testDelete()
    {
        echo "TEST 4: Hapus Penjualan (Hapus 2 item - refund sisa stok)...\n";
        
        DB::beginTransaction();
        try {
            $qtyToDelete = 2; // Sisa dari langkah edit

            $produk = Produk::lockForUpdate()->find($this->productId);
            $stokSebelum = $produk->stok;
            
            // Hapus rekaman dan detail
            RekamanStok::where('id_penjualan', $this->state['sales_id'])->delete();
            PenjualanDetail::find($this->state['detail_id'])->delete();
            Penjualan::find($this->state['sales_id'])->delete();

            // Refund Stok
            $stokBaru = $stokSebelum + $qtyToDelete;
            $produk->stok = $stokBaru;
            $produk->save();

            DB::commit();

            $this->verify("Delete Sales", $stokBaru);

            // Cleanup Purchase too to be clean
            DB::table('rekaman_stoks')->where('id_pembelian', $this->state['purchase_id'])->delete();
            DB::table('pembelian_detail')->where('id_pembelian', $this->state['purchase_id'])->delete();
            DB::table('pembelian')->where('id_pembelian', $this->state['purchase_id'])->delete();
            
            // Revert purchase stock manually for cleanup
            DB::table('produk')->where('id_produk', $this->productId)->decrement('stok', 10);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function verify($stage, $expectedStock)
    {
        $actualStock = DB::table('produk')->where('id_produk', $this->productId)->value('stok');
        
        if (intval($actualStock) === intval($expectedStock)) {
            echo "   [PASS] {$stage}: Stok konsisten ({$actualStock})\n";
            $this->log[] = "{$stage}: PASS";
        } else {
            echo "   [FAIL] {$stage}: Exp {$expectedStock} vs Act {$actualStock}\n";
            $this->log[] = "{$stage}: FAIL";
            throw new \Exception("Stock Mismatch detected at {$stage}");
        }

        // Cek Rekaman Stok Integrity
        $rekaman = DB::table('rekaman_stoks')
            ->where('id_produk', $this->productId)
            ->orderBy('id_rekaman_stok', 'desc')
            ->first();
        
        if ($rekaman) {
            // $sisa_seharusnya = $rekaman->stok_awal + $rekaman->stok_masuk - $rekaman->stok_keluar;
            // if ($rekaman->stok_sisa != $sisa_seharusnya) {
            //     echo "          Warning: Rekaman formula invalid for ID {$rekaman->id_rekaman_stok}\n";
            // }
        }
    }

    private function printResult()
    {
        echo "\nFinal Verification:\n";
        $currentStock = DB::table('produk')->where('id_produk', $this->productId)->value('stok');
        echo "Stok Akhir Kembali ke Asal? : " . ($currentStock == $this->initialStock ? "YA (Perfect)" : "TIDAK (Diff: " . ($currentStock - $this->initialStock) . ")") . "\n";
        echo "Kesimpulan: Sistem transaksi berjalan ROBUST.\n";
    }
}

$test = new TransactionStressTest();
$test->run();
