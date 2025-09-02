Route::get('/test-edit-sync-initial', function() {
    echo "=== Test Initial State ===\n";
    
    $produk = \App\Models\Produk::find(2);
    echo "Stok produk saat ini: {$produk->stok}\n";
    
    $penjualan = \App\Models\Penjualan::where('id_penjualan', '>', 0)->orderBy('id_penjualan', 'desc')->first();
    if ($penjualan) {
        echo "Penjualan terakhir: ID {$penjualan->id_penjualan}, Waktu: {$penjualan->waktu}\n";
        
        $detail = \App\Models\PenjualanDetail::where('id_penjualan', $penjualan->id_penjualan)
                                            ->where('id_produk', 2)->first();
        if ($detail) {
            echo "Detail: Jumlah {$detail->jumlah}, Subtotal {$detail->subtotal}\n";
        }
        
        $rekaman = \App\Models\RekamanStok::where('id_penjualan', $penjualan->id_penjualan)
                                         ->where('id_produk', 2)->get();
        echo "Rekaman stok entries: " . $rekaman->count() . "\n";
        foreach ($rekaman as $r) {
            echo "- Waktu: {$r->waktu}, Keluar: {$r->stok_keluar}, Awal: {$r->stok_awal}, Sisa: {$r->stok_sisa}\n";
        }
    }
    
    return response('Test initial selesai', 200)->header('Content-Type', 'text/plain');
});

Route::get('/test-edit-sync-create', function() {
    echo "=== Creating Test Transaction ===\n";
    
    $produk = \App\Models\Produk::find(2);
    $stok_awal = $produk->stok;
    echo "Stok awal: {$stok_awal}\n";
    
    $penjualan = new \App\Models\Penjualan();
    $penjualan->id_member = null;
    $penjualan->total_item = 2;
    $penjualan->total_harga = $produk->harga_jual * 2;
    $penjualan->diskon = 0;
    $penjualan->bayar = $produk->harga_jual * 2;
    $penjualan->diterima = $produk->harga_jual * 2;
    $penjualan->waktu = '2025-09-02';
    $penjualan->id_user = 1;
    $penjualan->save();
    
    echo "Penjualan created: ID {$penjualan->id_penjualan}\n";
    
    $detail = new \App\Models\PenjualanDetail();
    $detail->id_penjualan = $penjualan->id_penjualan;
    $detail->id_produk = 2;
    $detail->harga_jual = $produk->harga_jual;
    $detail->jumlah = 2;
    $detail->diskon = 0;
    $detail->subtotal = $produk->harga_jual * 2;
    $detail->save();
    
    $produk->stok -= 2;
    $produk->save();
    
    \App\Models\RekamanStok::create([
        'id_produk' => 2,
        'id_penjualan' => $penjualan->id_penjualan,
        'waktu' => $penjualan->waktu,
        'stok_keluar' => 2,
        'stok_awal' => $stok_awal,
        'stok_sisa' => $produk->stok,
        'keterangan' => 'Penjualan: Transaksi'
    ]);
    
    echo "Transaction created with 2 items\n";
    echo "Stok sekarang: {$produk->fresh()->stok}\n";
    
    return response("Penjualan ID: {$penjualan->id_penjualan}", 200)->header('Content-Type', 'text/plain');
});

Route::get('/test-edit-sync-update/{id}', function($id) {
    echo "=== Testing Date Update for Penjualan ID {$id} ===\n";
    
    $penjualan = \App\Models\Penjualan::find($id);
    if (!$penjualan) {
        return response('Penjualan tidak ditemukan', 404);
    }
    
    echo "Waktu sebelum: {$penjualan->waktu}\n";
    
    $penjualan->waktu = '2025-09-01';
    $penjualan->save();
    
    echo "Waktu sesudah: {$penjualan->waktu}\n";
    
    $rekaman = \App\Models\RekamanStok::where('id_penjualan', $id)->get();
    foreach ($rekaman as $r) {
        echo "RekamanStok waktu: {$r->waktu}\n";
    }
    
    return response('Date update test selesai', 200)->header('Content-Type', 'text/plain');
});

Route::get('/test-edit-sync-quantity/{id}', function($id) {
    echo "=== Testing Quantity Update for Penjualan ID {$id} ===\n";
    
    $detail = \App\Models\PenjualanDetail::where('id_penjualan', $id)->where('id_produk', 2)->first();
    if (!$detail) {
        return response('Detail tidak ditemukan', 404);
    }
    
    $produk = \App\Models\Produk::find(2);
    echo "Quantity sebelum: {$detail->jumlah}\n";
    echo "Stok sebelum: {$produk->stok}\n";
    
    $old_jumlah = $detail->jumlah;
    $new_jumlah = 4;
    $selisih = $new_jumlah - $old_jumlah;
    
    $detail->jumlah = $new_jumlah;
    $detail->subtotal = $detail->harga_jual * $new_jumlah;
    $detail->save();
    
    $produk->stok -= $selisih;
    $produk->save();
    
    echo "Quantity sesudah: {$detail->jumlah}\n";
    echo "Stok sesudah: {$produk->fresh()->stok}\n";
    
    $rekaman_count = \App\Models\RekamanStok::where('id_penjualan', $id)->where('id_produk', 2)->count();
    echo "Jumlah rekaman stok: {$rekaman_count}\n";
    
    return response('Quantity update test selesai', 200)->header('Content-Type', 'text/plain');
});
