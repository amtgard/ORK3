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
            [9001, 'Empire of Ashkara', 'EAK', 0],
            [9002, 'Kingdom of Meridia', 'KMR', 0],
            [9003, 'Sultanate of Zanzibarr', 'SZ', 0],
            [9004, 'Tsardom of Vyatka', 'TVK', 0],
            [9005, 'Grand Duchy of Litavia', 'GDL', 9001],
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
        foreach ([9001 => 4, 9002 => 4, 9003 => 3, 9004 => 6, 9005 => 3] as $kingdomId => $count) {
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
            [9001, 'Empire of Ashkara', 'EAK', 0],
            [9002, 'Kingdom of Meridia', 'KMR', 0],
            [9003, 'Sultanate of Zanzibarr', 'SZ', 0],
            [9004, 'Tsardom of Vyatka', 'TVK', 0],
            [9005, 'Grand Duchy of Litavia', 'GDL', 9001],
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
        foreach ([9001 => 4, 9002 => 4, 9003 => 3, 9004 => 6, 9005 => 3] as $kingdomId => $count) {
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
                [9001, 'Empire of Ashkara', 'EAK', 0],
                [9002, 'Kingdom of Meridia', 'KMR', 0],
                [9003, 'Sultanate of Zanzibarr', 'SZ', 0],
                [9004, 'Tsardom of Vyatka', 'TVK', 0],
                [9005, 'Grand Duchy of Litavia', 'GDL', 9001],
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
        foreach ([9001 => 1, 9002 => 5, 9003 => 5, 9004 => 5, 9005 => 4] as $kingdomId => $count) {
            for ($i = 0; $i < $count; $i++) {
                $pdo->exec('INSERT INTO ork_park VALUES (' . $parkId++ . ', ' . $kingdomId . ')');
            }
        }

        $validate = new Validate(new Wiring($toolRoot), $toolRoot, fn (): \PDO => $pdo);
        $result = $validate->run(Validate::MODE_POST_APPLY);
        $this->removeTree($toolRoot);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Parks:        FAIL (kingdom 9001 has 1 parks)', implode("\n", $result['lines']));
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
                [9001, 'Empire of Ashkara', 'EAK', 0],
                [9002, 'Kingdom of Meridia', 'KMR', 0],
                [9003, 'Sultanate of Zanzibarr', 'SZ', 0],
                [9004, 'Tsardom of Vyatka', 'TVK', 0],
                [9005, 'Grand Duchy of Litavia', 'GDL', 9001],
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
        $pdo->exec("INSERT INTO ork_kingdom VALUES (9001, 'Wrong Name', 'EAK', 0)");

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
                [9001, 'Empire of Ashkara', 'EAK', 0],
                [9002, 'Kingdom of Meridia', 'KMR', 0],
                [9003, 'Sultanate of Zanzibarr', 'SZ', 0],
                [9004, 'Tsardom of Vyatka', 'TVK', 0],
                [9005, 'Grand Duchy of Litavia', 'GDL', 9001],
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
        $pdo->exec('INSERT INTO ork_park VALUES (1, 9001)');

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
                [9001, 'Empire of Ashkara', 'EAK', 0],
                [9002, 'Kingdom of Meridia', 'KMR', 0],
                [9003, 'Sultanate of Zanzibarr', 'SZ', 0],
                [9004, 'Tsardom of Vyatka', 'TVK', 0],
                [9005, 'Grand Duchy of Litavia', 'GDL', 9001],
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
        foreach ([9001 => 4, 9002 => 4, 9003 => 3, 9004 => 6, 9005 => 3] as $kingdomId => $count) {
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
