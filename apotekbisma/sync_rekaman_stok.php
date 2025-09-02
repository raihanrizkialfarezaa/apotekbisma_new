<?php

require_once 'bootstrap/app.php';

use App\Models\RekamanStok;
use App\Models\Penjualan;
use App\Models\Pembelian;
use Illuminate\Support\Facades\DB;

echo "=== SCRIPT SINKRONISASI REKAMAN STOK ===\n\n";

DB::beginTransaction();

try {
    // 1. Sinkronisasi RekamanStok yang terkait dengan Penjualan
    echo "1. Sinkronisasi RekamanStok dengan Penjualan...\n";
    $penjualan_records = RekamanStok::whereNotNull('id_penjualan')->get();
    $penjualan_updated = 0;
    
    foreach ($penjualan_records as $rekaman) {
        $penjualan = Penjualan::find($rekaman->id_penjualan);
        if ($penjualan && $rekaman->waktu != $penjualan->waktu) {
            $old_waktu = $rekaman->waktu;
            $rekaman->waktu = $penjualan->waktu;
            $rekaman->save();
            echo "  Updated RekamanStok ID {$rekaman->id_rekaman_stok}: {$old_waktu} -> {$penjualan->waktu}\n";
            $penjualan_updated++;
        }
    }
    echo "  Total Penjualan records updated: {$penjualan_updated}\n\n";

    // 2. Sinkronisasi RekamanStok yang terkait dengan Pembelian
    echo "2. Sinkronisasi RekamanStok dengan Pembelian...\n";
    $pembelian_records = RekamanStok::whereNotNull('id_pembelian')->get();
    $pembelian_updated = 0;
    
    foreach ($pembelian_records as $rekaman) {
        $pembelian = Pembelian::find($rekaman->id_pembelian);
        if ($pembelian && $rekaman->waktu != $pembelian->waktu) {
            $old_waktu = $rekaman->waktu;
            $rekaman->waktu = $pembelian->waktu;
            $rekaman->save();
            echo "  Updated RekamanStok ID {$rekaman->id_rekaman_stok}: {$old_waktu} -> {$pembelian->waktu}\n";
            $pembelian_updated++;
        }
    }
    echo "  Total Pembelian records updated: {$pembelian_updated}\n\n";

    // 3. Hapus duplikasi yang mungkin terjadi
    echo "3. Menghapus duplikasi RekamanStok...\n";
    $duplicates = DB::select("
        SELECT id_produk, id_penjualan, id_pembelian, COUNT(*) as count
        FROM rekaman_stok 
        WHERE (id_penjualan IS NOT NULL OR id_pembelian IS NOT NULL)
        GROUP BY id_produk, id_penjualan, id_pembelian
        HAVING COUNT(*) > 1
    ");
    
    $deleted_count = 0;
    foreach ($duplicates as $dup) {
        if ($dup->id_penjualan) {
            $records = RekamanStok::where('id_produk', $dup->id_produk)
                                 ->where('id_penjualan', $dup->id_penjualan)
                                 ->orderBy('id_rekaman_stok', 'desc')
                                 ->get();
        } else {
            $records = RekamanStok::where('id_produk', $dup->id_produk)
                                 ->where('id_pembelian', $dup->id_pembelian)
                                 ->orderBy('id_rekaman_stok', 'desc')
                                 ->get();
        }
        
        // Keep the latest, delete others
        for ($i = 1; $i < $records->count(); $i++) {
            echo "  Deleting duplicate RekamanStok ID {$records[$i]->id_rekaman_stok}\n";
            $records[$i]->delete();
            $deleted_count++;
        }
    }
    echo "  Total duplicate records deleted: {$deleted_count}\n\n";

    DB::commit();
    echo "=== SINKRONISASI BERHASIL ===\n";
    
    // 4. Verifikasi hasil
    echo "\n4. Verifikasi hasil untuk produk ID 2...\n";
    $rekaman_produk_2 = RekamanStok::where('id_produk', 2)
                                  ->orderBy('id_rekaman_stok', 'desc')
                                  ->take(5)
                                  ->get();
    
    foreach ($rekaman_produk_2 as $r) {
        echo "ID: {$r->id_rekaman_stok} | Waktu: {$r->waktu} | Jenis: {$r->jenis_transaksi}\n";
        if ($r->id_penjualan) {
            $penjualan = Penjualan::find($r->id_penjualan);
            if ($penjualan) {
                $status = ($r->waktu == $penjualan->waktu) ? "SYNC ✓" : "MISMATCH ✗";
                echo "  Penjualan waktu: {$penjualan->waktu} | Status: {$status}\n";
            }
        }
        if ($r->id_pembelian) {
            $pembelian = Pembelian::find($r->id_pembelian);
            if ($pembelian) {
                $status = ($r->waktu == $pembelian->waktu) ? "SYNC ✓" : "MISMATCH ✗";
                echo "  Pembelian waktu: {$pembelian->waktu} | Status: {$status}\n";
            }
        }
        echo "\n";
    }

} catch (Exception $e) {
    DB::rollback();
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "ROLLBACK performed.\n";
}
