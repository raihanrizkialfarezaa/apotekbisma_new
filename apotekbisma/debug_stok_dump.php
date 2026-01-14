<?php
// debug_stok_dump.php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

use Illuminate\Support\Facades\DB;
use App\Models\Produk;

$id = 48; // Product ID from the user report


$output = "";
$output .= "=== DIAGNOSA STOK PRODUK ID $id ===\n";

$produk = Produk::find($id);
if (!$produk) {
    die("Produk tidak ditemukan.\n");
}
$output .= "Produk: " . $produk->nama_produk . "\n";
$output .= "Stok saat ini (di tabel produk): " . $produk->stok . "\n\n";

$output .= "--- REKAMAN STOK (Urut Waktu) ---\n";
$rekaman = DB::table('rekaman_stoks')
    ->where('id_produk', $id)
    ->orderBy('waktu', 'asc')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get();

$lastSisa = 0;
foreach ($rekaman as $r) {
    $diff = $r->stok_awal - $lastSisa;
    $status = ($diff == 0) ? "OK" : "GAP ($diff)";
    
    // Check for negative logic in row
    $calcSisa = $r->stok_awal + $r->stok_masuk - $r->stok_keluar;
    $mathStatus = ($calcSisa == $r->stok_sisa) ? "MATH OK" : "MATH FAIL ($calcSisa vs {$r->stok_sisa})";

    $output .= sprintf(
        "ID: %d | Time: %s | Awal: %d | Masuk: %d | Keluar: %d | Sisa: %d | Ket: %s | Status: %s | %s\n",
        $r->id_rekaman_stok,
        $r->waktu,
        $r->stok_awal,
        $r->stok_masuk,
        $r->stok_keluar,
        $r->stok_sisa,
        substr($r->keterangan, 0, 30),
        $status,
        $mathStatus
    );
    $lastSisa = $r->stok_sisa;
}

$output .= "\n--- RAW TRANSACTIONS (PENJUALAN) ---\n";
$penjualan = DB::table('penjualan_detail')
    ->join('penjualan', 'penjualan.id_penjualan', '=', 'penjualan_detail.id_penjualan')
    ->where('penjualan_detail.id_produk', $id)
    ->select('penjualan.waktu', 'penjualan.id_penjualan', 'penjualan_detail.jumlah')
    ->orderBy('penjualan.waktu')
    ->get();

foreach ($penjualan as $p) {
    $output .= "Penjualan ID {$p->id_penjualan} | Time: {$p->waktu} | Qty: {$p->jumlah}\n";
}

$output .= "\n--- RAW TRANSACTIONS (PEMBELIAN) ---\n";
$pembelian = DB::table('pembelian_detail')
    ->join('pembelian', 'pembelian.id_pembelian', '=', 'pembelian_detail.id_pembelian')
    ->where('pembelian_detail.id_produk', $id)
    ->select('pembelian.waktu', 'pembelian.id_pembelian', 'pembelian_detail.jumlah')
    ->orderBy('pembelian.waktu')
    ->get();

foreach ($pembelian as $p) {
    $output .= "Pembelian ID {$p->id_pembelian} | Time: {$p->waktu} | Qty: {$p->jumlah}\n";
}

file_put_contents('debug_clean_output.txt', $output);
echo "Output written to debug_clean_output.txt";

