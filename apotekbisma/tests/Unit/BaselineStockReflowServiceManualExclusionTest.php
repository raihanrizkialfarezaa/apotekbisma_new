<?php

namespace Tests\Unit;

use App\Services\BaselineStockReflowService;
use Tests\TestCase;

class BaselineStockReflowServiceManualExclusionTest extends TestCase
{
    public function test_auto_negative_stabilizer_rows_are_excluded_from_manual_reflow(): void
    {
        $service = new BaselineStockReflowService();

        $this->assertTrue(
            $this->invokePrivateMethod(
                $service,
                'isExcludedManualRecord',
                ['[AUTO-NEGATIVE-STABILIZER] Penyesuaian Stok Manual: Stabilisasi defisit historis sebelum stok minus']
            )
        );

        $this->assertFalse(
            $this->invokePrivateMethod(
                $service,
                'isExcludedManualRecord',
                ['Penyesuaian Stok Manual via Edit Produk']
            )
        );
    }

    private function invokePrivateMethod(object $instance, string $methodName, array $arguments = [])
    {
        $reflection = new \ReflectionMethod($instance, $methodName);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($instance, $arguments);
    }
}