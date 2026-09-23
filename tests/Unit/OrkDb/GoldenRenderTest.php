<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\Render;
use PHPUnit\Framework\TestCase;

final class GoldenRenderTest extends TestCase
{
    private string $toolRoot;

    protected function setUp(): void
    {
        $this->toolRoot = sys_get_temp_dir() . '/ork-db-golden-' . uniqid('', true);
        $this->copyTree(ORK3_ROOT . '/tools/ork-db', $this->toolRoot);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->toolRoot);
    }

    public function testDeterministicRenderMatchesGoldenSha256(): void
    {
        $render = new Render($this->toolRoot, ORK3_ROOT);
        $output = $this->toolRoot . '/rendered/sandbox.sql';
        $render->run([
            'anchor_date' => '2026-07-07',
            'seed' => 42,
            'output' => $output,
            'deterministic' => true,
        ]);

        $sql = file_get_contents($output);
        $this->assertIsString($sql);
        $hash = 'sha256:' . hash('sha256', $sql);

        $goldenPath = ORK3_ROOT . '/tests/fixtures/ork-db/golden-sandbox.sha256';
        $this->assertFileExists($goldenPath);
        $expected = trim((string) file_get_contents($goldenPath));

        $this->assertSame($expected, $hash, 'Golden render hash drift — update tests/fixtures/ork-db/golden-sandbox.sha256 if intentional');
        $this->assertStringContainsString('-- migration: 2026-05-17-add-entity-banners.sql', $sql);
    }

    private function copyTree(string $source, string $destination): void
    {
        if (!mkdir($destination, 0775, true) && !is_dir($destination)) {
            throw new \RuntimeException("Failed to create directory: {$destination}");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new \RuntimeException("Failed to create directory: {$target}");
                }
                continue;
            }

            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            copy($item->getPathname(), $target);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
