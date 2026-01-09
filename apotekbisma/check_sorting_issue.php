<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== CHECKING SORTING ISSUE FOR PRODUCT 994 ===\n\n";

// Cek record pembelian yang terkait dengan rekaman stok ID 176313
$rs = DB::table('rekaman_stoks')->where('id_rekaman_stok', 176313)->first();
echo "Rekaman Stok ID 176313 (Pembelian Jan 2026):\n";
echo "  waktu: " . $rs->waktu . "\n";
echo "  id_pembelian: " . $rs->id_pembelian . "\n";
echo "  created_at: " . $rs->created_at . "\n";

if ($rs->id_pembelian) {
    $pembelian = DB::table('pembelian')->where('id_pembelian', $rs->id_pembelian)->first();
    echo "\n  Pembelian Detail:\n";
    echo "    pembelian.waktu: " . $pembelian->waktu . "\n";
    echo "    pembelian.created_at: " . $pembelian->created_at . "\n";
}

// Cek rekaman Stock Opname
$so = DB::table('rekaman_stoks')->where('id_rekaman_stok', 176469)->first();
echo "\n\nStock Opname ID 176469 (31 Des 2025):\n";
echo "  waktu: " . $so->waktu . "\n";
echo "  created_at: " . $so->created_at . "\n";
echo "  id_pembelian: " . ($so->id_pembelian ?: 'NULL') . "\n";
echo "  id_penjualan: " . ($so->id_penjualan ?: 'NULL') . "\n";
echo "  keterangan: " . $so->keterangan . "\n";

// Check bagaimana UI sorting - bandingkan waktu_raw
echo "\n\n=== COMPARING WAKTU FOR SORTING ===\n";
echo "Pembelian rekaman_stoks.waktu: " . $rs->waktu . "\n";
echo "Pembelian pembelians.waktu: " . ($pembelian->waktu ?? 'N/A') . "\n";
echo "Stock Opname rekaman_stoks.waktu: " . $so->waktu . "\n";

echo "\n\n=== URUTAN YANG BENAR (by rekaman_stoks.waktu DESC) ===\n";
$records = DB::table('rekaman_stoks')
    ->where('id_produk', 994)
    ->whereBetween('waktu', ['2025-12-25 00:00:00', '2026-01-05 00:00:00'])
    ->orderBy('waktu', 'desc')
    ->orderBy('created_at', 'desc')
    ->get();

echo "NO | ID        | WAKTU                | CREATED_AT           | MASUK | KELUAR | SISA | KETERANGAN\n";
echo str_repeat("-", 120) . "\n";
$no = 1;
foreach ($records as $r) {
    $masuk = $r->stok_masuk ?: '-';
    $keluar = $r->stok_keluar ?: '-';
    $ket = substr($r->keterangan ?? '', 0, 40);
    printf("%2d | %9d | %s | %s | %5s | %6s | %4d | %s\n",
        $no++,
        $r->id_rekaman_stok,
        $r->waktu,
        $r->created_at,
        $masuk, $keluar,
        $r->stok_sisa,
        $ket
    );
}

echo "\n\n=== MASALAH TERIDENTIFIKASI ===\n";
echo "UI menggunakan pembelian.waktu untuk tampilan, BUKAN rekaman_stoks.waktu\n";
echo "Stock Opname tidak punya id_pembelian, jadi menggunakan rekaman_stoks.waktu\n\n";

echo "SOLUSI: Perlu update waktu record pembelian di rekaman_stoks agar konsisten\n";
echo "Atau: Perlu sorting di UI menggunakan rekaman_stoks.waktu untuk stock movement order\n";
