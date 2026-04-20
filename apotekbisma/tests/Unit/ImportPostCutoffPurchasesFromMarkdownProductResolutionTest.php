<?php

namespace Tests\Unit;

use App\Console\Commands\ImportPostCutoffPurchasesFromMarkdown;
use Tests\TestCase;

class ImportPostCutoffPurchasesFromMarkdownProductResolutionTest extends TestCase
{
    public function test_resolve_product_matches_spaced_brand_and_variant_synonym_to_existing_master(): void
    {
        $command = new ImportPostCutoffPurchasesFromMarkdown();

        $products = [
            $this->buildProductRow($command, 412, 'KOOLFEVER ANAK'),
            $this->buildProductRow($command, 413, 'KOOLFEVER BABY'),
            $this->buildProductRow($command, 548, 'KOOL FEVER DEWASA'),
        ];

        $this->primeProductIndexes($command, $products);

        $result = $this->invokePrivateMethod($command, 'resolveProduct', ['Koolfever Adult/Lbr']);

        $this->assertTrue($result['ok']);
        $this->assertSame(548, $result['id_produk']);
        $this->assertSame('KOOL FEVER DEWASA', $result['nama_produk']);
    }

    public function test_extract_significant_tokens_adds_compound_brand_and_canonical_variant(): void
    {
        $command = new ImportPostCutoffPurchasesFromMarkdown();

        $normalized = $this->invokePrivateMethod($command, 'normalizeProductName', ['KOOL FEVER DEWASA']);
        $tokens = $this->invokePrivateMethod($command, 'extractSignificantProductTokens', [$normalized]);

        $this->assertContains('adult', $tokens);
        $this->assertContains('koolfever', $tokens);
        $this->assertNotContains('dewasa', $tokens);
    }

    public function test_map_suppliers_and_products_falls_back_from_mismatched_source_product_id(): void
    {
        $command = new ImportPostCutoffPurchasesFromMarkdown();

        $products = [
            $this->buildProductRow($command, 412, 'KOOLFEVER ANAK'),
            $this->buildProductRow($command, 413, 'KOOLFEVER BABY'),
            $this->buildProductRow($command, 548, 'KOOL FEVER DEWASA'),
        ];

        $this->primeProductIndexes($command, $products);
        $this->setPrivateProperty($command, 'supplierById', [
            1 => [
                'id_supplier' => 1,
                'nama' => 'TEST SUPPLIER',
            ],
        ]);

        $mapping = $this->invokePrivateMethod($command, 'mapSuppliersAndProducts', [[[
            'no_faktur' => 'TEST-KOOL-001',
            'supplier_name' => 'TEST SUPPLIER',
            'id_supplier_input' => 1,
            'invoice_date' => '2026-02-28 00:00:00',
            'source_total_harga' => 76224,
            'source_bayar' => 76224,
            'source_sections' => [],
            'rows' => [[
                'line' => 1,
                'nama_produk_raw' => 'Koolfever Adult/Lbr',
                'id_produk_input' => 412,
                'jumlah' => 12,
                'subtotal' => 76224,
                'harga_beli' => 6352,
            ]],
        ]]]);

        $this->assertSame([], $mapping['issues']);
        $this->assertCount(1, $mapping['invoices']);
        $this->assertCount(1, $mapping['invoices'][0]['details']);
        $this->assertSame(548, $mapping['invoices'][0]['details'][0]['id_produk']);
        $this->assertSame('KOOL FEVER DEWASA', $mapping['invoices'][0]['details'][0]['nama_produk']);
    }

    private function buildProductRow(ImportPostCutoffPurchasesFromMarkdown $command, int $idProduk, string $namaProduk): array
    {
        $normalized = $this->invokePrivateMethod($command, 'normalizeProductName', [$namaProduk]);
        $tokens = $this->invokePrivateMethod($command, 'extractSignificantProductTokens', [$normalized]);

        return [
            'id_produk' => $idProduk,
            'nama_produk' => $namaProduk,
            'normalized' => $normalized,
            'tokens' => $tokens,
            'token_key' => $this->invokePrivateMethod($command, 'buildTokenKey', [$tokens]),
            'measure_tokens' => $this->invokePrivateMethod($command, 'extractMeasureTokens', [$namaProduk]),
            'measure_details' => $this->invokePrivateMethod($command, 'extractMeasureDetails', [$namaProduk]),
            'pack_count_tokens' => $this->invokePrivateMethod($command, 'extractPackCountTokens', [$namaProduk]),
            'aggressive_tokens' => $this->invokePrivateMethod($command, 'extractAggressiveTokens', [$namaProduk]),
        ];
    }

    private function primeProductIndexes(ImportPostCutoffPurchasesFromMarkdown $command, array $products): void
    {
        $productIndex = [];
        $productTokenIndex = [];
        $productById = [];

        foreach ($products as $row) {
            $productIndex[$row['normalized']][] = $row;

            if ($row['token_key'] !== '') {
                $productTokenIndex[$row['token_key']][] = $row;
            }

            $productById[$row['id_produk']] = $row;
        }

        $this->setPrivateProperty($command, 'productRows', $products);
        $this->setPrivateProperty($command, 'productIndex', $productIndex);
        $this->setPrivateProperty($command, 'productTokenIndex', $productTokenIndex);
        $this->setPrivateProperty($command, 'productById', $productById);
        $this->setPrivateProperty($command, 'productAliasMap', []);
        $this->setProtectedProperty($command, 'input', null);
    }

    private function invokePrivateMethod(object $instance, string $methodName, array $arguments = [])
    {
        $reflection = new \ReflectionMethod($instance, $methodName);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($instance, $arguments);
    }

    private function setPrivateProperty(object $instance, string $propertyName, $value): void
    {
        $reflection = new \ReflectionProperty($instance, $propertyName);
        $reflection->setAccessible(true);
        $reflection->setValue($instance, $value);
    }

    private function setProtectedProperty(object $instance, string $propertyName, $value): void
    {
        $reflection = new \ReflectionProperty($instance, $propertyName);
        $reflection->setAccessible(true);
        $reflection->setValue($instance, $value);
    }
}