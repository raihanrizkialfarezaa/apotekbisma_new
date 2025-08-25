<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Penjualan;
use App\Models\PenjualanDetail;

echo "=== TEST ROUTE TRANSAKSI AKTIF ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n\n";

// Cek session yang ada
$session_id = session('id_penjualan');
echo "📝 Session id_penjualan saat ini: " . ($session_id ?: 'NULL') . "\n";

if ($session_id) {
    $penjualan = Penjualan::find($session_id);
    if ($penjualan) {
        echo "✅ Transaksi ditemukan: ID {$penjualan->id_penjualan}\n";
        
        $items = PenjualanDetail::where('id_penjualan', $session_id)->get();
        echo "📋 Jumlah item dalam transaksi: " . $items->count() . "\n";
        
        foreach ($items as $item) {
            echo "   - {$item->produk->nama_produk}: {$item->jumlah} unit\n";
        }
        
        echo "\n✅ Route /transaksi/aktif akan menampilkan transaksi ini\n";
        echo "✅ Tabel penjualan akan terisi dengan data yang ada\n";
    } else {
        echo "❌ Transaksi tidak ditemukan dalam database\n";
        echo "⚠️  Route /transaksi/aktif akan redirect ke /transaksi/baru\n";
    }
} else {
    echo "ℹ️  Tidak ada session aktif\n";
    echo "⚠️  Route /transaksi/aktif akan redirect ke /transaksi/baru\n";
}

echo "\n=== SIMULATION ROUTE BEHAVIOR ===\n";

// Simulasi logic PenjualanController::createOrContinue()
if ($session_id = session('id_penjualan')) {
    $penjualan = Penjualan::find($session_id);
    if ($penjualan) {
        echo "✅ HASIL: Akan menampilkan halaman transaksi dengan data existing\n";
        echo "   - ID Penjualan: {$penjualan->id_penjualan}\n";
        echo "   - DataTable akan load data dari route: transaksi.data/{$penjualan->id_penjualan}\n";
    } else {
        echo "⚠️  HASIL: Akan redirect ke transaksi baru (transaksi tidak valid)\n";
    }
} else {
    echo "⚠️  HASIL: Akan redirect ke transaksi baru (tidak ada session)\n";
}

echo "\n=== SELESAI ===\n";
