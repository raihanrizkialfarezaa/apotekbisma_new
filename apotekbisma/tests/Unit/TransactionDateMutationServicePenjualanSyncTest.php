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

    public function test_synchronize_finalized_penjualan_allows_historical_negative_events_when_projected_final_stock_remains_positive(): void
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
            ->with([10], $now->format('Y-m-d H:i:s'))
            ->andReturn([
                'negative_event_count' => 2,
                'negative_event_product_ids' => [10],
                'products_with_non_positive_final_stock' => 0,
                'non_positive_final_stock_product_ids' => [],
                'final_stock_by_product' => [
                    [
                        'id_produk' => 10,
                        'final_stock' => 3,
                    ],
                ],
            ]);

        $integrityService = new class extends StockRuntimeIntegrityService {
            public array $lastCall = [];

            public function __construct()
            {
            }

            public function buildProjectedCurrentStockRows(array $finalStockRows): array
            {
                return [
                    [
                        'id_produk' => 10,
                        'committed_final_stock' => 3,
                        'draft_pembelian_qty' => 0,
                        'draft_penjualan_qty' => 0,
                        'projected_current_stock' => 3,
                    ],
                ];
            }

            public function rebuildAndValidate(
                array $productIds,
                string $contextLabel,
                bool $blockOnNegativeHistoricalStock = false,
                ?string $until = null,
                bool $validateDraftAwareStock = false
            ): array {
                $this->lastCall = [$productIds, $contextLabel, $blockOnNegativeHistoricalStock, $until, $validateDraftAwareStock];

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
            [[10], 'finalisasi penjualan #200', false, $now->format('Y-m-d H:i:s'), true],
            $integrityService->lastCall
        );
    }

    public function test_synchronize_finalized_penjualan_allows_when_projected_current_stock_becomes_zero(): void
    {
        DB::table('penjualan_detail')->insert([
            'id_penjualan' => 205,
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
            ->with([10], $now->format('Y-m-d H:i:s'))
            ->andReturn([
                'negative_event_count' => 1,
                'negative_event_product_ids' => [10],
                'products_with_non_positive_final_stock' => 1,
                'non_positive_final_stock_product_ids' => [10],
                'final_stock_by_product' => [
                    [
                        'id_produk' => 10,
                        'final_stock' => 0,
                    ],
                ],
            ]);

        $integrityService = new class extends StockRuntimeIntegrityService {
            public array $lastCall = [];

            public function __construct()
            {
            }

            public function buildProjectedCurrentStockRows(array $finalStockRows): array
            {
                return [
                    [
                        'id_produk' => 10,
                        'committed_final_stock' => 0,
                        'draft_pembelian_qty' => 0,
                        'draft_penjualan_qty' => 0,
                        'projected_current_stock' => 0,
                    ],
                ];
            }

            public function rebuildAndValidate(
                array $productIds,
                string $contextLabel,
                bool $blockOnNegativeHistoricalStock = false,
                ?string $until = null,
                bool $validateDraftAwareStock = false
            ): array {
                $this->lastCall = [$productIds, $contextLabel, $blockOnNegativeHistoricalStock, $until, $validateDraftAwareStock];

                return [
                    'negative_event_count' => 1,
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
        $penjualan->id_penjualan = 205;
        $penjualan->waktu = '2026-04-21 15:42:40';
        $penjualan->created_at = '2026-04-21 15:42:40';

        $result = $service->synchronizeFinalizedPenjualan($penjualan);

        $this->assertSame(
            [
                'negative_event_count' => 1,
                'negative_event_product_ids' => [10],
            ],
            $result
        );
        $this->assertSame(
            [[10], 'finalisasi penjualan #205', false, $now->format('Y-m-d H:i:s'), true],
            $integrityService->lastCall
        );
    }

    public function test_synchronize_finalized_penjualan_blocks_when_projected_current_stock_becomes_minus(): void
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
            ->with([10], $now->format('Y-m-d H:i:s'))
            ->andReturn([
                'negative_event_count' => 1,
                'negative_event_product_ids' => [10],
                'products_with_non_positive_final_stock' => 1,
                'non_positive_final_stock_product_ids' => [10],
                'final_stock_by_product' => [
                    [
                        'id_produk' => 10,
                        'final_stock' => 0,
                    ],
                ],
            ]);

        $integrityService = new class extends StockRuntimeIntegrityService {
            public function __construct()
            {
            }

            public function buildProjectedCurrentStockRows(array $finalStockRows): array
            {
                return [
                    [
                        'id_produk' => 10,
                        'committed_final_stock' => 0,
                        'draft_pembelian_qty' => 0,
                        'draft_penjualan_qty' => 1,
                        'projected_current_stock' => -1,
                    ],
                ];
            }

            public function rebuildAndValidate(
                array $productIds,
        $this->expectExceptionMessage('stok akhir saat ini menjadi minus');
        $this->expectExceptionMessage('POLYCROL FORTE (#10, stok akhir -1)');
                ?string $until = null,
                bool $validateDraftAwareStock = false
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
                string $contextLabel,
                bool $blockOnNegativeHistoricalStock = false,
                ?string $until = null,
                bool $validateDraftAwareStock = false
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
        $this->expectExceptionMessage('stok akhir saat ini menjadi minus');
        $this->expectExceptionMessage('POLYCROL FORTE (#10, stok akhir -1)');

        $service->synchronizeFinalizedPenjualan($penjualan);
    }

    public function test_synchronize_finalized_penjualan_blocks_backdate_before_next_day_floor_after_cutoff(): void
    {
        Config::set('stock.cutoff_datetime', '2025-12-31 12:34:56');

        DB::table('penjualan_detail')->insert([
            'id_penjualan' => 202,
            'id_produk' => 10,
        ]);

        $baselineReflowService = Mockery::mock(BaselineStockReflowService::class);
        $baselineReflowService->shouldNotReceive('previewRebuildSummary');

        $integrityService = new class extends StockRuntimeIntegrityService {
            public function __construct()
            {
            }

            public function buildProjectedCurrentStockRows(array $finalStockRows): array
            {
                return [];
            }

            public function rebuildAndValidate(
                array $productIds,

        $penjualan->waktu = '2026-04-21 15:42:40';
        $penjualan->created_at = '2026-04-21 15:42:40';

                return [];
        $service->synchronizeFinalizedPenjualan($penjualan);
    }
}
