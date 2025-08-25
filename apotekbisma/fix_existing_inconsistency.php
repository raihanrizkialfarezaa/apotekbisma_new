<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;

echo "=== PERBAIKAN DATA INCONSISTENT ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n\n";

$produk = Produk::find(2);
echo "📦 Produk: {$produk->nama_produk}\n";
echo "📦 Stok saat ini: {$produk->stok}\n\n";

// Ambil semua rekaman stok untuk produk ini
$rekaman_stoks = RekamanStok::where('id_produk', 2)
                           ->orderBy('waktu', 'desc')
                           ->limit(10)
                           ->get();

echo "📋 10 Rekaman Stok Terbaru:\n";
echo "----------------------------\n";
foreach ($rekaman_stoks as $i => $rekaman) {
    echo ($i + 1) . ". [{$rekaman->waktu}] ";
    echo "Awal: {$rekaman->stok_awal}, ";
    echo "Masuk: " . ($rekaman->stok_masuk ?? 0) . ", ";
    echo "Keluar: " . ($rekaman->stok_keluar ?? 0) . ", ";
    echo "Sisa: {$rekaman->stok_sisa}";
    if ($rekaman->keterangan) {
        echo " | {$rekaman->keterangan}";
    }
    echo "\n";
}

echo "\n📊 Analisis Inkonsistensi:\n";
echo "----------------------------\n";

$rekaman_terakhir = $rekaman_stoks->first();
if ($rekaman_terakhir && $rekaman_terakhir->stok_sisa != $produk->stok) {
    echo "❌ Rekaman terakhir menunjukkan stok: {$rekaman_terakhir->stok_sisa}\n";
    echo "❌ Stok produk actual: {$produk->stok}\n";
    echo "❌ Selisih: " . ($rekaman_terakhir->stok_sisa - $produk->stok) . "\n\n";
    
    echo "🔧 Melakukan perbaikan...\n";
    
    // Update stok produk agar sesuai dengan rekaman terakhir yang valid
    // Atau buat rekaman penyesuaian
    
    $stok_seharusnya = $rekaman_terakhir->stok_sisa;
    $stok_sekarang = $produk->stok;
    
    if ($stok_seharusnya != $stok_sekarang) {
        // Update stok produk
        $produk->stok = $stok_seharusnya;
        $produk->save();
        
        echo "✅ Stok produk diupdate dari {$stok_sekarang} menjadi {$stok_seharusnya}\n";
        
        // Buat rekaman penyesuaian
        RekamanStok::create([
            'id_produk' => $produk->id_produk,
            'waktu' => now(),
            'stok_masuk' => $stok_seharusnya > $stok_sekarang ? $stok_seharusnya - $stok_sekarang : 0,
            'stok_keluar' => $stok_sekarang > $stok_seharusnya ? $stok_sekarang - $stok_seharusnya : 0,
            'stok_awal' => $stok_sekarang,
            'stok_sisa' => $stok_seharusnya,
            'keterangan' => 'Penyesuaian otomatis: Sinkronisasi stok dengan rekaman'
        ]);
        
        echo "✅ Rekaman penyesuaian dibuat\n";
    }
} else {
    echo "✅ Data sudah konsisten\n";
}

echo "\n=== SELESAI ===\n";
