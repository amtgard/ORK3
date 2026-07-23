<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\Apply;
use OrkDb\Bootstrap;
use OrkDb\DeployAssets;
use OrkDb\DeploySandbox;
use OrkDb\DeploymentTier;
use OrkDb\Extract;
use OrkDb\Init;
use OrkDb\LastRender;
use OrkDb\Render;
use OrkDb\UseProfile;
use OrkDb\Validate;
use OrkDb\Wiring;
use PDO;
use PHPUnit\Framework\TestCase;

final class DeploySandboxTest extends TestCase
{
    public function testPreflightFailsWhenMirrorUnreachable(): void
    {
        $deploy = $this->makeDeploySandbox(
            tier: new DeploymentTier(
                new Wiring(ORK3_ROOT . '/tools/ork-db'),
                ORK3_ROOT,
                static fn (string $host, int $port): bool => $port === 19307
            )
        );

        $result = $deploy->preflight();

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('mirror unreachable', implode("\n", $result['lines']));
        $this->assertStringContainsString('ork3db', implode("\n", $result['lines']));
    }

    public function testPreflightFailsWhenSandboxOrMirrorUnreachable(): void
    {
        $deploy = $this->makeDeploySandbox(
            tier: new DeploymentTier(
                new Wiring(ORK3_ROOT . '/tools/ork-db'),
                ORK3_ROOT,
                static fn (string $host, int $port): bool => $port === 19306
            )
        );

        $result = $deploy->preflight();

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('sandbox unreachable', implode("\n", $result['lines']));
        $this->assertStringContainsString('ork3testdb', implode("\n", $result['lines']));
    }

    public function testPreflightPassesWhenBothEndpointsReachable(): void
    {
        $deploy = $this->makeDeploySandbox();

        $result = $deploy->preflight();

        $this->assertTrue($result['passed']);
        $this->assertStringContainsString('sandbox reachable', implode("\n", $result['lines']));
        $this->assertStringContainsString('mirror reachable', implode("\n", $result['lines']));
    }

    public function testRemediationHintsCoverKnownValidationFailures(): void
    {
        $deploy = $this->makeDeploySandbox();

        $hints = $deploy->remediationHints([
            'Connection:   FAIL (timeout)',
            'Prod canary:  FAIL (_ork_canary_prod row present)',
            'Test canary:  FAIL (missing or invalid marker)',
            'Kingdoms:     FAIL (0/5 rows)',
            'Blocklist:    FAIL (real kingdom name: Neverwinter)',
        ]);

        $text = implode("\n", $hints);
        $this->assertStringContainsString('ork3testdb', $text);
        $this->assertStringContainsString('ABORT', $text);
        $this->assertStringContainsString('bin/ork-db init', $text);
        $this->assertStringContainsString('bootstrap --yes', $text);
        $this->assertStringContainsString('sandbox.sql', $text);
    }

    public function testRemediationHintsUseForceRefreshAfterPostApply(): void
    {
        $deploy = $this->makeDeploySandbox();
        $hints = $deploy->remediationHints(['Kingdoms:     FAIL (3/5 rows)'], true);

        $this->assertStringContainsString('--force-refresh', implode("\n", $hints));
    }

    public function testRemediationHintsFallbackToValidateCommand(): void
    {
        $deploy = $this->makeDeploySandbox();
        $hints = $deploy->remediationHints(['RESULT:       ABORT — refusing wipe/replay']);

        $this->assertStringContainsString('bin/ork-db validate --mode pre-apply', implode("\n", $hints));
    }

    public function testRemediationHintsCoverAssetFailures(): void
    {
        $deploy = $this->makeDeploySandbox();
        $hints = $deploy->remediationHints(['Assets:       FAIL (missing 3 files: kingdom/100001)'], true);

        $text = implode("\n", $hints);
        $this->assertStringContainsString('--force-refresh', $text);
        $this->assertStringContainsString('generate-assets && bin/ork-db deploy-assets', $text);
    }

    public function testRunAbortsOnPreflightFailure(): void
    {
        $deploy = $this->makeDeploySandbox(
            tier: new DeploymentTier(
                new Wiring(ORK3_ROOT . '/tools/ork-db'),
                ORK3_ROOT,
                static fn (): bool => false
            )
        );

        $result = $deploy->run();

        $this->assertSame(2, $result['exit_code']);
        $this->assertStringContainsString('ABORT — preflight failed', implode("\n", $result['lines']));
    }

    public function testRunCompletesWhenSandboxAlreadyCurrent(): void
    {
        $toolRoot = $this->copyToolRoot();
        $pdo = $this->makePostApplyPdo();
        LastRender::write($toolRoot, '2026-07-07', 42);

        $deploy = $this->makeDeploySandbox(
            toolRoot: $toolRoot,
            pdo: $pdo,
            clock: new \DateTimeImmutable('2026-07-07', new \DateTimeZone(LastRender::TIMEZONE)),
        );

        $result = $deploy->run(['yes' => true]);
        $this->removeTree($toolRoot);

        $output = implode("\n", $result['lines']);
        $this->assertSame(0, $result['exit_code'], $output);
        $this->assertStringContainsString('init skipped', $output);
        $this->assertStringContainsString('bootstrap skipped', $output);
        $this->assertStringContainsString('daily refresh skipped', $output);
        $this->assertStringContainsString('deploy-assets →', $output);
        $this->assertStringContainsString('asset manifest ok', $output);
        $this->assertStringContainsString('Profile:      dev', $output);
        $this->assertStringContainsString('POST-APPLY VALIDATION PASSED', $output);
        $this->assertStringContainsString('Deploy:       complete', $output);
    }

    public function testRunRunsBootstrapWhenKingdomRowsMissing(): void
    {
        $toolRoot = $this->copyToolRoot();
        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );

        $wiring = new Wiring($toolRoot);
        $validate = new Validate($wiring, $toolRoot, fn (): PDO => $pdo);
        $render = new Render($toolRoot, ORK3_ROOT);
        $apply = new Apply(
            $wiring,
            $validate,
            $render,
            ORK3_ROOT,
            function () use ($pdo): void {
                $this->seedPostApplyTables($pdo);
            }
        );
        $init = new Init($wiring, $validate, ORK3_ROOT);
        $extract = new Extract($wiring, $toolRoot, fn (): PDO => $pdo);
        $bootstrap = new Bootstrap($validate, $init, $extract, $apply, $toolRoot);

        putenv('ENVIRONMENT=DEV');
        $repoRoot = dirname($toolRoot) . '/repo-' . uniqid('', true);
        mkdir($repoRoot . '/assets', 0775, true);
        $deployAssets = new DeployAssets($toolRoot, $repoRoot);
        $deploy = new DeploySandbox(
            $this->makeLocalTier($repoRoot),
            $wiring,
            $validate,
            $init,
            $bootstrap,
            $extract,
            $render,
            $apply,
            new UseProfile($this->makeLocalTier($repoRoot), $repoRoot, static fn (): int => 0),
            $deployAssets,
            $toolRoot,
            new \DateTimeImmutable('2026-07-07', new \DateTimeZone(LastRender::TIMEZONE)),
            $this->seedStub(),
        );

        $result = $deploy->run(['yes' => true, 'skip_use_dev' => true]);
        putenv('ENVIRONMENT');
        $this->removeTree($toolRoot);

        $output = implode("\n", $result['lines']);
        $this->assertSame(0, $result['exit_code'], $output);
        $this->assertStringContainsString('running bootstrap', $output);
        $this->assertStringContainsString('Bootstrap:    complete', $output);
    }

    public function testRunAbortsWhenBootstrapApplyFails(): void
    {
        $toolRoot = $this->copyToolRoot();
        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_prod (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_prod VALUES (1, 'ORK3_PROD_CANARY_v1');"
            . 'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );

        $deploy = $this->makeDeploySandbox(toolRoot: $toolRoot, pdo: $pdo);
        $result = $deploy->run(['yes' => true]);
        $this->removeTree($toolRoot);

        $this->assertSame(2, $result['exit_code']);
        $this->assertStringContainsString('ABORT — bootstrap failed', implode("\n", $result['lines']));
    }

    public function testRunHaltsWhenValidationGateFails(): void
    {
        $toolRoot = $this->copyToolRoot();
        $pdo = $this->makePostApplyPdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_prod (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_prod VALUES (1, 'ORK3_PROD_CANARY_v1');"
        );

        putenv('ENVIRONMENT=DEV');
        $deploy = $this->makeDeploySandbox(toolRoot: $toolRoot, pdo: $pdo);
        $result = $deploy->run();
        putenv('ENVIRONMENT');
        $this->removeTree($toolRoot);

        $this->assertSame(2, $result['exit_code']);
        $this->assertStringContainsString('ABORT — validation failed', implode("\n", $result['lines']));
        $this->assertStringContainsString('ABORT — sandbox target looks like production', implode("\n", $result['lines']));
    }

    public function testRunSkipsUseDevWhenRequested(): void
    {
        $toolRoot = $this->copyToolRoot();
        $pdo = $this->makePostApplyPdo();
        LastRender::write($toolRoot, '2026-07-07', 42);

        $deploy = $this->makeDeploySandbox(
            toolRoot: $toolRoot,
            pdo: $pdo,
            clock: new \DateTimeImmutable('2026-07-07', new \DateTimeZone(LastRender::TIMEZONE)),
        );

        $result = $deploy->run(['yes' => true, 'skip_use_dev' => true]);
        $this->removeTree($toolRoot);

        $output = implode("\n", $result['lines']);
        $this->assertSame(0, $result['exit_code'], $output);
        $this->assertStringContainsString('use dev skipped', $output);
        $this->assertStringNotContainsString('Profile:      dev', $output);
    }

    public function testRunAbortsWhenPostApplyValidationFailsAfterDailyRefresh(): void
    {
        if (!ork3_mirror_db_available()) {
            $this->markTestSkipped('Mirror database is not available.');
        }

        $toolRoot = $this->copyToolRoot();
        $pdo = $this->makePostApplyPdo();
        LastRender::write($toolRoot, '2026-07-06', 42);

        $wiring = new Wiring($toolRoot);
        $validate = new Validate($wiring, $toolRoot, fn (): PDO => $pdo);
        $render = new Render($toolRoot, ORK3_ROOT);
        $extract = new Extract($wiring, $toolRoot);
        $apply = new Apply(
            $wiring,
            $validate,
            $render,
            ORK3_ROOT,
            function () use ($pdo): void {
                $pdo->exec('DELETE FROM ork_kingdom');
            }
        );

        putenv('ENVIRONMENT=DEV');
        $repoRoot = dirname($toolRoot) . '/repo-' . uniqid('', true);
        mkdir($repoRoot . '/assets', 0775, true);
        $deployAssets = new DeployAssets($toolRoot, $repoRoot);
        $deploy = new DeploySandbox(
            $this->makeLocalTier($repoRoot),
            $wiring,
            $validate,
            new Init($wiring, $validate, ORK3_ROOT),
            new Bootstrap($validate, new Init($wiring, $validate, ORK3_ROOT), $extract, $apply, $toolRoot),
            $extract,
            $render,
            $apply,
            new UseProfile($this->makeLocalTier($repoRoot), $repoRoot, static fn (): int => 0),
            $deployAssets,
            $toolRoot,
            new \DateTimeImmutable('2026-07-07', new \DateTimeZone(LastRender::TIMEZONE)),
            $this->seedStub(),
        );

        $result = $deploy->run(['yes' => true, 'skip_use_dev' => true]);
        putenv('ENVIRONMENT');
        $this->removeTree($toolRoot);

        $output = implode("\n", $result['lines']);
        $this->assertSame(2, $result['exit_code'], $output);
        $this->assertStringContainsString('ABORT — daily refresh apply failed', $output);
        $this->assertStringContainsString('Kingdoms:     FAIL', $output);
    }

    public function testRunForceRefreshAttemptsDailyPipeline(): void
    {
        if (!ork3_mirror_db_available()) {
            $this->markTestSkipped('Mirror database is not available.');
        }

        $toolRoot = $this->copyToolRoot();
        $pdo = $this->makePostApplyPdo();
        LastRender::write($toolRoot, '2026-07-07', 42);

        $wiring = new Wiring($toolRoot);
        $validate = new Validate($wiring, $toolRoot, fn (): PDO => $pdo);
        $render = new Render($toolRoot, ORK3_ROOT);
        $extract = new Extract($wiring, $toolRoot);
        $apply = new Apply(
            $wiring,
            $validate,
            $render,
            ORK3_ROOT,
            function () use ($pdo): void {
                $this->seedPostApplyTables($pdo);
            }
        );

        putenv('ENVIRONMENT=DEV');
        $repoRoot = dirname($toolRoot) . '/repo-' . uniqid('', true);
        mkdir($repoRoot . '/assets', 0775, true);
        $deployAssets = new DeployAssets($toolRoot, $repoRoot);
        $deploy = new DeploySandbox(
            $this->makeLocalTier($repoRoot),
            $wiring,
            $validate,
            new Init($wiring, $validate, ORK3_ROOT),
            new Bootstrap($validate, new Init($wiring, $validate, ORK3_ROOT), $extract, $apply, $toolRoot),
            $extract,
            $render,
            $apply,
            new UseProfile($this->makeLocalTier($repoRoot), $repoRoot, static fn (): int => 0),
            $deployAssets,
            $toolRoot,
            new \DateTimeImmutable('2026-07-07', new \DateTimeZone(LastRender::TIMEZONE)),
            $this->seedStub(),
        );

        $result = $deploy->run(['yes' => true, 'force_refresh' => true, 'skip_use_dev' => true]);
        putenv('ENVIRONMENT');
        $this->removeTree($toolRoot);

        $output = implode("\n", $result['lines']);
        $this->assertSame(0, $result['exit_code'], $output);
        $this->assertStringContainsString('daily refresh (render anchor stale)', $output);
    }

    /** @return callable(array{target?: string}): array{lines: list<string>, exit_code: int} */
    private function seedStub(): callable
    {
        return static fn (): array => [
            'lines' => ['Seed credentials: OK sandbox (unit-test stub)'],
            'exit_code' => 0,
        ];
    }

    private function makeDeploySandbox(
        ?DeploymentTier $tier = null,
        ?string $toolRoot = null,
        ?PDO $pdo = null,
        ?\DateTimeImmutable $clock = null,
        ?string $repoRoot = null,
    ): DeploySandbox {
        $toolRoot ??= $this->copyToolRoot();
        $repoRoot ??= dirname($toolRoot) . '/repo-' . uniqid('', true);
        if (!is_dir($repoRoot . '/assets')) {
            mkdir($repoRoot . '/assets', 0775, true);
        }
        $pdo ??= $this->makePostApplyPdo();
        $tier ??= $this->makeLocalTier($repoRoot);

        $wiring = new Wiring($toolRoot);
        $validate = new Validate($wiring, $toolRoot, fn (): PDO => $pdo, $repoRoot);
        $render = new Render($toolRoot, ORK3_ROOT);
        $init = new Init($wiring, $validate, ORK3_ROOT);
        $extract = new Extract($wiring, $toolRoot, fn (): PDO => $pdo);
        $apply = new Apply($wiring, $validate, $render, ORK3_ROOT, static function (): void {
        });
        $bootstrap = new Bootstrap($validate, $init, $extract, $apply, $toolRoot);
        $deployAssets = new DeployAssets($toolRoot, $repoRoot);

        putenv('ENVIRONMENT=DEV');
        $deploy = new DeploySandbox(
            $tier,
            $wiring,
            $validate,
            $init,
            $bootstrap,
            $extract,
            $render,
            $apply,
            new UseProfile($tier, $repoRoot, static fn (): int => 0),
            $deployAssets,
            $toolRoot,
            $clock,
            $this->seedStub(),
        );

        return $deploy;
    }

    private function makeLocalTier(?string $repoRoot = null): DeploymentTier
    {
        return new DeploymentTier(
            new Wiring(ORK3_ROOT . '/tools/ork-db'),
            $repoRoot ?? ORK3_ROOT,
            static fn (): bool => true
        );
    }

    private function copyToolRoot(): string
    {
        $toolRoot = sys_get_temp_dir() . '/ork-db-deploy-tool-' . uniqid('', true);
        $this->copyTree(ORK3_ROOT . '/tools/ork-db', $toolRoot);

        return $toolRoot;
    }

    private function makeValidateSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function makePostApplyPdo(): PDO
    {
        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );
        $this->seedPostApplyTables($pdo);

        return $pdo;
    }

    private function seedPostApplyTables(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS ork_park');
        $pdo->exec('DROP TABLE IF EXISTS ork_kingdom');
        $pdo->exec(
            'CREATE TABLE ork_kingdom (
                kingdom_id INTEGER PRIMARY KEY,
                name TEXT,
                abbreviation TEXT,
                parent_kingdom_id INTEGER
            )'
        );
        $kingdoms = [
            [100001, 'Empire of Ashkara', 'EAK', 0],
            [100002, 'Kingdom of Meridia', 'KMR', 0],
            [100003, 'Sultanate of Zanzibarr', 'SZ', 0],
            [100004, 'Tsardom of Vyatka', 'TVK', 0],
            [100005, 'Grand Duchy of Litavia', 'GDL', 100001],
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
        foreach ([100001 => 4, 100002 => 4, 100003 => 3, 100004 => 6, 100005 => 3] as $kingdomId => $count) {
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
