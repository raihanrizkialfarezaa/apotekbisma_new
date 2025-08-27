<?php

require_once 'vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;
use App\Models\Produk;
use App\Models\RekamanStok;

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);

echo "=== ANALISIS DAN PERBAIKAN TRANSAKSI TIDAK SELESAI ===\n\n";

// Cek transaksi penjualan yang tidak selesai
$incomplete_sales = Penjualan::where('diterima', 0)
    ->orWhere('total_harga', 0)
    ->get();

echo "Total transaksi penjualan tidak selesai: " . $incomplete_sales->count() . "\n\n";

$empty_transactions = 0;
$old_incomplete = 0;
$recent_incomplete = 0;

foreach ($incomplete_sales as $sale) {
    $detail_count = PenjualanDetail::where('id_penjualan', $sale->id_penjualan)->count();
    $days_old = now()->diffInDays($sale->created_at);
    
    if ($detail_count == 0) {
        $empty_transactions++;
    } elseif ($days_old > 1) {
        $old_incomplete++;
    } else {
        $recent_incomplete++;
    }
}

echo "Breakdown:\n";
echo "- Transaksi kosong (tanpa detail): {$empty_transactions}\n";
echo "- Transaksi lama tidak selesai (>1 hari): {$old_incomplete}\n";
echo "- Transaksi terbaru tidak selesai (≤1 hari): {$recent_incomplete}\n\n";

// Bersihkan transaksi kosong yang lama
echo "MEMBERSIHKAN TRANSAKSI KOSONG LAMA...\n";
$cleaned = 0;

$empty_old_sales = Penjualan::where(function($query) {
        $query->where('diterima', 0)->orWhere('total_harga', 0);
    })
    ->whereDoesntHave('detail')
    ->where('created_at', '<', now()->subDays(1))
    ->get();

foreach ($empty_old_sales as $sale) {
    echo "- Menghapus transaksi kosong ID {$sale->id_penjualan} (dibuat: " . $sale->created_at->format('Y-m-d H:i:s') . ")\n";
    $sale->delete();
    $cleaned++;
}

echo "✅ Berhasil membersihkan {$cleaned} transaksi kosong lama\n\n";

// Perbaiki transaksi yang memiliki detail tapi tidak selesai
echo "MEMPERBAIKI TRANSAKSI DENGAN DETAIL TAPI TIDAK SELESAI...\n";
$fixed = 0;

$incomplete_with_details = Penjualan::where(function($query) {
        $query->where('diterima', 0)->orWhere('total_harga', 0);
    })
    ->whereHas('detail')
    ->where('created_at', '<', now()->subDays(1))
    ->get();

foreach ($incomplete_with_details as $sale) {
    $details = PenjualanDetail::where('id_penjualan', $sale->id_penjualan)->get();
    
    if ($details->count() > 0) {
        $total_item = $details->sum('jumlah');
        $total_harga = $details->sum('subtotal');
        
        // Update transaksi untuk melengkapi data yang hilang
        $sale->total_item = $total_item;
        $sale->total_harga = $total_harga;
        $sale->bayar = $total_harga;
        $sale->diterima = $total_harga;
        $sale->waktu = $sale->waktu ?? $sale->created_at->format('Y-m-d');
        $sale->save();
        
        echo "- Memperbaiki transaksi ID {$sale->id_penjualan}: Item={$total_item}, Total=Rp{$total_harga}\n";
        $fixed++;
    }
}

echo "✅ Berhasil memperbaiki {$fixed} transaksi dengan detail\n\n";

// Verifikasi ulang
echo "VERIFIKASI SETELAH PERBAIKAN:\n";
$remaining_incomplete = Penjualan::where('diterima', 0)
    ->orWhere('total_harga', 0)
    ->count();

echo "Transaksi tidak selesai yang tersisa: {$remaining_incomplete}\n";

if ($remaining_incomplete == 0) {
    echo "✅ SEMUA TRANSAKSI TIDAK SELESAI BERHASIL DIPERBAIKI!\n";
} else {
    // Tampilkan detail yang tersisa
    $remaining_sales = Penjualan::where('diterima', 0)
        ->orWhere('total_harga', 0)
        ->limit(5)
        ->get();
    
    echo "\nTransaksi yang masih tersisa (5 teratas):\n";
    foreach ($remaining_sales as $sale) {
        $detail_count = PenjualanDetail::where('id_penjualan', $sale->id_penjualan)->count();
        echo "- ID {$sale->id_penjualan}: Detail={$detail_count}, Created=" . $sale->created_at->format('Y-m-d H:i:s') . "\n";
    }
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "PERBAIKAN SELESAI\n";
echo str_repeat("=", 60) . "\n";

?>
