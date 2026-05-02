<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Pembelian;
use App\Models\PembelianDetail;
use App\Models\RekamanStok;
use App\Models\Produk;
use Carbon\Carbon;
use App\Services\PembelianStockSyncService;
use App\Services\TransactionDateMutationService;

$jsonData = '{
    "nomor_faktur": "JM1-2604-01939",
    "id_supplier": 1,
    "tanggal_waktu_faktur": "2026-04-18 00:00:00",
    "tanggal_waktu_obat_datang": "2026-04-18 00:00:00",
    "total": 1106957,
    "diskon": 686462,
    "bayar": 466750,
    "detail": [
        {
            "no": 1,
            "id_produk": 320,
            "kode_produk": "",
            "nama_produk": "GLIMEPIRID 2MG TAB 100\'S",
            "harga_beli": 130299,
            "harga_jual": 0,
            "expired_date": "0000-00-00",
            "batch": "",
            "jumlah": 300,
            "subtotal": 390897
        },
        {
            "no": 2,
            "id_produk": 0,
            "kode_produk": "",
            "nama_produk": "GLIMEPIRID 3MG TAB 100\'S",
            "harga_beli": 177680,
            "harga_jual": 0,
            "expired_date": "0000-00-00",
            "batch": "",
            "jumlah": 200,
            "subtotal": 355360
        },
        {
            "no": 3,
            "id_produk": 377,
            "kode_produk": "",
            "nama_produk": "INSTO EYE DROPS 7.5 ML",
            "harga_beli": 12280,
            "harga_jual": 0,
            "expired_date": "0000-00-00",
            "batch": "",
            "jumlah": 10,
            "subtotal": 122800
        },
        {
            "no": 4,
            "id_produk": 0,
            "kode_produk": "",
            "nama_produk": "ERLAMYCETIN TM 10ML",
            "harga_beli": 14600,
            "harga_jual": 0,
            "expired_date": "0000-00-00",
            "batch": "",
            "jumlah": 3,
            "subtotal": 43800
        },
        {
            "no": 5,
            "id_produk": 903,
            "kode_produk": "",
            "nama_produk": "ERLAMYCETIN TT 10ML",
            "harga_beli": 12400,
            "harga_jual": 0,
            "expired_date": "0000-00-00",
            "batch": "",
            "jumlah": 6,
            "subtotal": 74400
        },
        {
            "no": 6,
            "id_produk": 0,
            "kode_produk": "",
            "nama_produk": "NEBACETIN POWDER 5GR",
            "harga_beli": 24500,
            "harga_jual": 0,
            "expired_date": "0000-00-00",
            "batch": "",
            "jumlah": 3,
            "subtotal": 73500
        },
        {
            "no": 7,
            "id_produk": 185,
            "kode_produk": "",
            "nama_produk": "COUNTERPAIN CR 5GR",
            "harga_beli": 7700,
            "harga_jual": 0,
            "expired_date": "0000-00-00",
            "batch": "",
            "jumlah": 6,
            "subtotal": 46200
        }
    ]
}';

$data = json_decode($jsonData, true);

// Map missing product IDs based on previous diagnostic findings
$idMap = [
    "GLIMEPIRID 3MG TAB 100'S" => 321,
    "ERLAMYCETIN TM 10ML" => 251,
    "NEBACETIN POWDER 5GR" => 552
];

DB::beginTransaction();
try {
    // 1. Check if invoice already exists
    $existing = Pembelian::where('no_faktur', $data['nomor_faktur'])->first();
    if ($existing) {
        throw new Exception("Faktur {$data['nomor_faktur']} sudah ada di database!");
    }

    // 2. Resolve Product IDs
    $resolvedDetails = [];
    $totalItemCount = 0;
    foreach ($data['detail'] as $item) {
        $idProduk = $item['id_produk'];
        if ($idProduk == 0 && isset($idMap[$item['nama_produk']])) {
            $idProduk = $idMap[$item['nama_produk']];
        }

        $produk = Produk::find($idProduk);
        if (!$produk) {
            throw new Exception("Produk dengan ID {$idProduk} (Nama: {$item['nama_produk']}) tidak ditemukan.");
        }
        
        $item['id_produk'] = $idProduk;
        $resolvedDetails[] = $item;
        $totalItemCount += $item['jumlah'];
    }

    // 3. Create Pembelian Header
    $pembelian = new Pembelian();
    $pembelian->id_supplier = $data['id_supplier'];
    $pembelian->no_faktur = $data['nomor_faktur'];
    $pembelian->total_item = $totalItemCount;
    $pembelian->total_harga = $data['total'];
    $pembelian->diskon = round(($data['diskon'] / $data['total']) * 100); // Estimating diskon persen if needed, or keeping nominal
    $pembelian->bayar = $data['bayar'];
    $pembelian->waktu = $data['tanggal_waktu_faktur'];
    $pembelian->waktu_datang = $data['tanggal_waktu_obat_datang'];
    $pembelian->created_at = Carbon::parse($data['tanggal_waktu_faktur'])->addHours(8); // simulate late input
    $pembelian->updated_at = Carbon::now();
    $pembelian->save();

    echo "Berhasil membuat header pembelian dengan ID: {$pembelian->id_pembelian}\n";

    $affectedProductIds = [];

    // 4. Create Details and Rekaman Stok, Update Produk Stok
    foreach ($resolvedDetails as $item) {
        $produk = Produk::lockForUpdate()->find($item['id_produk']);
        $stokSebelum = $produk->stok;
        $stokBaru = $stokSebelum + $item['jumlah'];
        
        // Simpan Detail
        $detail = new PembelianDetail();
        $detail->id_pembelian = $pembelian->id_pembelian;
        $detail->id_produk = $item['id_produk'];
        $detail->harga_beli = $item['harga_beli'];
        $detail->jumlah = $item['jumlah'];
        $detail->subtotal = $item['subtotal'];
        $detail->save();

        // Update Stok Produk (this is technically overridden by sync service, but good for baseline)
        $produk->stok = $stokBaru;
        $produk->save();

        // Simpan Rekaman Stok
        RekamanStok::create([
            'id_produk' => $item['id_produk'],
            'id_pembelian' => $pembelian->id_pembelian,
            'waktu' => $pembelian->waktu_datang,
            'stok_masuk' => $item['jumlah'],
            'stok_keluar' => 0,
            'stok_awal' => $stokSebelum,
            'stok_sisa' => $stokBaru,
            'keterangan' => 'Pembelian: Penambahan stok dari supplier (Recovery Tante Ut)',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now()
        ]);

        $affectedProductIds[] = $item['id_produk'];
        echo " - Memproses produk ID {$item['id_produk']} ({$item['nama_produk']}) | Qty: {$item['jumlah']}\n";
    }

    DB::commit();
    echo "Transaksi utama berhasil dikomit. Menjalankan sinkronisasi stok robust...\n";

    // 5. Run Sync Service to ensure robustness and historical integrity
    try {
        app(TransactionDateMutationService::class)->synchronizeFinalizedPembelian($pembelian);
        echo "Sinkronisasi via TransactionDateMutationService sukses.\n";
    } catch (\Throwable $syncException) {
        echo "Peringatan sinkronisasi TransactionDateMutationService: " . $syncException->getMessage() . "\nFallback ke PembelianStockSyncService...\n";
        
        $affectedProductIds = array_unique($affectedProductIds);
        app(PembelianStockSyncService::class)->syncAffectedProducts($affectedProductIds, $pembelian->id_pembelian);
        echo "Sinkronisasi via PembelianStockSyncService selesai.\n";
    }

    echo "\n=== VALIDASI STOK AKHIR ===\n";
    foreach ($affectedProductIds as $idProduk) {
        $p = Produk::find($idProduk);
        echo "Produk ID {$idProduk} ({$p->nama_produk}) -> Stok Akhir: {$p->stok}\n";
    }

} catch (Exception $e) {
    DB::rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
