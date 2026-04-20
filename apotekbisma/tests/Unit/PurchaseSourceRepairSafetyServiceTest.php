<?php

namespace Tests\Unit;

use App\Services\PurchaseSourceRepairSafetyService;
use Tests\TestCase;

class PurchaseSourceRepairSafetyServiceTest extends TestCase
{
    public function test_allows_explicit_override_mismatch(): void
    {
        $service = new PurchaseSourceRepairSafetyService();

        $result = $service->assessMismatch(
            'GUAIFENESIN TAB / GG TRIMAN NR ;80X100',
            200,
            100,
            true
        );

        $this->assertTrue($result['auto_repair_safe']);
        $this->assertSame('explicit_source_override', $result['reason']);
    }

    public function test_blocks_bodrex_quantity_ratio_for_manual_review(): void
    {
        $service = new PurchaseSourceRepairSafetyService();

        $result = $service->assessMismatch(
            'BODREX TAB NORETUR ;BX/20TAB',
            960,
            24,
            false
        );

        $this->assertFalse($result['auto_repair_safe']);
        $this->assertSame('packed_product_quantity_mismatch_requires_manual_review', $result['reason']);
        $this->assertContains('20', $result['pack_count_tokens']);
    }

    public function test_blocks_superhoid_packed_mismatch_for_manual_review(): void
    {
        $service = new PurchaseSourceRepairSafetyService();

        $result = $service->assessMismatch(
            'SUPERHOID SUPP NORETUR ;BX/6',
            24,
            12,
            false
        );

        $this->assertFalse($result['auto_repair_safe']);
        $this->assertSame('packed_product_quantity_mismatch_requires_manual_review', $result['reason']);
        $this->assertContains('6', $result['pack_count_tokens']);
    }
}