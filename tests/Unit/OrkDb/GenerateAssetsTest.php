<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\GenerateAssets;
use OrkDb\Render;
use PHPUnit\Framework\TestCase;

final class GenerateAssetsTest extends TestCase
{
    private string $toolRoot;

    protected function setUp(): void
    {
        $this->toolRoot = sys_get_temp_dir() . '/ork-db-generate-' . uniqid('', true);
        $this->copyTree(ORK3_ROOT . '/tools/ork-db', $this->toolRoot);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->toolRoot);
    }

    public function testRunWritesKingdomParkAndPlayerAssetsWithNewIdScheme(): void
    {
        $outputRoot = $this->toolRoot . '/generated-assets';
        $render = new Render($this->toolRoot, ORK3_ROOT);
        $generator = new GenerateAssets($this->toolRoot, $render, function (string $svg, string $outputPath): void {
            if (!is_dir(dirname($outputPath))) {
                mkdir(dirname($outputPath), 0775, true);
            }
            file_put_contents($outputPath, 'PNG:' . md5($svg));
        });

        $result = $generator->run([
            'seed' => 42,
            'output_root' => $outputRoot,
        ]);

        $heraldryIds = $render->fakeMundaneHeraldryIdsForSeed(42);
        $allFakeIds = $render->fakeMundaneIdsForSeed(42);

        $this->assertSame(5, $result['kingdom_count']);
        $this->assertSame(20, $result['park_count']);
        $this->assertFileExists($outputRoot . '/kingdom/100001.jpg');
        $this->assertFileExists($outputRoot . '/park/1000001.jpg');
        $this->assertFileExists($outputRoot . '/park/1000403.jpg');
        $this->assertFileExists($outputRoot . '/player/000000.png');
        $this->assertFileExists($outputRoot . '/players/000000.png');
        foreach ($heraldryIds as $mundaneId) {
            $this->assertFileExists($outputRoot . '/player/' . sprintf('%06d', $mundaneId) . '.jpg');
        }
        $this->assertFileDoesNotExist($outputRoot . '/players/100000000.jpg');
        $this->assertNotEmpty($heraldryIds);
        $this->assertLessThan(count($allFakeIds), count($heraldryIds));
        $this->assertGreaterThan(count($allFakeIds) * 0.2, count($heraldryIds));
        $this->assertLessThan(count($allFakeIds) * 0.4, count($heraldryIds));
        $this->assertFileExists($this->toolRoot . '/manifests/asset-manifest.json5');
        $this->assertGreaterThan(25, count($result['files']));
    }

    public function testBuildKingdomSvgIncludesFieldColor(): void
    {
        $render = new Render($this->toolRoot, ORK3_ROOT);
        $generator = new GenerateAssets($this->toolRoot, $render);
        $svg = $generator->buildKingdomSvg([
            'field' => '#8B0000',
            'charge' => 'eagle',
            'charge_color' => '#FFD700',
        ]);

        $this->assertStringContainsString('#8B0000', $svg);
        $this->assertStringContainsString('<svg', $svg);
    }

    public function testBuildParkSvgUsesInitialBadge(): void
    {
        $render = new Render($this->toolRoot, ORK3_ROOT);
        $generator = new GenerateAssets($this->toolRoot, $render);
        $svg = $generator->buildParkSvg([
            'field' => '#003366',
            'charge' => 'lion',
            'charge_color' => '#FFD700',
        ], 'SIL');

        $this->assertStringContainsString('>S<', $svg);
    }

    public function testBuildKingdomSvgSupportsAllChargeTypes(): void
    {
        $render = new Render($this->toolRoot, ORK3_ROOT);
        $generator = new GenerateAssets($this->toolRoot, $render);

        foreach (['eagle', 'lion', 'crescent', 'bear', 'cross', 'unknown'] as $charge) {
            $svg = $generator->buildKingdomSvg([
                'field' => '#111111',
                'charge' => $charge,
                'charge_color' => '#FFD700',
                'border' => '#CCCCCC',
            ]);
            $this->assertStringContainsString('#111111', $svg);
        }
    }

    public function testReadPlayerPhoenixSvgLoadsTemplate(): void
    {
        $render = new Render($this->toolRoot, ORK3_ROOT);
        $generator = new GenerateAssets($this->toolRoot, $render);
        $svg = $generator->readPlayerPhoenixSvg();

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('viewBox="0 0 256 256"', $svg);
    }

    public function testRunUsesRsvgConverterWhenAvailable(): void
    {
        if (trim((string) shell_exec('command -v rsvg-convert')) === '') {
            $this->markTestSkipped('rsvg-convert not available');
        }

        $outputRoot = $this->toolRoot . '/generated-rsvg';
        $render = new Render($this->toolRoot, ORK3_ROOT);
        $generator = new GenerateAssets($this->toolRoot, $render);
        $result = $generator->run([
            'seed' => 42,
            'output_root' => $outputRoot,
        ]);

        $this->assertFileExists($outputRoot . '/kingdom/100001.jpg');
        $this->assertGreaterThan(100, (int) filesize($outputRoot . '/kingdom/100001.jpg'));
        $this->assertSame(5, $result['kingdom_count']);
    }

    public function testWriteJpegUsesInjectedConverter(): void
    {
        $render = new Render($this->toolRoot, ORK3_ROOT);
        $generator = new GenerateAssets($this->toolRoot, $render, function (string $svg, string $outputPath): void {
            file_put_contents($outputPath, 'JPEG:' . md5($svg));
        });
        $path = $this->toolRoot . '/jpeg-test.jpg';
        $method = new \ReflectionMethod(GenerateAssets::class, 'writeJpeg');
        $method->setAccessible(true);
        $method->invoke($generator, '<svg xmlns="http://www.w3.org/2000/svg"></svg>', $path);

        $this->assertFileExists($path);
        $this->assertStringStartsWith('JPEG:', (string) file_get_contents($path));
    }

    public function testBuildParkSvgUsesPWhenAbbreviationEmpty(): void
    {
        $render = new Render($this->toolRoot, ORK3_ROOT);
        $generator = new GenerateAssets($this->toolRoot, $render);
        $svg = $generator->buildParkSvg(['field' => '#003366'], '');

        $this->assertStringContainsString('>P<', $svg);
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
