<?php

declare(strict_types=1);

namespace OrkDb;

use PDO;
use PDOException;

final class SchemaDiff
{
    /** @var (callable(string): PDO)|null */
    private $connectionFactory;

    private readonly string $toolRoot;

    public function __construct(
        private readonly Wiring $wiring,
        private readonly string $repoRoot,
        $connectionFactory = null,
        ?string $toolRoot = null,
    ) {
        $this->connectionFactory = $connectionFactory;
        $this->toolRoot = $toolRoot ?? $repoRoot . '/tools/ork-db';
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

        $allowance = $this->applyMirrorOnlyColumnAllowances($mirrorMap, $sandboxMap);
        foreach ($allowance['notes'] as $note) {
            $lines[] = $note;
        }

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

        foreach ($allowance['stale'] as $stale) {
            $lines[] = 'FAIL  stale schema-diff allowance: ' . $stale;
        }

        $issueCount = $ddlDiffs + count($onlyMirror) + count($onlySandbox) + count($allowance['stale']);
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


    /**
     * Drop columns that legitimately exist only on the mirror from the mirror-side DDL.
     *
     * The mirror is shared and always ahead: it carries whatever every in-flight branch has
     * applied to it. Comparing the whole DDL string against it means a column another branch
     * added to ork_mundane makes ork_mundane differ forever, whatever this repository does —
     * the same permanently-red shape that made drift-check's catalog hash worthless.
     *
     * So each allowance is narrow (one named column on one named table), carries a reason,
     * is printed on every run, and is reported STALE — a hard failure — the moment the
     * column leaves the mirror or the repo starts defining it. See
     * manifests/schema-diff-allowlist.json5.
     *
     * @param array<string, string> $mirrorMap
     * @param array<string, string> $sandboxMap
     * @return array{notes: list<string>, stale: list<string>}
     */
    private function applyMirrorOnlyColumnAllowances(array &$mirrorMap, array $sandboxMap): array
    {
        $path = $this->toolRoot . '/manifests/schema-diff-allowlist.json5';
        if (!is_readable($path)) {
            return ['notes' => [], 'stale' => []];
        }

        $manifest = Json5::decodeFile($path);
        $notes = [];
        $stale = [];

        foreach ($manifest['mirror_only_columns'] ?? [] as $table => $columns) {
            $table = (string) $table;
            if (!is_array($columns)) {
                throw new ValidationException("schema-diff-allowlist: {$table} must map columns to entries");
            }

            foreach ($columns as $column => $entry) {
                $column = (string) $column;
                $reason = is_array($entry) ? trim((string) ($entry['reason'] ?? '')) : '';
                if ($reason === '') {
                    throw new ValidationException(
                        "schema-diff-allowlist: {$table}.{$column} needs a reason"
                    );
                }

                if (!isset($mirrorMap[$table]) || !self::hasColumn($mirrorMap[$table], $column)) {
                    $stale[] = "{$table}.{$column} is no longer a mirror-only column — delete the entry";

                    continue;
                }

                if (isset($sandboxMap[$table]) && self::hasColumn($sandboxMap[$table], $column)) {
                    $stale[] = "{$table}.{$column} is now defined by the repo — delete the entry";

                    continue;
                }

                $mirrorMap[$table] = self::removeColumn($mirrorMap[$table], $column);
                $notes[] = 'NOTE  mirror-only column ' . $table . '.' . $column . ' — ' . $reason;
            }
        }

        return ['notes' => $notes, 'stale' => $stale];
    }

    private static function hasColumn(string $ddl, string $column): bool
    {
        return self::columnLineIndex(explode("\n", $ddl), $column) !== null;
    }

    private static function removeColumn(string $ddl, string $column): string
    {
        $lines = explode("\n", $ddl);
        $index = self::columnLineIndex($lines, $column);
        if ($index === null) {
            return $ddl;
        }

        array_splice($lines, $index, 1);

        // The member block is comma-separated, so dropping the last member would leave a
        // dangling comma on the line before the closing paren.
        for ($i = count($lines) - 1; $i > 0; $i--) {
            if (str_starts_with(ltrim($lines[$i]), ')')) {
                $lines[$i - 1] = rtrim(rtrim($lines[$i - 1]), ',');
                break;
            }
        }

        return implode("\n", $lines);
    }

    /** @param list<string> $lines */
    private static function columnLineIndex(array $lines, string $column): ?int
    {
        $needle = '`' . $column . '` ';
        foreach ($lines as $index => $line) {
            if ($index === 0) {
                continue;
            }
            if (str_starts_with(ltrim($line), $needle)) {
                return $index;
            }
        }

        return null;
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
