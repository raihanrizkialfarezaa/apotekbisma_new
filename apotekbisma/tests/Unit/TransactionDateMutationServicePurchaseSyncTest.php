<?php

namespace Tests\Unit;

use App\Models\Pembelian;
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

class TransactionDateMutationServicePurchaseSyncTest extends TestCase
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

        Schema::create('pembelian_detail', function (Blueprint $table) {
            $table->increments('id_pembelian_detail');
            $table->unsignedInteger('id_pembelian');
            $table->unsignedInteger('id_produk');
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

    public function test_synchronize_finalized_pembelian_enables_negative_history_blocking(): void
    {
        DB::table('pembelian_detail')->insert([
            'id_pembelian' => 100,
            'id_produk' => 10,
        ]);

        $now = Carbon::parse('2026-03-20 12:00:00');

        $baselineReflowService = Mockery::mock(BaselineStockReflowService::class);
        $baselineReflowService
            ->shouldReceive('previewRebuildSummary')
            ->once()
            ->with([10], $now->format('Y-m-d H:i:s'), 'pembelian', 100)
            ->andReturn([
                'negative_event_count' => 0,
                'negative_event_product_ids' => [],
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

                return ['negative_event_count' => 0];
            }
        };

        $clockService = Mockery::mock(TransactionLogicalClockService::class);
        $clockService->shouldReceive('now')->twice()->andReturn(clone $now, clone $now);

        $service = new TransactionDateMutationService(
            $baselineReflowService,
            $integrityService,
            $clockService
        );

        $pembelian = new Pembelian();
        $pembelian->id_pembelian = 100;
        $pembelian->no_faktur = 'INV-100';
        $pembelian->waktu = '2026-02-01 10:00:00';
        $pembelian->waktu_datang = '2026-02-01 10:00:00';

        $result = $service->synchronizeFinalizedPembelian($pembelian);

        $this->assertSame(['negative_event_count' => 0], $result);
        $this->assertSame(
            [[10], 'sinkronisasi pembelian final INV-100', false, $now->format('Y-m-d H:i:s')],
            $integrityService->lastCall
        );
    }
}