<?php

namespace Tests\Unit;

use App\Console\Commands\ImportPostCutoffPurchasesFromMarkdown;
use Tests\TestCase;

class ImportPostCutoffPurchasesFromMarkdownAliasSafetyTest extends TestCase
{
    public function test_unsafe_legacy_alias_entries_are_skipped_without_aborting_loader(): void
    {
        $command = new ImportPostCutoffPurchasesFromMarkdown();

        $this->setPrivateProperty($command, 'productById', [
            413 => [
                'id_produk' => 413,
                'nama_produk' => 'KOOLFEVER BABY',
                'aggressive_tokens' => ['koolfever', 'baby'],
            ],
        ]);

        $aliasPath = base_path('storage/app/testing_unsafe_alias_loader.json');
        file_put_contents($aliasPath, json_encode([
            'Koolfever Adult/Lbr' => 413,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        try {
            $this->invokePrivateMethod($command, 'loadProductAliases', [$aliasPath]);

            $aliasMap = $this->getPrivateProperty($command, 'productAliasMap');
            $skipped = $this->getPrivateProperty($command, 'skippedAliasEntries');

            $this->assertSame([], $aliasMap);
            $this->assertCount(1, $skipped);
            $this->assertSame('Koolfever Adult/Lbr', $skipped[0]['source_name']);
            $this->assertSame('unsafe_identity_guard', $skipped[0]['reason']);
            $this->assertSame('KOOLFEVER BABY', $skipped[0]['resolved_target_name']);
        } finally {
            if (is_file($aliasPath)) {
                @unlink($aliasPath);
            }
        }
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

    private function getPrivateProperty(object $instance, string $propertyName)
    {
        $reflection = new \ReflectionProperty($instance, $propertyName);
        $reflection->setAccessible(true);

        return $reflection->getValue($instance);
    }
}