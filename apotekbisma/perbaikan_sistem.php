<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\Penjualan;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

echo "=== PERBAIKAN SISTEM SINKRONISASI ===\n\n";

DB::beginTransaction();

try {
    // 1. Perbaiki transaksi dengan waktu NULL
    echo "1. PERBAIKI TRANSAKSI DENGAN WAKTU NULL\n";
    echo str_repeat("=", 50) . "\n";
    
    $penjualan_null = Penjualan::whereNull('waktu')->get();
    echo "Ditemukan {$penjualan_null->count()} penjualan dengan waktu NULL\n";
    
    foreach ($penjualan_null as $penjualan) {
        $waktu_default = $penjualan->created_at ?? Carbon::today();
        $penjualan->waktu = $waktu_default;
        $penjualan->save();
        
        // Update rekaman stok terkait
        RekamanStok::where('id_penjualan', $penjualan->id_penjualan)
                   ->update(['waktu' => $waktu_default]);
        
        echo "- Penjualan ID {$penjualan->id_penjualan}: waktu diset ke {$waktu_default}\n";
    }
    
    // 2. Perbaiki produk tanpa rekaman stok
    echo "\n2. PERBAIKI PRODUK TANPA REKAMAN STOK\n";
    echo str_repeat("=", 50) . "\n";
    
    $produk_tanpa_rekaman = DB::select("
        SELECT p.id_produk, p.nama_produk, p.stok
        FROM produk p
        LEFT JOIN rekaman_stoks rs ON p.id_produk = rs.id_produk
        WHERE rs.id_produk IS NULL AND p.stok > 0
        LIMIT 20
    ");
    
    echo "Ditemukan " . count($produk_tanpa_rekaman) . " produk tanpa rekaman stok\n";
    
    foreach ($produk_tanpa_rekaman as $produk_data) {
        RekamanStok::create([
            'id_produk' => $produk_data->id_produk,
            'waktu' => Carbon::now(),
            'stok_masuk' => $produk_data->stok,
            'stok_awal' => 0,
            'stok_sisa' => $produk_data->stok,
            'keterangan' => 'Rekonstruksi rekaman stok: Stok awal produk'
        ]);
        
        echo "- {$produk_data->nama_produk}: dibuat rekaman stok awal {$produk_data->stok}\n";
    }
    
    // 3. Sinkronisasi produk dengan rekaman stok terakhir yang tidak sesuai
    echo "\n3. SINKRONISASI STOK PRODUK DENGAN REKAMAN TERAKHIR\n";
    echo str_repeat("=", 50) . "\n";
    
    $inconsistent = DB::select("
        WITH latest_rekaman AS (
            SELECT 
                id_produk,
                stok_sisa,
                ROW_NUMBER() OVER (PARTITION BY id_produk ORDER BY waktu DESC, id_rekaman_stok DESC) as rn
            FROM rekaman_stoks
        )
        SELECT 
            p.id_produk,
            p.nama_produk,
            p.stok as stok_produk,
            lr.stok_sisa as stok_rekaman,
            (p.stok - lr.stok_sisa) as selisih
        FROM produk p
        LEFT JOIN latest_rekaman lr ON p.id_produk = lr.id_produk AND lr.rn = 1
        WHERE p.stok != COALESCE(lr.stok_sisa, 0)
        LIMIT 15
    ");
    
    echo "Ditemukan " . count($inconsistent) . " produk dengan stok tidak konsisten\n";
    
    foreach ($inconsistent as $item) {
        if ($item->stok_rekaman === null) {
            // Produk tanpa rekaman, buat rekaman baru
            RekamanStok::create([
                'id_produk' => $item->id_produk,
                'waktu' => Carbon::now(),
                'stok_masuk' => $item->stok_produk,
                'stok_awal' => 0,
                'stok_sisa' => $item->stok_produk,
                'keterangan' => 'Rekonstruksi: Rekaman stok awal produk'
            ]);
            
            echo "- {$item->nama_produk}: dibuat rekaman stok awal {$item->stok_produk}\n";
        } else {
            // Produk dengan rekaman tidak sesuai, buat rekaman penyesuaian
            $selisih = $item->stok_produk - $item->stok_rekaman;
            
            RekamanStok::create([
                'id_produk' => $item->id_produk,
                'waktu' => Carbon::now(),
                'stok_awal' => $item->stok_rekaman,
                'stok_masuk' => $selisih > 0 ? $selisih : 0,
                'stok_keluar' => $selisih < 0 ? abs($selisih) : 0,
                'stok_sisa' => $item->stok_produk,
                'keterangan' => 'Penyesuaian otomatis: Sinkronisasi stok produk dengan rekaman'
            ]);
            
            echo "- {$item->nama_produk}: disesuaikan stok dari {$item->stok_rekaman} ke {$item->stok_produk}\n";
        }
    }
    
    DB::commit();
    echo "\n✓ SEMUA PERBAIKAN BERHASIL DITERAPKAN\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
}
