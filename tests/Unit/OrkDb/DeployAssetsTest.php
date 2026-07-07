<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\DeployAssets;
use OrkDb\ValidationException;
use PHPUnit\Framework\TestCase;

final class DeployAssetsTest extends TestCase
{
    private string $toolRoot;
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->toolRoot = sys_get_temp_dir() . '/ork-db-deploy-assets-tool-' . uniqid('', true);
        $this->repoRoot = sys_get_temp_dir() . '/ork-db-deploy-assets-repo-' . uniqid('', true);
        $this->copyTree(ORK3_ROOT . '/tools/ork-db', $this->toolRoot);
        mkdir($this->repoRoot . '/assets', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->toolRoot);
        $this->removeTree($this->repoRoot);
    }

    public function testRunCopiesGeneratedAssetsIntoAssetsTree(): void
    {
        $deploy = new DeployAssets($this->toolRoot, $this->repoRoot);
        $result = $deploy->run(['verify_manifest' => true]);

        $this->assertSame(5, $result['kingdom_count']);
        $this->assertSame(20, $result['park_count']);
        $this->assertGreaterThan(70, $result['player_heraldry_count']);
        $this->assertSame(1, $result['player_portrait_count']);
        $this->assertFileExists($this->repoRoot . '/assets/heraldry/kingdom/100001.jpg');
        $this->assertFileExists($this->repoRoot . '/assets/heraldry/park/1000001.jpg');
        $this->assertFileExists($this->repoRoot . '/assets/heraldry/player/000000.png');
        $this->assertFileExists($this->repoRoot . '/assets/players/000000.png');
        $this->assertTrue($result['manifest_ok']);
    }

    public function testRunUsesCustomSourceAndAssetsRoots(): void
    {
        $customSource = $this->toolRoot . '/generated-assets';
        $customAssets = $this->repoRoot . '/custom-assets';
        mkdir($customAssets, 0775, true);

        $deploy = new DeployAssets($this->toolRoot, $this->repoRoot);
        $result = $deploy->run([
            'source_root' => $customSource,
            'assets_root' => $customAssets,
            'verify_manifest' => false,
        ]);

        $this->assertSame($customAssets, $result['assets_root']);
        $this->assertFileExists($customAssets . '/heraldry/kingdom/100005.jpg');
        $this->assertGreaterThan(50, count($result['files']));
    }

    public function testRunFailsWhenGeneratedAssetsMissing(): void
    {
        $emptyToolRoot = sys_get_temp_dir() . '/ork-db-deploy-empty-' . uniqid('', true);
        mkdir($emptyToolRoot, 0775, true);

        $deploy = new DeployAssets($emptyToolRoot, $this->repoRoot);

        try {
            $this->expectException(ValidationException::class);
            $deploy->run(['verify_manifest' => false]);
        } finally {
            rmdir($emptyToolRoot);
        }
    }

    public function testRunFailsWhenManifestChecksumMismatch(): void
    {
        $deploy = new DeployAssets($this->toolRoot, $this->repoRoot);
        $badSource = $this->toolRoot . '/bad-generated';
        $this->copyTree($this->toolRoot . '/generated-assets', $badSource);
        file_put_contents($badSource . '/kingdom/100001.jpg', 'corrupt');

        $this->expectException(ValidationException::class);
        $deploy->run([
            'source_root' => $badSource,
            'verify_manifest' => true,
        ]);
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
