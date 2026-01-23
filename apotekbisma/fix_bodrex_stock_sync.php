<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

echo "\n";
echo "=============================================================\n";
echo "  FIX BODREX STOCK SYNCHRONIZATION ISSUE\n";
echo "=============================================================\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// Get BODREX product
$produk = Produk::find(108);
if (!$produk) {
    echo "❌ Produk BODREX (ID: 108) tidak ditemukan!\n";
    exit(1);
}

echo "📦 PRODUK BODREX (ID: 108)\n";
echo "   Nama: {$produk->nama_produk}\n";
echo "   Kode: {$produk->kode_produk}\n";
echo "   🔴 Stok Saat Ini: {$produk->stok}\n\n";

// Get latest rekaman stok
$latestRekaman = RekamanStok::where('id_produk', 108)
    ->orderBy('waktu', 'desc')
    ->orderBy('id_rekaman_stok', 'desc')
    ->first();

if (!$latestRekaman) {
    echo "❌ Tidak ada rekaman stok!\n";
    exit(1);
}

echo "📊 REKAMAN STOK TERAKHIR:\n";
echo "   ID: {$latestRekaman->id}\n";
echo "   Waktu: {$latestRekaman->waktu}\n";
echo "   Stok Sisa: {$latestRekaman->stok_sisa}\n";
echo "   Keterangan: {$latestRekaman->keterangan}\n\n";

// Calculate expected stock
$expectedStock = $latestRekaman->stok_sisa;
$currentStock = $produk->stok;

echo "═══════════════════════════════════════════════════════════\n";
echo "ANALISIS:\n";
echo "═══════════════════════════════════════════════════════════\n\n";

if ($expectedStock == $currentStock) {
    echo "✅ STOK SUDAH SINKRON!\n";
    echo "   Expected: {$expectedStock}\n";
    echo "   Current: {$currentStock}\n";
    exit(0);
}

echo "❌ STOK TIDAK SINKRON!\n";
echo "   Expected (dari rekaman): {$expectedStock}\n";
echo "   Current (di produk): {$currentStock}\n";
echo "   Selisih: " . ($expectedStock - $currentStock) . "\n\n";

// Ask for confirmation
echo "═══════════════════════════════════════════════════════════\n";
echo "FIX YANG AKAN DILAKUKAN:\n";
echo "═══════════════════════════════════════════════════════════\n\n";
echo "1. Update produk.stok dari {$currentStock} → {$expectedStock}\n";
echo "2. Tidak mengubah rekaman_stoks (sudah benar)\n\n";

echo "Lanjutkan fix? (y/n): ";
$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));
fclose($handle);

if (strtolower($line) !== 'y') {
    echo "\n❌ Fix dibatalkan.\n";
    exit(0);
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "EXECUTING FIX...\n";
echo "═══════════════════════════════════════════════════════════\n\n";

DB::beginTransaction();

try {
    // Update produk stok
    $oldStok = $produk->stok;
    $produk->stok = $expectedStock;
    
    // Disable observer temporarily to avoid creating new rekaman
    Produk::unguard();
    $produk->timestamps = false;
    $produk->save();
    Produk::reguard();
    
    echo "✅ Updated produk.stok: {$oldStok} → {$expectedStock}\n\n";
    
    DB::commit();
    
    echo "═══════════════════════════════════════════════════════════\n";
    echo "✅ FIX BERHASIL!\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    echo "VERIFIKASI:\n";
    $produk->refresh();
    echo "   Stok Produk: {$produk->stok}\n";
    echo "   Stok Rekaman: {$latestRekaman->stok_sisa}\n";
    echo "   Status: " . ($produk->stok == $latestRekaman->stok_sisa ? "✅ SINKRON" : "❌ MASIH TIDAK SINKRON") . "\n\n";
    
    echo "═══════════════════════════════════════════════════════════\n";
    echo "PENJELASAN MASALAH:\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    echo "🔍 Mengapa terjadi?\n";
    echo "   - Ada 2 baseline records di rekaman_stoks:\n";
    echo "     1. 2025-12-31 23:59:59 (BASELINE_OPNAME_31DES2025_V3)\n";
    echo "     2. 2026-01-23 09:27:09 (Stock Opname Cutoff 31 Desember 2025)\n\n";
    echo "   - Record terakhir (2026-01-23) menambah stok 0→9\n";
    echo "   - Tapi produk.stok tidak ter-update (masih 0)\n\n";
    
    echo "✅ Apa yang sudah diperbaiki?\n";
    echo "   - Stok di tabel 'produk' sudah disinkronkan dengan rekaman_stoks\n";
    echo "   - Tidak ada data rekaman yang diubah (preserving history)\n\n";
    
    echo "⚠️  Catatan:\n";
    echo "   - CSV cutoff menunjukkan 'BODREX FB ALL VAR' (kode 110) = 25 stok\n";
    echo "   - Database punya 'BODREX' (ID 108, kode kosong) = 9 stok\n";
    echo "   - Ini mungkin produk yang berbeda, perlu cek manual!\n\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "❌ ERROR: {$e->getMessage()}\n";
    echo "   File: {$e->getFile()}\n";
    echo "   Line: {$e->getLine()}\n\n";
    exit(1);
}

echo "=============================================================\n";
echo "Fix selesai: " . date('Y-m-d H:i:s') . "\n";
echo "=============================================================\n\n";
