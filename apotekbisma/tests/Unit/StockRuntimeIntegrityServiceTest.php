<?php

namespace Tests\Unit;

use App\Exceptions\UnsafeStockMutationException;
use App\Services\BaselineStockReflowService;
use App\Services\StockRuntimeIntegrityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockRuntimeIntegrityServiceTest extends TestCase
{
    private string $originalDefaultConnection;

    private array $originalSqliteConnection;

    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver is not installed in this environment.');
        }

        $this->originalDefaultConnection = (string) config('database.default');
        $this->originalSqliteConnection = (array) config('database.connections.sqlite');

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->createMinimalSchema();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        Config::set('database.default', $this->originalDefaultConnection);
        Config::set('database.connections.sqlite', $this->originalSqliteConnection);

        parent::tearDown();
    }

    public function test_draft_stock_consistency_allows_open_penjualan_reservation_against_later_committed_stock(): void
    {
        $this->seedDraftPenjualanScenario(930);

        $service = $this->makeService();

        $service->assertDraftStockConsistency([161], 'sinkronisasi draft penjualan');

        $this->expectException(UnsafeStockMutationException::class);
        $service->assertLatestStockConsistency([161], 'sinkronisasi draft penjualan');
    }

    public function test_draft_stock_consistency_blocks_when_master_stock_ignores_open_penjualan_reservation(): void
    {
        $this->seedDraftPenjualanScenario(950);

        $service = $this->makeService();

        try {
            $service->assertDraftStockConsistency([161], 'sinkronisasi draft penjualan');
            $this->fail('Expected draft stock consistency check to throw.');
        } catch (UnsafeStockMutationException $exception) {
            $this->assertStringContainsString('expected 930', $exception->getMessage());
            $this->assertStringContainsString('draft jual 20', $exception->getMessage());
        }
    }

    private function makeService(): StockRuntimeIntegrityService
    {
        return new StockRuntimeIntegrityService(new BaselineStockReflowService());
    }

    private function seedDraftPenjualanScenario(int $masterStock): void
    {
        $now = '2026-04-08 22:35:00';

        DB::table('produk')->insert([
            'id_produk' => 161,
            'id_kategori' => 1,
            'nama_produk' => 'CETIRIZIN',
            'harga_beli' => 500,
            'diskon' => 0,
            'harga_jual' => 900,
            'stok' => $masterStock,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('pembelian')->insert([
            'id_pembelian' => 10,
            'id_supplier' => 1,
            'no_faktur' => 'FK-10',
            'total_item' => 500,
            'total_harga' => 500000,
            'diskon' => 0,
            'bayar' => 500000,
            'waktu' => '2026-04-08 22:30:00',
            'waktu_datang' => '2026-04-08 22:30:00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('penjualan')->insert([
            'id_penjualan' => 20,
            'id_member' => null,
            'total_item' => 0,
            'total_harga' => 0,
            'diskon' => 0,
            'bayar' => 0,
            'diterima' => 0,
            'id_user' => 1,
            'waktu' => '2026-04-08 22:10:00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('penjualan_detail')->insert([
            'id_penjualan_detail' => 30,
            'id_penjualan' => 20,
            'id_produk' => 161,
            'harga_jual' => 900,
            'jumlah' => 20,
            'diskon' => 0,
            'subtotal' => 18000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('rekaman_stoks')->insert([
            [
                'id_rekaman_stok' => 40,
                'id_produk' => 161,
                'id_penjualan' => 20,
                'id_pembelian' => null,
                'waktu' => '2026-04-08 22:10:00',
                'stok_awal' => 950,
                'stok_masuk' => 0,
                'stok_keluar' => 20,
                'stok_sisa' => 930,
                'keterangan' => 'Penjualan',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id_rekaman_stok' => 41,
                'id_produk' => 161,
                'id_penjualan' => null,
                'id_pembelian' => 10,
                'waktu' => '2026-04-08 22:30:00',
                'stok_awal' => 450,
                'stok_masuk' => 500,
                'stok_keluar' => 0,
                'stok_sisa' => 950,
                'keterangan' => 'Pembelian',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    private function createMinimalSchema(): void
    {
        Schema::create('produk', function (Blueprint $table) {
            $table->increments('id_produk');
            $table->unsignedInteger('id_kategori')->nullable();
            $table->string('nama_produk')->unique();
            $table->string('merk')->nullable();
            $table->integer('harga_beli')->default(0);
            $table->tinyInteger('diskon')->default(0);
            $table->string('expired_date')->nullable();
            $table->integer('harga_jual')->default(0);
            $table->integer('stok')->default(0);
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