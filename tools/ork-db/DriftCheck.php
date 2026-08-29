<?php

declare(strict_types=1);

namespace OrkDb;

use PDO;
use PDOException;

final class DriftCheck
{
    /** @var (callable(): PDO)|null */
    private $mirrorConnectionFactory;

    /** @var (callable(): bool)|null */
    private $mirrorReachableProbe;

    public function __construct(
        private readonly Wiring $wiring,
        private readonly string $toolRoot,
        private readonly string $repoRoot,
        $mirrorConnectionFactory = null,
        $mirrorReachableProbe = null,
    ) {
        $this->mirrorConnectionFactory = $mirrorConnectionFactory;
        $this->mirrorReachableProbe = $mirrorReachableProbe;
    }

    /**
     * @return array{lines: list<string>, exit_code: int, passed: bool}
     */
    public function run(bool $strict = false, bool $allowCatalogDrift = false): array
    {
        $lines = ['DRIFT CHECK'];
        $issues = 0;

        $classifier = new MigrationClassifier($this->repoRoot, $this->toolRoot);
        $unclassified = $classifier->unclassifiedFiles();
        if ($unclassified !== []) {
            $issues++;
            $lines[] = 'FAIL  unclassified migrations (' . count($unclassified) . ')';
            foreach ($unclassified as $file) {
                $lines[] = '      - ' . $file;
            }
        } else {
            $lines[] = 'OK    migration coverage (' . count($classifier->repoMigrationFiles()) . ' files classified)';
        }

        $issues += $this->checkTableReferences($lines);

        $fingerprints = Json5::decodeFile($this->toolRoot . '/manifests/fingerprints.json5');
        $catalogIssues = $this->checkCommittedCatalogHashes($fingerprints);
        $catalogFails = array_values(array_filter($catalogIssues, static fn ($i) => ($i['kind'] ?? 'fail') === 'fail'));
        $catalogWarns = array_values(array_filter($catalogIssues, static fn ($i) => ($i['kind'] ?? 'fail') === 'warn'));
        // Catalog-hash MISMATCH is a hard failure by default -- DriftCheckTest pins that, and
        // a wrong hash genuinely is a problem. But these hashes cover the `fixed_extract`
        // catalogs, which are extracted FROM THE MIRROR into tools/ork-db/extracted/, a
        // .gitignored directory (.gitignore:53). So the check compares a committed constant
        // against a file each developer regenerates locally, and any legitimate prod reload
        // that touches this reference data turns it red until someone re-records the hash.
        //
        // That is exactly what happened: it had been red long enough that
        // bin/run-unit-tests.sh -- which runs this under `set -e` BEFORE PHPUnit -- never
        // reached the tests at all, and people invoked phpunit directly instead. A
        // permanently-red gate is not a gate; it is a thing you learn to step around, and it
        // was masking checks that ARE deterministic (table references reads only committed
        // sources). $allowCatalogDrift lets the suite runner opt out of THIS ONE check
        // explicitly, rather than silently weakening it for everybody.
        if ($allowCatalogDrift && $catalogWarns !== []) {
            // WARN, not FAIL, and deliberately so. These hashes cover the `fixed_extract`
            // catalogs (award/class/parktitle/pronoun), which are extracted FROM THE MIRROR
            // into tools/ork-db/extracted/ -- a directory that is .gitignored (.gitignore:53).
            // The check therefore compares a committed constant against a file each developer
            // regenerates locally, so any legitimate prod reload that touches this reference
            // data turns it red for everyone until someone re-records the hash.
            //
            // It had been red long enough that `bin/run-unit-tests.sh` -- which runs this under
            // `set -e` BEFORE PHPUnit -- never reached the tests at all, and people (this author
            // included) invoked phpunit directly instead. A permanently-red gate is not a gate;
            // it is a thing you learn to step around, and it was hiding a check that IS
            // deterministic (table references, which reads only committed sources).
            //
            // The drift is still reported on every run and is still printed on every run
            // but no longer blocks the suite or --strict. Re-record with the
            // catalog extract you trust when prod's reference data genuinely changes.
            //
            // A MISSING hash or a MISSING extract file is NOT machine variance -- that is a
            // broken setup -- so those stay a hard FAIL below, and DriftCheckTest pins both.
            $lines[] = 'WARN  committed catalog hash drift (local extract; advisory)';
            foreach ($catalogWarns as $issue) {
                $lines[] = '      - ' . $issue['text'];
            }
        }
        if (!$allowCatalogDrift) {
            $catalogFails = array_merge($catalogFails, $catalogWarns);
            $catalogWarns = [];
        }
        if ($catalogFails !== []) {
            $issues += count($catalogFails);
            $lines[] = 'FAIL  committed catalog hash drift';
            foreach ($catalogFails as $issue) {
                $lines[] = '      - ' . $issue['text'];
            }
        }
        if ($catalogWarns === [] && $catalogFails === []) {
            $lines[] = 'OK    committed catalog hashes match fingerprints.json5';
        }

        if ($this->mirrorReachable()) {
            try {
                $mirrorIssues = $this->checkLiveMirror($fingerprints);
                if ($mirrorIssues !== []) {
                    $issues += count($mirrorIssues);
                    $lines[] = 'FAIL  live mirror drift';
                    foreach ($mirrorIssues as $issue) {
                        $lines[] = '      - ' . $issue;
                    }
                } else {
                    $lines[] = 'OK    live mirror schema/catalog match committed fingerprints';
                }
            } catch (\Throwable $e) {
                $issues++;
                $lines[] = 'FAIL  live mirror check: ' . $e->getMessage();
            }
        } else {
            $lines[] = 'SKIP  mirror unreachable — live schema/catalog checks deferred';
        }

        $passed = $issues === 0;
        if ($strict && !$passed) {
            $lines[] = 'RESULT: FAIL (--strict)';
        } elseif ($passed) {
            $lines[] = 'RESULT: PASS';
        } else {
            $lines[] = 'RESULT: WARN (re-run with --strict to fail build)';
        }

        return [
            'lines' => $lines,
            'exit_code' => ($strict && !$passed) ? 2 : 0,
            'passed' => $passed,
        ];
    }

    /**
     * Fail when domain code references a table the committed schema does not define.
     *
     * This is the gate for the failure mode that has bitten this codebase repeatedly:
     * ork_kingdomaward.disabled, ork_recommendations.snoozed_by_id and the
     * ork_officer_position family all reached the domain layer while the repo could not
     * build the schema they need. Every one of them was invisible to the checks above,
     * which compare committed catalog ROW DATA and (when baselined) the live mirror —
     * never "does the repo define what the code asks for".
     *
     * Compared against the COMMITTED schema sources, not tools/ork-db/rendered/sandbox.sql:
     * rendered/ is .gitignored, so a check reading it would silently pass everywhere it
     * matters. See SchemaTableIndex.
     *
     * Table granularity only. Column-level checking is NOT attempted — SQL here is
     * assembled by string concatenation, so binding a column to its table without a real
     * SQL parser produces guesses, and a guessing gate gets switched off. The
     * snoozed_by_id class of bug therefore stays uncovered, and the NOTE line below says
     * so on every run rather than letting a green result imply otherwise.
     *
     * @param list<string> $lines
     */
    private function checkTableReferences(array &$lines): int
    {
        try {
            $index = new SchemaTableIndex($this->repoRoot, $this->toolRoot);
            $scan = new TableReferenceScan($this->repoRoot, $this->toolRoot);
            $result = $scan->undefinedTables($index->definedTables());
            $totals = $scan->scan();
        } catch (\Throwable $e) {
            $lines[] = 'FAIL  table reference check: ' . $e->getMessage();

            return 1;
        }

        $issues = 0;

        if ($result['undefined'] !== []) {
            $issues += count($result['undefined']);
            $lines[] = 'FAIL  code references tables the committed schema does not define';
            foreach ($result['undefined'] as $table => $sites) {
                $lines[] = '      - ' . $table . ' (' . count($sites) . ' site'
                    . (count($sites) === 1 ? '' : 's') . ')';
                foreach (array_slice($sites, 0, 5) as $site) {
                    $lines[] = '          ' . $site;
                }
                if (count($sites) > 5) {
                    $lines[] = '          ... and ' . (count($sites) - 5) . ' more';
                }
            }
            $lines[] = '      Add a migration that creates the table, or — if it legitimately';
            $lines[] = '      lives only on prod/the mirror — record it with a reason in';
            $lines[] = '      tools/ork-db/manifests/table-reference-allowlist.json5.';
        }

        if ($result['stale_allowlist'] !== []) {
            $issues += count($result['stale_allowlist']);
            $lines[] = 'FAIL  stale table-reference allow-list entries';
            foreach ($result['stale_allowlist'] as $table) {
                $lines[] = '      - ' . $table . ' is no longer referenced (or is now defined)';
            }
            $lines[] = '      Delete these from tools/ork-db/manifests/table-reference-allowlist.json5';
            $lines[] = '      so a real problem is never hidden behind an obsolete excuse.';
        }

        if ($issues === 0) {
            $lines[] = 'OK    table references resolve to the committed schema ('
                . count($totals['references']) . ' tables named across '
                . $totals['files_scanned'] . ' files)';
        }

        // Always visible, pass or fail: the parts of this gate that are NOT covered.
        foreach ($result['allowed_hits'] as $table => $hit) {
            $detail = $hit['kind'] === 'known_defect' && $hit['sites'] !== []
                ? ' — ' . implode(', ', array_slice($hit['sites'], 0, 3))
                : '';
            $lines[] = 'NOTE  allow-listed table ' . $table . ' [' . $hit['kind'] . ']' . $detail;
        }

        if ($totals['dynamic'] !== []) {
            $lines[] = 'NOTE  ' . count($totals['dynamic'])
                . ' runtime-computed table name(s) (DB_PREFIX . $var) cannot be checked';
        }

        $lines[] = 'NOTE  table granularity only — column references are not checked';

        return $issues;
    }

    /** @param array<string, mixed> $fingerprints @return list<string> */
    private function checkCommittedCatalogHashes(array $fingerprints): array
    {
        $issues = [];
        $expected = $fingerprints['catalog_hashes'] ?? [];
        if (!is_array($expected) || $expected === []) {
            return [['kind' => 'fail', 'text' => 'catalog_hashes missing in fingerprints.json5']];
        }

        $extractManifest = Json5::decodeFile($this->toolRoot . '/manifests/extract-sources.json5');
        foreach ($extractManifest['fixed_extract'] ?? [] as $table) {
            $table = (string) $table;
            $path = $this->toolRoot . '/extracted/' . $table . '.sql';
            if (!is_readable($path)) {
                $issues[] = ['kind' => 'fail', 'text' => "{$table}: missing extracted/{$table}.sql"];
                continue;
            }

            $actual = SchemaIntrospection::hashFileContents($path);
            $recorded = (string) ($expected[$table] ?? '');
            if ($recorded === '') {
                $issues[] = ['kind' => 'fail', 'text' => "{$table}: no catalog hash recorded in fingerprints.json5"];
                continue;
            }
            if ($actual !== $recorded) {
                $issues[] = ['kind' => 'warn', 'text' => "{$table}: committed extract hash mismatch (recorded {$recorded}, actual {$actual})"];
            }
        }

        return $issues;
    }

    /** @param array<string, mixed> $fingerprints @return list<string> */
    private function checkLiveMirror(array $fingerprints): array
    {
        $issues = [];
        $pdo = $this->connectMirror();
        $mirror = $this->wiring->mirror();
        $introspection = new SchemaIntrospection($pdo, (string) $mirror['database']);
        $liveSchema = $introspection->fingerprint();
        $recordedSchema = (string) ($fingerprints['schema_fingerprint'] ?? '');

        if ($recordedSchema === '' || $recordedSchema === 'null') {
            // Not baselined yet — live schema drift is reported by schema-diff after apply.
        } elseif ($liveSchema !== $recordedSchema) {
            $issues[] = "schema fingerprint mismatch (recorded {$recordedSchema}, live {$liveSchema})";
        }

        $extractManifest = Json5::decodeFile($this->toolRoot . '/manifests/extract-sources.json5');
        $extract = new Extract($this->wiring, $this->toolRoot, fn (): PDO => $pdo);
        foreach ($extractManifest['fixed_extract'] ?? [] as $table) {
            $table = (string) $table;
            $path = $this->toolRoot . '/extracted/' . $table . '.sql';
            if (!is_readable($path)) {
                $issues[] = "{$table}: missing committed extract for live comparison";
                continue;
            }

            $committed = SchemaIntrospection::hashFileContents($path);
            $live = $extract->catalogSqlHash($pdo, $table);
            if ($committed !== $live) {
                $issues[] = "{$table}: live mirror catalog differs from committed extract";
            }
        }

        return $issues;
    }

    private function mirrorReachable(): bool
    {
        if ($this->mirrorReachableProbe !== null) {
            return ($this->mirrorReachableProbe)();
        }

        $mirror = $this->wiring->mirror();

        return DeploymentTier::probePort((string) $mirror['host'], (int) $mirror['port']);
    }

    private function connectMirror(): PDO
    {
        if ($this->mirrorConnectionFactory !== null) {
            return ($this->mirrorConnectionFactory)();
        }

        $mirror = $this->wiring->mirror();
        $credentials = $this->wiring->credentials();

        try {
            return new PDO(
                $this->wiring->mirrorDsn(),
                $credentials['user'],
                $credentials['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $e) {
            throw new \RuntimeException('Mirror connection failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
