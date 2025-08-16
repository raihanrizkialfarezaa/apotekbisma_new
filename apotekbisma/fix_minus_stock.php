<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== PERBAIKAN STOK MINUS ===\n";
echo "Tanggal: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Perbaiki rekaman stok yang minus
echo "1. Memperbaiki rekaman stok minus...\n";

$rekamanAwalMinus = DB::table('rekaman_stoks')->where('stok_awal', '<', 0)->get();
$rekamanSisaMinus = DB::table('rekaman_stoks')->where('stok_sisa', '<', 0)->get();

echo "   Rekaman stok_awal minus: " . $rekamanAwalMinus->count() . "\n";
echo "   Rekaman stok_sisa minus: " . $rekamanSisaMinus->count() . "\n";

// Fix stok_awal minus
$fixedAwal = 0;
foreach ($rekamanAwalMinus as $rekaman) {
    DB::table('rekaman_stoks')
        ->where('id_rekaman_stok', $rekaman->id_rekaman_stok)
        ->update(['stok_awal' => 0, 'updated_at' => now()]);
    $fixedAwal++;
}

// Fix stok_sisa minus
$fixedSisa = 0;
foreach ($rekamanSisaMinus as $rekaman) {
    DB::table('rekaman_stoks')
        ->where('id_rekaman_stok', $rekaman->id_rekaman_stok)
        ->update(['stok_sisa' => 0, 'updated_at' => now()]);
    $fixedSisa++;
}

echo "   ✅ Diperbaiki $fixedAwal rekaman stok_awal minus\n";
echo "   ✅ Diperbaiki $fixedSisa rekaman stok_sisa minus\n";

// 2. Verifikasi tidak ada stok produk minus
echo "\n2. Verifikasi stok produk...\n";
$produkMinus = DB::table('produk')->where('stok', '<', 0)->count();
echo "   Produk dengan stok minus: $produkMinus\n";

if ($produkMinus > 0) {
    DB::table('produk')->where('stok', '<', 0)->update(['stok' => 0, 'updated_at' => now()]);
    echo "   ✅ Diperbaiki $produkMinus produk dengan stok minus\n";
} else {
    echo "   ✅ Tidak ada produk dengan stok minus\n";
}

// 3. Final check
echo "\n3. Verifikasi akhir...\n";
$rekamanAwalMinusAfter = DB::table('rekaman_stoks')->where('stok_awal', '<', 0)->count();
$rekamanSisaMinusAfter = DB::table('rekaman_stoks')->where('stok_sisa', '<', 0)->count();
$produkMinusAfter = DB::table('produk')->where('stok', '<', 0)->count();

echo "   Rekaman stok_awal minus: $rekamanAwalMinusAfter\n";
echo "   Rekaman stok_sisa minus: $rekamanSisaMinusAfter\n";
echo "   Produk stok minus: $produkMinusAfter\n";

$totalMinusAfter = $rekamanAwalMinusAfter + $rekamanSisaMinusAfter + $produkMinusAfter;

if ($totalMinusAfter == 0) {
    echo "\n✅ SUKSES: Semua stok minus telah diperbaiki!\n";
} else {
    echo "\n❌ Masih ada $totalMinusAfter data dengan stok minus\n";
}

echo "\n=== PERBAIKAN SELESAI ===\n";
