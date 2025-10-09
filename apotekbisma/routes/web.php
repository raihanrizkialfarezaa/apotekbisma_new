<?php

use App\Http\Controllers\{
    DashboardController,
    KartuStokController,
    KategoriController,
    LaporanController,
    ProdukController,
    MemberController,
    PengeluaranController,
    PembelianController,
    PembelianDetailController,
    PenjualanController,
    PenjualanDetailController,
    SettingController,
    SupplierController,
    UserController,
};
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return redirect()->route('login');
});

// Secure proxy route for the standalone fixer script (useful on shared hosting)
// Usage: https://your-domain.tld/fix-kartu-stok?key=YOUR_FIX_SECRET
Route::get('/fix-kartu-stok', function (Request $request) {
    $key = $request->query('key');
    $secret = env('FIX_SECRET');
    if (empty($secret) || !$key || !hash_equals((string) $secret, (string) $key)) {
        abort(403, 'Forbidden');
    }

    $path = public_path('fix_kartu_stok_perfect.php');
    if (!file_exists($path)) {
        abort(404, 'Fix script not found');
    }

    // Execute the script and return its output
    return require $path;
});

// Backwards-compatible proxy so URL like /fix_kartu_stok_perfect.php?product_id=... works
Route::get('/fix_kartu_stok_perfect.php', [\App\Http\Controllers\FixerController::class, 'perfect']);

// Controller-based secure route (preferred): /fix-kartu-stok-controller?key=FIX_SECRET
Route::get('/fix-kartu-stok-controller', [\App\Http\Controllers\FixerController::class, 'perfect']);

// Admin-only route: allow logged-in admin (level:1) to run the fixer without FIX_SECRET
Route::get('/fix-kartu-stok-admin', [\App\Http\Controllers\FixerController::class, 'perfect'])
    ->middleware(['auth', 'level:1']);

// Lightweight probe to verify Laravel is receiving requests and whether the fixer file exists
Route::get('/fix-probe', function () {
    $path = public_path('fix_kartu_stok_perfect.php');
    return response()->json([
        'ok' => true,
        'fix_script_exists' => file_exists($path),
        'app_env' => env('APP_ENV', 'production'),
    ]);
});




Route::group(['middleware' => 'auth'], function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::group(['middleware' => 'level:1'], function () {
        Route::post('/admin/sync-stock', [DashboardController::class, 'syncStock'])->name('admin.sync.stock');
        
        Route::get('/kategori/data', [KategoriController::class, 'data'])->name('kategori.data');
        Route::resource('/kategori', KategoriController::class);

        Route::get('/produk/data', [ProdukController::class, 'data'])->name('produk.data');
        Route::post('/produk/delete-selected', [ProdukController::class, 'deleteSelected'])->name('produk.delete_selected');
        Route::post('/produk/cetak-barcode', [ProdukController::class, 'cetakBarcode'])->name('produk.cetak_barcode');
        Route::post('/produk/beli/{id}', [ProdukController::class, 'beliProduk'])->name('produk.beli');
        Route::resource('/produk', ProdukController::class);
        Route::get('/halaman-import', [ProdukController::class, 'importPage'])->name('importview');
        Route::post('/import-excel', [ProdukController::class, 'importExcel'])->name('importobat');
        Route::put('/updateHargaJual/{id}', [ProdukController::class, 'updateHargaJual'])->name('updateHargaJual');
        Route::put('/updateHargaBeli/{id}', [ProdukController::class, 'updateHargaBeli'])->name('updateHargaBeli');
        Route::put('/updateExpiredDate/{id}', [ProdukController::class, 'updateExpiredDate'])->name('updateExpiredDate');
        Route::put('/updateBatch/{id}', [ProdukController::class, 'updateBatch'])->name('updateBatch');
        Route::put('/produk/update-stok-manual/{id}', [ProdukController::class, 'updateStokManual'])->name('produk.update_stok_manual');

        Route::get('/member/data', [MemberController::class, 'data'])->name('member.data');
        Route::post('/member/cetak-member', [MemberController::class, 'cetakMember'])->name('member.cetak_member');
        Route::resource('/member', MemberController::class);

        Route::get('/supplier/data', [SupplierController::class, 'data'])->name('supplier.data');
        Route::resource('/supplier', SupplierController::class);

        Route::get('/pengeluaran/data', [PengeluaranController::class, 'data'])->name('pengeluaran.data');
        Route::resource('/pengeluaran', PengeluaranController::class);

        Route::get('/pembelian/data', [PembelianController::class, 'data'])->name('pembelian.data');
        Route::get('/pembelian/{id}/create', [PembelianController::class, 'create'])->name('pembelian.create');
        Route::get('/pembelian/{id}/lanjutkan', [PembelianController::class, 'lanjutkanTransaksi'])->name('pembelian.lanjutkan');
        Route::get('/pembelian/create', [PembelianController::class, 'create'])->name('pembelian.create.new');
        Route::get('/pembelian/nota-kecil', [PembelianController::class, 'notaKecil'])->name('pembelian.nota_kecil');
        Route::get('/pembelian/nota-besar', [PembelianController::class, 'notaBesar'])->name('pembelian.nota_besar');
        Route::get('/pembelian/{id}/print', [PembelianController::class, 'printReceipt'])->name('pembelian.print');
        Route::post('/pembelian/cleanup', [PembelianController::class, 'cleanupIncompleteTransactions'])->name('pembelian.cleanup');
        Route::delete('/pembelian/{id}', [PembelianController::class, 'destroy'])->name('pembelian.destroy');
        Route::delete('/pembelian/{id}/empty', [PembelianController::class, 'destroyEmpty'])->name('pembelian.destroyEmpty');
        Route::resource('/pembelian', PembelianController::class)
            ->except('create', 'destroy');

        Route::get('/pembelian_detail/{id}/data', [PembelianDetailController::class, 'data'])->name('pembelian_detail.data');
        Route::get('/pembelian_detail/loadform/{diskon}/{total}', [PembelianDetailController::class, 'loadForm'])->name('pembelian_detail.load_form');
        Route::get('/pembelian_detail/produk-data', [PembelianDetailController::class, 'getProdukData'])->name('pembelian_detail.produk_data');
        Route::get('/pembelian_detail/editBayar/{id}', [PembelianDetailController::class, 'editBayar'])->name('pembelian_detail.editBayar');
        Route::put('/pembelian_detail/updateEdit/{id}', [PembelianDetailController::class, 'updateEdit'])->name('pembelian_detail.updateEdit');
        Route::post('/pembelian_detail/batch-update', [PembelianDetailController::class, 'batchUpdate'])->name('pembelian_detail.batch_update')->middleware('optimize.bulk');
        Route::resource('/pembelian_detail', PembelianDetailController::class)
            ->except('create', 'show', 'edit');

        Route::get('/penjualan/data', [PenjualanController::class, 'data'])->name('penjualan.data');
        Route::get('/penjualan', [PenjualanController::class, 'index'])->name('penjualan.index');
        Route::get('/penjualan/{id}', [PenjualanController::class, 'show'])->name('penjualan.show');
        Route::get('/penjualan/{id}/lanjutkan', [PenjualanController::class, 'lanjutkanTransaksi'])->name('penjualan.lanjutkan');
        Route::get('/penjualan/{id}/edit', [PenjualanController::class, 'editTransaksi'])->name('penjualan.edit');
        Route::get('/penjualan/{id}/print', [PenjualanController::class, 'printReceipt'])->name('penjualan.print');
        Route::delete('/penjualan/{id}', [PenjualanController::class, 'destroy'])->name('penjualan.destroy');
        Route::delete('/penjualan/empty/cleanup', [PenjualanController::class, 'destroyEmpty'])->name('penjualan.destroyEmpty');
    });

    Route::group(['middleware' => 'level:1,2'], function () {
        Route::get('/transaksi', [PenjualanDetailController::class, 'index'])->name('transaksi.index');
        Route::get('/transaksi/baru', [PenjualanController::class, 'create'])->name('transaksi.baru');
        Route::get('/transaksi/aktif', [PenjualanController::class, 'createOrContinue'])->name('transaksi.aktif');
        Route::post('/transaksi/simpan', [PenjualanController::class, 'store'])->name('transaksi.simpan');
        Route::get('/transaksi/selesai', [PenjualanController::class, 'selesai'])->name('transaksi.selesai');
        Route::get('/transaksi/nota-kecil', [PenjualanController::class, 'notaKecil'])->name('transaksi.nota_kecil');
        Route::get('/transaksi/nota-besar', [PenjualanController::class, 'notaBesar'])->name('transaksi.nota_besar');
        Route::get('/transaksi/updateTransaksi', [PenjualanController::class, 'notaBesar'])->name('transaksi.updateForm');
        Route::put('/transaksi/updatesTransaksi/{id}', [PenjualanController::class, 'update'])->name('transaksi.updates');
        Route::put('/transaksi/updateEdit/{id}', [PenjualanDetailController::class, 'updateEdit'])->name('transaksi.updateEdit');
        Route::put('/transaksi/{id}', [PenjualanDetailController::class, 'update'])->name('transaksi.update');

        Route::get('/transaksi/{id}/data', [PenjualanDetailController::class, 'data'])->name('transaksi.data');
        Route::get('/transaksi_detail/{id}/data', [PenjualanDetailController::class, 'data'])->name('transaksi_detail.data');
        Route::get('/transaksi/loadform/{diskon}/{total}/{diterima}', [PenjualanDetailController::class, 'loadForm'])->name('transaksi.load_form');
        Route::get('/transaksi/produk-data', [PenjualanDetailController::class, 'getProdukData'])->name('transaksi.produk_data');
        Route::resource('/transaksi', PenjualanDetailController::class)
            ->except('create', 'show', 'edit', 'update');
    });

    Route::group(['middleware' => 'level:1'], function () {
        Route::get('/laporan', [LaporanController::class, 'index'])->name('laporan.index');
        Route::get('/laporan/data/{awal}/{akhir}', [LaporanController::class, 'data'])->name('laporan.data');
        Route::get('/laporan/pdf/{awal}/{akhir}', [LaporanController::class, 'exportPDF'])->name('laporan.export_pdf');
        Route::get('/kartustok', [KartuStokController::class, 'index'])->name('kartu_stok.index');
        Route::get('/kartustok/data/{id}', [KartuStokController::class, 'data'])->name('kartu_stok.data');
        Route::get('/kartustok/detail/{id}', [KartuStokController::class, 'detail'])->name('kartu_stok.detail');
        Route::get('/kartustok/pdf/{id}', [KartuStokController::class, 'exportPDF'])->name('kartu_stok.export_pdf');
        Route::get('/kartustok/fix-records', [KartuStokController::class, 'fixRecords'])->name('kartu_stok.fix_records');
        Route::get('/kartustok/fix-records/{id}', [KartuStokController::class, 'fixRecordsForProduct'])->name('kartu_stok.fix_records_product');

        Route::get('/user/data', [UserController::class, 'data'])->name('user.data');
        Route::resource('/user', UserController::class);

        Route::get('/setting', [SettingController::class, 'index'])->name('setting.index');
        Route::get('/setting/first', [SettingController::class, 'show'])->name('setting.show');
        Route::post('/setting', [SettingController::class, 'update'])->name('setting.update');
    });
 
    Route::group(['middleware' => 'level:1,2'], function () {
        Route::get('/profil', [UserController::class, 'profil'])->name('user.profil');
        Route::post('/profil', [UserController::class, 'updateProfil'])->name('user.update_profil');
    });
});
