<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\Validate;
use OrkDb\Wiring;
use PDO;
use PHPUnit\Framework\TestCase;

final class ValidateTest extends TestCase
{
    public function testRunReportsPortLockFailureWithoutConnecting(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19306);

        $validate = new Validate(new Wiring($toolRoot), $toolRoot);
        $result = $validate->run(Validate::MODE_PRE_APPLY);

        $this->removeTree($toolRoot);

        $this->assertFalse($result['passed']);
        $this->assertSame(2, $result['exit_code']);
        $this->assertStringContainsString('Port lock:    FAIL', implode("\n", $result['lines']));
    }

    public function testRunFailsWhenProdCanaryPresent(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_prod (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_prod VALUES (1, 'ORK3_PROD_CANARY_v1');"
        );
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );

        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): PDO => $pdo);
        $result = $validate->run(Validate::MODE_PRE_APPLY);

        $this->removeTree($toolRoot);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Prod canary:  FAIL', implode("\n", $result['lines']));
    }

    public function testRunPostApplyChecksKingdomAndParkFingerprints(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );
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
            $pdo->exec(
                'INSERT INTO ork_kingdom VALUES (' . implode(', ', array_map(
                    static fn ($value) => $pdo->quote((string) $value),
                    $row
                )) . ')'
            );
        }
        $pdo->exec('CREATE TABLE ork_park (park_id INTEGER PRIMARY KEY, kingdom_id INTEGER)');
        $parkId = 1;
        foreach ([100001 => 4, 100002 => 4, 100003 => 3, 100004 => 6, 100005 => 3] as $kingdomId => $count) {
            for ($i = 0; $i < $count; $i++) {
                $pdo->exec('INSERT INTO ork_park VALUES (' . $parkId++ . ', ' . $kingdomId . ')');
            }
        }

        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): PDO => $pdo);
        $result = $validate->run(Validate::MODE_POST_APPLY);

        $this->removeTree($toolRoot);

        $output = implode("\n", $result['lines']);
        $this->assertTrue($result['passed'], $output);
        $this->assertStringContainsString('Kingdoms:     PASS', $output);
        $this->assertStringContainsString('Parks:        PASS', $output);
        $this->assertStringContainsString('POST-APPLY VALIDATION PASSED', $output);
    }

    public function testRunFailsWhenTestCanaryMissingInStrictMode(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $pdo = $this->makeValidateSqlitePdo();

        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): PDO => $pdo);
        $result = $validate->run(Validate::MODE_PRE_APPLY);

        $this->removeTree($toolRoot);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Test canary:  FAIL', implode("\n", $result['lines']));
    }

    public function testRunFailsBlocklistWhenRealKingdomNamePresent(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );
        $pdo->exec('CREATE TABLE ork_kingdom (kingdom_id INTEGER PRIMARY KEY, name TEXT, abbreviation TEXT, parent_kingdom_id INTEGER)');
        $kingdoms = [
            [100001, 'Empire of Ashkara', 'EAK', 0],
            [100002, 'Kingdom of Meridia', 'KMR', 0],
            [100003, 'Sultanate of Zanzibarr', 'SZ', 0],
            [100004, 'Tsardom of Vyatka', 'TVK', 0],
            [100005, 'Grand Duchy of Litavia', 'GDL', 100001],
            [99, 'Northern Lights', 'NL', 0],
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

        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): PDO => $pdo);
        $result = $validate->run(Validate::MODE_POST_APPLY);

        $this->removeTree($toolRoot);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Blocklist:    FAIL', implode("\n", $result['lines']));
    }

    public function testRunFailsWhenKingdomParkCountOutOfRange(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );
        $pdo->exec('CREATE TABLE ork_kingdom (kingdom_id INTEGER PRIMARY KEY, name TEXT, abbreviation TEXT, parent_kingdom_id INTEGER)');
        foreach (
            [
                [100001, 'Empire of Ashkara', 'EAK', 0],
                [100002, 'Kingdom of Meridia', 'KMR', 0],
                [100003, 'Sultanate of Zanzibarr', 'SZ', 0],
                [100004, 'Tsardom of Vyatka', 'TVK', 0],
                [100005, 'Grand Duchy of Litavia', 'GDL', 100001],
            ] as $row
        ) {
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
        foreach ([100001 => 1, 100002 => 5, 100003 => 5, 100004 => 5, 100005 => 4] as $kingdomId => $count) {
            for ($i = 0; $i < $count; $i++) {
                $pdo->exec('INSERT INTO ork_park VALUES (' . $parkId++ . ', ' . $kingdomId . ')');
            }
        }

        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): \PDO => $pdo);
        $result = $validate->run(Validate::MODE_POST_APPLY);
        $this->removeTree($toolRoot);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Parks:        FAIL (kingdom 100001 has 1 parks)', implode("\n", $result['lines']));
    }

    public function testRunFailsWhenParkTableMissingInPostApply(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );
        $pdo->exec('CREATE TABLE ork_kingdom (kingdom_id INTEGER PRIMARY KEY, name TEXT, abbreviation TEXT, parent_kingdom_id INTEGER)');
        foreach (
            [
                [100001, 'Empire of Ashkara', 'EAK', 0],
                [100002, 'Kingdom of Meridia', 'KMR', 0],
                [100003, 'Sultanate of Zanzibarr', 'SZ', 0],
                [100004, 'Tsardom of Vyatka', 'TVK', 0],
                [100005, 'Grand Duchy of Litavia', 'GDL', 100001],
            ] as $row
        ) {
            $pdo->exec(sprintf(
                'INSERT INTO ork_kingdom VALUES (%d, %s, %s, %d)',
                $row[0],
                $pdo->quote($row[1]),
                $pdo->quote($row[2]),
                $row[3]
            ));
        }

        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): \PDO => $pdo);
        $result = $validate->run(Validate::MODE_POST_APPLY);
        $this->removeTree($toolRoot);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Parks:        FAIL (ork_park missing)', implode("\n", $result['lines']));
    }

    public function testRunFailsWhenKingdomTableMissingInPostApply(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );

        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): \PDO => $pdo);
        $result = $validate->run(Validate::MODE_POST_APPLY);
        $this->removeTree($toolRoot);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Kingdoms:     FAIL (ork_kingdom missing)', implode("\n", $result['lines']));
    }

    public function testRunInitModeAllowsMissingTestCanary(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $pdo = $this->makeValidateSqlitePdo();

        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): \PDO => $pdo);
        $result = $validate->run(Validate::MODE_INIT);
        $this->removeTree($toolRoot);

        $this->assertTrue($result['passed']);
        $this->assertStringContainsString('Test canary:  PASS (missing — allowed in init mode)', implode("\n", $result['lines']));
    }

    public function testRunReportsConnectionFailure(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $validate = new Validate(new Wiring($toolRoot), $toolRoot, static function (): \PDO {
            throw new \PDOException('connection refused');
        });
        $result = $validate->run(Validate::MODE_PRE_APPLY);
        $this->removeTree($toolRoot);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Connection:   FAIL', implode("\n", $result['lines']));
    }

    public function testRunInitModeReportsExistingTestCanary(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );

        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): \PDO => $pdo);
        $result = $validate->run(Validate::MODE_INIT);
        $this->removeTree($toolRoot);

        $this->assertTrue($result['passed']);
        $this->assertStringContainsString('Test canary:  PASS (ORK3_TEST_CANARY_v1)', implode("\n", $result['lines']));
    }

    public function testRunFailsWhenKingdomFingerprintMismatch(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );
        $pdo->exec('CREATE TABLE ork_kingdom (kingdom_id INTEGER PRIMARY KEY, name TEXT, abbreviation TEXT, parent_kingdom_id INTEGER)');
        $pdo->exec("INSERT INTO ork_kingdom VALUES (100001, 'Wrong Name', 'EAK', 0)");

        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): \PDO => $pdo);
        $result = $validate->run(Validate::MODE_POST_APPLY);
        $this->removeTree($toolRoot);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Kingdoms:     FAIL', implode("\n", $result['lines']));
    }

    public function testRunPreApplySkipsFingerprintsWithoutTestKingdomRows(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );

        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): PDO => $pdo);
        $result = $validate->run(Validate::MODE_PRE_APPLY);

        $this->removeTree($toolRoot);

        $output = implode("\n", $result['lines']);
        $this->assertTrue($result['passed'], $output);
        $this->assertStringContainsString('Kingdoms:     SKIP (no test kingdom rows yet)', $output);
    }

    public function testRunFailsWhenParkCountMismatch(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );
        $pdo->exec('CREATE TABLE ork_kingdom (kingdom_id INTEGER PRIMARY KEY, name TEXT, abbreviation TEXT, parent_kingdom_id INTEGER)');
        foreach (
            [
                [100001, 'Empire of Ashkara', 'EAK', 0],
                [100002, 'Kingdom of Meridia', 'KMR', 0],
                [100003, 'Sultanate of Zanzibarr', 'SZ', 0],
                [100004, 'Tsardom of Vyatka', 'TVK', 0],
                [100005, 'Grand Duchy of Litavia', 'GDL', 100001],
            ] as $row
        ) {
            $pdo->exec(sprintf(
                'INSERT INTO ork_kingdom VALUES (%d, %s, %s, %d)',
                $row[0],
                $pdo->quote($row[1]),
                $pdo->quote($row[2]),
                $row[3]
            ));
        }
        $pdo->exec('CREATE TABLE ork_park (park_id INTEGER PRIMARY KEY, kingdom_id INTEGER)');
        $pdo->exec('INSERT INTO ork_park VALUES (1, 100001)');

        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): \PDO => $pdo);
        $result = $validate->run(Validate::MODE_POST_APPLY);
        $this->removeTree($toolRoot);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Parks:        FAIL', implode("\n", $result['lines']));
    }

    public function testRunFailsWhenKingdomCountHeuristicTrips(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );
        $pdo->exec('CREATE TABLE ork_kingdom (kingdom_id INTEGER PRIMARY KEY, name TEXT, abbreviation TEXT, parent_kingdom_id INTEGER)');
        foreach (
            [
                [100001, 'Empire of Ashkara', 'EAK', 0],
                [100002, 'Kingdom of Meridia', 'KMR', 0],
                [100003, 'Sultanate of Zanzibarr', 'SZ', 0],
                [100004, 'Tsardom of Vyatka', 'TVK', 0],
                [100005, 'Grand Duchy of Litavia', 'GDL', 100001],
            ] as $row
        ) {
            $pdo->exec(sprintf(
                'INSERT INTO ork_kingdom VALUES (%d, %s, %s, %d)',
                $row[0],
                $pdo->quote($row[1]),
                $pdo->quote($row[2]),
                $row[3]
            ));
        }
        for ($i = 1; $i <= 46; $i++) {
            $pdo->exec(sprintf(
                'INSERT INTO ork_kingdom VALUES (%d, %s, %s, 0)',
                1000 + $i,
                $pdo->quote('Synthetic ' . $i),
                $pdo->quote('S' . $i)
            ));
        }
        $pdo->exec('CREATE TABLE ork_park (park_id INTEGER PRIMARY KEY, kingdom_id INTEGER)');
        $parkId = 1;
        foreach ([100001 => 4, 100002 => 4, 100003 => 3, 100004 => 6, 100005 => 3] as $kingdomId => $count) {
            for ($i = 0; $i < $count; $i++) {
                $pdo->exec('INSERT INTO ork_park VALUES (' . $parkId++ . ', ' . $kingdomId . ')');
            }
        }

        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): \PDO => $pdo);
        $result = $validate->run(Validate::MODE_POST_APPLY);
        $this->removeTree($toolRoot);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Blocklist:    FAIL (kingdom count heuristic', implode("\n", $result['lines']));
    }

    public function testPostApplySkipsAssetsWhenHeraldryColumnMissing(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );
        $pdo->exec('CREATE TABLE ork_kingdom (kingdom_id INTEGER PRIMARY KEY, name TEXT, abbreviation TEXT, parent_kingdom_id INTEGER)');
        foreach (
            [
                [100001, 'Empire of Ashkara', 'EAK', 0],
                [100002, 'Kingdom of Meridia', 'KMR', 0],
                [100003, 'Sultanate of Zanzibarr', 'SZ', 0],
                [100004, 'Tsardom of Vyatka', 'TVK', 0],
                [100005, 'Grand Duchy of Litavia', 'GDL', 100001],
            ] as $row
        ) {
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

        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): PDO => $pdo);
        $result = $validate->run(Validate::MODE_POST_APPLY, true);
        $this->removeTree($toolRoot);

        $output = implode("\n", $result['lines']);
        $this->assertTrue($result['passed'], $output);
        $this->assertStringContainsString('Assets:       SKIP (no heraldry flags in schema)', $output);
    }

    public function testHeraldryManifestDriftedDetectsMismatch(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $pdo = $this->makePostApplyPdoWithHeraldryFlags();
        $pdo->exec('INSERT INTO ork_mundane VALUES (100099999, 1, 0)');

        $render = new \OrkDb\Render(ORK3_ROOT . '/tools/ork-db', ORK3_ROOT);
        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): PDO => $pdo);

        $this->assertTrue($validate->heraldryManifestDrifted($render->mundaneHeraldryIdsForSeed(42)));
        $this->removeTree($toolRoot);
    }

    public function testHeraldryManifestDriftedPassesWhenAligned(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $pdo = $this->makePostApplyPdoWithHeraldryFlags();
        $render = new \OrkDb\Render(ORK3_ROOT . '/tools/ork-db', ORK3_ROOT);
        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): PDO => $pdo);

        foreach (array_slice($render->mundaneHeraldryIdsForSeed(42), 0, 3) as $id) {
            $pdo->exec(sprintf('INSERT INTO ork_mundane VALUES (%d, 1, 0)', $id));
        }

        $this->assertFalse($validate->heraldryManifestDrifted($render->mundaneHeraldryIdsForSeed(42)));
        $this->removeTree($toolRoot);
    }

    public function testPostApplyFailsWhenKingdomAssetMissing(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $repoRoot = sys_get_temp_dir() . '/ork-db-validate-assets-' . uniqid('', true);
        mkdir($repoRoot . '/assets/heraldry/kingdom', 0775, true);
        $pdo = $this->makePostApplyPdoWithHeraldryFlags();

        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): PDO => $pdo, $repoRoot);
        $result = $validate->run(Validate::MODE_POST_APPLY, true);

        $this->removeTree($toolRoot);
        $this->removeTree($repoRoot);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Assets:       FAIL', implode("\n", $result['lines']));
    }

    public function testPostApplyPassesWhenDeployedAssetsPresent(): void
    {
        $toolRoot = $this->makeTempToolRootWithSandboxPort(19307);
        $repoRoot = sys_get_temp_dir() . '/ork-db-validate-assets-' . uniqid('', true);
        $deploy = new \OrkDb\DeployAssets(ORK3_ROOT . '/tools/ork-db', $repoRoot);
        $deploy->run(['verify_manifest' => true]);
        $pdo = $this->makePostApplyPdoWithHeraldryFlags();

        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): PDO => $pdo, $repoRoot);
        $result = $validate->run(Validate::MODE_POST_APPLY, true);

        $this->removeTree($toolRoot);
        $this->removeTree($repoRoot);

        $output = implode("\n", $result['lines']);
        $this->assertTrue($result['passed'], $output);
        $this->assertStringContainsString('Assets:       PASS (heraldry files present)', $output);
    }

    private function makePostApplyPdoWithHeraldryFlags(): PDO
    {
        $pdo = $this->makeValidateSqlitePdo();
        $pdo->exec(
            'CREATE TABLE _ork_canary_test (id INTEGER PRIMARY KEY, marker TEXT);'
            . "INSERT INTO _ork_canary_test VALUES (1, 'ORK3_TEST_CANARY_v1');"
        );
        $pdo->exec(
            'CREATE TABLE ork_kingdom (
                kingdom_id INTEGER PRIMARY KEY,
                name TEXT,
                abbreviation TEXT,
                parent_kingdom_id INTEGER,
                has_heraldry INTEGER
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
                'INSERT INTO ork_kingdom VALUES (%d, %s, %s, %d, 1)',
                $row[0],
                $pdo->quote($row[1]),
                $pdo->quote($row[2]),
                $row[3]
            ));
        }

        $pdo->exec('CREATE TABLE ork_park (park_id INTEGER PRIMARY KEY, kingdom_id INTEGER, has_heraldry INTEGER)');
        $parkLayouts = [
            100001 => [1000001, 1000002, 1000003, 1000004],
            100002 => [1000101, 1000102, 1000103, 1000104],
            100003 => [1000201, 1000202, 1000203],
            100004 => [1000301, 1000302, 1000303, 1000304, 1000305, 1000306],
            100005 => [1000401, 1000402, 1000403],
        ];
        foreach ($parkLayouts as $kingdomId => $parkIds) {
            foreach ($parkIds as $parkId) {
                $pdo->exec(sprintf(
                    'INSERT INTO ork_park VALUES (%d, %d, 1)',
                    $parkId,
                    $kingdomId
                ));
            }
        }

        $pdo->exec(
            'CREATE TABLE ork_mundane (
                mundane_id INTEGER PRIMARY KEY,
                has_heraldry INTEGER,
                has_image INTEGER
            )'
        );
        for ($id = 100_000_000; $id < 100_000_005; $id++) {
            $pdo->exec(sprintf('INSERT INTO ork_mundane VALUES (%d, 0, 0)', $id));
        }

        $render = new \OrkDb\Render(ORK3_ROOT . '/tools/ork-db', ORK3_ROOT);
        foreach (array_slice($render->fakeMundaneHeraldryIdsForSeed(42), 0, 3) as $id) {
            $pdo->exec(sprintf('UPDATE ork_mundane SET has_heraldry = 1 WHERE mundane_id = %d', $id));
        }

        return $pdo;
    }

    private function makeTempToolRootWithSandboxPort(int $port): string
    {
        $toolRoot = sys_get_temp_dir() . '/ork-db-validate-' . uniqid('', true);
        mkdir($toolRoot . '/manifests', 0775, true);
        copy(
            ORK3_ROOT . '/tools/ork-db/manifests/fingerprints.json5',
            $toolRoot . '/manifests/fingerprints.json5'
        );

        $wiringJson = str_replace('19307', (string) $port, file_get_contents(ORK3_ROOT . '/tools/ork-db/manifests/wiring.json5'));
        file_put_contents($toolRoot . '/manifests/wiring.json5', $wiringJson);

        return $toolRoot;
    }

    private function makeValidateSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            is_dir($full) ? $this->removeTree($full) : unlink($full);
        }

        rmdir($path);
    }
}
