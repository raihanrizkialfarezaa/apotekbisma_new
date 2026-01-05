<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Fixing negative stok_sisa in rekaman_stoks...\n";
$count = DB::table('rekaman_stoks')->where('stok_sisa', '<', 0)->update(['stok_sisa' => 0]);
echo "Fixed: {$count} records\n";

echo "\nChecking VOLTADEX...\n";
$voltadex = DB::table('produk')->where('nama_produk', 'LIKE', '%VOLTADEX%')->first();
if ($voltadex) {
    $lastRek = DB::table('rekaman_stoks')
        ->where('id_produk', $voltadex->id_produk)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    echo "VOLTADEX ID: {$voltadex->id_produk}\n";
    echo "Stok Produk: {$voltadex->stok}\n";
    echo "Stok Rekaman: " . ($lastRek ? $lastRek->stok_sisa : 'N/A') . "\n";
    
    if ($lastRek && intval($lastRek->stok_sisa) != intval($voltadex->stok)) {
        DB::table('produk')
            ->where('id_produk', $voltadex->id_produk)
            ->update(['stok' => max(0, $lastRek->stok_sisa)]);
        echo "Synced VOLTADEX stok to rekaman value\n";
    }
}

echo "\nCreating missing pembelian rekaman...\n";

$TX_START = '2026-01-01 00:00:00';

$pembelianTanpaRekaman = DB::select("
    SELECT pd.id_pembelian, pd.id_produk, SUM(pd.jumlah) as total_jumlah, pb.waktu,
           p.nama_produk
    FROM pembelian_detail pd
    JOIN pembelian pb ON pd.id_pembelian = pb.id_pembelian
    JOIN produk p ON pd.id_produk = p.id_produk
    WHERE pb.waktu >= ?
    AND NOT EXISTS (
        SELECT 1 FROM rekaman_stoks rs 
        WHERE rs.id_pembelian = pd.id_pembelian 
        AND rs.id_produk = pd.id_produk
    )
    GROUP BY pd.id_pembelian, pd.id_produk, pb.waktu, p.nama_produk
", [$TX_START]);

echo "Pembelian tanpa rekaman: " . count($pembelianTanpaRekaman) . "\n";

foreach ($pembelianTanpaRekaman as $pb) {
    $produk = DB::table('produk')->where('id_produk', $pb->id_produk)->first();
    if (!$produk) continue;
    
    $stokSekarang = intval($produk->stok);
    $stokAwal = $stokSekarang - intval($pb->total_jumlah);
    if ($stokAwal < 0) $stokAwal = 0;
    
    DB::table('rekaman_stoks')->insert([
        'id_produk' => $pb->id_produk,
        'id_pembelian' => $pb->id_pembelian,
        'waktu' => $pb->waktu,
        'stok_awal' => $stokAwal,
        'stok_masuk' => intval($pb->total_jumlah),
        'stok_keluar' => 0,
        'stok_sisa' => $stokSekarang,
        'keterangan' => 'Pembelian: Auto-created rekaman yang hilang',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    
    echo "  Created rekaman for: {$pb->nama_produk}\n";
}

echo "\nDone!\n";
