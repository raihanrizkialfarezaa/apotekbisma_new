<?php

namespace Tests\Unit;

use App\Services\PurchaseSourceOverrideService;
use Tests\TestCase;

class PurchaseSourceOverrideServiceTest extends TestCase
{
    public function test_applies_configured_quantity_override_for_known_invoice_product(): void
    {
        $service = new PurchaseSourceOverrideService();

        $detail = $service->applyDetailOverride('NPS-2602-629903', [
            'id_produk' => 954,
            'nama_produk' => 'GUAIFENESIN TAB / GG TRIMAN NR ;80X100',
            'jumlah' => 100,
        ]);

        $this->assertSame(200, intval($detail['jumlah']));
        $this->assertSame(954, intval($detail['id_produk']));
        $this->assertSame(
            'Invoice NPS-2602-629903 has one box that must be converted to 200 unit tablets for GUAFINESIN.',
            $detail['_source_override_reason'] ?? null
        );
    }

    public function test_leaves_unmatched_invoice_detail_unchanged(): void
    {
        $service = new PurchaseSourceOverrideService();

        $detail = $service->applyDetailOverride('NPS-2602-629904', [
            'id_produk' => 954,
            'nama_produk' => 'GUAIFENESIN TAB / GG TRIMAN NR ;80X100',
            'jumlah' => 100,
        ]);

        $this->assertSame(100, intval($detail['jumlah']));
        $this->assertArrayNotHasKey('_source_override_reason', $detail);
    }

    public function test_applies_global_raw_name_override_for_koolfever_dewasa(): void
    {
        $service = new PurchaseSourceOverrideService();

        $detail = $service->applyDetailOverride('JM1-2602-02924', [
            'id_produk' => 412,
            'nama_produk' => 'Koolfever Adult/Lbr',
            'jumlah' => 12,
        ]);

        $this->assertSame(548, intval($detail['id_produk']));
        $this->assertSame('Koolfever Adult/Lbr', $detail['nama_produk']);
        $this->assertSame(
            'Supplier source files label Koolfever Adult/Lbr with anak product id 412; normalize all such source rows to KOOL FEVER DEWASA (#548).',
            $detail['_source_override_reason'] ?? null
        );
    }
}