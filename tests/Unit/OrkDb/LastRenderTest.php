<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\LastRender;
use PHPUnit\Framework\TestCase;

final class LastRenderTest extends TestCase
{
    private string $toolRoot;

    protected function setUp(): void
    {
        $this->toolRoot = sys_get_temp_dir() . '/ork-db-last-render-' . uniqid('', true);
        mkdir($this->toolRoot . '/rendered', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->toolRoot);
    }

    public function testWriteAndReadRoundTrip(): void
    {
        LastRender::write($this->toolRoot, '2026-07-07', 42);

        $metadata = LastRender::read($this->toolRoot);

        $this->assertNotNull($metadata);
        $this->assertSame('2026-07-07', $metadata['anchor_date']);
        $this->assertSame(42, $metadata['content_seed']);
        $this->assertNotSame('', $metadata['rendered_at']);
        $this->assertSame(
            $this->toolRoot . '/rendered/.last-render.json',
            LastRender::metadataPath($this->toolRoot)
        );
    }

    public function testReadReturnsNullWhenMissingOrInvalid(): void
    {
        $this->assertNull(LastRender::read($this->toolRoot));

        file_put_contents(LastRender::metadataPath($this->toolRoot), '{"anchor_date":"2026-07-07"}');
        $this->assertNull(LastRender::read($this->toolRoot));

        file_put_contents(LastRender::metadataPath($this->toolRoot), 'not-json');
        $this->assertNull(LastRender::read($this->toolRoot));
    }

    public function testIsStaleWhenMetadataMissingOrBeforeToday(): void
    {
        $today = new \DateTimeImmutable('2026-07-07', new \DateTimeZone(LastRender::TIMEZONE));

        $this->assertTrue(LastRender::isStale($this->toolRoot, false, $today));

        LastRender::write($this->toolRoot, '2026-07-06', 42);
        $this->assertTrue(LastRender::isStale($this->toolRoot, false, $today));

        LastRender::write($this->toolRoot, '2026-07-07', 42);
        $this->assertFalse(LastRender::isStale($this->toolRoot, false, $today));
    }

    public function testIsStaleHonorsForceRefresh(): void
    {
        $today = new \DateTimeImmutable('2026-07-07', new \DateTimeZone(LastRender::TIMEZONE));
        LastRender::write($this->toolRoot, '2026-07-07', 42);

        $this->assertTrue(LastRender::isStale($this->toolRoot, true, $today));
    }

    public function testParseAnchorDateAndContentSeedFromSqlHeader(): void
    {
        $sqlPath = $this->toolRoot . '/sample.sql';
        file_put_contents($sqlPath, implode("\n", [
            '-- ORK3 Test Database Render',
            '-- anchor_date: 2026-07-07',
            '-- content_seed: 42',
            'SELECT 1;',
        ]));

        $this->assertSame('2026-07-07', LastRender::parseAnchorDateFromSql($sqlPath));
        $this->assertSame(42, LastRender::parseContentSeedFromSql($sqlPath));
        $this->assertNull(LastRender::parseAnchorDateFromSql($this->toolRoot . '/missing.sql'));
    }

    public function testParseReturnsNullWhenHeaderFieldsAbsent(): void
    {
        $sqlPath = $this->toolRoot . '/headerless.sql';
        file_put_contents($sqlPath, "SELECT 1;\n");

        $this->assertNull(LastRender::parseAnchorDateFromSql($sqlPath));
        $this->assertNull(LastRender::parseContentSeedFromSql($sqlPath));
    }

    public function testWriteCreatesRenderedDirectoryWhenMissing(): void
    {
        $toolRoot = sys_get_temp_dir() . '/ork-db-last-render-nodir-' . uniqid('', true);
        mkdir($toolRoot, 0775, true);

        LastRender::write($toolRoot, '2026-07-07', 7);

        $this->assertFileExists(LastRender::metadataPath($toolRoot));
        $this->removeTree($toolRoot);
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
