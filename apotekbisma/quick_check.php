<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\RekamanStok;
use App\Models\Produk;
use Illuminate\Support\Facades\DB;

$p = Produk::find(48);
echo "Produk: " . $p->nama_produk . "\n";
echo "Stok di tabel produk: " . $p->stok . "\n\n";

$recs = RekamanStok::where('id_produk', 48)
    ->orderBy('waktu', 'desc')
    ->orderBy('id_rekaman_stok', 'desc')
    ->take(10)
    ->get();

echo "10 REKAMAN TERAKHIR (DESC):\n";
echo str_repeat("-", 100) . "\n";
foreach($recs as $r) {
    echo $r->id_rekaman_stok . " | " . $r->waktu . " | awal:" . $r->stok_awal . " | +:" . $r->stok_masuk . " | -:" . $r->stok_keluar . " | sisa:" . $r->stok_sisa . " | " . substr($r->keterangan, 0, 30) . "\n";
}

echo "\nTotal rekaman stok: " . RekamanStok::where('id_produk', 48)->count() . "\n";

$dupPenjualan = DB::table('rekaman_stoks')
    ->select('id_penjualan', DB::raw('COUNT(*) as cnt'))
    ->where('id_produk', 48)
    ->whereNotNull('id_penjualan')
    ->groupBy('id_penjualan')
    ->having('cnt', '>', 1)
    ->get();

echo "\nDuplikat penjualan: " . count($dupPenjualan) . "\n";
foreach($dupPenjualan as $d) {
    echo "  - id_penjualan {$d->id_penjualan}: {$d->cnt}x\n";
}

$first = RekamanStok::where('id_produk', 48)->orderBy('waktu', 'asc')->orderBy('id_rekaman_stok', 'asc')->first();
$last = RekamanStok::where('id_produk', 48)->orderBy('waktu', 'desc')->orderBy('id_rekaman_stok', 'desc')->first();

echo "\nRekaman pertama: ID " . $first->id_rekaman_stok . " pada " . $first->waktu . " stok_sisa: " . $first->stok_sisa . "\n";
echo "Rekaman terakhir: ID " . $last->id_rekaman_stok . " pada " . $last->waktu . " stok_sisa: " . $last->stok_sisa . "\n";
