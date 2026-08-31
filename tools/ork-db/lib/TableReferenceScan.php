<?php

declare(strict_types=1);

namespace OrkDb;

/**
 * Finds every database table the domain layer names, so drift-check can fail when code
 * references a table the committed schema does not define.
 *
 * Why this exists
 * ---------------
 * Three times on this codebase, code shipped against schema the repo never defined —
 * ork_kingdomaward.is_ladder/max_level/disabled, ork_recommendations.snoozed_by_id, and
 * the ork_officer_position family — because the tables/columns had been created by hand
 * on prod or by an untracked migrations/ directory. Nothing compared what the CODE asks
 * for against what the REPO builds. This does, at table granularity.
 *
 * Scanning is token-based (token_get_all), never line-regex. That is what makes comments,
 * docblocks and prose free: a table named in a `//` or `/* *\/` comment is not a T_STRING
 * or a string token, so it is never collected. Only real code is scanned.
 *
 * Recognised reference forms
 * --------------------------
 *   DB_PREFIX . 'award'                     concatenation, single or double quoted
 *   DB_PREFIX . "award WHERE x = 1"         leading identifier of the literal is the table
 *   $p = DB_PREFIX; ... "{$p}award"         interpolated prefix variable (Report/Administration)
 *   $p = DB_PREFIX; ... $p . 'award'        concatenated prefix variable
 *   'SELECT ... FROM ork_day_convert'       bare literal that already carries the prefix
 *
 * Deliberately NOT resolved
 * -------------------------
 *   DB_PREFIX . $table                      the name is computed at runtime
 * These sites are counted and surfaced as a coverage gap rather than guessed at. Guessing
 * would mean either false failures (wrong name) or false confidence (skipped silently).
 *
 * Column-level references are OUT OF SCOPE. SQL here is built by string concatenation
 * across many statements, so there is no reliable way to bind a column name to the table
 * it belongs to without a real SQL parser. The ork_recommendations.snoozed_by_id class of
 * bug therefore remains uncovered — see the note in drift-check's output.
 */
final class TableReferenceScan
{
    private const MANIFEST = '/manifests/table-reference-allowlist.json5';

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    /** @var array{references: array<string, list<string>>, dynamic: list<string>, files_scanned: int}|null */
    private ?array $scanResult = null;

    public function __construct(
        private readonly string $repoRoot,
        private readonly string $toolRoot,
    ) {
    }

    /**
     * Every table named by the scanned roots.
     *
     * @return array{
     *   references: array<string, list<string>>,
     *   dynamic: list<string>,
     *   files_scanned: int
     * }
     */
    public function scan(): array
    {
        if ($this->scanResult !== null) {
            return $this->scanResult;
        }

        $references = [];
        $dynamic = [];
        $filesScanned = 0;

        foreach ($this->scanRoots() as $root) {
            foreach ($this->phpFiles($root) as $path) {
                $filesScanned++;
                $this->scanFile($path, $references, $dynamic);
            }
        }

        foreach ($references as $table => $sites) {
            $references[$table] = array_values(array_unique($sites));
        }
        ksort($references);
        sort($dynamic, SORT_STRING);

        return [
            'references' => $references,
            'dynamic' => $dynamic,
            'files_scanned' => $filesScanned,
        ];
    }

    /**
     * Referenced tables that the committed schema does not define and the manifest does
     * not excuse.
     *
     * @param list<string> $definedTables
     * @return array{
     *   undefined: array<string, list<string>>,
     *   allowed_hits: array<string, array{kind: string, reason: string, sites: list<string>}>,
     *   stale_allowlist: list<string>
     * }
     */
    public function undefinedTables(array $definedTables): array
    {
        $defined = array_fill_keys(array_map('strtolower', $definedTables), true);
        $allowed = $this->allowList();

        $undefined = [];
        $allowedHits = [];

        foreach ($this->scan()['references'] as $table => $sites) {
            if (isset($defined[$table])) {
                continue;
            }

            if (isset($allowed[$table])) {
                $allowedHits[$table] = [
                    'kind' => (string) ($allowed[$table]['kind'] ?? 'unspecified'),
                    'reason' => (string) ($allowed[$table]['reason'] ?? ''),
                    'sites' => $sites,
                ];
                continue;
            }

            $undefined[$table] = $sites;
        }

        // An allow-list entry whose reference is gone, or whose table now exists, is dead
        // weight — it hides the next real problem behind a stale excuse.
        $stale = [];
        foreach (array_keys($allowed) as $table) {
            if (!isset($allowedHits[$table])) {
                $stale[] = $table;
            }
        }
        sort($stale, SORT_STRING);

        return [
            'undefined' => $undefined,
            'allowed_hits' => $allowedHits,
            'stale_allowlist' => $stale,
        ];
    }

    /** @return list<string> repo-relative directories to scan */
    public function scanRoots(): array
    {
        $roots = $this->loadManifest()['scan_roots'] ?? [];
        $resolved = [];

        foreach ($roots as $root) {
            $path = $this->repoRoot . '/' . trim((string) $root, '/');
            if (!is_dir($path)) {
                throw new ValidationException('table-reference scan root missing: ' . $root);
            }
            $resolved[] = $path;
        }

        if ($resolved === []) {
            throw new ValidationException('table-reference allow-list defines no scan_roots');
        }

        return $resolved;
    }

    /** @return array<string, array<string, mixed>> */
    public function allowList(): array
    {
        $entries = $this->loadManifest()['allowed'] ?? [];
        $normalized = [];

        foreach ($entries as $table => $entry) {
            if (!is_array($entry) || trim((string) ($entry['reason'] ?? '')) === '') {
                throw new ValidationException(
                    "table-reference allow-list entry '{$table}' has no reason. "
                    . 'Every entry must say why the table is absent from the committed schema.'
                );
            }
            $normalized[strtolower((string) $table)] = $entry;
        }

        return $normalized;
    }

    /** @return list<string> */
    private function phpFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (!$item->isFile() || strtolower($item->getExtension()) !== 'php') {
                continue;
            }
            $files[] = $item->getPathname();
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param array<string, list<string>> $references
     * @param list<string>                $dynamic
     */
    private function scanFile(string $path, array &$references, array &$dynamic): void
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new ValidationException('Failed to read source file: ' . $path);
        }

        $tokens = @token_get_all($source);
        $relative = $this->relativePath($path);
        $prefixVars = $this->prefixVariables($tokens);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)
                && ($token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE)
            ) {
                // Bare literal that already carries the prefix, e.g.
                // 'left join ork_day_convert c on ...' in class.Park.php.
                if (preg_match_all('/\bork_[a-z0-9_]+/i', $token[1], $bare) > 0) {
                    foreach ($bare[0] as $name) {
                        $references[strtolower($name)][] = $relative . ':' . $token[2];
                    }
                }
            }

            $isPrefixConstant = is_array($token) && $token[0] === T_STRING && $token[1] === 'DB_PREFIX';
            $isPrefixVariable = is_array($token)
                && $token[0] === T_VARIABLE
                && isset($prefixVars[$token[1]])
                && !$this->insideInterpolation($tokens, $i);

            if ($isPrefixConstant || $isPrefixVariable) {
                $next = $this->nextSignificant($tokens, $i);
                if ($next === null || $tokens[$next] !== '.') {
                    continue;
                }

                $operand = $this->nextSignificant($tokens, $next);
                if ($operand === null) {
                    continue;
                }

                $value = $tokens[$operand];
                if (is_array($value) && $value[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $name = $this->leadingIdentifier(substr($value[1], 1));
                    if ($name !== null) {
                        $references['ork_' . strtolower($name)][] = $relative . ':' . $value[2];
                    }
                    continue;
                }

                if (is_array($value) && $value[0] === T_VARIABLE) {
                    $dynamic[] = $relative . ':' . $value[2];
                }

                continue;
            }

            // "{$p}award" / <<<SQL {$p}award SQL — the table name is the head of the
            // encapsed run that follows the closing brace.
            if (is_array($token) && $token[0] === T_VARIABLE && isset($prefixVars[$token[1]])) {
                $close = $i + 1;
                if (($tokens[$close] ?? null) !== '}') {
                    continue;
                }
                $tail = $tokens[$close + 1] ?? null;
                if (!is_array($tail) || $tail[0] !== T_ENCAPSED_AND_WHITESPACE) {
                    continue;
                }
                $name = $this->leadingIdentifier($tail[1]);
                if ($name !== null) {
                    $references['ork_' . strtolower($name)][] = $relative . ':' . $tail[2];
                }
            }
        }
    }

    /**
     * Variables assigned the raw prefix: `$p = DB_PREFIX;`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<string, true>
     */
    private function prefixVariables(array $tokens): array
    {
        $vars = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_VARIABLE) {
                continue;
            }

            $assign = $this->nextSignificant($tokens, $i);
            if ($assign === null || $tokens[$assign] !== '=') {
                continue;
            }

            $value = $this->nextSignificant($tokens, $assign);
            if ($value === null) {
                continue;
            }

            $valueToken = $tokens[$value];
            if (!is_array($valueToken) || $valueToken[0] !== T_STRING || $valueToken[1] !== 'DB_PREFIX') {
                continue;
            }

            $terminator = $this->nextSignificant($tokens, $value);
            if ($terminator !== null && $tokens[$terminator] === ';') {
                $vars[$token[1]] = true;
            }
        }

        return $vars;
    }

    /**
     * True when a T_VARIABLE sits inside "{$p}" — that form is handled by the
     * interpolation branch, not the concatenation branch.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function insideInterpolation(array $tokens, int $index): bool
    {
        $previous = $tokens[$index - 1] ?? null;

        return is_array($previous)
            && ($previous[0] === T_CURLY_OPEN || $previous[0] === T_DOLLAR_OPEN_CURLY_BRACES);
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function nextSignificant(array $tokens, int $index): ?int
    {
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                return $i;
            }
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * The table name at the head of a SQL fragment: 'award WHERE x = 1' -> 'award'.
     * A leading backtick is skipped so "`{$p}award`" style quoting still resolves.
     */
    private function leadingIdentifier(string $fragment): ?string
    {
        if (preg_match('/^`?([A-Za-z0-9_]+)/', $fragment, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    private function relativePath(string $path): string
    {
        $prefix = $this->repoRoot . '/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    /** @return array<string, mixed> */
    private function loadManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $path = $this->toolRoot . self::MANIFEST;
        if (!is_readable($path)) {
            throw new ValidationException('Missing table-reference allow-list manifest: ' . $path);
        }

        $this->manifest = Json5::decodeFile($path);

        return $this->manifest;
    }
}
