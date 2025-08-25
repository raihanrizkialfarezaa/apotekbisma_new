<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\PenjualanDetail;
use App\Models\PembelianDetail;
use Illuminate\Support\Facades\DB;

echo "=== ANALISIS KOMPREHENSIF SISTEM STOK ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Cek produk acethylesistein
$produk = Produk::where('nama_produk', 'LIKE', '%acethylesistein%')->first();

if (!$produk) {
    echo "❌ Produk acethylesistein tidak ditemukan\n";
    exit;
}

echo "1. ANALISIS PRODUK TARGET:\n";
echo "   ID: {$produk->id_produk}\n";
echo "   Nama: {$produk->nama_produk}\n";
echo "   Stok saat ini: {$produk->stok}\n";

// 2. Analisis transaksi pembelian
$totalPembelian = PembelianDetail::join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
    ->where('pembelian_detail.id_produk', $produk->id_produk)
    ->where('pembelian.no_faktur', '!=', 'o')
    ->whereNotNull('pembelian.no_faktur')
    ->sum('pembelian_detail.jumlah');

echo "\n2. ANALISIS TRANSAKSI PEMBELIAN:\n";
echo "   Total pembelian (selesai): {$totalPembelian}\n";

// 3. Analisis transaksi penjualan
$totalPenjualan = PenjualanDetail::join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
    ->where('penjualan_detail.id_produk', $produk->id_produk)
    ->where('penjualan.bayar', '>', 0)
    ->sum('penjualan_detail.jumlah');

echo "\n3. ANALISIS TRANSAKSI PENJUALAN:\n";
echo "   Total penjualan (dibayar): {$totalPenjualan}\n";

// 4. Analisis rekaman stok
$rekamanStok = RekamanStok::where('id_produk', $produk->id_produk)->orderBy('waktu', 'desc')->get();

echo "\n4. ANALISIS REKAMAN STOK:\n";
echo "   Total rekaman: {$rekamanStok->count()}\n";

$totalMasukRekaman = $rekamanStok->sum('stok_masuk');
$totalKeluarRekaman = $rekamanStok->sum('stok_keluar');

echo "   Total masuk (rekaman): {$totalMasukRekaman}\n";
echo "   Total keluar (rekaman): {$totalKeluarRekaman}\n";

// 5. Hitung perubahan manual
$perubahanManual = RekamanStok::where('id_produk', $produk->id_produk)
    ->whereNull('id_pembelian')
    ->whereNull('id_penjualan')
    ->where(function($query) {
        $query->where('keterangan', 'LIKE', '%Update Stok Manual%')
              ->orWhere('keterangan', 'LIKE', '%Perubahan Stok Manual%');
    })
    ->get()
    ->sum(function($item) {
        return $item->stok_masuk - $item->stok_keluar;
    });

echo "   Perubahan manual: {$perubahanManual}\n";

// 6. Kalkulasi seharusnya
$stokSeharusnya = $totalPembelian - $totalPenjualan + $perubahanManual;
echo "\n5. KALKULASI KONSISTENSI:\n";
echo "   Stok seharusnya: {$stokSeharusnya}\n";
echo "   Stok aktual: {$produk->stok}\n";
echo "   Selisih: " . ($produk->stok - $stokSeharusnya) . "\n";

if ($produk->stok == $stokSeharusnya) {
    echo "   Status: ✅ KONSISTEN\n";
} else {
    echo "   Status: ❌ TIDAK KONSISTEN\n";
}

// 7. Analisis rekaman terbaru
echo "\n6. ANALISIS REKAMAN TERBARU:\n";
$rekamanTerbaru = RekamanStok::where('id_produk', $produk->id_produk)
    ->orderBy('id_rekaman_stok', 'desc')
    ->take(5)
    ->get();

foreach ($rekamanTerbaru as $index => $rekaman) {
    echo "   Rekaman " . ($index + 1) . ":\n";
    echo "     Waktu: {$rekaman->waktu}\n";
    echo "     Masuk: {$rekaman->stok_masuk}, Keluar: {$rekaman->stok_keluar}\n";
    echo "     Awal: {$rekaman->stok_awal}, Sisa: {$rekaman->stok_sisa}\n";
    echo "     Keterangan: {$rekaman->keterangan}\n";
    
    // Cek konsistensi dengan stok produk
    if ($rekaman->stok_sisa != $produk->stok && $index == 0) {
        echo "     ❌ REKAMAN TIDAK SESUAI STOK PRODUK\n";
    }
    echo "\n";
}

// 8. Cek race condition potentials
echo "7. ANALISIS POTENSI RACE CONDITION:\n";

// Cek duplikasi rekaman dalam waktu yang sama
$duplicates = DB::select("
    SELECT waktu, COUNT(*) as count 
    FROM rekaman_stoks 
    WHERE id_produk = ? 
    GROUP BY waktu 
    HAVING COUNT(*) > 1
", [$produk->id_produk]);

if (count($duplicates) > 0) {
    echo "   ❌ Ditemukan " . count($duplicates) . " waktu dengan rekaman ganda\n";
    foreach ($duplicates as $dup) {
        echo "     Waktu: {$dup->waktu}, Jumlah: {$dup->count}\n";
    }
} else {
    echo "   ✅ Tidak ada rekaman ganda\n";
}

// 9. Cek stok minus dalam rekaman
$minusRecords = RekamanStok::where('id_produk', $produk->id_produk)
    ->where(function($query) {
        $query->where('stok_awal', '<', 0)
              ->orWhere('stok_sisa', '<', 0);
    })
    ->count();

echo "   Rekaman dengan stok minus: {$minusRecords}\n";

// 10. Analisis mutator effects
echo "\n8. ANALISIS MUTATOR EFFECTS:\n";

// Temporary disable mutators untuk cek raw values
RekamanStok::$skipMutators = true;

$rawRekaman = RekamanStok::where('id_produk', $produk->id_produk)
    ->orderBy('id_rekaman_stok', 'desc')
    ->first();

if ($rawRekaman) {
    echo "   Raw stok_awal: {$rawRekaman->stok_awal}\n";
    echo "   Raw stok_sisa: {$rawRekaman->stok_sisa}\n";
}

// Re-enable mutators
RekamanStok::$skipMutators = false;

if ($rawRekaman) {
    $processedRekaman = RekamanStok::find($rawRekaman->id_rekaman_stok);
    echo "   Processed stok_awal: {$processedRekaman->stok_awal}\n";
    echo "   Processed stok_sisa: {$processedRekaman->stok_sisa}\n";
    
    if ($rawRekaman->stok_awal != $processedRekaman->stok_awal || 
        $rawRekaman->stok_sisa != $processedRekaman->stok_sisa) {
        echo "   ❌ MUTATOR MENGUBAH DATA\n";
    } else {
        echo "   ✅ MUTATOR TIDAK MENGUBAH DATA\n";
    }
}

echo "\n=== ANALISIS SELESAI ===\n";
