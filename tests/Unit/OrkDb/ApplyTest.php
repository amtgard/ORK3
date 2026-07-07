<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\Apply;
use OrkDb\Render;
use OrkDb\Validate;
use OrkDb\Wiring;
use PDO;
use PHPUnit\Framework\TestCase;

final class ApplyTest extends TestCase
{
    public function testRunAbortsWhenPreApplyValidationFails(): void
    {
        $toolRoot = $this->makeTempToolRoot();
        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_prod (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_prod VALUES (1, 'ORK3_PROD_CANARY_v1');"
        );

        $wiring = new Wiring($toolRoot);
        $validate = new Validate($wiring, $toolRoot, fn (): PDO => $pdo);
        $render = new Render($toolRoot, ORK3_ROOT);
        $apply = new Apply($wiring, $validate, $render, ORK3_ROOT);

        $result = $apply->run(['yes' => true]);
        $this->removeTree($toolRoot);

        $this->assertSame(2, $result['exit_code']);
        $this->assertStringContainsString('pre-apply validation failed', implode("\n", $result['lines']));
    }

    public function testRunLoadsRenderedSqlIntoSandboxWhenValidationPasses(): void
    {
        $toolRoot = $this->makeTempToolRoot();
        $sqlPath = sys_get_temp_dir() . '/ork-db-apply-' . uniqid('', true) . '.sql';
        file_put_contents($sqlPath, "SELECT 1;\n");

        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );
        $this->seedPostApplyTables($pdo);

        $wiring = new Wiring($toolRoot);
        $validate = new Validate($wiring, $toolRoot, fn (): PDO => $pdo);
        $render = new Render($toolRoot, ORK3_ROOT);

        $loaded = false;
        $apply = new Apply(
            $wiring,
            $validate,
            $render,
            ORK3_ROOT,
            static function (array $command, ?string $inputFile) use (&$loaded, $sqlPath): void {
                $loaded = $command[0] === 'docker' && $inputFile === $sqlPath;
            }
        );

        $result = $apply->run(['yes' => true, 'sql' => $sqlPath]);
        unlink($sqlPath);
        $this->removeTree($toolRoot);

        $this->assertTrue($loaded);
        $this->assertSame(0, $result['exit_code']);
    }

    public function testRunUsesRenderOutputWhenSqlNotProvided(): void
    {
        $toolRoot = sys_get_temp_dir() . '/ork-db-apply-render-' . uniqid('', true);
        $this->copyTree(ORK3_ROOT . '/tools/ork-db', $toolRoot);

        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );
        $this->seedPostApplyTables($pdo);

        $wiring = new Wiring($toolRoot);
        $validate = new Validate($wiring, $toolRoot, fn (): PDO => $pdo);
        $render = new Render($toolRoot, ORK3_ROOT);

        $loaded = false;
        $apply = new Apply(
            $wiring,
            $validate,
            $render,
            ORK3_ROOT,
            static function (array $command, ?string $inputFile) use (&$loaded): void {
                $loaded = $command[0] === 'docker' && is_string($inputFile) && is_readable($inputFile);
            }
        );

        $result = $apply->run(['yes' => true]);
        $this->removeTree($toolRoot);

        $this->assertTrue($loaded);
        $this->assertSame(0, $result['exit_code']);
        $metadata = \OrkDb\LastRender::read(ORK3_ROOT . '/tools/ork-db');
        $this->assertNotNull($metadata);
        $this->assertSame(42, $metadata['content_seed']);
    }

    public function testRunRecordsLastRenderMetadataFromProvidedSqlHeader(): void
    {
        $toolRoot = $this->makeTempToolRoot();
        $sqlPath = sys_get_temp_dir() . '/ork-db-apply-header-' . uniqid('', true) . '.sql';
        file_put_contents($sqlPath, implode("\n", [
            '-- anchor_date: 2026-06-01',
            '-- content_seed: 99',
            'SELECT 1;',
        ]));

        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );
        $this->seedPostApplyTables($pdo);

        $wiring = new Wiring($toolRoot);
        $validate = new Validate($wiring, $toolRoot, fn (): PDO => $pdo);
        $render = new Render($toolRoot, ORK3_ROOT);
        $apply = new Apply(
            $wiring,
            $validate,
            $render,
            ORK3_ROOT,
            static function (): void {
            }
        );

        $result = $apply->run(['yes' => true, 'sql' => $sqlPath]);
        unlink($sqlPath);
        $this->removeTree($toolRoot);

        $this->assertSame(0, $result['exit_code']);
        $metadata = \OrkDb\LastRender::read(ORK3_ROOT . '/tools/ork-db');
        $this->assertNotNull($metadata);
        $this->assertSame('2026-06-01', $metadata['anchor_date']);
        $this->assertSame(99, $metadata['content_seed']);
    }

    private function makeTempToolRoot(): string
    {
        $toolRoot = sys_get_temp_dir() . '/ork-db-apply-tool-' . uniqid('', true);
        $this->copyTree(ORK3_ROOT . '/tools/ork-db', $toolRoot);

        return $toolRoot;
    }

    private function makeValidateSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function seedPostApplyTables(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE ork_kingdom (
                kingdom_id INTEGER PRIMARY KEY,
                name TEXT,
                abbreviation TEXT,
                parent_kingdom_id INTEGER
            )'
        );
        $kingdoms = [
            [9001, 'Empire of Ashkara', 'EAK', 0],
            [9002, 'Kingdom of Meridia', 'KMR', 0],
            [9003, 'Sultanate of Zanzibarr', 'SZ', 0],
            [9004, 'Tsardom of Vyatka', 'TVK', 0],
            [9005, 'Grand Duchy of Litavia', 'GDL', 9001],
        ];
        foreach ($kingdoms as $row) {
            $pdo->exec(sprintf(
                'INSERT INTO ork_kingdom VALUES (%d, %s, %s, %d)',
                $row[0],
                $pdo->quote($row[1]),
                $pdo->quote($row[2]),
                $row[3]
            ));
        }

        $pdo->exec('CREATE TABLE ork_park (park_id INTEGER PRIMARY KEY, kingdom_id INTEGER)');
        $parkId = 1;
        foreach ([9001 => 4, 9002 => 4, 9003 => 3, 9004 => 6, 9005 => 3] as $kingdomId => $count) {
            for ($i = 0; $i < $count; $i++) {
                $pdo->exec('INSERT INTO ork_park VALUES (' . $parkId++ . ', ' . $kingdomId . ')');
            }
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
