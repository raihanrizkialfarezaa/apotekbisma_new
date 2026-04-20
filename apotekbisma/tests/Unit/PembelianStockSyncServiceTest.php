<?php

namespace Tests\Unit;

use App\Services\BaselineStockReflowService;
use App\Services\PembelianStockSyncService;
use App\Services\StockRuntimeIntegrityService;
use Mockery;
use Tests\TestCase;

class PembelianStockSyncServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_post_baseline_sync_fails_safe_when_reflow_cannot_run(): void
    {
        $reflowService = Mockery::mock(BaselineStockReflowService::class);
        $reflowService
            ->shouldReceive('rebuildProducts')
            ->once()
            ->andThrow(new \RuntimeException('simulated reflow failure'));

        $integrityService = Mockery::mock(StockRuntimeIntegrityService::class);
        $integrityService->shouldNotReceive('assertLatestStockConsistency');
        $integrityService->shouldNotReceive('assertDraftStockConsistency');

        $service = new PembelianStockSyncService($reflowService, $integrityService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sinkronisasi pembelian post-baseline wajib memakai baseline reflow');

        $service->syncAffectedProducts([10], 100, [
            'force_reflow' => true,
        ]);
    }

    public function test_post_baseline_sync_blocks_negative_historical_stock(): void
    {
        $summary = [
            'products_rebuilt' => 1,
            'products_with_negative_event' => 1,
            'negative_event_count' => 1,
            'negative_event_product_ids' => [10],
        ];

        $reflowService = Mockery::mock(BaselineStockReflowService::class);
        $reflowService
            ->shouldReceive('rebuildProducts')
            ->once()
            ->andReturn($summary);

        $integrityService = Mockery::mock(StockRuntimeIntegrityService::class);
        $integrityService
            ->shouldReceive('assertNoNegativeHistoricalStock')
            ->once()
            ->with([10], $summary, 'sinkronisasi pembelian')
            ->andThrow(new \App\Exceptions\UnsafeStockMutationException('negative historical stock detected'));
        $integrityService->shouldNotReceive('assertLatestStockConsistency');
        $integrityService->shouldNotReceive('assertDraftStockConsistency');

        $service = new PembelianStockSyncService($reflowService, $integrityService);

        $this->expectException(\App\Exceptions\UnsafeStockMutationException::class);
        $this->expectExceptionMessage('negative historical stock detected');

        $service->syncAffectedProducts([10], 100, [
            'force_reflow' => true,
        ]);
    }
}