<?php

declare(strict_types=1);

namespace OrkDb;

use PDO;
use PDOException;

final class Validate
{
    public const MODE_INIT = 'init';
    public const MODE_PRE_APPLY = 'pre-apply';
    public const MODE_POST_APPLY = 'post-apply';

    private const PROD_CANARY_MARKER = 'ORK3_PROD_CANARY_v1';
    private const TEST_CANARY_MARKER = 'ORK3_TEST_CANARY_v1';

    /** @var array<string, mixed> */
    private array $fingerprints;

    /** @var (callable(): PDO)|null */
    private $pdoFactory;

    private readonly string $repoRoot;

    public function __construct(
        private readonly Wiring $wiring,
        string $toolRoot,
        $pdoFactory = null,
        ?string $repoRoot = null,
    ) {
        $this->fingerprints = Json5::decodeFile($toolRoot . '/manifests/fingerprints.json5');
        $this->pdoFactory = $pdoFactory;
        $this->repoRoot = $repoRoot ?? dirname($toolRoot, 2);
    }

    /**
     * @return array{passed: bool, lines: list<string>, exit_code: int}
     */
    public function run(string $mode = self::MODE_PRE_APPLY, bool $checkAssets = false): array
    {
        $sandbox = $this->wiring->sandbox();
        $host = (string) $sandbox['host'];
        $port = (int) $sandbox['port'];
        $database = (string) $sandbox['database'];
        $credentials = $this->wiring->credentials();

        $lines = [];
        $failed = false;

        $lines[] = 'Target:       ' . $this->wiring->sandboxTargetLabel();

        try {
            $this->wiring->assertSandboxEndpoint($host, $port, $database);
            $lines[] = "Port lock:    PASS ({$port})";
            $lines[] = "DB name:      PASS ({$database})";
        } catch (ValidationException $e) {
            $failed = true;
            $lines[] = 'Port lock:    FAIL (' . $e->getMessage() . ')';
            $lines[] = 'DB name:      FAIL';
            $lines[] = str_repeat('─', 33);
            $lines[] = 'RESULT:       ABORT — refusing wipe/replay';

            return ['passed' => false, 'lines' => $lines, 'exit_code' => 2];
        }

        try {
            $pdo = $this->connectSandbox($credentials['user'], $credentials['password']);
        } catch (PDOException $e) {
            $lines[] = 'Connection:   FAIL (' . $e->getMessage() . ')';
            $lines[] = str_repeat('─', 33);
            $lines[] = 'RESULT:       ABORT — refusing wipe/replay';

            return ['passed' => false, 'lines' => $lines, 'exit_code' => 2];
        }

        $prodCanary = $this->checkProdCanary($pdo);
        if ($prodCanary['present']) {
            $failed = true;
            $lines[] = 'Prod canary:  FAIL (_ork_canary_prod row present)';
        } else {
            $lines[] = 'Prod canary:  PASS (absent)';
        }

        $testCanary = $this->checkTestCanary($pdo);
        if ($mode === self::MODE_INIT) {
            if ($testCanary['present']) {
                $lines[] = 'Test canary:  PASS (' . self::TEST_CANARY_MARKER . ')';
            } else {
                $lines[] = 'Test canary:  PASS (missing — allowed in init mode)';
            }
        } elseif (!$testCanary['present']) {
            $failed = true;
            $lines[] = 'Test canary:  FAIL (missing or invalid marker)';
        } else {
            $lines[] = 'Test canary:  PASS (' . self::TEST_CANARY_MARKER . ')';
        }

        $runFingerprints = $mode === self::MODE_POST_APPLY
            || ($mode === self::MODE_PRE_APPLY && $this->hasTestKingdomRowsOnPdo($pdo));

        if ($runFingerprints) {
            $kingdomResult = $this->checkKingdoms($pdo);
            $lines[] = $kingdomResult['line'];
            $failed = $failed || !$kingdomResult['passed'];

            $parkResult = $this->checkParks($pdo);
            $lines[] = $parkResult['line'];
            $failed = $failed || !$parkResult['passed'];

            $blocklistResult = $this->checkBlocklist($pdo);
            $lines[] = $blocklistResult['line'];
            $failed = $failed || !$blocklistResult['passed'];

            if ($mode === self::MODE_POST_APPLY && $checkAssets) {
                $assetResult = $this->checkDeployedAssets($pdo);
                $lines[] = $assetResult['line'];
                $failed = $failed || !$assetResult['passed'];
            }
        } elseif ($mode === self::MODE_INIT) {
            $lines[] = 'Kingdoms:     SKIP (init mode)';
            $lines[] = 'Parks:        SKIP (init mode)';
            $lines[] = 'Blocklist:    SKIP (init mode)';
        } else {
            $lines[] = 'Kingdoms:     SKIP (no test kingdom rows yet)';
            $lines[] = 'Parks:        SKIP (no test kingdom rows yet)';
            $lines[] = 'Blocklist:    SKIP (no test kingdom rows yet)';
        }

        $lines[] = str_repeat('─', 33);
        if ($failed) {
            $lines[] = 'RESULT:       ABORT — refusing wipe/replay';

            return ['passed' => false, 'lines' => $lines, 'exit_code' => 2];
        }

        $lines[] = $mode === self::MODE_POST_APPLY
            ? 'RESULT:       POST-APPLY VALIDATION PASSED'
            : 'RESULT:       SAFE TO APPLY';

        return ['passed' => true, 'lines' => $lines, 'exit_code' => 0];
    }

    public function testCanaryPresent(): bool
    {
        try {
            $credentials = $this->wiring->credentials();
            $pdo = $this->connectSandbox($credentials['user'], $credentials['password']);

            return $this->checkTestCanary($pdo)['present'];
        } catch (\Throwable) {
            return false;
        }
    }

    public function hasTestKingdomRows(): bool
    {
        try {
            $credentials = $this->wiring->credentials();
            $pdo = $this->connectSandbox($credentials['user'], $credentials['password']);

            return $this->hasTestKingdomRowsOnPdo($pdo);
        } catch (\Throwable) {
            return false;
        }
    }

    public function connectSandbox(string $user, string $password): PDO
    {
        if ($this->pdoFactory !== null) {
            return ($this->pdoFactory)();
        }

        $pdo = new PDO(
            $this->wiring->sandboxDsn(),
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        return $pdo;
    }

    /** @return array{present: bool} */
    private function checkProdCanary(PDO $pdo): array
    {
        if (!$this->tableExists($pdo, '_ork_canary_prod')) {
            return ['present' => false];
        }

        $count = (int) $pdo->query('SELECT COUNT(*) FROM _ork_canary_prod')->fetchColumn();

        return ['present' => $count > 0];
    }

    /** @return array{present: bool} */
    private function checkTestCanary(PDO $pdo): array
    {
        if (!$this->tableExists($pdo, '_ork_canary_test')) {
            return ['present' => false];
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM _ork_canary_test WHERE id = 1 AND marker = :marker'
        );
        $stmt->execute(['marker' => self::TEST_CANARY_MARKER]);
        $count = (int) $stmt->fetchColumn();

        return ['present' => $count === 1];
    }

    private function hasTestKingdomRowsOnPdo(PDO $pdo): bool
    {
        if (!$this->tableExists($pdo, 'ork_kingdom')) {
            return false;
        }

        $count = (int) $pdo->query(
            'SELECT COUNT(*) FROM ork_kingdom WHERE kingdom_id BETWEEN ' . IdNamespace::kingdomIdRangeSql()
        )->fetchColumn();

        return $count > 0;
    }

    /** @return array{passed: bool, line: string} */
    private function checkKingdoms(PDO $pdo): array
    {
        if (!$this->tableExists($pdo, 'ork_kingdom')) {
            return ['passed' => false, 'line' => 'Kingdoms:     FAIL (ork_kingdom missing)'];
        }

        $expected = $this->fingerprints['kingdoms'];
        $stmt = $pdo->query(
            'SELECT kingdom_id, name, abbreviation, parent_kingdom_id
             FROM ork_kingdom
             WHERE kingdom_id BETWEEN ' . IdNamespace::kingdomIdRangeSql() . '
             ORDER BY kingdom_id'
        );
        $rows = $stmt->fetchAll();

        if (count($rows) !== count($expected)) {
            return [
                'passed' => false,
                'line' => 'Kingdoms:     FAIL (' . count($rows) . '/' . count($expected) . ' rows)',
            ];
        }

        foreach ($expected as $index => $kingdom) {
            $row = $rows[$index];
            if ((int) $row['kingdom_id'] !== (int) $kingdom['id']) {
                return ['passed' => false, 'line' => 'Kingdoms:     FAIL (id mismatch at index ' . $index . ')'];
            }
            if ($row['name'] !== $kingdom['name']) {
                return ['passed' => false, 'line' => 'Kingdoms:     FAIL (name mismatch for id ' . $kingdom['id'] . ')'];
            }
            if ($row['abbreviation'] !== $kingdom['abbreviation']) {
                return [
                    'passed' => false,
                    'line' => 'Kingdoms:     FAIL (abbreviation mismatch for id ' . $kingdom['id'] . ')',
                ];
            }
            if ((int) $row['parent_kingdom_id'] !== (int) $kingdom['parent_kingdom_id']) {
                return [
                    'passed' => false,
                    'line' => 'Kingdoms:     FAIL (parent_kingdom_id mismatch for id ' . $kingdom['id'] . ')',
                ];
            }
        }

        $names = array_column($rows, 'name');

        return [
            'passed' => true,
            'line' => 'Kingdoms:     PASS (5/5 ' . implode(' … ', [$names[0], end($names)]) . ')',
        ];
    }

    /** @return array{passed: bool, line: string} */
    private function checkParks(PDO $pdo): array
    {
        if (!$this->tableExists($pdo, 'ork_park')) {
            return ['passed' => false, 'line' => 'Parks:        FAIL (ork_park missing)'];
        }

        $seed = (string) ($this->fingerprints['render_seed_default'] ?? '42');
        $expectedBySeed = $this->fingerprints['park_count_by_seed'] ?? [];
        $expectedTotal = isset($expectedBySeed[$seed]) ? (int) $expectedBySeed[$seed] : null;

        $actualTotal = (int) $pdo->query(
            'SELECT COUNT(*) FROM ork_park WHERE kingdom_id BETWEEN ' . IdNamespace::kingdomIdRangeSql()
        )->fetchColumn();

        if ($expectedTotal !== null && $actualTotal !== $expectedTotal) {
            return [
                'passed' => false,
                'line' => "Parks:        FAIL ({$actualTotal}/{$expectedTotal} for seed={$seed})",
            ];
        }

        $range = $this->fingerprints['parks_per_kingdom_range'] ?? [2, 6];
        $min = (int) $range[0];
        $max = (int) $range[1];
        $counts = $pdo->query(
            'SELECT kingdom_id, COUNT(*) AS park_count
             FROM ork_park
             WHERE kingdom_id BETWEEN ' . IdNamespace::kingdomIdRangeSql() . '
             GROUP BY kingdom_id'
        )->fetchAll();

        foreach ($counts as $row) {
            $count = (int) $row['park_count'];
            if ($count < $min || $count > $max) {
                return [
                    'passed' => false,
                    'line' => 'Parks:        FAIL (kingdom ' . $row['kingdom_id'] . " has {$count} parks)",
                ];
            }
        }

        $label = $expectedTotal !== null
            ? "Parks:        PASS ({$actualTotal}/{$expectedTotal} for seed={$seed})"
            : "Parks:        PASS ({$actualTotal} parks)";

        return ['passed' => true, 'line' => $label];
    }

    /** @return array{passed: bool, line: string} */
    private function checkBlocklist(PDO $pdo): array
    {
        if (!$this->tableExists($pdo, 'ork_kingdom')) {
            return ['passed' => true, 'line' => 'Blocklist:    PASS (no kingdom table)'];
        }

        $blocklist = $this->fingerprints['real_kingdom_name_blocklist'] ?? [];
        if ($blocklist === []) {
            return ['passed' => true, 'line' => 'Blocklist:    PASS (empty blocklist)'];
        }

        $placeholders = implode(',', array_fill(0, count($blocklist), '?'));
        $stmt = $pdo->prepare("SELECT name FROM ork_kingdom WHERE name IN ({$placeholders}) LIMIT 1");
        $stmt->execute($blocklist);
        $hit = $stmt->fetchColumn();
        if ($hit !== false) {
            return ['passed' => false, 'line' => 'Blocklist:    FAIL (real kingdom name: ' . $hit . ')'];
        }

        $totalKingdoms = (int) $pdo->query('SELECT COUNT(*) FROM ork_kingdom')->fetchColumn();
        if ($totalKingdoms > 50) {
            return [
                'passed' => false,
                'line' => 'Blocklist:    FAIL (kingdom count heuristic: ' . $totalKingdoms . ' > 50)',
            ];
        }

        return ['passed' => true, 'line' => 'Blocklist:    PASS (no real kingdom names)'];
    }

    /** @return array{passed: bool, line: string} */
    private function checkDeployedAssets(PDO $pdo): array
    {
        if (!$this->tableExists($pdo, 'ork_kingdom')
            || !$this->columnExists($pdo, 'ork_kingdom', 'has_heraldry')) {
            return ['passed' => true, 'line' => 'Assets:       SKIP (no heraldry flags in schema)'];
        }

        $assetsRoot = rtrim($this->repoRoot, '/') . '/assets';
        $missing = [];

        $kingdomStmt = $pdo->query(
            'SELECT kingdom_id FROM ork_kingdom
             WHERE kingdom_id BETWEEN ' . IdNamespace::kingdomIdRangeSql() . ' AND has_heraldry = 1'
        );
        foreach ($kingdomStmt->fetchAll() as $row) {
            $id = (int) $row['kingdom_id'];
            if (!$this->heraldryFileExists($assetsRoot . '/heraldry/kingdom/', $id, 4)) {
                $missing[] = 'kingdom/' . $id;
            }
        }

        if ($this->tableExists($pdo, 'ork_park') && $this->columnExists($pdo, 'ork_park', 'has_heraldry')) {
            $parkStmt = $pdo->query(
                'SELECT park_id FROM ork_park
                 WHERE kingdom_id BETWEEN ' . IdNamespace::kingdomIdRangeSql() . ' AND has_heraldry = 1'
            );
            foreach ($parkStmt->fetchAll() as $row) {
                $id = (int) $row['park_id'];
                if (!$this->heraldryFileExists($assetsRoot . '/heraldry/park/', $id, 5)) {
                    $missing[] = 'park/' . $id;
                }
            }
        }

        if (!$this->heraldryFileExists(
            $assetsRoot . '/heraldry/player/',
            (int) IdNamespace::PLAYER_HERALDRY_DEFAULT_BASENAME,
            6
        )) {
            $missing[] = 'player/' . IdNamespace::PLAYER_HERALDRY_DEFAULT_BASENAME;
        }

        if (!$this->heraldryFileExists(
            $assetsRoot . '/players/',
            (int) IdNamespace::PLAYER_HERALDRY_DEFAULT_BASENAME,
            6
        )) {
            $missing[] = 'players/' . IdNamespace::PLAYER_HERALDRY_DEFAULT_BASENAME;
        }

        if ($this->tableExists($pdo, 'ork_mundane')
            && $this->columnExists($pdo, 'ork_mundane', 'has_heraldry')) {
            $mundaneStmt = $pdo->query(
                'SELECT mundane_id FROM ork_mundane WHERE has_heraldry = 1'
            );
            foreach ($mundaneStmt->fetchAll() as $row) {
                $id = (int) $row['mundane_id'];
                if (!$this->heraldryFileExists($assetsRoot . '/heraldry/player/', $id, 6)) {
                    $missing[] = 'player/' . $id;
                }
            }
        }

        if ($missing === []) {
            return ['passed' => true, 'line' => 'Assets:       PASS (heraldry files present)'];
        }

        $sample = implode(', ', array_slice($missing, 0, 3));
        if (count($missing) > 3) {
            $sample .= ', …';
        }

        return [
            'passed' => false,
            'line' => 'Assets:       FAIL (missing ' . count($missing) . ' files: ' . $sample . ')',
        ];
    }

    private function heraldryFileExists(string $directory, int $id, int $padLength): bool
    {
        $basename = sprintf('%0' . $padLength . 'd', $id);

        return is_readable($directory . $basename . '.png')
            || is_readable($directory . $basename . '.jpg');
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
            foreach ($stmt->fetchAll() as $row) {
                if (($row['name'] ?? null) === $column) {
                    return true;
                }
            }

            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table"
            );
            $stmt->execute(['table' => $table]);

            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $stmt->execute(['table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
