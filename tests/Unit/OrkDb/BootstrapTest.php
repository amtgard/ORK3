<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\Apply;
use OrkDb\Bootstrap;
use OrkDb\Extract;
use OrkDb\Init;
use OrkDb\Render;
use OrkDb\Validate;
use OrkDb\Wiring;
use PDO;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    public function testExtractArtifactsFreshRequiresManifestAndFiles(): void
    {
        $toolRoot = $this->copyToolRoot();
        $this->removeTree($toolRoot . '/extracted');
        mkdir($toolRoot . '/extracted', 0775, true);

        $wiring = new Wiring($toolRoot);
        $validate = new Validate($wiring, $toolRoot, fn (): PDO => $this->makeValidateSqlitePdo());
        $bootstrap = new Bootstrap(
            $validate,
            new Init($wiring, $validate, ORK3_ROOT),
            new Extract($wiring, $toolRoot),
            new Apply($wiring, $validate, new Render($toolRoot, ORK3_ROOT), ORK3_ROOT),
            $toolRoot
        );

        $this->assertFalse($bootstrap->extractArtifactsFresh());

        file_put_contents(
            $toolRoot . '/extracted/manifest.json',
            json_encode(['files' => ['award.sql']], JSON_THROW_ON_ERROR)
        );
        $this->assertFalse($bootstrap->extractArtifactsFresh());

        file_put_contents($toolRoot . '/extracted/award.sql', "SELECT 1;\n");
        $this->assertTrue($bootstrap->extractArtifactsFresh());

        $this->removeTree($toolRoot);
    }

    public function testTestCanaryPresentReturnsFalseWhenMissing(): void
    {
        $toolRoot = $this->copyToolRoot();
        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): PDO => $this->makeValidateSqlitePdo());
        $this->removeTree($toolRoot);

        $this->assertFalse($validate->testCanaryPresent());
    }

    public function testTestCanaryPresentReturnsTrueWhenValid(): void
    {
        $toolRoot = $this->copyToolRoot();
        $pdo = $this->makeValidateSqlitePdo();
        $this->seedPreApplySandbox($pdo);
        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): PDO => $pdo);
        $this->removeTree($toolRoot);

        $this->assertTrue($validate->testCanaryPresent());
    }

    public function testHasTestKingdomRowsReturnsFalseWhenMissing(): void
    {
        $toolRoot = $this->copyToolRoot();
        $pdo = $this->makeValidateSqlitePdo();
        $this->seedPreApplySandbox($pdo);
        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): PDO => $pdo);
        $this->removeTree($toolRoot);

        $this->assertFalse($validate->hasTestKingdomRows());
    }

    public function testHasTestKingdomRowsReturnsTrueWhenPresent(): void
    {
        $toolRoot = $this->copyToolRoot();
        $pdo = $this->makeValidateSqlitePdo();
        $this->seedPreApplySandbox($pdo);
        $pdo->exec(
            'CREATE TABLE ork_kingdom (kingdom_id INTEGER PRIMARY KEY, name TEXT, abbreviation TEXT, parent_kingdom_id INTEGER);'
            . 'INSERT INTO ork_kingdom VALUES (9001, "Empire of Ashkara", "EAK", 0);'
        );
        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): PDO => $pdo);
        $this->removeTree($toolRoot);

        $this->assertTrue($validate->hasTestKingdomRows());
    }

    public function testHasTestKingdomRowsReturnsFalseWhenConnectionFails(): void
    {
        $toolRoot = $this->copyToolRoot();
        $validate = new Validate(new Wiring($toolRoot), $toolRoot, function (): PDO {
            throw new \PDOException('connection failed');
        });
        $this->removeTree($toolRoot);

        $this->assertFalse($validate->hasTestKingdomRows());
    }

    public function testRunAbortsWhenApplyFails(): void
    {
        $toolRoot = $this->copyToolRoot();
        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_prod (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_prod VALUES (1, 'ORK3_PROD_CANARY_v1');"
            . 'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );

        $wiring = new Wiring($toolRoot);
        $validate = new Validate($wiring, $toolRoot, fn (): PDO => $pdo);
        $bootstrap = new Bootstrap(
            $validate,
            new Init($wiring, $validate, ORK3_ROOT),
            new Extract($wiring, $toolRoot, fn (): PDO => $pdo),
            new Apply($wiring, $validate, new Render($toolRoot, ORK3_ROOT), ORK3_ROOT),
            $toolRoot
        );

        $result = $bootstrap->run(['yes' => true, 'skip_extract' => true]);
        $this->removeTree($toolRoot);

        $this->assertSame(2, $result['exit_code']);
        $this->assertStringContainsString('ABORT — apply failed', implode("\n", $result['lines']));
    }

    private function copyToolRoot(): string
    {
        $toolRoot = sys_get_temp_dir() . '/ork-db-bootstrap-tool-' . uniqid('', true);
        $this->copyTree(ORK3_ROOT . '/tools/ork-db', $toolRoot);

        return $toolRoot;
    }

    private function makeValidateSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function seedPreApplySandbox(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );
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
            copy((string) $item->getPathname(), $target);
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
