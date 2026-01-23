<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "\n";
echo "=============================================================\n";
echo "  ANALISIS: KENAPA ADA 2 STOCK OPNAME BODREX?\n";
echo "=============================================================\n\n";

// Get rekaman setelah cutoff
$rekaman = DB::table('rekaman_stoks')
    ->where('id_produk', 108)
    ->where('waktu', '>=', '2025-12-31')
    ->orderBy('waktu', 'asc')
    ->get();

echo "Total Rekaman setelah 31 Des 2025: {$rekaman->count()}\n\n";

echo "═══════════════════════════════════════════════════════════\n";
echo "DETAIL REKAMAN:\n";
echo "═══════════════════════════════════════════════════════════\n\n";

foreach ($rekaman as $idx => $r) {
    echo "RECORD #" . ($idx + 1) . ":\n";
    echo "  ID Rekaman  : {$r->id_rekaman_stok}\n";
    echo "  Waktu       : {$r->waktu}\n";
    echo "  Created At  : {$r->created_at}\n";
    echo "  Stok Masuk  : {$r->stok_masuk}\n";
    echo "  Stok Keluar : {$r->stok_keluar}\n";
    echo "  Stok Awal   : {$r->stok_awal}\n";
    echo "  Stok Sisa   : {$r->stok_sisa}\n";
    echo "  Keterangan  : {$r->keterangan}\n";
    
    // Cek jika ini stock opname
    if (stripos($r->keterangan, 'opname') !== false || 
        stripos($r->keterangan, 'baseline') !== false) {
        echo "  ⚠️  JENIS    : STOCK OPNAME / BASELINE\n";
    } else {
        echo "  📦 JENIS    : TRANSAKSI NORMAL\n";
    }
    echo "\n";
}

// Analisis kedua stock opname
$opnames = $rekaman->filter(function($r) {
    return stripos($r->keterangan, 'opname') !== false || 
           stripos($r->keterangan, 'baseline') !== false;
});

echo "═══════════════════════════════════════════════════════════\n";
echo "ANALISIS STOCK OPNAME:\n";
echo "═══════════════════════════════════════════════════════════\n\n";

echo "Jumlah Stock Opname: {$opnames->count()}\n\n";

if ($opnames->count() >= 2) {
    $first = $opnames->first();
    $last = $opnames->last();
    
    echo "🔴 DITEMUKAN 2 STOCK OPNAME!\n\n";
    
    echo "Stock Opname #1 (BASELINE ASLI):\n";
    echo "  Tanggal     : {$first->waktu}\n";
    echo "  Created At  : {$first->created_at}\n";
    echo "  Stok: {$first->stok_awal} → {$first->stok_sisa}\n";
    echo "  Keterangan  : {$first->keterangan}\n";
    echo "  ✅ Ini adalah baseline cutoff 31 Des 2025 yang BENAR\n\n";
    
    echo "Stock Opname #2 (DUPLIKAT/MANUAL):\n";
    echo "  Tanggal     : {$last->waktu}\n";
    echo "  Created At  : {$last->created_at}\n";
    echo "  Stok: {$last->stok_awal} → {$last->stok_sisa}\n";
    echo "  Keterangan  : {$last->keterangan}\n";
    echo "  ⚠️  Ini dibuat di tanggal " . date('d M Y H:i:s', strtotime($last->created_at)) . "\n";
    echo "     tapi waktu-nya di-set ke {$last->waktu}\n\n";
    
    echo "═══════════════════════════════════════════════════════════\n";
    echo "KESIMPULAN:\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    // Cari transaksi di antara dua opname
    $transaksi_antara = $rekaman->filter(function($r) use ($first, $last) {
        return $r->waktu > $first->waktu && 
               $r->waktu < $last->waktu &&
               stripos($r->keterangan, 'opname') === false &&
               stripos($r->keterangan, 'baseline') === false;
    });
    
    echo "1. BASELINE PERTAMA (31 Des 2025 23:59:59):\n";
    echo "   - Dibuat: {$first->created_at}\n";
    echo "   - Stok hasil: {$first->stok_sisa}\n";
    echo "   - Status: ✅ BENAR (dari create_so_baseline.php)\n\n";
    
    echo "2. TRANSAKSI SETELAH BASELINE:\n";
    echo "   - Jumlah transaksi: {$transaksi_antara->count()}\n";
    
    if ($transaksi_antara->count() > 0) {
        $stok_before_second_opname = $transaksi_antara->last()->stok_sisa;
        echo "   - Stok sebelum opname kedua: {$stok_before_second_opname}\n";
    }
    echo "\n";
    
    echo "3. STOCK OPNAME KEDUA (23 Jan 2026):\n";
    echo "   - Dibuat: {$last->created_at}\n";
    echo "   - Waktu di-set ke: {$last->waktu} (tidak sesuai created_at!)\n";
    echo "   - Stok awal: {$last->stok_awal}\n";
    echo "   - Stok akhir: {$last->stok_sisa}\n";
    echo "   - Keterangan: \"{$last->keterangan}\"\n";
    echo "   - Status: ⚠️  DUPLIKAT/MANUAL\n\n";
    
    echo "═══════════════════════════════════════════════════════════\n";
    echo "PERTANYAAN: STOK YANG BENAR BERAPA?\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    // Cek transaksi setelah opname kedua
    $transaksi_setelah = $rekaman->filter(function($r) use ($last) {
        return $r->waktu > $last->waktu;
    });
    
    if ($transaksi_setelah->count() == 0) {
        echo "✅ STOK YANG BENAR: {$last->stok_sisa}\n\n";
        echo "Alasan:\n";
        echo "- Tidak ada transaksi setelah opname kedua\n";
        echo "- Record terakhir di rekaman_stoks = {$last->stok_sisa}\n";
        echo "- Ini adalah stok real saat ini\n\n";
        
        echo "⚠️  TAPI stok di tabel produk = 0 (TIDAK SINKRON!)\n\n";
    } else {
        $last_rekaman = $rekaman->last();
        echo "📊 STOK YANG BENAR: {$last_rekaman->stok_sisa}\n\n";
        echo "Alasan:\n";
        echo "- Ada {$transaksi_setelah->count()} transaksi setelah opname kedua\n";
        echo "- Transaksi terakhir: {$last_rekaman->waktu}\n";
        echo "- Stok terakhir di rekaman: {$last_rekaman->stok_sisa}\n\n";
    }
    
    echo "═══════════════════════════════════════════════════════════\n";
    echo "REKOMENDASI:\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    $produk = DB::table('produk')->where('id_produk', 108)->first();
    $stok_rekaman_terakhir = $rekaman->last()->stok_sisa;
    
    if ($produk->stok != $stok_rekaman_terakhir) {
        echo "❌ PROBLEM: produk.stok ({$produk->stok}) ≠ rekaman terakhir ({$stok_rekaman_terakhir})\n\n";
        echo "FIX:\n";
        echo "1. Update produk.stok menjadi {$stok_rekaman_terakhir}\n";
        echo "2. Jangan hapus rekaman kedua (sudah jadi bagian dari history)\n";
        echo "3. Jalankan: php fix_bodrex_stock_sync.php dan jawab 'y'\n\n";
        echo "ATAU jika stok 0 adalah yang benar:\n";
        echo "1. Hapus stock opname kedua (ID: {$last->id_rekaman_stok})\n";
        echo "2. Produk.stok sudah benar (0)\n\n";
    } else {
        echo "✅ STOK SUDAH SINKRON!\n";
        echo "   produk.stok = rekaman_stoks terakhir = {$stok_rekaman_terakhir}\n\n";
    }
}

echo "=============================================================\n";
echo "Analisis selesai: " . date('Y-m-d H:i:s') . "\n";
echo "=============================================================\n\n";
