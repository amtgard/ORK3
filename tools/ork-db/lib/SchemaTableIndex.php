<?php

declare(strict_types=1);

namespace OrkDb;

/**
 * The set of tables the repository's COMMITTED schema sources define.
 *
 * This is deliberately assembled from the same inputs Render::sectionSchema() and
 * Render::sectionCatalogs() consume, rather than from tools/ork-db/rendered/sandbox.sql:
 *
 *   - rendered/ is .gitignored. A check that reads it silently no-ops on a fresh clone,
 *     in CI, and on any machine that has not run `bin/ork-db render` — which is exactly
 *     the situation the check exists to protect.
 *   - The committed sources ARE the definition of record. If a table is missing from
 *     them, `bin/ork-db bootstrap` on a clean machine produces a database without it,
 *     and any code that touches it fails at runtime.
 *
 * Sources, in render order:
 *   ork.sql
 *   tools/ork-db/templates/schema/baseline-gaps.sql
 *   every migration MigrationClassifier::renderSources() yields (render: full|override)
 *   tools/ork-db/templates/schema/post-schema-indexes.sql
 *   tools/ork-db/templates/schema/supplements.sql
 *   tools/ork-db/templates/catalogs/*.sql   (committed catalogs: the hand-maintained
 *                                            ork_day_convert, plus award/class/parktitle/
 *                                            pronoun, which extract refreshes in place)
 *
 * tools/ork-db/extracted/*.sql is NOT a source. It is .gitignored — it holds
 * mundane_real.json and configuration.sql, which carry real player data — and every table
 * it carries is already created by ork.sql; extract only supplies ROW DATA. Note that of
 * the committed catalogs only day_convert.sql carries DDL; the four extracted ones are
 * INSERT-only and therefore define no tables here.
 */
final class SchemaTableIndex
{
    /** @var list<string>|null */
    private ?array $tables = null;

    public function __construct(
        private readonly string $repoRoot,
        private readonly string $toolRoot,
    ) {
    }

    /**
     * Lowercased table names defined by the committed schema sources.
     *
     * @return list<string>
     */
    public function definedTables(): array
    {
        if ($this->tables !== null) {
            return $this->tables;
        }

        $defined = [];

        foreach ($this->sourceFiles() as $path) {
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new ValidationException('Failed to read schema source: ' . $path);
            }

            $this->applyStatements($sql, $defined);
        }

        $names = array_keys($defined);
        sort($names, SORT_STRING);
        $this->tables = $names;

        return $this->tables;
    }

    /**
     * Absolute paths of every committed file that contributes DDL, in render order.
     *
     * @return list<string>
     */
    public function sourceFiles(): array
    {
        $paths = [$this->repoRoot . '/ork.sql'];

        $baselineGaps = $this->toolRoot . '/templates/schema/baseline-gaps.sql';
        if (is_readable($baselineGaps)) {
            $paths[] = $baselineGaps;
        }

        $classifier = new MigrationClassifier($this->repoRoot, $this->toolRoot);
        foreach ($classifier->renderSources() as $source) {
            $paths[] = $source['path'];
        }

        foreach (['post-schema-indexes.sql', 'supplements.sql'] as $tail) {
            $path = $this->toolRoot . '/templates/schema/' . $tail;
            if (is_readable($path)) {
                $paths[] = $path;
            }
        }

        $catalogs = glob($this->toolRoot . '/templates/catalogs/*.sql') ?: [];
        sort($catalogs, SORT_STRING);
        foreach ($catalogs as $path) {
            $paths[] = $path;
        }

        if (!is_readable($paths[0])) {
            throw new ValidationException('Schema file not readable: ' . $paths[0]);
        }

        return $paths;
    }

    /**
     * Fold one file's CREATE / DROP / RENAME statements into the running table set.
     *
     * Order matters: ork.sql pairs `DROP TABLE IF EXISTS x` with `CREATE TABLE x`, and a
     * future migration that drops or renames a table has to remove it from the set.
     *
     * @param array<string, true> $defined
     */
    private function applyStatements(string $sql, array &$defined): void
    {
        $sql = $this->stripComments($sql);
        $name = '`?([A-Za-z0-9_$]+)`?';

        $pattern = '/\b(?:'
            . 'CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?P<create>' . $name . ')'
            . '|CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM\s*=\s*\w+\s+)?(?:DEFINER\s*=\s*\S+\s+)?'
            . '(?:SQL\s+SECURITY\s+\w+\s+)?VIEW\s+(?:IF\s+NOT\s+EXISTS\s+)?(?P<view>' . $name . ')'
            . '|DROP\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+EXISTS\s+)?(?P<drop>' . $name . ')'
            . '|DROP\s+VIEW\s+(?:IF\s+EXISTS\s+)?(?P<dropview>' . $name . ')'
            . '|RENAME\s+TABLE\s+(?P<renamefrom>' . $name . ')\s+TO\s+(?P<renameto>' . $name . ')'
            . '|ALTER\s+TABLE\s+(?P<alterfrom>' . $name . ')\s+RENAME\s+(?:TO\s+)?(?P<alterto>' . $name . ')'
            . ')/i';

        if (preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER) === false) {
            throw new ValidationException('Failed to scan schema source for DDL');
        }

        foreach ($matches as $match) {
            foreach (['create', 'view'] as $key) {
                if (($match[$key] ?? '') !== '') {
                    $defined[strtolower(trim($match[$key], '`'))] = true;
                }
            }

            foreach (['drop', 'dropview'] as $key) {
                if (($match[$key] ?? '') !== '') {
                    unset($defined[strtolower(trim($match[$key], '`'))]);
                }
            }

            foreach ([['renamefrom', 'renameto'], ['alterfrom', 'alterto']] as [$from, $to]) {
                if (($match[$to] ?? '') === '') {
                    continue;
                }
                unset($defined[strtolower(trim($match[$from], '`'))]);
                $defined[strtolower(trim($match[$to], '`'))] = true;
            }
        }
    }

    /**
     * Drop `--`, `#` and block comments so a table named only in prose is never counted
     * as defined. `/*!40101 ... *\/` executable comments keep their body.
     */
    private function stripComments(string $sql): string
    {
        $sql = preg_replace('#/\*(?!!)[\s\S]*?\*/#', ' ', $sql) ?? $sql;
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $sql = preg_replace('/\s--\s.*$/m', '', $sql) ?? $sql;

        return preg_replace('/^\s*#.*$/m', '', $sql) ?? $sql;
    }
}
