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
    public function run(bool $strict = false): array
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

        $fingerprints = Json5::decodeFile($this->toolRoot . '/manifests/fingerprints.json5');
        $catalogIssues = $this->checkCommittedCatalogHashes($fingerprints);
        if ($catalogIssues !== []) {
            $issues += count($catalogIssues);
            $lines[] = 'FAIL  committed catalog hash drift';
            foreach ($catalogIssues as $issue) {
                $lines[] = '      - ' . $issue;
            }
        } else {
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

    /** @param array<string, mixed> $fingerprints @return list<string> */
    private function checkCommittedCatalogHashes(array $fingerprints): array
    {
        $issues = [];
        $expected = $fingerprints['catalog_hashes'] ?? [];
        if (!is_array($expected) || $expected === []) {
            return ['catalog_hashes missing in fingerprints.json5'];
        }

        $extractManifest = Json5::decodeFile($this->toolRoot . '/manifests/extract-sources.json5');
        foreach ($extractManifest['fixed_extract'] ?? [] as $table) {
            $table = (string) $table;
            $path = $this->toolRoot . '/extracted/' . $table . '.sql';
            if (!is_readable($path)) {
                $issues[] = "{$table}: missing extracted/{$table}.sql";
                continue;
            }

            $actual = SchemaIntrospection::hashFileContents($path);
            $recorded = (string) ($expected[$table] ?? '');
            if ($recorded === '') {
                $issues[] = "{$table}: no catalog hash recorded in fingerprints.json5";
                continue;
            }
            if ($actual !== $recorded) {
                $issues[] = "{$table}: committed extract hash mismatch (recorded {$recorded}, actual {$actual})";
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
