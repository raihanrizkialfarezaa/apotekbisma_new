<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Produk;
use App\Models\RekamanStok;

set_time_limit(300);

$f = fopen(__DIR__ . '/fix_all_issues_result.txt', 'w');

fwrite($f, "=== FIX ALL ISSUES " . date('Y-m-d H:i:s') . " ===\n\n");

DB::beginTransaction();

try {
    fwrite($f, "1. FIXING: Sinkronisasi Stok Produk dengan Kartu Stok\n");
    $mismatch = DB::select("
        SELECT p.id_produk, p.nama_produk, p.stok as stok_produk, r.stok_sisa as stok_kartu
        FROM produk p
        INNER JOIN (
            SELECT id_produk, stok_sisa FROM rekaman_stoks r1
            WHERE id_rekaman_stok = (SELECT MAX(id_rekaman_stok) FROM rekaman_stoks r2 WHERE r2.id_produk = r1.id_produk)
        ) r ON p.id_produk = r.id_produk
        WHERE p.stok != r.stok_sisa
    ");
    
    foreach ($mismatch as $m) {
        $newStok = max(0, intval($m->stok_kartu));
        DB::table('produk')->where('id_produk', $m->id_produk)->update(['stok' => $newStok]);
        fwrite($f, "   Fixed: {$m->nama_produk}: {$m->stok_produk} -> {$newStok}\n");
    }
    fwrite($f, "   Total fixed: " . count($mismatch) . "\n\n");

    fwrite($f, "2. FIXING: Hapus Duplikasi Rekaman Penjualan\n");
    $dups = DB::select("
        SELECT id_penjualan, id_produk, COUNT(*) as cnt 
        FROM rekaman_stoks WHERE id_penjualan IS NOT NULL
        GROUP BY id_penjualan, id_produk HAVING cnt > 1
    ");
    
    $deletedDups = 0;
    foreach ($dups as $d) {
        $allRecs = DB::table('rekaman_stoks')
            ->where('id_penjualan', $d->id_penjualan)
            ->where('id_produk', $d->id_produk)
            ->orderBy('id_rekaman_stok', 'desc')
            ->get();
        
        $keep = $allRecs->first();
        foreach ($allRecs->skip(1) as $del) {
            DB::table('rekaman_stoks')->where('id_rekaman_stok', $del->id_rekaman_stok)->delete();
            $deletedDups++;
        }
    }
    fwrite($f, "   Deleted: {$deletedDups} duplicate records\n\n");

    fwrite($f, "3. FIXING: Hapus Orphan Records (rekaman tanpa produk)\n");
    $orphanDeleted = DB::table('rekaman_stoks')
        ->leftJoin('produk', 'rekaman_stoks.id_produk', '=', 'produk.id_produk')
        ->whereNull('produk.id_produk')
        ->delete();
    fwrite($f, "   Deleted: {$orphanDeleted} orphan records\n\n");

    fwrite($f, "4. FIXING: Recalculate formula yang salah\n");
    $broken = DB::select("
        SELECT id_rekaman_stok, stok_awal, stok_masuk, stok_keluar,
               (stok_awal + stok_masuk - stok_keluar) as calculated
        FROM rekaman_stoks
        WHERE stok_sisa != (stok_awal + stok_masuk - stok_keluar)
    ");
    
    foreach ($broken as $b) {
        DB::table('rekaman_stoks')
            ->where('id_rekaman_stok', $b->id_rekaman_stok)
            ->update(['stok_sisa' => max(0, $b->calculated)]);
    }
    fwrite($f, "   Fixed: " . count($broken) . " records\n\n");

    DB::commit();
    fwrite($f, "=== SEMUA PERBAIKAN BERHASIL ===\n");

} catch (\Exception $e) {
    DB::rollBack();
    fwrite($f, "ERROR: " . $e->getMessage() . "\n");
}

fwrite($f, "\nSelesai: " . date('Y-m-d H:i:s') . "\n");
fclose($f);

echo "Done. Check fix_all_issues_result.txt\n";
