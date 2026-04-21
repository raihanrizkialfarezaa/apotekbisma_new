<?php

namespace Tests\Unit;

use App\Http\Controllers\PembelianDetailController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PembelianDetailControllerStockSyncTest extends TestCase
{
    private string $originalDefaultConnection;

    private array $originalSqliteConnection;

    private string $originalCacheDriver;

    private string $originalBaselineCsv;

    private string $baselineRelativePath = 'storage/app/testing_baseline_sync.csv';

    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver is not installed in this environment.');
        }

        $this->originalDefaultConnection = (string) config('database.default');
        $this->originalSqliteConnection = (array) config('database.connections.sqlite');
        $this->originalCacheDriver = (string) config('cache.default');
        $this->originalBaselineCsv = (string) config('stock.baseline_csv');

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        Config::set('cache.default', 'array');
        Config::set('stock.baseline_csv', $this->baselineRelativePath);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->createMinimalSchema();
        $this->writeBaselineCsv();

        session()->start();

        $user = new User();
        $user->id = 1;
        $user->name = 'Tester';
        $user->email = 'tester@example.com';
        $this->be($user);
    }

    protected function tearDown(): void
    {
        $baselinePath = base_path($this->baselineRelativePath);
        if (is_file($baselinePath)) {
            @unlink($baselinePath);
        }

        DB::disconnect('sqlite');
        Config::set('database.default', $this->originalDefaultConnection);
        Config::set('database.connections.sqlite', $this->originalSqliteConnection);
        Config::set('cache.default', $this->originalCacheDriver);
        Config::set('stock.baseline_csv', $this->originalBaselineCsv);

        parent::tearDown();
    }

    public function test_finalized_post_cutoff_quantity_update_rebuilds_stock_from_baseline_atomically(): void
    {
        $now = '2026-04-20 09:00:00';

        DB::table('produk')->insert([
            'id_produk' => 10,
            'id_kategori' => 1,
            'nama_produk' => 'TEST FINAL PRODUCT',
            'harga_beli' => 100,
            'diskon' => 0,
            'harga_jual' => 150,
            'stok' => 1009,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('pembelian')->insert([
            'id_pembelian' => 100,
            'id_supplier' => 1,
            'no_faktur' => 'INV-FINAL-100',
            'total_item' => 10,
            'total_harga' => 1000,
            'diskon' => 0,
            'bayar' => 1000,
            'waktu' => '2026-02-01 10:00:00',
            'waktu_datang' => '2026-02-01 10:00:00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('pembelian_detail')->insert([
            'id_pembelian_detail' => 500,
            'id_pembelian' => 100,
            'id_produk' => 10,
            'harga_beli' => 100,
            'jumlah' => 10,
            'subtotal' => 1000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('rekaman_stoks')->insert([
            'id_rekaman_stok' => 700,
            'id_produk' => 10,
            'id_penjualan' => null,
            'id_pembelian' => 100,
            'waktu' => '2026-02-01 10:00:00',
            'stok_awal' => 999,
            'stok_masuk' => 10,
            'stok_keluar' => 0,
            'stok_sisa' => 1009,
            'keterangan' => 'Pembelian corrupt',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        session(['id_pembelian' => 100, 'id_supplier' => 1]);

        $controller = app(PembelianDetailController::class);
        $request = Request::create('/pembelian_detail/500', 'PUT', ['jumlah' => 15]);

        $response = $controller->update($request, 500);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(15, intval(DB::table('pembelian_detail')->where('id_pembelian_detail', 500)->value('jumlah')));
        $this->assertSame(1500, intval(DB::table('pembelian_detail')->where('id_pembelian_detail', 500)->value('subtotal')));
        $this->assertSame(65, intval(DB::table('produk')->where('id_produk', 10)->value('stok')));

        $records = DB::table('rekaman_stoks')
            ->where('id_produk', 10)
            ->orderBy('waktu', 'asc')
            ->orderBy('id_rekaman_stok', 'asc')
            ->get();

        $this->assertCount(2, $records);
        $this->assertSame('Saldo Awal Stok per 31-12-2025', (string) $records[0]->keterangan);
        $this->assertSame(50, intval($records[0]->stok_sisa));
        $this->assertSame(100, intval($records[1]->id_pembelian));
        $this->assertSame(15, intval($records[1]->stok_masuk));
        $this->assertSame(65, intval($records[1]->stok_sisa));
    }

    public function test_draft_quantity_update_keeps_existing_atomic_stock_mutation_flow(): void
    {
        $now = '2026-04-20 09:00:00';

        DB::table('produk')->insert([
            'id_produk' => 11,
            'id_kategori' => 1,
            'nama_produk' => 'TEST DRAFT PRODUCT',
            'harga_beli' => 200,
            'diskon' => 0,
            'harga_jual' => 250,
            'stok' => 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('pembelian')->insert([
            'id_pembelian' => 101,
            'id_supplier' => 1,
            'no_faktur' => 'o',
            'total_item' => 0,
            'total_harga' => 0,
            'diskon' => 0,
            'bayar' => 0,
            'waktu' => '2026-04-20 08:30:00',
            'waktu_datang' => '2026-04-20 08:30:00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('pembelian_detail')->insert([
            'id_pembelian_detail' => 501,
            'id_pembelian' => 101,
            'id_produk' => 11,
            'harga_beli' => 200,
            'jumlah' => 2,
            'subtotal' => 400,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('rekaman_stoks')->insert([
            'id_rekaman_stok' => 701,
            'id_produk' => 11,
            'id_penjualan' => null,
            'id_pembelian' => 101,
            'waktu' => '2026-04-20 08:30:00',
            'stok_awal' => 3,
            'stok_masuk' => 2,
            'stok_keluar' => 0,
            'stok_sisa' => 5,
            'keterangan' => 'Pembelian draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        session(['id_pembelian' => 101, 'id_supplier' => 1]);

        $controller = app(PembelianDetailController::class);
        $request = Request::create('/pembelian_detail/501', 'PUT', ['jumlah' => 4]);

        $response = $controller->update($request, 501);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(4, intval(DB::table('pembelian_detail')->where('id_pembelian_detail', 501)->value('jumlah')));
        $this->assertSame(800, intval(DB::table('pembelian_detail')->where('id_pembelian_detail', 501)->value('subtotal')));
        $this->assertSame(7, intval(DB::table('produk')->where('id_produk', 11)->value('stok')));

        $record = DB::table('rekaman_stoks')
            ->where('id_pembelian', 101)
            ->where('id_produk', 11)
            ->first();

        $this->assertNotNull($record);
        $this->assertSame(4, intval($record->stok_masuk));
        $this->assertSame(7, intval($record->stok_sisa));
        $this->assertSame(1, DB::table('rekaman_stoks')->where('id_produk', 11)->count());
    }

    public function test_finalized_post_cutoff_reflow_ignores_pre_baseline_rekaman_for_products_without_baseline_seed(): void
    {
        $now = '2026-04-20 09:00:00';

        DB::table('produk')->insert([
            'id_produk' => 12,
            'id_kategori' => 1,
            'nama_produk' => 'TEST NO BASELINE PRODUCT',
            'harga_beli' => 100,
            'diskon' => 0,
            'harga_jual' => 150,
            'stok' => 90,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('rekaman_stoks')->insert([
            'id_rekaman_stok' => 702,
            'id_produk' => 12,
            'id_penjualan' => null,
            'id_pembelian' => null,
            'waktu' => '2025-12-30 10:00:00',
            'stok_awal' => 0,
            'stok_masuk' => 80,
            'stok_keluar' => 0,
            'stok_sisa' => 80,
            'keterangan' => 'Histori lama sebelum baseline',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('pembelian')->insert([
            'id_pembelian' => 102,
            'id_supplier' => 1,
            'no_faktur' => 'INV-FINAL-102',
            'total_item' => 10,
            'total_harga' => 1000,
            'diskon' => 0,
            'bayar' => 1000,
            'waktu' => '2026-02-02 10:00:00',
            'waktu_datang' => '2026-02-02 10:00:00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('pembelian_detail')->insert([
            'id_pembelian_detail' => 502,
            'id_pembelian' => 102,
            'id_produk' => 12,
            'harga_beli' => 100,
            'jumlah' => 10,
            'subtotal' => 1000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('rekaman_stoks')->insert([
            'id_rekaman_stok' => 703,
            'id_produk' => 12,
            'id_penjualan' => null,
            'id_pembelian' => 102,
            'waktu' => '2026-02-02 10:00:00',
            'stok_awal' => 80,
            'stok_masuk' => 10,
            'stok_keluar' => 0,
            'stok_sisa' => 90,
            'keterangan' => 'Pembelian corrupt',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        session(['id_pembelian' => 102, 'id_supplier' => 1]);

        $controller = app(PembelianDetailController::class);
        $request = Request::create('/pembelian_detail/502', 'PUT', ['jumlah' => 15]);

        $response = $controller->update($request, 502);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(15, intval(DB::table('pembelian_detail')->where('id_pembelian_detail', 502)->value('jumlah')));
        $this->assertSame(15, intval(DB::table('produk')->where('id_produk', 12)->value('stok')));

        $records = DB::table('rekaman_stoks')
            ->where('id_produk', 12)
            ->orderBy('waktu', 'asc')
            ->orderBy('id_rekaman_stok', 'asc')
            ->get();

        $this->assertCount(3, $records);
        $this->assertSame('Histori lama sebelum baseline', (string) $records[0]->keterangan);
        $this->assertSame('Saldo Awal Stok tanpa referensi baseline', (string) $records[1]->keterangan);
        $this->assertSame(0, intval($records[1]->stok_sisa));
        $this->assertSame(15, intval($records[2]->stok_masuk));
        $this->assertSame(15, intval($records[2]->stok_sisa));
    }

    public function test_finalized_post_cutoff_reflow_uses_cutoff_stock_record_when_csv_seed_is_missing(): void
    {
        $now = '2026-04-20 09:00:00';

        DB::table('produk')->insert([
            'id_produk' => 13,
            'id_kategori' => 1,
            'nama_produk' => 'TEST DB BASELINE PRODUCT',
            'harga_beli' => 100,
            'diskon' => 0,
            'harga_jual' => 150,
            'stok' => 11,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('rekaman_stoks')->insert([
            'id_rekaman_stok' => 704,
            'id_produk' => 13,
            'id_penjualan' => null,
            'id_pembelian' => null,
            'waktu' => '2025-12-31 23:59:59',
            'stok_awal' => 12,
            'stok_masuk' => 0,
            'stok_keluar' => 0,
            'stok_sisa' => 12,
            'keterangan' => 'Saldo Awal Stok dari histori sebelum cutoff',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('pembelian')->insert([
            'id_pembelian' => 103,
            'id_supplier' => 1,
            'no_faktur' => 'INV-FINAL-103',
            'total_item' => 1,
            'total_harga' => 100,
            'diskon' => 0,
            'bayar' => 100,
            'waktu' => '2026-02-02 10:00:00',
            'waktu_datang' => '2026-02-02 10:00:00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('pembelian_detail')->insert([
            'id_pembelian_detail' => 503,
            'id_pembelian' => 103,
            'id_produk' => 13,
            'harga_beli' => 100,
            'jumlah' => 5,
            'subtotal' => 500,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('rekaman_stoks')->insert([
            'id_rekaman_stok' => 705,
            'id_produk' => 13,
            'id_penjualan' => null,
            'id_pembelian' => 103,
            'waktu' => '2026-02-02 10:00:00',
            'stok_awal' => 6,
            'stok_masuk' => 5,
            'stok_keluar' => 0,
            'stok_sisa' => 11,
            'keterangan' => 'Pembelian corrupt',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        session(['id_pembelian' => 103, 'id_supplier' => 1]);

        $controller = app(PembelianDetailController::class);
        $request = Request::create('/pembelian_detail/503', 'PUT', ['jumlah' => 6]);

        $response = $controller->update($request, 503);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(18, intval(DB::table('produk')->where('id_produk', 13)->value('stok')));

        $records = DB::table('rekaman_stoks')
            ->where('id_produk', 13)
            ->orderBy('waktu', 'asc')
            ->orderBy('id_rekaman_stok', 'asc')
            ->get();

        $this->assertCount(2, $records);
        $this->assertSame('Saldo Awal Stok per 31-12-2025', (string) $records[0]->keterangan);
        $this->assertSame(12, intval($records[0]->stok_sisa));
        $this->assertSame(6, intval($records[1]->stok_masuk));
        $this->assertSame(18, intval($records[1]->stok_sisa));
    }

    private function writeBaselineCsv(): void
    {
        file_put_contents(
            base_path($this->baselineRelativePath),
            implode("\n", [
                'produk_id_produk;produk_nama_produk;produk_stok',
                '10;TEST FINAL PRODUCT;50',
                '11;TEST DRAFT PRODUCT;3',
            ])
        );
    }

    private function createMinimalSchema(): void
    {
        Schema::create('produk', function (Blueprint $table) {
            $table->increments('id_produk');
            $table->unsignedInteger('id_kategori')->nullable();
            $table->string('nama_produk')->unique();
            $table->integer('harga_beli')->default(0);
            $table->tinyInteger('diskon')->default(0);
            $table->string('expired_date')->nullable();
            $table->string('batch')->nullable();
            $table->integer('harga_jual')->default(0);
            $table->integer('stok')->default(0);
            $table->timestamps();
        });

        Schema::create('pembelian', function (Blueprint $table) {
            $table->increments('id_pembelian');
            $table->integer('id_supplier')->nullable();
            $table->string('no_faktur')->nullable();
            $table->integer('total_item')->default(0);
            $table->integer('total_harga')->default(0);
            $table->tinyInteger('diskon')->default(0);
            $table->integer('bayar')->default(0);
            $table->dateTime('waktu')->nullable();
            $table->dateTime('waktu_datang')->nullable();
            $table->timestamps();
        });

        Schema::create('pembelian_detail', function (Blueprint $table) {
            $table->increments('id_pembelian_detail');
            $table->integer('id_pembelian');
            $table->integer('id_produk');
            $table->integer('harga_beli')->default(0);
            $table->integer('jumlah')->default(0);
            $table->integer('subtotal')->default(0);
            $table->timestamps();
        });

        Schema::create('penjualan', function (Blueprint $table) {
            $table->increments('id_penjualan');
            $table->integer('id_member')->nullable();
            $table->integer('total_item')->default(0);
            $table->integer('total_harga')->default(0);
            $table->tinyInteger('diskon')->default(0);
            $table->integer('bayar')->default(0);
            $table->integer('diterima')->default(0);
            $table->integer('id_user')->default(0);
            $table->dateTime('waktu')->nullable();
            $table->timestamps();
        });

        Schema::create('penjualan_detail', function (Blueprint $table) {
            $table->increments('id_penjualan_detail');
            $table->integer('id_penjualan');
            $table->integer('id_produk');
            $table->integer('harga_jual')->default(0);
            $table->integer('jumlah')->default(0);
            $table->tinyInteger('diskon')->default(0);
            $table->integer('subtotal')->default(0);
            $table->timestamps();
        });

        Schema::create('rekaman_stoks', function (Blueprint $table) {
            $table->increments('id_rekaman_stok');
            $table->integer('id_produk');
            $table->integer('id_penjualan')->nullable();
            $table->integer('id_pembelian')->nullable();
            $table->dateTime('waktu');
            $table->integer('stok_awal')->default(0);
            $table->integer('stok_masuk')->default(0);
            $table->integer('stok_keluar')->default(0);
            $table->integer('stok_sisa')->default(0);
            $table->string('keterangan')->nullable();
            $table->timestamps();
        });
    }
}