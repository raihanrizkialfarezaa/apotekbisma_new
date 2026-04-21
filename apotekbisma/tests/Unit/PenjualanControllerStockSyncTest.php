<?php

namespace Tests\Unit;

use App\Http\Controllers\PenjualanController;
use App\Services\StockRuntimeIntegrityService;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class PenjualanControllerStockSyncTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_incomplete_penjualan_uses_draft_stock_consistency_guard(): void
    {
        Config::set('stock.cutoff_datetime', '2025-12-31 23:59:59');

        $integrityService = Mockery::mock(StockRuntimeIntegrityService::class);
        $integrityService
            ->shouldReceive('synchronizeDraftStockAgainstCommittedTruth')
            ->once()
            ->with([388], 'hapus penjualan #2509');
        $integrityService->shouldNotReceive('rebuildAndValidate');
        $integrityService->shouldNotReceive('assertLatestStockConsistency');
        $integrityService->shouldNotReceive('reconcileDraftStockConsistency');
        $integrityService->shouldNotReceive('assertDraftStockConsistency');

        $this->app->instance(StockRuntimeIntegrityService::class, $integrityService);

        $this->invokeSyncAffectedPenjualanProducts(
            [388],
            [
                'id_penjualan' => 2509,
                'total_item' => 1,
                'total_harga' => 1500,
                'bayar' => 0,
                'diterima' => 0,
                'waktu' => '2026-04-20 14:18:49',
                'created_at' => '2026-04-20 14:18:49',
                'is_incomplete' => true,
            ],
            'hapus penjualan #2509'
        );

        $this->addToAssertionCount(1);
    }

    public function test_post_cutoff_finalized_penjualan_uses_rebuild_and_validate(): void
    {
        Config::set('stock.cutoff_datetime', '2025-12-31 23:59:59');

        $integrityService = Mockery::mock(StockRuntimeIntegrityService::class);
        $integrityService
            ->shouldReceive('rebuildAndValidate')
            ->once()
            ->with([388], 'hapus penjualan #2509', true)
            ->andReturn([
                'products_rebuilt' => 1,
                'products_with_negative_event' => 0,
                'negative_event_count' => 0,
                'negative_event_product_ids' => [],
                'until' => null,
            ]);
        $integrityService->shouldNotReceive('reconcileDraftStockConsistency');
        $integrityService->shouldNotReceive('assertDraftStockConsistency');
        $integrityService->shouldNotReceive('assertLatestStockConsistency');

        $this->app->instance(StockRuntimeIntegrityService::class, $integrityService);

        $this->invokeSyncAffectedPenjualanProducts(
            [388],
            [
                'id_penjualan' => 2509,
                'total_item' => 2,
                'total_harga' => 3000,
                'bayar' => 3000,
                'diterima' => 5000,
                'waktu' => '2026-04-20 14:18:49',
                'created_at' => '2026-04-20 14:18:49',
                'is_incomplete' => false,
            ],
            'hapus penjualan #2509'
        );

        $this->addToAssertionCount(1);
    }

    public function test_pre_cutoff_finalized_penjualan_uses_latest_stock_consistency(): void
    {
        Config::set('stock.cutoff_datetime', '2025-12-31 23:59:59');

        $integrityService = Mockery::mock(StockRuntimeIntegrityService::class);
        $integrityService
            ->shouldReceive('assertLatestStockConsistency')
            ->once()
            ->with([388], 'hapus penjualan #99');
        $integrityService->shouldNotReceive('reconcileDraftStockConsistency');
        $integrityService->shouldNotReceive('assertDraftStockConsistency');
        $integrityService->shouldNotReceive('rebuildAndValidate');

        $this->app->instance(StockRuntimeIntegrityService::class, $integrityService);

        $this->invokeSyncAffectedPenjualanProducts(
            [388],
            [
                'id_penjualan' => 99,
                'total_item' => 2,
                'total_harga' => 3000,
                'bayar' => 3000,
                'diterima' => 5000,
                'waktu' => '2025-12-31 20:00:00',
                'created_at' => '2025-12-31 20:00:00',
                'is_incomplete' => false,
            ],
            'hapus penjualan #99'
        );

        $this->addToAssertionCount(1);
    }

    private function invokeSyncAffectedPenjualanProducts(array $productIds, ?array $snapshot, string $contextLabel): void
    {
        $controller = new PenjualanController();
        $method = new \ReflectionMethod(PenjualanController::class, 'syncAffectedPenjualanProducts');
        $method->setAccessible(true);
        $method->invoke($controller, $productIds, $snapshot, $contextLabel);
    }
}