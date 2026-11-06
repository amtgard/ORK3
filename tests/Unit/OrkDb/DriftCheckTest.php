<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\DriftCheck;
use OrkDb\Wiring;
use PHPUnit\Framework\TestCase;

final class DriftCheckTest extends TestCase
{
    public function testStrictPassWhenManifestAndCatalogHashesMatch(): void
    {
        $driftCheck = new DriftCheck(
            new Wiring(ORK3_ROOT . '/tools/ork-db'),
            ORK3_ROOT . '/tools/ork-db',
            ORK3_ROOT,
            null,
            static fn (): bool => false
        );

        $result = $driftCheck->run(true);

        $this->assertTrue($result['passed']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertStringContainsString('migration coverage', implode("\n", $result['lines']));
    }

    public function testStrictFailsWhenCatalogHashMissing(): void
    {
        $toolRoot = sys_get_temp_dir() . '/ork-db-drift-' . uniqid('', true);
        $this->copyTree(ORK3_ROOT . '/tools/ork-db', $toolRoot);

        $fingerprintsPath = $toolRoot . '/manifests/fingerprints.json5';
        $fingerprints = file_get_contents($fingerprintsPath);
        $this->assertIsString($fingerprints);
        file_put_contents(
            $fingerprintsPath,
            str_replace('"award": "sha256:86fc15bba12eb4a76ba7724d8a15b300a0fcb72447542bf8a6ffe37c60779ffb"', '"award": "sha256:deadbeef"', $fingerprints)
        );

        $driftCheck = new DriftCheck(new Wiring($toolRoot), $toolRoot, ORK3_ROOT);
        $result = $driftCheck->run(true);

        $this->assertFalse($result['passed']);
        $this->assertSame(2, $result['exit_code']);

        $this->removeTree($toolRoot);
    }

    public function testStrictFailsWhenCommittedCatalogMissing(): void
    {
        $toolRoot = sys_get_temp_dir() . '/ork-db-drift-missing-' . uniqid('', true);
        $this->copyTree(ORK3_ROOT . '/tools/ork-db', $toolRoot);
        unlink($toolRoot . '/templates/catalogs/award.sql');

        $driftCheck = new DriftCheck(new Wiring($toolRoot), $toolRoot, ORK3_ROOT, null, static fn (): bool => false);
        $result = $driftCheck->run(true);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('award: missing templates/catalogs/award.sql', implode("\n", $result['lines']));

        $this->removeTree($toolRoot);
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
