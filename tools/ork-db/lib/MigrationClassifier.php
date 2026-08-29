<?php

declare(strict_types=1);

namespace OrkDb;

final class MigrationClassifier
{
    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly string $repoRoot,
        private readonly string $toolRoot,
    ) {
    }

    /** @return list<string> */
    public function repoMigrationFiles(): array
    {
        $dir = $this->repoRoot . '/db-migrations';
        if (!is_dir($dir)) {
            throw new \RuntimeException("Missing db-migrations directory: {$dir}");
        }

        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_file($path)) {
                $files[] = $entry;
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /** @return list<string> */
    public function unclassifiedFiles(): array
    {
        $manifest = $this->loadManifest();
        $entries = $manifest['migrations'] ?? [];
        $unclassified = [];

        foreach ($this->repoMigrationFiles() as $file) {
            if (!isset($entries[$file])) {
                $unclassified[] = $file;
            }
        }

        return $unclassified;
    }

    /**
     * @return list<array{name: string, path: string, class: string}>
     */
    public function renderSources(): array
    {
        $manifest = $this->loadManifest();
        $entries = $manifest['migrations'] ?? [];
        $sources = [];

        foreach ($this->repoMigrationFiles() as $file) {
            if (!isset($entries[$file])) {
                continue;
            }

            $entry = $entries[$file];
            $render = (string) ($entry['render'] ?? 'skip');
            if ($render === 'skip') {
                continue;
            }

            $path = match ($render) {
                'full' => $this->repoRoot . '/db-migrations/' . $file,
                'override' => $this->resolveOverridePath((string) ($entry['override'] ?? '')),
                default => null,
            };

            if ($path === null || !is_readable($path)) {
                throw new ValidationException("Migration render source missing for {$file}");
            }

            $sources[] = [
                'name' => $file,
                'path' => $path,
                'class' => (string) ($entry['class'] ?? ''),
            ];
        }

        return $sources;
    }

    public function sanitizeMigrationSql(string $sql): string
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $kept[] = '';
                continue;
            }

            if (preg_match('/^USE\s+[`"]?\w+[`"]?\s*;?\s*$/i', $trimmed) === 1) {
                continue;
            }

            if (preg_match('/^\s*COMMIT\s*;?\s*$/i', $trimmed) === 1) {
                continue;
            }

            // Strip a DATABASE qualifier (`ork`.`foo` / ork.foo) so a migration written
            // against the mirror applies to the sandbox schema.
            //
            // Bounded to the real database names ON PURPOSE. The previous pattern was
            // (?:ork|[\w]+), and that [\w]+ branch matched ANY identifier before a dot --
            // so it also stripped table ALIASES and table-qualified columns. A correct
            // multi-table UPDATE in db-migrations/2026-08-25-04-officer-position.sql:
            //     UPDATE `ork_officer` o JOIN `ork_officer_position` p
            //       ON p.kingdom_id=0 AND p.canonical_key=o.role
            //       SET o.position_id=p.position_id;
            // rendered as `SET position_id=position_id`, which MariaDB rejects with
            // "Column 'position_id' in SET is ambiguous" -- the render simply would not
            // build. Where two joined columns have DIFFERENT names it is worse than an
            // error: the statement silently becomes a self-assignment.
            //
            // Exact names matter here: `ork.ork_officer` is a database-qualified table
            // and must lose its prefix, while `ork_officer.role` is a TABLE-qualified
            // column and must keep it. A looser 'ork\w*' would wrongly strip the latter.
            $dbQualifier = 'ork_test|ork';
            $line = preg_replace('/`(?:' . $dbQualifier . ')`\.`(\w+)`/', '`\1`', $line) ?? $line;
            $line = preg_replace('/\b(?:' . $dbQualifier . ')\.(\w+)/', '\1', $line) ?? $line;

            $kept[] = $line;
        }

        return trim(implode("\n", $kept));
    }

    /** @return array<string, mixed> */
    public function loadManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $path = $this->toolRoot . '/manifests/migration-classification.json5';
        if (!is_readable($path)) {
            throw new ValidationException('Missing migration classification manifest: ' . $path);
        }

        $this->manifest = Json5::decodeFile($path);

        return $this->manifest;
    }

    private function resolveOverridePath(string $relative): string
    {
        if ($relative === '') {
            throw new ValidationException('Migration override path is empty');
        }

        return $this->toolRoot . '/templates/schema/overrides/' . ltrim($relative, '/');
    }
}
