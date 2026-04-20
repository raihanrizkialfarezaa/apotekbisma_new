<?php

namespace Tests\Unit;

use App\Services\ProductMappingSafetyService;
use Tests\TestCase;

class ProductMappingSafetyServiceTest extends TestCase
{
    public function test_rejects_obh_itrasal_mapping_to_ob_herbal_tokens(): void
    {
        $service = new ProductMappingSafetyService();

        $this->assertFalse(
            $service->passesIdentityGuard(
                'OBH ITRASAL 100ML NORETUR ;FL/100ML',
                ['obh', 'herbal']
            )
        );
    }

    public function test_rejects_guaifnesin_mapping_to_unrelated_hot_in_tokens(): void
    {
        $service = new ProductMappingSafetyService();

        $this->assertFalse(
            $service->passesIdentityGuard(
                'GUAIFNESIN TAB / GG TRIMAN NR ;BOX100',
                ['hot', 'in']
            )
        );
    }

    public function test_accepts_legitimate_identity_token_match(): void
    {
        $service = new ProductMappingSafetyService();

        $this->assertTrue(
            $service->passesIdentityGuard(
                'GUAIFNESIN TAB / GG TRIMAN NR ;BOX100',
                ['guafinesin']
            )
        );

        $this->assertTrue(
            $service->passesIdentityGuard(
                'OBH ITRASAL 100ML NORETUR ;FL/100ML',
                ['obh', 'itrasal']
            )
        );
    }

    public function test_rejects_cross_variant_mapping_between_dewasa_and_anak(): void
    {
        $service = new ProductMappingSafetyService();

        $this->assertFalse(
            $service->passesIdentityGuard(
                'PULFIVER DEWASA 60ML',
                ['pulfiver', 'anak']
            )
        );

        $this->assertFalse(
            $service->passesIdentityGuard(
                'VICKS F 44 DEWASA 54ML',
                ['vicks', 'anak']
            )
        );

        $this->assertFalse(
            $service->passesIdentityGuard(
                'KOOLFEVER ADULT/LBR',
                ['koolfever', 'baby']
            )
        );

        $this->assertFalse(
            $service->passesIdentityGuard(
                'KOOL FEVER DEWASA',
                ['koolfever', 'anak']
            )
        );

        $this->assertTrue(
            $service->passesIdentityGuard(
                'PULFIVER DEWASA 60ML',
                ['pulfiver', 'dewasa']
            )
        );

        $this->assertTrue(
            $service->passesIdentityGuard(
                'KOOLFEVER ADULT/LBR',
                ['koolfever', 'dewasa']
            )
        );
    }
}