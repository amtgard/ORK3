<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\Render;
use OrkDb\ValidationException;
use PHPUnit\Framework\TestCase;

final class RenderTest extends TestCase
{
    private string $toolRoot;
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = ORK3_ROOT;
        $this->toolRoot = sys_get_temp_dir() . '/ork-db-render-' . uniqid('', true);
        $this->copyTree(ORK3_ROOT . '/tools/ork-db', $this->toolRoot);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->toolRoot);
    }

    public function testRunProducesDeterministicSandboxSqlWithoutDatabaseConnection(): void
    {
        $render = new Render($this->toolRoot, $this->repoRoot);
        $output = $this->toolRoot . '/rendered/sandbox.sql';

        $first = $render->run([
            'anchor_date' => '2026-07-07',
            'seed' => 42,
            'output' => $output,
            'deterministic' => true,
        ]);
        $second = (new Render($this->toolRoot, $this->repoRoot))->run([
            'anchor_date' => '2026-07-07',
            'seed' => 42,
            'output' => $output . '.2',
            'deterministic' => true,
        ]);

        $sql = file_get_contents($first['output']);
        $sql2 = file_get_contents($second['output']);

        $this->assertIsString($sql);
        $this->assertSame($sql, $sql2);
        $this->assertSame(20, $first['park_count']);
        $this->assertSame(5, $first['kingdom_count']);
        $this->assertStringContainsString('DROP DATABASE IF EXISTS ork_test', $sql);
        $this->assertStringContainsString('Empire of Ashkara', $sql);
        $this->assertStringContainsString('Grand Duchy of Litavia', $sql);
        $this->assertStringContainsString('ork_day_convert', $sql);
        $this->assertStringContainsString('_ork_canary_test', $sql);
        $this->assertStringContainsString('INSERT INTO `ork_award`', $sql);
        $this->assertStringContainsString('INSERT INTO `ork_kingdomaward`', $sql);
        $this->assertStringContainsString('INSERT INTO `ork_configuration`', $sql);
        $this->assertStringContainsString('-- migration: 2026-05-17-add-entity-banners.sql', $sql);
        $this->assertStringContainsString('-- baseline schema gaps', $sql);
        $this->assertLessThan(
            strpos($sql, 'INSERT INTO `ork_park`'),
            strpos($sql, 'INSERT INTO `ork_kingdom`')
        );
    }

    public function testRunFailsWhenExtractCatalogMissing(): void
    {
        unlink($this->toolRoot . '/extracted/award.sql');
        $render = new Render($this->toolRoot, $this->repoRoot);

        $this->expectException(ValidationException::class);
        $render->run(['deterministic' => true, 'seed' => 42, 'anchor_date' => '2026-07-07']);
    }

    public function testExpectedParkCountForSeedMatchesFingerprint(): void
    {
        $render = new Render($this->toolRoot, $this->repoRoot);
        $this->assertSame(20, $render->expectedParkCountForSeed(42));
    }

    public function testAttendanceDatesStayInsideThreeYearWindow(): void
    {
        $render = new Render($this->toolRoot, $this->repoRoot);
        $output = $this->toolRoot . '/rendered/window.sql';
        $render->run([
            'anchor_date' => '2026-07-07',
            'seed' => 42,
            'output' => $output,
            'deterministic' => true,
        ]);

        $sql = (string) file_get_contents($output);
        preg_match_all("/INSERT INTO `ork_attendance`[^;]+'([0-9]{4}-[0-9]{2}-[0-9]{2})'/", $sql, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $date) {
            $this->assertGreaterThanOrEqual('2023-07-07', $date);
            $this->assertLessThanOrEqual('2026-07-07', $date);
        }
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
