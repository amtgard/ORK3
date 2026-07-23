<?php

declare(strict_types=1);

namespace OrkDb;

use PDO;
use PDOException;

final class SchemaDiff
{
    /** @var (callable(string): PDO)|null */
    private $connectionFactory;

    public function __construct(
        private readonly Wiring $wiring,
        private readonly string $repoRoot,
        $connectionFactory = null,
    ) {
        $this->connectionFactory = $connectionFactory;
    }

    /**
     * @return array{lines: list<string>, exit_code: int, passed: bool}
     */
    public function run(): array
    {
        $lines = ['SCHEMA DIFF (mirror vs sandbox)'];
        $mirror = $this->wiring->mirror();
        $sandbox = $this->wiring->sandbox();

        if (!DeploymentTier::probePort((string) $mirror['host'], (int) $mirror['port'])) {
            throw new ValidationException('Mirror unreachable — start ork3db before schema-diff');
        }
        if (!DeploymentTier::probePort((string) $sandbox['host'], (int) $sandbox['port'])) {
            throw new ValidationException('Sandbox unreachable — start ork3testdb before schema-diff');
        }

        $mirrorMap = $this->createTableMap('mirror', (string) $mirror['database']);
        $sandboxMap = $this->createTableMap('sandbox', (string) $sandbox['database']);

        $mirrorTables = array_keys($mirrorMap);
        $sandboxTables = array_keys($sandboxMap);
        $onlyMirror = array_values(array_diff($mirrorTables, $sandboxTables));
        $onlySandbox = array_values(array_diff($sandboxTables, $mirrorTables));
        $shared = array_values(array_intersect($mirrorTables, $sandboxTables));

        $ddlDiffs = 0;
        foreach ($shared as $table) {
            if ($mirrorMap[$table] === $sandboxMap[$table]) {
                continue;
            }
            $ddlDiffs++;
            $lines[] = 'DIFF  ' . $table;
        }

        foreach ($onlyMirror as $table) {
            $lines[] = 'ONLY  mirror: ' . $table;
        }
        foreach ($onlySandbox as $table) {
            $lines[] = 'ONLY  sandbox: ' . $table;
        }

        $issueCount = $ddlDiffs + count($onlyMirror) + count($onlySandbox);
        if ($issueCount === 0) {
            $lines[] = 'RESULT: PASS — DDL parity on ' . count($shared) . ' shared tables';
        } else {
            $lines[] = 'RESULT: FAIL — ' . $issueCount . ' schema difference(s)';
        }

        return [
            'lines' => $lines,
            'exit_code' => $issueCount === 0 ? 0 : 2,
            'passed' => $issueCount === 0,
        ];
    }

    /** @return array<string, string> */
    private function createTableMap(string $target, string $database): array
    {
        $pdo = $this->connect($target);
        $introspection = new SchemaIntrospection($pdo, $database);

        return $introspection->createTableMap();
    }

    private function connect(string $target): PDO
    {
        if ($this->connectionFactory !== null) {
            return ($this->connectionFactory)($target);
        }

        $dsn = $target === 'mirror' ? $this->wiring->mirrorDsn() : $this->wiring->sandboxDsn();
        $credentials = $this->wiring->credentials();

        try {
            return new PDO(
                $dsn,
                $credentials['user'],
                $credentials['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $e) {
            throw new \RuntimeException("{$target} connection failed: " . $e->getMessage(), 0, $e);
        }
    }
}
