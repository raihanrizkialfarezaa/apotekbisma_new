<?php

namespace Tests\Unit;

use App\Exceptions\UnsafeStockMutationException;
use App\Models\Penjualan;
use App\Services\BaselineStockReflowService;
use App\Services\StockRuntimeIntegrityService;
use App\Services\TransactionDateMutationService;
use App\Services\TransactionLogicalClockService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class TransactionDateMutationServicePenjualanSyncTest extends TestCase
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

        Schema::create('penjualan_detail', function (Blueprint $table) {
            $table->increments('id_penjualan_detail');
            $table->unsignedInteger('id_penjualan');
            $table->unsignedInteger('id_produk');
        });

        Schema::create('produk', function (Blueprint $table) {
            $table->increments('id_produk');
            $table->string('nama_produk');
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();

        DB::disconnect('sqlite');
        Config::set('database.default', $this->originalDefaultConnection);
        Config::set('database.connections.sqlite', $this->originalSqliteConnection);

        parent::tearDown();
    }

    public function test_synchronize_finalized_penjualan_allows_preexisting_negative_history_when_no_new_negative_is_introduced(): void
    {
        DB::table('penjualan_detail')->insert([
            'id_penjualan' => 200,
            'id_produk' => 10,
        ]);

        DB::table('produk')->insert([
            'id_produk' => 10,
            'nama_produk' => 'POLYCROL FORTE',
        ]);

        $now = Carbon::parse('2026-04-21 15:45:00');

        $baselineReflowService = Mockery::mock(BaselineStockReflowService::class);
        $baselineReflowService
            ->shouldReceive('previewRebuildSummary')
            ->once()
            ->with([10], $now->format('Y-m-d H:i:s'), 'penjualan', 200)
            ->andReturn([
                'negative_event_count' => 2,
                'negative_event_product_ids' => [10],
            ]);

        $integrityService = new class extends StockRuntimeIntegrityService {
            public array $lastCall = [];

            public function __construct()
            {
            }

            public function rebuildAndValidate(
                array $productIds,
                string $contextLabel,
                bool $blockOnNegativeHistoricalStock = false,
                ?string $until = null
            ): array {
                $this->lastCall = [$productIds, $contextLabel, $blockOnNegativeHistoricalStock, $until];

                return [
                    'negative_event_count' => 2,
                    'negative_event_product_ids' => [10],
                ];
            }
        };

        $clockService = Mockery::mock(TransactionLogicalClockService::class);
        $clockService->shouldReceive('now')->twice()->andReturn(clone $now, clone $now);

        $service = new TransactionDateMutationService(
            $baselineReflowService,
            $integrityService,
            $clockService
        );

        $penjualan = new Penjualan();
        $penjualan->id_penjualan = 200;
        $penjualan->waktu = '2026-04-21 15:42:40';
        $penjualan->created_at = '2026-04-21 15:42:40';

        $result = $service->synchronizeFinalizedPenjualan($penjualan);

        $this->assertSame(
            [
                'negative_event_count' => 2,
                'negative_event_product_ids' => [10],
            ],
            $result
        );
        $this->assertSame(
            [[10], 'finalisasi penjualan #200', false, $now->format('Y-m-d H:i:s')],
            $integrityService->lastCall
        );
    }

    public function test_synchronize_finalized_penjualan_blocks_when_it_adds_new_negative_history(): void
    {
        DB::table('penjualan_detail')->insert([
            'id_penjualan' => 201,
            'id_produk' => 10,
        ]);

        DB::table('produk')->insert([
            'id_produk' => 10,
            'nama_produk' => 'POLYCROL FORTE',
        ]);

        $now = Carbon::parse('2026-04-21 15:45:00');

        $baselineReflowService = Mockery::mock(BaselineStockReflowService::class);
        $baselineReflowService
            ->shouldReceive('previewRebuildSummary')
            ->once()
            ->with([10], $now->format('Y-m-d H:i:s'), 'penjualan', 201)
            ->andReturn([
                'negative_event_count' => 1,
                'negative_event_product_ids' => [10],
            ]);

        $integrityService = new class extends StockRuntimeIntegrityService {
            public function __construct()
            {
            }

            public function rebuildAndValidate(
                array $productIds,
                string $contextLabel,
                bool $blockOnNegativeHistoricalStock = false,
                ?string $until = null
            ): array {
                return [
                    'negative_event_count' => 2,
                    'negative_event_product_ids' => [10],
                ];
            }
        };

        $clockService = Mockery::mock(TransactionLogicalClockService::class);
        $clockService->shouldReceive('now')->twice()->andReturn(clone $now, clone $now);

        $service = new TransactionDateMutationService(
            $baselineReflowService,
            $integrityService,
            $clockService
        );

        $penjualan = new Penjualan();
        $penjualan->id_penjualan = 201;
        $penjualan->waktu = '2026-04-21 15:42:40';
        $penjualan->created_at = '2026-04-21 15:42:40';

        $this->expectException(UnsafeStockMutationException::class);
        $this->expectExceptionMessage('POLYCROL FORTE (#10)');

        $service->synchronizeFinalizedPenjualan($penjualan);
    }
}
