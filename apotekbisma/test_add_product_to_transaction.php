<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;
use App\Models\RekamanStok;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

echo "=== TEST PENAMBAHAN PRODUK KE TRANSAKSI ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n\n";

$produk = Produk::find(2);
if (!$produk) {
    echo "❌ Produk tidak ditemukan\n";
    exit;
}

echo "📦 Produk: {$produk->nama_produk}\n";
echo "📦 Stok awal: {$produk->stok}\n\n";

// Test simulasi penambahan produk ke transaksi baru
echo "🧪 TEST 1: Menambah produk ke transaksi baru\n";
echo "============================================\n";

// Simulasi session kosong (transaksi baru)
session()->forget('id_penjualan');

$stok_awal = $produk->stok;

// Simulasi request POST ke PenjualanDetailController::store
$request_data = [
    'id_produk' => $produk->id_produk,
    'id_penjualan' => null
];

DB::beginTransaction();

try {
    // Simulasi controller logic
    $produk_check = Produk::where('id_produk', $request_data['id_produk'])->first();
    
    if ($produk_check->stok <= 0) {
        throw new Exception('Stok habis');
    }
    
    // Tidak ada id_penjualan, buat baru
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
    
    $id_penjualan = $penjualan->id_penjualan;
    session(['id_penjualan' => $id_penjualan]);
    
    echo "✅ Transaksi baru dibuat dengan ID: {$id_penjualan}\n";
    
    // Buat detail
    $stok_sebelum = $produk_check->stok;
    $jumlah = 1;
    
    $detail = new PenjualanDetail();
    $detail->id_penjualan = $id_penjualan;
    $detail->id_produk = $produk_check->id_produk;
    $detail->harga_jual = $produk_check->harga_jual;
    $detail->jumlah = $jumlah;
    $detail->diskon = 0;
    $detail->subtotal = $produk_check->harga_jual;
    $detail->save();
    
    // Update stok
    $produk_check->stok = $stok_sebelum - $jumlah;
    $produk_check->save();
    
    // Rekaman stok
    RekamanStok::create([
        'id_produk' => $produk_check->id_produk,
        'id_penjualan' => $id_penjualan,
        'waktu' => Carbon::now(),
        'stok_keluar' => $jumlah,
        'stok_awal' => $stok_sebelum,
        'stok_sisa' => $produk_check->stok,
        'keterangan' => 'Test: Transaksi penjualan produk'
    ]);
    
    echo "✅ Produk berhasil ditambahkan ke transaksi\n";
    echo "📊 Stok sebelum: {$stok_sebelum}\n";
    echo "📊 Stok setelah: {$produk_check->stok}\n";
    echo "📝 Session id_penjualan: " . session('id_penjualan') . "\n\n";
    
    // Test ambil data untuk tabel (simulasi method data)
    echo "🧪 TEST 2: Mengambil data untuk tabel penjualan\n";
    echo "===============================================\n";
    
    $detail_data = PenjualanDetail::with('produk')
        ->where('id_penjualan', $id_penjualan)
        ->join('produk', 'penjualan_detail.id_produk', '=', 'produk.id_produk')
        ->orderBy('produk.nama_produk', 'asc')
        ->select('penjualan_detail.*')
        ->get();
    
    echo "📋 Jumlah item di tabel: " . $detail_data->count() . "\n";
    
    foreach ($detail_data as $item) {
        echo "✅ Item ditemukan:\n";
        echo "   - Produk: {$item->produk->nama_produk}\n";
        echo "   - Jumlah: {$item->jumlah}\n";
        echo "   - Harga: " . number_format($item->harga_jual) . "\n";
        echo "   - Subtotal: " . number_format($item->subtotal) . "\n";
    }
    
    if ($detail_data->count() > 0) {
        echo "✅ BERHASIL: Produk muncul di tabel penjualan\n";
    } else {
        echo "❌ GAGAL: Produk tidak muncul di tabel penjualan\n";
    }
    
    // Test session setelah redirect ke transaksi aktif
    echo "\n🧪 TEST 3: Session setelah redirect ke transaksi aktif\n";
    echo "====================================================\n";
    
    $session_id = session('id_penjualan');
    if ($session_id) {
        echo "✅ Session id_penjualan masih ada: {$session_id}\n";
        
        $penjualan_check = Penjualan::find($session_id);
        if ($penjualan_check) {
            echo "✅ Transaksi ditemukan di database\n";
            
            $items_count = PenjualanDetail::where('id_penjualan', $session_id)->count();
            echo "✅ Jumlah item dalam transaksi: {$items_count}\n";
            
            if ($items_count > 0) {
                echo "✅ SUKSES: Transaksi memiliki item dan siap ditampilkan\n";
            } else {
                echo "❌ MASALAH: Transaksi tidak memiliki item\n";
            }
        } else {
            echo "❌ MASALAH: Transaksi tidak ditemukan di database\n";
        }
    } else {
        echo "❌ MASALAH: Session id_penjualan hilang\n";
    }
    
    DB::commit();
    echo "\n✅ Test berhasil - data disimpan\n";
    
} catch (Exception $e) {
    DB::rollback();
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n=== SELESAI ===\n";
