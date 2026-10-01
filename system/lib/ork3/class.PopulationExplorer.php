<?php

/**
 * Population Explorer: criteria/columns registry and filter-tree validator.
 *
 * The filter tree is untrusted client input. NormalizeTree() either returns a
 * canonical tree whose values are all ints / validated dates, or an error.
 * CompileTree() / ColumnSelectSql() turn a canonical tree into a SQL boolean
 * over alias `m` (ork_mundane). Only ints and validated dates ever reach SQL.
 */
class PopulationExplorer extends Ork3
{
    public const MAX_DEPTH = 6;
    public const MAX_LEAVES = 40;
    public const MAX_LIST = 100;
    public const MAX_ROWS = 5000;
    public const MAX_LINK_BYTES = 8192;
    /** Share-link URL parameter. Not `q`: Google Analytics logs any `q` as a site search. */
    public const LINK_PARAM = 'pe';
    public const MAX_MONTHS = 60;
    /** MariaDB max_statement_time (seconds) for the row and COUNT queries. */
    public const STATEMENT_TIMEOUT_S = 10;
    /** RuntimeException code _select() uses for a statement killed by the timeout. */
    public const TIMEOUT_CODE = 1969;
    public const TIMEOUT_MESSAGE = 'This query took too long — narrow your filter.';
    /**
     * Named-lock prefix: one Run at a time per player. Named locks are server-wide,
     * so the name also carries the database (see RunLockName): staging and
     * production on one server never block each other.
     */
    public const RUN_LOCK_PREFIX = 'pe:';
    /** MariaDB's limit on a user-level lock name. */
    public const LOCK_NAME_MAX = 64;
    public const BUSY_MESSAGE = 'Another Population Explorer run of yours is still in progress — please wait for it to finish.';

    public const OPS_CMP = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'between'];
    public const OPS_SET = ['is', 'is_not', 'in', 'not_in'];
    public const OPS_PEER = ['has_any', 'has_all', 'has_none', 'is'];
    public const OPS_BOOL = ['is'];
    public const SQL_CMP = ['eq' => '=', 'ne' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];
    private const NOTE_NONE_HELD = 'Players who hold none at all also match.';
    /** Rule rejection for a restricted criterion (suspended, banned) sent by a non-officer of the scope. */
    public const RESTRICTED_MESSAGE = 'This filter requires officer access for this kingdom or park.';

    /** Ladder Award Ranks (spec §4): one number criterion per ladder in the scope. */
    public const LADDER_GROUP = 'Ladder Award Ranks';
    public const LADDER_NOTE = 'Players with no award in this ladder count as rank 0.';
    public const LADDER_UNAVAILABLE_MESSAGE = "This ladder isn't available for this kingdom or park.";
    /** Order of the Walker in the Middle: is_ladder = 1 but not a ranked ladder (as in Report::GetLadderAwardGrid). */
    public const WALKER_AWARD_ID = 31;
    private const LADDER_ID_RE = '/^ladder_([ak])([1-9]\d{0,9})$/D';

    /** Last sign-in days ago (spec §3.4): whole days, 0 to MAX_DAYS_AGO (100 years). */
    public const MAX_DAYS_AGO = 36500;
    public const DAYS_AGO_NOTE = 'Players who have never signed in are not matched. Use Total sign-ins = 0 to find them.';

    public function __construct()
    {
        parent::__construct();
    }

    public function Registry(): array
    {
        return ['criteria' => $this->_criteria(), 'columns' => $this->_columnDefs()];
    }

    private function _opsFor(string $type): array
    {
        switch ($type) {
            case 'enum_set':
                return self::OPS_SET;
            case 'bool':
                return self::OPS_BOOL;
            case 'peerage_set':
                return self::OPS_PEER;
            default:
                return self::OPS_CMP;
        }
    }

    private function _crit(string $label, string $group, string $type, array $extra = []): array
    {
        return array_merge([
            'label'    => $label,
            'group'    => $group,
            'type'     => $type,
            'operands' => $this->_opsFor($type),
            'param'    => false,
            // nullable: the value can be missing (never signed in, no dues). Negated
            // operands then do not match those players (spec §3.2). Every criterion
            // whose negated operands treat "no value" specially carries a neg_note
            // (shown by the UI on those operands) saying exactly what happens.
            'nullable' => false,
            'sql'      => null,
        ], $extra);
    }

    /** Criteria with SQL builders. $known['ladders'] (LoadLadders) adds the scope's ladder criteria. */
    private function _criteria(array $known = []): array
    {
        $list = $this->_criteriaDefs($known);
        foreach ($list as $id => &$def) {
            $def['sql'] = $this->_criterionSql($id, $def);
        }
        unset($def);
        return $list;
    }

    /**
     * Criteria definitions. The static list is scope-free; the Ladder Award Ranks
     * criteria are generated from $known['ladders'] (see LoadLadders), so every
     * caller that validates, compiles or publishes the registry for a scope passes
     * the same ladder set.
     */
    private function _criteriaDefs(array $known = []): array
    {
        return $this->_staticCriteriaDefs() + $this->_ladderDefs($known['ladders'] ?? []);
    }

    private function _staticCriteriaDefs(): array
    {
        return [
            'last_signin'           => $this->_crit('Last sign-in date', 'Activity', 'date', ['nullable' => true, 'neg_note' => 'Players who have never signed in are not matched.']),
            // DATEDIFF over the same expression (owner decision 2026-10-01): never signed
            // in is NULL, which no operand matches, so the note is shown on every operand
            // rather than as a neg_note on the negated ones only.
            'last_signin_days_ago'  => $this->_crit('Last sign-in days ago', 'Activity', 'number', [
                'min'      => 0,
                'max'      => self::MAX_DAYS_AGO,
                'nullable' => true,
                'note'     => self::DAYS_AGO_NOTE,
            ]),
            'player_since'          => $this->_crit('Player since', 'Activity', 'date', [
                'nullable' => true,
                'neg_note' => 'Players with no sign-in dated 1988 or later are not matched.',
                'note'     => 'First sign-in dated 1988 or later, as on the player profile. Earlier dates (such as 0000-00-00) are data-entry errors and are ignored.',
            ]),
            'signins_last_n_months' => $this->_crit('Sign-ins in last N months', 'Activity', 'number', ['param' => true]),
            'total_signins'         => $this->_crit('Total sign-ins', 'Activity', 'number'),
            'last_class'            => $this->_crit('Last class played', 'Activity', 'enum_set', ['set' => 'class', 'nullable' => true, 'neg_note' => 'Players with no sign-in that recorded a class are not matched.']),
            // Negated: "did not play these classes in the window", so no sign-ins at all also matches (spec §3.4).
            'classes_last_n_months' => $this->_crit('Classes played in last N months', 'Activity', 'enum_set', ['set' => 'class', 'param' => true, 'neg_note' => 'Players with no sign-ins in the last N months also match (they did not play these classes).']),
            'home_kingdom'          => $this->_crit('Home kingdom', 'Location', 'enum_set', ['set' => 'kingdom']),
            'home_park'             => $this->_crit('Home park', 'Location', 'enum_set', ['set' => 'park']),
            'last_signin_park'      => $this->_crit('Last sign-in park', 'Location', 'enum_set', ['set' => 'park', 'nullable' => true, 'neg_note' => 'Players with no last sign-in park are not matched: they never signed in, or last signed in at an event with no park.']),
            'dues_paid'             => $this->_crit('Dues paid', 'Status', 'bool', ['note' => 'Same as the Dues report: dues paid to this scope that run through today or later, or lifetime dues.']),
            'dues_through'          => $this->_crit('Dues paid through', 'Status', 'date', ['nullable' => true, 'neg_note' => 'Players with no dues paid to this scope are not matched.', 'note' => 'Latest dues date paid to this scope. Lifetime dues count as paid through 9999-12-31 and show as "Lifetime".']),
            'waivered'              => $this->_crit('Waivered', 'Status', 'bool'),
            'active'                => $this->_crit('Active', 'Status', 'bool'),
            // restricted: officers of the scope only (spec §3.3). Hidden from everyone
            // else by PublicCriteria and rejected for them by NormalizeTree.
            'suspended'             => $this->_crit('Suspended', 'Status', 'bool', ['restricted' => true]),
            'banned'                => $this->_crit('Banned', 'Status', 'bool', ['restricted' => true]),
            'knighthood'            => $this->_crit('Knighthood', 'Peerage', 'peerage_set', ['peerage' => ['Knight'], 'neg_note' => self::NOTE_NONE_HELD]),
            'masterhood'            => $this->_crit('Masterhood', 'Peerage', 'peerage_set', ['peerage' => ['Master'], 'neg_note' => self::NOTE_NONE_HELD]),
            'paragon'               => $this->_crit('Paragon', 'Peerage', 'peerage_set', ['peerage' => ['Paragon'], 'neg_note' => self::NOTE_NONE_HELD]),
            // Exactly these three (spec §4): Lords-Page and Apprentice are not included, hence the explicit label.
            'lesser_peerage'        => $this->_crit('Squire / Page / Man-At-Arms held', 'Peerage', 'peerage_set', ['peerage' => ['Squire', 'Page', 'Man-At-Arms'], 'neg_note' => self::NOTE_NONE_HELD]),
            'has_award'             => $this->_crit('Has award', 'Awards', 'enum_set', ['set' => 'award', 'neg_note' => 'Players with no awards at all also match.']),
            'award_count'           => $this->_crit('Award count', 'Awards', 'number'),
            'award_date_any'        => $this->_crit('Any award received date', 'Awards', 'date', [
                'operands' => ['gt', 'gte', 'lt', 'lte', 'between'],
                'note'     => 'Awards with an unknown date (before 1980, such as 0000-00-00) are ignored.',
            ]),
            'reeve_qualified'       => $this->_crit('Reeve qualified', 'Qualifications', 'bool'),
            'corpora_qualified'     => $this->_crit('Corpora qualified', 'Qualifications', 'bool'),
        ];
    }

    /**
     * One number criterion per scope ladder, in the order given (LoadLadders sorts by
     * label). An entry whose id, kind and int id do not agree is dropped, so only
     * well-formed ints can ever reach the rank SQL.
     *
     * @param array<string, array{label:string, kind:string, id:int}> $ladders
     */
    private function _ladderDefs(array $ladders): array
    {
        $out = [];
        foreach ($ladders as $cid => $l) {
            if (!is_string($cid) || !preg_match(self::LADDER_ID_RE, $cid, $m) || !is_array($l)
                || !is_int($l['id'] ?? null) || (string)$l['id'] !== $m[2]
                || ($l['kind'] ?? null) !== ($m[1] === 'a' ? 'award' : 'kingdomaward')
                || !is_string($l['label'] ?? null)) {
                continue;
            }
            $out[$cid] = $this->_crit($l['label'], self::LADDER_GROUP, 'number', [
                'min'    => 0,
                // Rank is 0, never NULL, for players with no award in the ladder, so
                // there is no neg_note: ne / lt / lte include them.
                'note'   => self::LADDER_NOTE,
                'ladder' => ['kind' => $l['kind'], 'id' => $l['id']],
            ]);
        }
        return $out;
    }

    private function _col(string $label, string $group, string $type, bool $default = false): array
    {
        return ['label' => $label, 'group' => $group, 'type' => $type, 'default' => $default];
    }

    private function _columnDefs(): array
    {
        return [
            'persona'           => $this->_col('Persona', 'Player', 'text', true),
            'home_park'         => $this->_col('Home park', 'Location', 'text', true),
            'home_kingdom'      => $this->_col('Home kingdom', 'Location', 'text'),
            'last_signin'       => $this->_col('Last sign-in date', 'Activity', 'date', true),
            'last_signin_park'  => $this->_col('Last sign-in park', 'Activity', 'text'),
            'last_class'        => $this->_col('Last class', 'Activity', 'text'),
            'signins_6m'        => $this->_col('Sign-ins (6 months)', 'Activity', 'number'),
            'total_signins'     => $this->_col('Total sign-ins', 'Activity', 'number'),
            'player_since'      => $this->_col('Player since', 'Activity', 'date'),
            'dues_paid'         => $this->_col('Dues paid', 'Status', 'bool', true),
            'dues_through'      => $this->_col('Dues paid through', 'Status', 'date'),
            'waivered'          => $this->_col('Waivered', 'Status', 'bool'),
            'active'            => $this->_col('Active', 'Status', 'bool'),
            'knighthoods'       => $this->_col('Knighthoods', 'Peerage', 'text'),
            'masterhoods'       => $this->_col('Masterhoods', 'Peerage', 'text'),
            'paragons'          => $this->_col('Paragons', 'Peerage', 'text'),
            'award_count'       => $this->_col('Award count', 'Awards', 'number'),
            'reeve_qualified'   => $this->_col('Reeve qualified', 'Qualifications', 'bool'),
            'corpora_qualified' => $this->_col('Corpora qualified', 'Qualifications', 'bool'),
        ];
    }

    /**
     * Validate and canonicalise a filter tree.
     *
     * $known carries the viewer context too: $known['officer'] must be exactly true
     * for restricted criteria (suspended, banned) to be accepted. Anything else,
     * including a missing key, is a non-officer: the check fails closed.
     *
     * @return array ['ok'=>true,'tree'=>array] or ['ok'=>false,'error'=>string,'path'=>int[]]
     */
    public function NormalizeTree(array $tree, ?array $known = null): array
    {
        $known = $known ?? $this->LoadKnown();
        $registry = $this->_criteriaDefs($known); // validation needs operands/param only, not the SQL closures
        $leafCount = 0;
        $r = $this->_normNode($tree, 1, $leafCount, [], $registry, $known);
        if (isset($r['error'])) {
            return ['ok' => false, 'error' => $r['error'], 'path' => $r['path']];
        }
        return ['ok' => true, 'tree' => $r['node']];
    }

    private function _err(string $msg, array $path): array
    {
        return ['error' => $msg, 'path' => $path];
    }

    private function _normNode($node, int $depth, int &$leafCount, array $path, array $registry, array $known): array
    {
        if (!is_array($node)) {
            return $this->_err('Invalid filter node', $path);
        }
        if (array_key_exists('children', $node)) {
            $op = $node['op'] ?? null;
            if ($op !== 'AND' && $op !== 'OR') {
                return $this->_err('Group operator must be AND or OR', $path);
            }
            if (!is_array($node['children'])) {
                return $this->_err('Group children must be a list', $path);
            }
            if ($depth > self::MAX_DEPTH) {
                return $this->_err('Filter is nested too deeply', $path);
            }
            $out = [];
            $i = 0;
            foreach ($node['children'] as $child) {
                $r = $this->_normNode($child, $depth + 1, $leafCount, array_merge($path, [$i]), $registry, $known);
                $i++;
                if (isset($r['error'])) {
                    return $r;
                }
                if (isset($r['node']['children']) && count($r['node']['children']) === 0) {
                    continue; // empty sub-group is dropped
                }
                $out[] = $r['node'];
            }
            return ['node' => ['op' => $op, 'children' => $out]];
        }

        $leafCount++;
        if ($leafCount > self::MAX_LEAVES) {
            return $this->_err('Filter has too many rules (max ' . self::MAX_LEAVES . ')', $path);
        }
        return $this->_normLeaf($node, $path, $registry, $known);
    }

    private function _normLeaf(array $leaf, array $path, array $registry, array $known): array
    {
        $c = $leaf['c'] ?? null;
        if (!is_string($c) || !isset($registry[$c])) {
            if (is_string($c) && preg_match(self::LADDER_ID_RE, $c)) {
                return $this->_err(self::LADDER_UNAVAILABLE_MESSAGE, $path);
            }
            return $this->_err('Unknown criterion', $path);
        }
        $def = $registry[$c];
        if (!empty($def['restricted']) && ($known['officer'] ?? false) !== true) {
            return $this->_err(self::RESTRICTED_MESSAGE, $path);
        }
        $o = $leaf['o'] ?? null;
        if (!is_string($o) || !in_array($o, $def['operands'], true)) {
            return $this->_err('Invalid operand for ' . $def['label'], $path);
        }
        $out = ['c' => $c, 'o' => $o];
        $p = null;
        if ($def['param']) {
            $p = $this->_int($leaf['p'] ?? null);
            if ($p === null || $p < 1 || $p > self::MAX_MONTHS) {
                return $this->_err('Missing or invalid param: months must be 1 to ' . self::MAX_MONTHS, $path);
            }
        }
        $v = $this->_normValue($def, $o, $leaf['v'] ?? null, $known);
        if (isset($v['error'])) {
            return $this->_err($v['error'], $path);
        }
        $out['v'] = $v['v'];
        if ($p !== null) {
            $out['p'] = $p;
        }
        return ['node' => $out];
    }

    /** Strict integer from int or digit string (ASCII only); null otherwise. */
    private function _int($v, bool $signed = false): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match($signed ? '/^-?\d{1,9}$/D' : '/^\d{1,10}$/D', $v)) {
            return (int)$v;
        }
        return null;
    }

    private function _normValue(array $def, string $o, $v, array $known): array
    {
        switch ($def['type']) {
            case 'date':
                return $this->_normScalar($v, $o, 'date', 'Value must be a valid date (YYYY-MM-DD)', function ($x) {
                    return (is_string($x) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $x)
                        && checkdate((int)substr($x, 5, 2), (int)substr($x, 8, 2), (int)substr($x, 0, 4))) ? $x : null;
                });
            case 'number':
                if (isset($def['min'])) {
                    $min = (int)$def['min'];
                    $max = isset($def['max']) ? (int)$def['max'] : null;
                    $msg = $max === null
                        ? 'Value must be a whole number, ' . $min . ' or more'
                        : 'Value must be a whole number from ' . $min . ' to ' . $max;
                    return $this->_normScalar($v, $o, 'number', $msg, function ($x) use ($min, $max) {
                        $n = $this->_int($x, true);
                        return $n !== null && $n >= $min && ($max === null || $n <= $max) ? $n : null;
                    });
                }
                return $this->_normScalar($v, $o, 'number', 'Value must be a number', function ($x) {
                    return $this->_int($x, true);
                });
            case 'bool':
                return $this->_normBool($v);
            case 'enum_set':
                return $this->_normSet($def, $o, $v, $known);
            case 'peerage_set':
                if ($o === 'is') {
                    return $this->_normBool($v);
                }
                $allowed = [];
                foreach ($def['peerage'] as $pe) {
                    $allowed = array_merge($allowed, $known['peerage'][$pe] ?? []);
                }
                return $this->_normList($v, $allowed, strtolower($def['label']));
        }
        return ['error' => 'Unsupported criterion type'];
    }

    private function _normScalar($v, string $o, string $word, string $msg, callable $check): array
    {
        if ($o === 'between') {
            if (!is_array($v) || count($v) !== 2 || !array_key_exists(0, $v) || !array_key_exists(1, $v)) {
                return ['error' => 'Between needs exactly two values'];
            }
            $a = $check($v[0]);
            $b = $check($v[1]);
            if ($a === null || $b === null) {
                return ['error' => $msg];
            }
            // Either order is accepted (spec §3.4): the pair is sorted ascending, so
            // Run, share links and export all see [lo, hi]. ISO dates sort as strings.
            return ['v' => $a > $b ? [$b, $a] : [$a, $b]];
        }
        $a = is_array($v) ? null : $check($v);
        if ($a === null) {
            return ['error' => $msg];
        }
        return ['v' => $a];
    }

    private function _normBool($v): array
    {
        $s = is_bool($v) ? ($v ? '1' : '0') : (is_int($v) || is_string($v) ? strtolower((string)$v) : '');
        if ($s === 'yes' || $s === 'true' || $s === '1') {
            return ['v' => 1];
        }
        if ($s === 'no' || $s === 'false' || $s === '0') {
            return ['v' => 0];
        }
        return ['error' => 'Value must be yes or no'];
    }

    private function _normSet(array $def, string $o, $v, array $known): array
    {
        $allowed = in_array($def['set'], ['class', 'award'], true) ? ($known[$def['set']] ?? []) : null;
        if ($o === 'is' || $o === 'is_not') {
            if (is_array($v) && count($v) === 1 && array_key_exists(0, $v)) {
                $v = $v[0];
            }
            $id = is_array($v) ? null : $this->_int($v);
            if ($id === null || $id < 1) {
                return ['error' => 'Value must be a valid ' . $def['set'] . ' id'];
            }
            if ($allowed !== null && !in_array($id, $allowed, true)) {
                return ['error' => 'Unknown ' . $def['set'] . ' id'];
            }
            return ['v' => $id];
        }
        return $this->_normList($v, $allowed, $def['set']);
    }

    /** Non-empty unique list of positive ints; $allowed null means no membership check. */
    private function _normList($v, ?array $allowed, string $what): array
    {
        if (!is_array($v) || count($v) === 0) {
            return ['error' => 'The ' . $what . ' list is empty'];
        }
        if (count($v) > self::MAX_LIST) {
            return ['error' => 'The ' . $what . ' list has too many items (max ' . self::MAX_LIST . ')'];
        }
        $out = [];
        foreach ($v as $item) {
            $id = is_array($item) ? null : $this->_int($item);
            if ($id === null || $id < 1) {
                return ['error' => 'Each ' . $what . ' value must be a valid id'];
            }
            if ($allowed !== null && !in_array($id, $allowed, true)) {
                return ['error' => 'Unknown ' . $what . ' id'];
            }
            if (!in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return ['v' => $out];
    }

    /**
     * Known class / award / peerage ids, read-only.
     *
     * Throws RuntimeException when the database cannot be read: an empty set
     * would make every class/award rule fail validation with a misleading
     * "unknown id" message, so a DB failure must surface as a DB failure.
     * (The class and award tables are never legitimately empty.)
     */
    public function LoadKnown(): array
    {
        $known = ['class' => [], 'award' => [], 'peerage' => []];
        try {
            $r = $this->_select('SELECT class_id FROM ' . $this->_t('class'));
        } catch (RuntimeException $e) {
            throw new RuntimeException('Could not read class list: ' . $e->getMessage(), 0, $e);
        }
        while ($r->next()) {
            $known['class'][] = (int)$r->class_id;
        }
        if (count($known['class']) === 0) {
            throw new RuntimeException('Could not read class list');
        }
        try {
            $r = $this->_select('SELECT award_id, peerage FROM ' . $this->_t('award'));
        } catch (RuntimeException $e) {
            throw new RuntimeException('Could not read award list: ' . $e->getMessage(), 0, $e);
        }
        while ($r->next()) {
            $id = (int)$r->award_id;
            $known['award'][] = $id;
            $pe = trim((string)$r->peerage);
            if ($pe !== '') {
                $known['peerage'][$pe][] = $id;
            }
        }
        if (count($known['award']) === 0) {
            throw new RuntimeException('Could not read award list');
        }
        return $known;
    }

    /**
     * Run a read-only statement and fail loudly. YapoDb::Query() always returns a
     * YapoResultSet (PDO runs in ERRMODE_WARNING), so a failed statement is only
     * visible in the result's __ERROR SQLSTATE; anything but 00000 throws.
     * $timed wraps the statement in MariaDB's per-statement timeout; a kill by
     * that timeout (error 1969) throws with code TIMEOUT_CODE.
     *
     * @return YapoResultSet
     */
    protected function _select(string $sql, bool $timed = false)
    {
        if ($timed) {
            $sql = $this->_timeoutClause() . $sql;
        }
        // @: the failure is read from __ERROR below and logged; a PHP warning
        // printed into a JSON or xlsx response would only corrupt it.
        $r = @$this->db->query($sql);
        $err = is_object($r) && isset($r->__ERROR[0]) ? $r->__ERROR[0] : null;
        $state = $err !== null ? (string)($err[1] ?? '') : 'no result';
        if (!is_object($r) || ($state !== '00000' && $state !== '')) {
            $driverCode = (int)($err[2][1] ?? 0);
            throw new RuntimeException(
                'query failed [' . $state . '/' . $driverCode . ']: ' . (string)($err[2][2] ?? ''),
                $driverCode === self::TIMEOUT_CODE ? self::TIMEOUT_CODE : 0
            );
        }
        return $r;
    }

    /** Seconds; overridable as a test seam. */
    protected function _statementTimeout(): float
    {
        return (float)self::STATEMENT_TIMEOUT_S;
    }

    /**
     * "SET STATEMENT max_statement_time=N FOR ". A seam value that is not a positive
     * finite number falls back to STATEMENT_TIMEOUT_S: MariaDB reads 0 or less as
     * "no limit", and NAN / INF would not be SQL at all.
     */
    protected function _timeoutClause(): string
    {
        $t = $this->_statementTimeout();
        if (!is_finite($t) || $t <= 0) {
            $t = (float)self::STATEMENT_TIMEOUT_S;
        }
        $n = rtrim(rtrim(sprintf('%.6F', max($t, 0.000001)), '0'), '.');
        return 'SET STATEMENT max_statement_time=' . $n . ' FOR ';
    }

    // ---------------------------------------------------------------- SQL compilation

    private function _t(string $name): string
    {
        return DB_PREFIX . $name;
    }

    /** Compare an SQL expression with a normalized scalar / [lo, hi] value. */
    private function _cmp(string $expr, string $type, string $op, $v): string
    {
        $q = function ($x) use ($type): string {
            if ($type === 'date') {
                if (!is_string($x) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $x)) {
                    throw new InvalidArgumentException('bad date');
                }
                return "'" . $x . "'";
            }
            return (string)(int)$x;
        };
        if ($op === 'between') {
            return "$expr BETWEEN " . $q($v[0]) . ' AND ' . $q($v[1]);
        }
        if (!isset(self::SQL_CMP[$op])) {
            throw new InvalidArgumentException('bad operand');
        }
        return "$expr " . self::SQL_CMP[$op] . ' ' . $q($v);
    }

    /** Compare an SQL expression with a normalized id / id list. */
    private function _set(string $expr, string $op, $v): string
    {
        $ids = implode(',', array_map('intval', (array)$v));
        switch ($op) {
            case 'is':
                return "$expr = " . (int)$v;
            case 'is_not':
                return "$expr <> " . (int)$v;
            case 'in':
                return "$expr IN ($ids)";
            case 'not_in':
                return "$expr NOT IN ($ids)";
        }
        throw new InvalidArgumentException('bad operand');
    }

    private function _months(?int $p): int
    {
        $n = (int)$p;
        if ($n < 1 || $n > self::MAX_MONTHS) {
            throw new InvalidArgumentException('bad months');
        }
        return $n;
    }

    private function _attSub(string $select, string $extraWhere = '', string $tail = ''): string
    {
        return '(SELECT ' . $select . ' FROM ' . $this->_t('attendance') . ' a WHERE a.mundane_id = m.mundane_id'
            . ($extraWhere !== '' ? ' AND ' . $extraWhere : '') . ($tail !== '' ? ' ' . $tail : '') . ')';
    }

    /**
     * MAX(a.date), read as the newest (mundane_id, date) index entry instead of all
     * of them: a.date is NOT NULL, so the top row is the maximum, and no rows is NULL.
     */
    private function _lastSigninExpr(): string
    {
        return $this->_attSub('a.date', '', 'ORDER BY a.date DESC LIMIT 1');
    }

    /**
     * Whole days since the last sign-in, over _lastSigninExpr() itself so the two
     * criteria cannot drift (no date floor, as there is none on the last sign-in).
     * Never signed in: MAX() is NULL, so this is NULL and no comparison matches.
     */
    private function _lastSigninDaysAgoExpr(): string
    {
        return 'DATEDIFF(CURDATE(), ' . $this->_lastSigninExpr() . ')';
    }

    /** Earliest sign-in date that counts (Player::get_earliest_attendance_date's floor). */
    public const FIRST_SIGNIN_FLOOR = '1988-01-01';
    /** Award dates before this are unknown ('0000-00-00' or typo years). */
    public const AWARD_DATE_FLOOR = '1980-01-01';

    /**
     * Player since = first sign-in dated FIRST_SIGNIN_FLOOR or later, as on the
     * player profile; earlier rows are '0000-00-00' or typo dates (spec §3.4).
     */
    private function _playerSinceExpr(): string
    {
        // MIN(a.date) as the oldest index entry at or after the floor (see _lastSigninExpr).
        return $this->_attSub('a.date', "a.date >= '" . self::FIRST_SIGNIN_FLOOR . "'", 'ORDER BY a.date LIMIT 1');
    }

    private function _signinsInMonthsExpr(int $n): string
    {
        return $this->_attSub('COUNT(*)', "a.date >= DATE_SUB(CURDATE(), INTERVAL $n MONTH)");
    }

    private function _totalSigninsExpr(): string
    {
        return $this->_attSub('COUNT(*)');
    }

    private function _lastClassExpr(): string
    {
        return $this->_attSub('a.class_id', 'a.class_id > 0', 'ORDER BY a.date DESC, a.attendance_id DESC LIMIT 1');
    }

    /**
     * Park of the most recent sign-in (same-day ties: highest attendance_id). An
     * event sign-in with no park (park_id 0) is NULL, so the column is blank and
     * negated park filters do not match it (spec §3.4).
     */
    private function _lastSigninParkExpr(): string
    {
        return $this->_attSub('NULLIF(a.park_id, 0)', '', 'ORDER BY a.date DESC, a.attendance_id DESC LIMIT 1');
    }

    /** Lifetime dues sort and compare as this date; the column shows LIFETIME_LABEL. */
    public const LIFETIME_DATE = '9999-12-31';
    public const LIFETIME_LABEL = 'Lifetime';

    /**
     * Live dues rows for the player in alias `m`, exactly as Report::GetDuesPaidList
     * reads them (ork_dues, not revoked), limited to the authorized scope:
     * ctx['duesScope'] is a SQL boolean over alias `d` (d.park_id / d.kingdom_id).
     */
    private function _duesFrom(array $ctx): string
    {
        $scope = isset($ctx['duesScope']) && $ctx['duesScope'] !== '' ? $ctx['duesScope'] : '1=0';
        return 'FROM ' . $this->_t('dues') . ' d'
            . ' WHERE d.mundane_id = m.mundane_id AND d.revoked = 0 AND (' . $scope . ')';
    }

    /** Latest dues_until, or LIFETIME_DATE when any in-scope row is lifetime dues. */
    private function _duesThroughExpr(array $ctx): string
    {
        return "(SELECT IF(MAX(d.dues_for_life) = 1, '" . self::LIFETIME_DATE . "', MAX(d.dues_until)) " . $this->_duesFrom($ctx) . ')';
    }

    /** Paid = GetDuesPaidList's predicate: dues_until >= today, or lifetime dues. */
    private function _duesPaidExists(array $ctx): string
    {
        return 'EXISTS (SELECT 1 ' . $this->_duesFrom($ctx) . ' AND (d.dues_until >= CURDATE() OR d.dues_for_life = 1))';
    }

    /**
     * Held awards for the player in alias `m`. `aw` is the EFFECTIVE award: a
     * Custom Title aliased to a peerage award (awards.alias_award_id) counts as
     * its alias target, matching Report::PlayerAwards / BeltlineData
     * (COALESCE(alias.peerage, a.peerage)). Revoked and stripped awards never count.
     *
     * $needAward = false (award count / award date) skips the award joins entirely,
     * the same as a LEFT JOIN on a primary key: kingdom-only orders
     * (ork_kingdomaward.award_id = 0, no ork_award row) still count, as on the
     * player profile. Peerage and award-id filters need the inner join to `aw`.
     */
    private function _heldAwardsFrom(string $extraWhere = '', bool $needAward = true): string
    {
        $from = 'FROM ' . $this->_t('awards') . ' w';
        if ($needAward) {
            $from .= ' LEFT JOIN ' . $this->_t('kingdomaward') . ' ka ON ka.kingdomaward_id = w.kingdomaward_id'
                . ' JOIN ' . $this->_t('award') . ' aw ON aw.award_id = COALESCE(NULLIF(w.alias_award_id, 0), NULLIF(w.award_id, 0), ka.award_id)';
        }
        return $from . ' WHERE w.mundane_id = m.mundane_id AND w.revoked = 0 AND COALESCE(w.stripped_from, 0) = 0'
            . ($extraWhere !== '' ? ' ' . $extraWhere : '');
    }

    /**
     * Held, NOT aliased award rows of the player in alias `m` whose effective award
     * is in $ids: the same rows as _heldAwardsFrom() + `aw.award_id IN ($ids)`, for
     * rows with no alias_award_id. Faster because it never joins ork_award (every id
     * in $ids is a validated ork_award id, so that join could only drop rows the IN
     * already drops), and because `w.award_id IN (0, ids)` (the effective id is
     * award_id when it is not 0, else ka.award_id) is checked on the
     * (mundane_id, award_id, ...) index before a row is read.
     * Aliased rows are the caller's job: see _aliasHolders().
     *
     * @param int[] $ids non-empty, positive
     */
    private function _heldNonAliasFrom(array $ids, string $join = ''): string
    {
        $in = $this->_idList($ids);
        return 'FROM ' . $this->_t('awards') . ' w LEFT JOIN ' . $this->_t('kingdomaward') . ' ka ON ka.kingdomaward_id = w.kingdomaward_id'
            . ($join !== '' ? ' ' . $join : '')
            . ' WHERE w.mundane_id = m.mundane_id AND w.award_id IN (0,' . $in . ')'
            . ' AND COALESCE(w.alias_award_id, 0) = 0 AND w.revoked = 0 AND COALESCE(w.stripped_from, 0) = 0'
            . ' AND COALESCE(NULLIF(w.award_id, 0), ka.award_id) IN (' . $in . ')';
    }

    /**
     * True for players in alias `m` with a held row aliased to one of $ids. Not
     * correlated, so MariaDB materializes it once per statement (idx_alias_award_id;
     * aliased rows are a handful).
     *
     * @param int[] $ids non-empty, positive
     */
    private function _aliasHolders(array $ids): string
    {
        return 'm.mundane_id IN (SELECT wa.mundane_id FROM ' . $this->_t('awards') . ' wa WHERE wa.alias_award_id IN (' . $this->_idList($ids) . ')'
            . ' AND wa.revoked = 0 AND COALESCE(wa.stripped_from, 0) = 0)';
    }

    /**
     * Holds at least one award in $ids (the effective, alias-aware id; never NULL).
     * `(SELECT 1 ... LIMIT 1) IS NOT NULL` is EXISTS written so MariaDB keeps it a
     * per-player index probe: it rewrites a correlated EXISTS into a materialized
     * scan of all of ork_awards, about 15x slower here.
     *
     * @param int[] $ids
     */
    private function _holdsAny(array $ids): string
    {
        if (count($ids) === 0) {
            return '(0=1)'; // no award qualifies, so nobody holds one
        }
        return '((SELECT 1 ' . $this->_heldNonAliasFrom($ids) . ' LIMIT 1) IS NOT NULL OR ' . $this->_aliasHolders($ids) . ')';
    }

    /** @param int[] $ids @return string comma-separated positive ints */
    private function _idList(array $ids): string
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0) {
                throw new InvalidArgumentException('bad id');
            }
            $out[] = $id;
        }
        if (count($out) === 0) {
            throw new InvalidArgumentException('empty id list');
        }
        return implode(',', array_values(array_unique($out)));
    }

    /**
     * Award ids carrying these peerages, from ctx['peerageIds'] (LoadKnown's
     * peerage => ids map; ork_award.peerage is an ENUM, so the map is exactly
     * `aw.peerage IN (...)`), or null when the context does not carry the map.
     *
     * @return int[]|null
     */
    private function _peerageIds(array $peerage, array $ctx): ?array
    {
        if (!isset($ctx['peerageIds']) || !is_array($ctx['peerageIds'])) {
            return null;
        }
        $ids = [];
        foreach ($peerage as $pe) {
            foreach ((array)($ctx['peerageIds'][$pe] ?? []) as $id) {
                if ((int)$id > 0) {
                    $ids[] = (int)$id;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    private function _peerageIn(array $peerage): string
    {
        $lits = [];
        foreach ($peerage as $pe) {
            if (!is_string($pe) || !preg_match('/^[A-Za-z-]+$/D', $pe)) {
                throw new InvalidArgumentException('bad peerage');
            }
            $lits[] = "'" . $pe . "'";
        }
        return 'aw.peerage IN (' . implode(',', $lits) . ')';
    }

    /** Reeve / Corpora qualified: mirrors Report::GetReeveQualified / GetCorporaQualified. */
    private function _qualifiedExpr(string $kind): string
    {
        return "(m.suspended = 0 AND m.{$kind}_qualified = 1 AND COALESCE(m.{$kind}_qualified_until >= CURDATE(), 0))";
    }

    /**
     * A player's rank in a ladder: GREATEST(MAX(rank), COUNT(*)) over the HELD awards
     * in that ladder (the Ladder Award Grid's rule; many rows carry no rank), 0 when
     * there are none (MAX is NULL then, COUNT is 0). Global ladders match the resolved
     * (alias-aware) award id; kingdom-only ladders match w.kingdomaward_id.
     *
     * @param array{kind:string, id:int} $ladder from the validated registry
     */
    private function _ladderRankExpr(array $ladder): string
    {
        $id = (int)($ladder['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('bad ladder');
        }
        $rank = 'SELECT GREATEST(COALESCE(MAX(w.rank), 0), COUNT(*)) ';
        switch ($ladder['kind'] ?? '') {
            case 'award':
                // Players with an aliased row in this ladder (a handful) take the exact
                // alias-aware form; everyone else, the index probe over the same rows.
                return '(CASE WHEN ' . $this->_aliasHolders([$id])
                    . ' THEN (' . $rank . $this->_heldAwardsFrom('AND aw.award_id = ' . $id) . ')'
                    . ' ELSE (' . $rank . $this->_heldNonAliasFrom([$id]) . ') END)';
            case 'kingdomaward':
                return '(' . $rank . $this->_heldAwardsFrom('AND w.kingdomaward_id = ' . $id, false) . ')';
        }
        throw new InvalidArgumentException('bad ladder');
    }

    /**
     * $peerage: the peerage criterion's peerages, or null for has_award. Ids are
     * validated ork_award ids (NormalizeTree), so "holds one of $ids" is _holdsAny.
     */
    private function _awardSetSql(string $o, $v, ?array $peerage, array $ctx): string
    {
        // Peerage criteria only (has_award passes null): `is` takes yes|no.
        if ($peerage !== null && $o === 'is') {
            $ids = $this->_peerageIds($peerage, $ctx);
            if ($ids === null) { // no peerage map in the context: the join form
                return ($v ? 'EXISTS' : 'NOT EXISTS') . ' (SELECT 1 ' . $this->_heldAwardsFrom('AND ' . $this->_peerageIn($peerage)) . ')';
            }
            return ($v ? '' : 'NOT ') . $this->_holdsAny($ids);
        }
        // has_award is an enum_set criterion, so also accept the set operands.
        $map = ['in' => 'has_any', 'is' => 'has_any', 'not_in' => 'has_none', 'is_not' => 'has_none'];
        $o = $map[$o] ?? $o;
        $ids = array_values(array_unique(array_map('intval', (array)$v)));
        if (count($ids) === 0) {
            throw new InvalidArgumentException('empty award list');
        }
        switch ($o) {
            case 'has_any':
                return $this->_holdsAny($ids);
            case 'has_none':
                return 'NOT ' . $this->_holdsAny($ids);
            case 'has_all':
                // COUNT(DISTINCT effective id) over $ids = count($ids), as one probe per id.
                $parts = [];
                foreach ($ids as $id) {
                    $parts[] = $this->_holdsAny([$id]);
                }
                return '(' . implode(' AND ', $parts) . ')';
        }
        throw new InvalidArgumentException('bad operand');
    }

    /** @return callable function(string $o, $v, ?int $p, array $ctx): string */
    private function _criterionSql(string $id, array $def): callable
    {
        return function (string $o, $v, ?int $p, array $ctx) use ($id, $def): string {
            if (isset($def['ladder'])) {
                return $this->_cmp($this->_ladderRankExpr($def['ladder']), 'number', $o, $v);
            }
            switch ($id) {
                case 'last_signin':
                    return $this->_cmp($this->_lastSigninExpr(), 'date', $o, $v);
                case 'last_signin_days_ago':
                    return $this->_cmp($this->_lastSigninDaysAgoExpr(), 'number', $o, $v);
                case 'player_since':
                    return $this->_cmp($this->_playerSinceExpr(), 'date', $o, $v);
                case 'signins_last_n_months':
                    return $this->_cmp($this->_signinsInMonthsExpr($this->_months($p)), 'number', $o, $v);
                case 'total_signins':
                    return $this->_cmp($this->_totalSigninsExpr(), 'number', $o, $v);
                case 'last_class':
                    return $this->_set($this->_lastClassExpr(), $o, $v);
                case 'classes_last_n_months':
                    $ids = implode(',', array_map('intval', (array)$v));
                    $ex = 'EXISTS ' . $this->_attSub('1', "a.class_id IN ($ids) AND a.date >= DATE_SUB(CURDATE(), INTERVAL " . $this->_months($p) . ' MONTH)');
                    if ($o === 'is' || $o === 'in') {
                        return $ex;
                    }
                    if ($o === 'is_not' || $o === 'not_in') {
                        return 'NOT ' . $ex;
                    }
                    throw new InvalidArgumentException('bad operand');
                case 'home_kingdom':
                    return $this->_set('m.kingdom_id', $o, $v);
                case 'home_park':
                    return $this->_set('m.park_id', $o, $v);
                case 'last_signin_park':
                    return $this->_set($this->_lastSigninParkExpr(), $o, $v);
                case 'dues_paid':
                    return ((int)$v === 1 ? '' : 'NOT ') . $this->_duesPaidExists($ctx);
                case 'dues_through':
                    return $this->_cmp($this->_duesThroughExpr($ctx), 'date', $o, $v);
                case 'waivered':
                    return 'm.waivered = ' . ((int)$v === 1 ? 1 : 0);
                case 'active':
                    return 'm.active = ' . ((int)$v === 1 ? 1 : 0);
                case 'suspended':
                    return 'm.suspended = ' . ((int)$v === 1 ? 1 : 0);
                case 'banned':
                    return 'm.penalty_box = ' . ((int)$v === 1 ? 1 : 0);
                case 'knighthood':
                case 'masterhood':
                case 'paragon':
                case 'lesser_peerage':
                    return $this->_awardSetSql($o, $v, $def['peerage'], $ctx);
                case 'has_award':
                    return $this->_awardSetSql($o, $v, null, $ctx);
                case 'award_count':
                    return $this->_cmp('(SELECT COUNT(*) ' . $this->_heldAwardsFrom('', false) . ')', 'number', $o, $v);
                case 'award_date_any':
                    // EXISTS as a probe (see _holdsAny): MariaDB would scan all of ork_awards.
                    return '(SELECT 1 ' . $this->_heldAwardsFrom("AND w.date >= '" . self::AWARD_DATE_FLOOR . "' AND " . $this->_cmp('w.date', 'date', $o, $v), false) . ' LIMIT 1) IS NOT NULL';
                case 'reeve_qualified':
                case 'corpora_qualified':
                    $e = $this->_qualifiedExpr($id === 'reeve_qualified' ? 'reeve' : 'corpora');
                    return (int)$v === 1 ? $e : 'NOT ' . $e;
            }
            throw new InvalidArgumentException('unknown criterion');
        };
    }

    /** SQL expression (no alias) for a result column; `m`, `k`, `p` are joined by the caller. */
    public function ColumnSelectSql(string $colId, array $ctx): string
    {
        return $this->_columnSql($colId, $ctx);
    }

    private function _columnSql(string $id, array $ctx): string
    {
        switch ($id) {
            case 'persona':
                return 'm.persona';
            case 'home_park':
                return 'p.name';
            case 'home_kingdom':
                return 'k.name';
            case 'last_signin':
                return $this->_lastSigninExpr();
            case 'player_since':
                return $this->_playerSinceExpr();
            case 'signins_6m':
                return $this->_signinsInMonthsExpr(6);
            case 'total_signins':
                return $this->_totalSigninsExpr();
            case 'dues_through':
                return $this->_duesThroughExpr($ctx);
            case 'last_signin_park':
                return '(SELECT p2.name FROM ' . $this->_t('park') . ' p2 WHERE p2.park_id = (' . $this->_lastSigninParkExpr() . '))';
            case 'last_class':
                return '(SELECT c.name FROM ' . $this->_t('class') . ' c WHERE c.class_id = (' . $this->_lastClassExpr() . '))';
            case 'dues_paid':
                return 'CASE WHEN ' . $this->_duesPaidExists($ctx) . ' THEN 1 ELSE 0 END';
            case 'waivered':
                return 'm.waivered';
            case 'active':
                return 'm.active';
            case 'knighthoods':
            case 'masterhoods':
            case 'paragons':
                $pe = ['knighthoods' => 'Knight', 'masterhoods' => 'Master', 'paragons' => 'Paragon'][$id];
                $names = "SELECT GROUP_CONCAT(DISTINCT aw.name ORDER BY aw.name SEPARATOR ', ') ";
                $exact = '(' . $names . $this->_heldAwardsFrom('AND ' . $this->_peerageIn([$pe])) . ')';
                $ids = $this->_peerageIds([$pe], $ctx);
                if ($ids === null) {
                    return $exact;
                }
                if (count($ids) === 0) {
                    return 'NULL'; // no award carries the peerage: GROUP_CONCAT of no rows
                }
                // Players with an aliased row of the peerage (a handful) take the exact
                // alias-aware form; everyone else, the index probe over the same rows.
                return 'CASE WHEN ' . $this->_aliasHolders($ids) . ' THEN ' . $exact
                    . ' ELSE (' . $names . $this->_heldNonAliasFrom($ids, 'JOIN ' . $this->_t('award') . ' aw ON aw.award_id = COALESCE(NULLIF(w.award_id, 0), ka.award_id)') . ') END';
            case 'award_count':
                return '(SELECT COUNT(*) ' . $this->_heldAwardsFrom('', false) . ')';
            case 'reeve_qualified':
                return 'CASE WHEN ' . $this->_qualifiedExpr('reeve') . ' THEN 1 ELSE 0 END';
            case 'corpora_qualified':
                return 'CASE WHEN ' . $this->_qualifiedExpr('corpora') . ' THEN 1 ELSE 0 END';
        }
        throw new InvalidArgumentException('unknown column');
    }

    /**
     * Compile a normalized tree (from NormalizeTree) into a SQL boolean over alias `m`.
     * ctx['duesScope'] is a SQL boolean over alias `d` (ork_dues) for dues rules;
     * ctx['ladders'] is the scope's ladder set (LoadLadders), the same one the tree
     * was validated against. A ladder rule outside it does not compile.
     */
    public function CompileTree(array $normalizedTree, array $ctx = []): string
    {
        $registry = $this->_criteria(['ladders' => $ctx['ladders'] ?? []]);
        if (empty($normalizedTree['children'])) {
            return '1=1';
        }
        return $this->_compileNode($normalizedTree, $registry, $ctx)[0];
    }

    /** Rough evaluation cost per player, for emission order only. */
    private const COST_COLUMN = 0;    // a column of m
    private const COST_PROBE = 1;     // one index probe / one index entry
    private const COST_AGGREGATE = 2; // reads all of the player's rows in an index, or several probes

    private function _leafCost(array $leaf, array $registry): int
    {
        $c = $leaf['c'] ?? '';
        $def = $registry[$c] ?? [];
        if (isset($def['ladder'])) {
            return ($def['ladder']['kind'] ?? '') === 'kingdomaward' ? self::COST_PROBE : self::COST_AGGREGATE;
        }
        switch ($c) {
            case 'home_kingdom':
            case 'home_park':
            case 'waivered':
            case 'active':
            case 'suspended':
            case 'banned':
            case 'reeve_qualified':
            case 'corpora_qualified':
                return self::COST_COLUMN;
            case 'last_signin':
            case 'last_signin_days_ago':
            case 'player_since':
            case 'classes_last_n_months':
            case 'dues_paid':
            case 'has_award':
            case 'award_date_any':
                return self::COST_PROBE;
        }
        if (isset($def['peerage'])) {
            return ($leaf['o'] ?? '') === 'has_all' ? self::COST_AGGREGATE : self::COST_PROBE;
        }
        return self::COST_AGGREGATE;
    }

    /**
     * SQL emission only simplifies; the normalized tree (and so every RulePath) is
     * untouched. Within one group:
     * - a rule identical to an earlier sibling is dropped (X OR X = X AND X = X);
     * - under OR, the "holds one of these awards" rules become one probe over the
     *   union of their ids (holds any of A, or any of B = holds any of A u B); under
     *   AND, the "holds none of these" rules do (De Morgan). None of these is ever
     *   NULL, so three-valued logic does not change the result;
     * - the parts are emitted cheapest first (a column of m, then single index
     *   probes, then aggregates; a group costs as much as its dearest part; equal
     *   costs keep the user's order). AND and OR are commutative in SQL's
     *   three-valued logic, so only the work changes: MariaDB stops at the first
     *   true OR part / false AND part.
     *
     * @return array{0:string, 1:int} SQL and its cost class
     */
    private function _compileNode(array $node, array $registry, array $ctx): array
    {
        if (isset($node['children'])) {
            // NormalizeTree drops empty groups and CompileTree handles an empty root.
            $or = $node['op'] === 'OR';
            $parts = [];
            $seen = [];
            $held = null;   // ids of the merged held-award rules
            $heldAt = null; // their place: where the first of them was
            foreach ($node['children'] as $child) {
                $h = isset($child['children']) ? null : $this->_heldAwardLeaf($child, $registry, $ctx);
                if ($h !== null && $h['none'] === !$or) {
                    if ($heldAt === null) {
                        $heldAt = count($parts);
                        $parts[] = '';
                    }
                    $held = array_merge($held ?? [], $h['ids']);
                    continue;
                }
                [$sql, $cost] = $this->_compileNode($child, $registry, $ctx);
                if (!isset($seen[$sql])) {
                    $seen[$sql] = true;
                    $parts[] = [$sql, $cost];
                }
            }
            if ($heldAt !== null) {
                $parts[$heldAt] = ['(' . ($or ? '' : 'NOT ') . $this->_holdsAny(array_values(array_unique($held))) . ')', self::COST_PROBE];
            }
            $order = array_keys($parts);
            usort($order, function (int $a, int $b) use ($parts): int {
                return ($parts[$a][1] <=> $parts[$b][1]) ?: ($a <=> $b);
            });
            $sqls = [];
            $max = self::COST_COLUMN;
            foreach ($order as $i) {
                $sqls[] = $parts[$i][0];
                $max = max($max, $parts[$i][1]);
            }
            return ['(' . implode($or ? ' OR ' : ' AND ', $sqls) . ')', $max];
        }
        if (!isset($registry[$node['c']])) {
            throw new InvalidArgumentException('unknown criterion');
        }
        $fn = $registry[$node['c']]['sql'];
        return ['(' . $fn($node['o'], $node['v'], $node['p'] ?? null, $ctx) . ')', $this->_leafCost($node, $registry)];
    }

    /**
     * A leaf that compiles to "holds one of $ids" (none = false) or "holds none of
     * $ids" (none = true) through _holdsAny, else null: has_award, peerage
     * has_any / has_none, and peerage yes / no when ctx carries the peerage ids.
     *
     * @return array{ids:int[], none:bool}|null
     */
    private function _heldAwardLeaf(array $leaf, array $registry, array $ctx): ?array
    {
        $def = $registry[$leaf['c'] ?? ''] ?? null;
        $o = $leaf['o'] ?? '';
        if ($def === null) {
            return null;
        }
        if (($leaf['c'] ?? '') === 'has_award') {
            $none = ['in' => false, 'is' => false, 'not_in' => true, 'is_not' => true][$o] ?? null;
            return $none === null ? null : ['ids' => array_map('intval', (array)$leaf['v']), 'none' => $none];
        }
        if (!isset($def['peerage'])) {
            return null;
        }
        if ($o === 'has_any' || $o === 'has_none') {
            return ['ids' => array_map('intval', (array)$leaf['v']), 'none' => $o === 'has_none'];
        }
        if ($o === 'is') {
            $ids = $this->_peerageIds($def['peerage'], $ctx);
            return $ids === null ? null : ['ids' => $ids, 'none' => (int)$leaf['v'] !== 1];
        }
        return null; // has_all
    }

    // ---------------------------------------------------------------- scope, authorization, execution

    /**
     * Access gate (spec §3.3): any valid session may explore any existing kingdom or
     * park. Officer authority is not needed here; it only unlocks the restricted
     * criteria (see IsScopeOfficer).
     *
     * @return array|null null when allowed; InvalidParameter for a bad scope type, a
     * missing id or a kingdom / park that does not exist; BadToken for a missing,
     * invalid or expired token; ProcessingError when the scope cannot be read.
     */
    public function AuthorizeScope(string $token, string $scopeType, int $scopeId): ?array
    {
        if ($scopeType !== 'Kingdom' && $scopeType !== 'Park') {
            return InvalidParameter('Scope type must be Kingdom or Park.');
        }
        if (!valid_id($scopeId)) {
            return InvalidParameter('Scope id is required.');
        }
        if (!valid_id(Ork3::$Lib->authorization->IsAuthorized($token))) {
            return BadToken();
        }
        try {
            $exists = $this->_scopeExists($scopeType, $scopeId);
        } catch (Throwable $e) {
            logtrace('PopulationExplorer::AuthorizeScope failure', $e->getMessage());
            return ProcessingError('The kingdom or park could not be read. Please try again.');
        }
        if (!$exists) {
            return InvalidParameter($scopeType === 'Park' ? 'That park could not be found.' : 'That kingdom could not be found.');
        }
        return null;
    }

    /** Throws on a DB failure. */
    private function _scopeExists(string $scopeType, int $scopeId): bool
    {
        $sql = $scopeType === 'Park'
            ? 'SELECT park_id FROM ' . $this->_t('park') . ' WHERE park_id = ' . (int)$scopeId
            : 'SELECT kingdom_id FROM ' . $this->_t('kingdom') . ' WHERE kingdom_id = ' . (int)$scopeId;
        return $this->_select($sql)->next();
    }

    /**
     * Officer authority over the scope, which unlocks the restricted criteria (spec
     * §3.3, ruling A1):
     * - global admin;
     * - Kingdom scope: kingdom AUTH_EDIT for that kingdom (a principality resolves to
     *   its parent kingdom's officers through HasAuthority's parent walk);
     * - Park scope: park AUTH_CREATE for that park, or kingdom AUTH_EDIT for the park's
     *   kingdom (again with the parent walk, so a principality's parks count for the
     *   parent kingdom's officers).
     * This deliberately differs from Report::_authorizeKingdomParkReportScope, which
     * asks only park AUTH_CREATE for a park (so an edit-only kingdom officer fails
     * there). Do not "re-sync" the two: a kingdom officer may already filter the whole
     * kingdom, which contains every player of the park.
     * Fails closed: a bad token, a bad scope or any error is "not an officer".
     */
    public function IsScopeOfficer(string $token, string $scopeType, int $scopeId): bool
    {
        if (($scopeType !== 'Kingdom' && $scopeType !== 'Park') || !valid_id($scopeId)) {
            return false;
        }
        try {
            $auth = Ork3::$Lib->authorization;
            $actorId = $auth->IsAuthorized($token);
            if (!valid_id($actorId)) {
                return false;
            }
            if ($auth->HasAuthority($actorId, AUTH_ADMIN, 0, AUTH_ADMIN)
                || $auth->HasAuthority($actorId, AUTH_ADMIN, 0, AUTH_CREATE)) {
                return true;
            }
            if ($scopeType === 'Park') {
                if ($auth->HasAuthority($actorId, AUTH_PARK, $scopeId, AUTH_CREATE)) {
                    return true;
                }
                $r = $this->_select('SELECT kingdom_id FROM ' . $this->_t('park') . ' WHERE park_id = ' . (int)$scopeId);
                $parkKingdom = $r->next() ? (int)$r->kingdom_id : 0;
                return valid_id($parkKingdom)
                    && (bool)$auth->HasAuthority($actorId, AUTH_KINGDOM, $parkKingdom, AUTH_EDIT);
            }
            return (bool)$auth->HasAuthority($actorId, AUTH_KINGDOM, $scopeId, AUTH_EDIT);
        } catch (Throwable $e) {
            logtrace('PopulationExplorer::IsScopeOfficer failure', $e->getMessage());
            return false;
        }
    }

    /**
     * Class / award / peerage ids plus the viewer context for this scope
     * ($known['officer']). Throws RuntimeException when the database cannot be read.
     */
    public function LoadKnownForScope(string $token, string $scopeType, int $scopeId): array
    {
        $known = $this->LoadKnown();
        $known['ladders'] = $this->LoadLadders($scopeType, $scopeId);
        $known['officer'] = $this->IsScopeOfficer($token, $scopeType, $scopeId);
        return $known;
    }

    /**
     * Kingdom-only ladders (kingdomaward ids). On master these are exactly
     * Award::pseudoLadderKingdomAwardIds(): ork_kingdomaward has no is_ladder column
     * yet. When it lands (feature/award-management's 2026-06-18-kingdomaward-ladder.sql
     * backfills is_ladder = 1 for this same list), read `ka.is_ladder = 1` instead.
     * A test seam.
     *
     * @return int[]
     */
    protected function _kingdomOnlyLadderIds(): array
    {
        return Award::pseudoLadderKingdomAwardIds();
    }

    /**
     * The ranked ladders of a scope, keyed by criterion id and sorted by label:
     * - `ladder_a<award_id>`: the 15 global ladders (ork_award.is_ladder = 1, Walker in
     *   the Middle excluded, as in Report::GetLadderAwardGrid), labelled with the
     *   scope kingdom's own ork_kingdomaward.name when it renames one (a Kingdom
     *   scope's kingdom, which is the parent when principalities are included, or a
     *   park's kingdom), else ork_award.name;
     * - `ladder_k<kingdomaward_id>`: the kingdom-only ladders of every kingdom in
     *   scope (the stats kingdoms, or the park's kingdom), labelled with ka.name; a
     *   name another ladder in scope also uses (kingdom-only or global) gets the
     *   kingdom's abbreviation appended, and the id as well when that is still not
     *   unique, so every label in the picker is distinct.
     * Throws RuntimeException when the database cannot be read.
     *
     * @return array<string, array{label:string, kind:string, id:int}>
     */
    public function LoadLadders(string $scopeType, int $scopeId): array
    {
        $scopeId = (int)$scopeId;
        if ($scopeType === 'Park') {
            $r = $this->_select('SELECT kingdom_id FROM ' . $this->_t('park') . ' WHERE park_id = ' . $scopeId);
            $labelKingdom = $r->next() ? (int)$r->kingdom_id : 0;
            $kingdoms = $labelKingdom > 0 ? [$labelKingdom] : [];
        } elseif ($scopeType === 'Kingdom' && $scopeId > 0) {
            $labelKingdom = $scopeId;
            $kingdoms = $this->_scopeKingdomIds($scopeId);
        } else {
            $labelKingdom = 0;
            $kingdoms = [];
        }

        $out = [];
        $r = $this->_select('SELECT a.award_id, a.name,'
            . ' (SELECT ka.name FROM ' . $this->_t('kingdomaward') . ' ka WHERE ka.kingdom_id = ' . (int)$labelKingdom
            . ' AND ka.award_id = a.award_id ORDER BY ka.kingdomaward_id LIMIT 1) AS kingdom_name'
            . ' FROM ' . $this->_t('award') . ' a WHERE a.is_ladder = 1 AND a.award_id <> ' . self::WALKER_AWARD_ID);
        while ($r->next()) {
            $id = (int)$r->award_id;
            $own = trim((string)$r->kingdom_name);
            $out['ladder_a' . $id] = ['label' => $own !== '' ? $own : (string)$r->name, 'kind' => 'award', 'id' => $id];
        }
        if (count($out) === 0) {
            throw new RuntimeException('Could not read the ladder list');
        }

        $kaIds = array_values(array_filter(array_map('intval', $this->_kingdomOnlyLadderIds()), function ($i) {
            return $i > 0;
        }));
        if (count($kingdoms) > 0 && count($kaIds) > 0) {
            $r = $this->_select('SELECT ka.kingdomaward_id, ka.name, k.abbreviation, k.name AS kingdom_name'
                . ' FROM ' . $this->_t('kingdomaward') . ' ka LEFT JOIN ' . $this->_t('kingdom') . ' k ON k.kingdom_id = ka.kingdom_id'
                . ' WHERE ka.kingdomaward_id IN (' . implode(',', $kaIds) . ') AND ka.kingdom_id IN (' . implode(',', array_map('intval', $kingdoms)) . ')'
                . ' ORDER BY ka.kingdomaward_id');
            $local = [];
            $seen = [];
            while ($r->next()) {
                $name = trim((string)$r->name);
                $abbr = trim((string)$r->abbreviation);
                $local[(int)$r->kingdomaward_id] = [$name, $abbr !== '' ? $abbr : trim((string)$r->kingdom_name)];
                $key = strtolower($name);
                $seen[$key] = ($seen[$key] ?? 0) + 1;
            }
            $globalLabels = [];
            foreach ($out as $def) {
                $globalLabels[strtolower($def['label'])] = true;
            }
            $labels = [];
            foreach ($local as $kaId => [$name, $kingdom]) {
                $key = strtolower($name);
                $clash = $seen[$key] > 1 || isset($globalLabels[$key]);
                $labels[$kaId] = $clash && $kingdom !== '' ? $name . ' (' . $kingdom . ')' : $name;
            }
            // A suffix that is still not unique (two same-named ladders of one kingdom,
            // or a kingdom without an abbreviation) also gets the id.
            $count = [];
            foreach (array_merge(array_keys($globalLabels), array_map('strtolower', $labels)) as $l) {
                $count[$l] = ($count[$l] ?? 0) + 1;
            }
            foreach ($local as $kaId => [$name, $kingdom]) {
                $label = $labels[$kaId];
                if ($count[strtolower($label)] > 1) {
                    $label = $name . ' (' . ($kingdom !== '' ? $kingdom . ' ' : '') . '#' . $kaId . ')';
                }
                $out['ladder_k' . $kaId] = ['label' => $label, 'kind' => 'kingdomaward', 'id' => $kaId];
            }
        }

        uksort($out, function ($a, $b) use ($out) {
            return strnatcasecmp($out[$a]['label'], $out[$b]['label']) ?: strcmp($a, $b);
        });
        return $out;
    }

    /** @return int[] kingdom ids covered by a Kingdom scope (stats kingdoms, ints only) */
    private function _scopeKingdomIds(int $kingdomId): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array)Ork3::$Lib->kingdom->GetStatsKingdomIds($kingdomId)),
            function ($i) {
                return $i > 0;
            }
        )));
        return $ids;
    }

    /**
     * @return array|null [mundane scope clause over m, dues scope clause over d], or
     * null when a park's kingdom cannot be resolved (the caller must refuse, never
     * run unscoped). The player clause matches Report::GetPlayerRoster: a park scope
     * is the park's players whose home kingdom is the park's kingdom. The dues
     * clause matches Report::GetDuesPaidList's Type filter (d.park_id / d.kingdom_id),
     * widened to the stats kingdoms for a Kingdom scope. Throws on a DB failure.
     */
    private function _scopeClauses(string $scopeType, int $scopeId): ?array
    {
        $scopeId = (int)$scopeId;
        if ($scopeType === 'Park') {
            $r = $this->_select('SELECT kingdom_id FROM ' . $this->_t('park') . ' WHERE park_id = ' . $scopeId);
            $parkKingdom = $r->next() ? (int)$r->kingdom_id : 0;
            if ($parkKingdom <= 0) {
                return null;
            }
            return ['m.park_id = ' . $scopeId . ' AND m.kingdom_id = ' . $parkKingdom, 'd.park_id = ' . $scopeId];
        }
        $ids = $this->_scopeKingdomIds($scopeId);
        if (count($ids) === 0) {
            return ['1=0', '1=0'];
        }
        $in = implode(',', $ids);
        return ["m.kingdom_id IN ($in)", "d.kingdom_id IN ($in)"];
    }

    /**
     * Run a Population Explorer query. See the plan's interface contract.
     * The scope clause is built here from the authorized scope and AND-ed
     * OUTSIDE the user's filter tree, so no tree can widen it.
     */
    public function Run(array $request): array
    {
        $started = microtime(true);
        $scopeType = is_string($request['ScopeType'] ?? null) ? $request['ScopeType'] : '';
        $scopeId = $this->_int($request['ScopeId'] ?? null) ?? 0;
        $token = is_string($request['Token'] ?? null) ? $request['Token'] : '';

        $denied = $this->AuthorizeScope($token, $scopeType, $scopeId);
        if ($denied !== null) {
            return ['Status' => $denied];
        }

        // One run at a time per player (BuildExport runs through here too): a second
        // request while one is still going is refused at once, not queued behind it.
        $lock = $this->_acquireRunLock($token);
        if ($lock === null) {
            return ['Status' => ProcessingError(self::BUSY_MESSAGE), 'Busy' => true];
        }
        try {
            return $this->_runAuthorized($request, $started, $scopeType, $scopeId, $token);
        } finally {
            $this->_releaseRunLock($lock);
        }
    }

    /**
     * GET_LOCK(RunLockName(database, the player's mundane_id), no wait) on this request's
     * connection; MariaDB also drops it if the connection ends. Returns the lock
     * name, null when another connection holds it, or '' when no lock could be
     * taken (a DB error): the run then goes ahead unguarded, since the lock only
     * spares the database, it does not protect data.
     */
    private function _acquireRunLock(string $token): ?string
    {
        try {
            $mid = (int)Ork3::$Lib->authorization->IsAuthorized($token);
            if ($mid <= 0) {
                return '';
            }
            $name = self::RunLockName(DB_DATABASE, $mid);
            $r = $this->_select("SELECT GET_LOCK('" . $name . "', 0) AS got");
            $got = $r->next() ? $r->got : null;
            if ($got === null) {
                return '';
            }
            return (int)$got === 1 ? $name : null;
        } catch (Throwable $e) {
            logtrace('PopulationExplorer::Run lock failure', $e->getMessage());
            return '';
        }
    }

    /**
     * "pe:<database>:<mundane_id>". A database name that is not plain [A-Za-z0-9_$],
     * or that would push the name past LOCK_NAME_MAX, is replaced by its SHA-1, so
     * the result is always a safe SQL string literal of at most 64 characters.
     */
    public static function RunLockName(string $database, int $mundaneId): string
    {
        $name = self::RUN_LOCK_PREFIX . $database . ':' . $mundaneId;
        if (!preg_match('/^[A-Za-z0-9_$]+$/', $database) || strlen($name) > self::LOCK_NAME_MAX) {
            $name = self::RUN_LOCK_PREFIX . sha1($database) . ':' . $mundaneId; // 3 + 40 + 1 + at most 20
        }
        return $name;
    }

    private function _releaseRunLock(string $name): void
    {
        if ($name === '') {
            return;
        }
        try {
            $this->_select("SELECT RELEASE_LOCK('" . $name . "') AS released");
        } catch (Throwable $e) {
            logtrace('PopulationExplorer::Run unlock failure', $e->getMessage()); // dropped with the connection anyway
        }
    }

    /** Run() after the scope check, under the run lock. */
    private function _runAuthorized(array $request, float $started, string $scopeType, int $scopeId, string $token): array
    {
        // Columns: whitelist, persona always first, request order, no duplicates.
        $registryCols = $this->_columnDefs();
        $colIds = ['persona'];
        foreach ((array)($request['Columns'] ?? []) as $c) {
            if (is_string($c) && isset($registryCols[$c]) && !in_array($c, $colIds, true)) {
                $colIds[] = $c;
            }
        }

        try {
            $known = $this->LoadKnownForScope($token, $scopeType, $scopeId);
        } catch (Throwable $e) {
            logtrace('PopulationExplorer::Run known-ids failure', $e->getMessage());
            return ['Status' => ProcessingError('Could not load the report options. Please try again.')];
        }

        $tree = $request['Tree'] ?? [];
        if (!is_array($tree) || count($tree) === 0) {
            $tree = ['op' => 'AND', 'children' => []]; // no filter: everyone in scope
        }
        $norm = $this->NormalizeTree($tree, $known);
        if (!$norm['ok']) {
            return ['Status' => InvalidParameter($norm['error']), 'RulePath' => $norm['path']];
        }

        $cap = self::MAX_ROWS;
        $reqCap = $this->_int($request['RowCap'] ?? null);
        if ($reqCap !== null && $reqCap >= 1 && $reqCap < self::MAX_ROWS) {
            $cap = $reqCap;
        }

        try {
            $clauses = $this->_scopeClauses($scopeType, $scopeId);
        } catch (Throwable $e) {
            logtrace('PopulationExplorer::Run scope failure', $e->getMessage());
            return ['Status' => ProcessingError('The query could not be completed. Try narrowing the filter or scope.')];
        }
        if ($clauses === null) {
            return ['Status' => InvalidParameter('That park could not be found in a kingdom, so it cannot be reported on.')];
        }
        list($scopeSql, $duesScope) = $clauses;
        $ctx = ['duesScope' => $duesScope, 'ladders' => $known['ladders'] ?? []];
        if (isset($known['peerage']) && is_array($known['peerage'])) {
            $ctx['peerageIds'] = $known['peerage']; // peerage criteria and columns probe these ids
        }
        try {
            $treeSql = $this->CompileTree($norm['tree'], $ctx);
            $selects = ['m.mundane_id AS mundane_id'];
            foreach ($colIds as $id) {
                $selects[] = '(' . $this->ColumnSelectSql($id, $ctx) . ') AS c_' . $id;
            }
        } catch (InvalidArgumentException $e) {
            return ['Status' => InvalidParameter($e->getMessage())];
        }

        // Two statements. 1: the matching ids in report order, at most $cap of them,
        // with COUNT(*) OVER () = every match (the true Total) from the same single
        // evaluation of the tree. 2: the columns for those ids only.
        // No outer GROUP BY: k and p join on primary keys and every criterion and
        // column is a scalar subquery or EXISTS, so rows cannot multiply.
        // CONCAT(m.persona) is the same value in the same collation, so the order is
        // unchanged; it only keeps MariaDB from walking the whole persona index (all
        // players, every kingdom) to satisfy the LIMIT instead of sorting the scope.
        $order = ' ORDER BY CONCAT(m.persona), m.mundane_id';
        $idSql = 'SELECT m.mundane_id AS mundane_id, COUNT(*) OVER () AS matches FROM ' . $this->_t('mundane') . ' m'
            . ' WHERE (' . $scopeSql . ') AND (' . $treeSql . ')' . $order . ' LIMIT ' . (int)$cap;
        logtrace('PopulationExplorer::Run', $idSql);

        $rows = [];
        $total = 0;
        $scopeTotal = 0;
        try {
            $ids = [];
            $r = $this->_select($idSql, true);
            while ($r->next()) {
                $ids[] = (int)$r->mundane_id;
                $total = (int)$r->matches;
            }
            if (count($ids) > 0) {
                $sql = 'SELECT ' . implode(', ', $selects)
                    . ' FROM ' . $this->_t('mundane') . ' m'
                    . ' LEFT JOIN ' . $this->_t('kingdom') . ' k ON k.kingdom_id = m.kingdom_id'
                    . ' LEFT JOIN ' . $this->_t('park') . ' p ON p.park_id = m.park_id'
                    . ' WHERE m.mundane_id IN (' . implode(',', $ids) . ')' . $order;
                logtrace('PopulationExplorer::Run columns', $sql);
                $r = $this->_select($sql, true);
                while ($r->next()) {
                    $row = ['MundaneId' => (int)$r->mundane_id];
                    foreach ($colIds as $id) {
                        $field = 'c_' . $id;
                        $row[$id] = $this->_cell($id, $registryCols[$id]['type'], $r->$field);
                    }
                    $rows[] = $row;
                }
            }
            // Everyone in the authorized scope, ignoring the tree (for "% of scope").
            $sc = $this->_select('SELECT COUNT(*) AS n FROM ' . $this->_t('mundane') . ' m WHERE (' . $scopeSql . ')', true);
            if (!$sc->next()) {
                throw new RuntimeException('scope count returned no row');
            }
            $scopeTotal = (int)$sc->n;
        } catch (Throwable $e) {
            logtrace('PopulationExplorer::Run failure', $e->getMessage());
            if ($e->getCode() === self::TIMEOUT_CODE) {
                return ['Status' => ProcessingError(self::TIMEOUT_MESSAGE), 'TimedOut' => true];
            }
            return ['Status' => ProcessingError('The query could not be completed. Try narrowing the filter or scope.')];
        }

        $columns = [];
        foreach ($colIds as $id) {
            $columns[] = ['id' => $id, 'label' => $registryCols[$id]['label'], 'type' => $registryCols[$id]['type']];
        }
        return [
            'Status'    => Success(),
            'Columns'   => $columns,
            'Rows'      => $rows,
            'Total'     => $total,
            'ScopeTotal' => $scopeTotal,
            'Truncated' => $total > $cap,
            'ElapsedMs' => (int)round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * Build an .xlsx of a Run() result. Reuses Run (auth, validation, 5,000-row cap).
     * Cells are written as inline strings / numbers by SimpleXlsx, so text beginning
     * with = + - @ is never evaluated as a formula. Dates are ISO text; flags Yes/No.
     *
     * @return array ['Status'=>..., 'Path'=>temp file, 'Filename'=>string] or ['Status'=>error]
     */
    public function BuildExport(array $request): array
    {
        if (!class_exists('SimpleXlsx', false)) {
            require_once __DIR__ . '/../vendor/SimpleXlsx.php'; // only export requests pay for it
        }
        $r = $this->Run($request);
        if (($r['Status']['Status'] ?? 1) != 0) {
            $out = ['Status' => $r['Status']];
            foreach (['RulePath', 'TimedOut', 'Busy'] as $k) { // the controller maps these to HTTP codes
                if (isset($r[$k])) {
                    $out[$k] = $r[$k];
                }
            }
            return $out;
        }

        $header = [];
        $widths = [];
        foreach ($r['Columns'] as $col) {
            $header[] = ['v' => (string)$col['label'], 's' => SimpleXlsx::S_HEADER];
            $widths[] = $col['type'] === 'text' ? 26 : ($col['type'] === 'date' ? 14 : 16);
        }
        $rows = [$header];
        foreach ($r['Rows'] as $row) {
            $line = [];
            foreach ($r['Columns'] as $col) {
                $v = $row[$col['id']] ?? null;
                if ($v === null || $v === '') {
                    $line[] = '';
                } elseif ($col['type'] === 'number') {
                    $line[] = (int)$v;
                } elseif ($col['type'] === 'bool') {
                    $line[] = ((int)$v) === 1 ? 'Yes' : 'No';
                } elseif ($col['type'] === 'date') {
                    $d = (string)$v;
                    $line[] = ['v' => (preg_match('/^\d{4}-\d{2}-\d{2}/', $d) ? substr($d, 0, 10) : $d), 't' => 's'];
                } else {
                    $line[] = ['v' => (string)$v, 't' => 's']; // persona was unslashed by _cell()
                }
            }
            $rows[] = $line;
        }
        if (!empty($r['Truncated'])) {
            $rows[] = [];
            $cap = count($r['Rows']);
            $rows[] = [['v' => 'Showing first ' . number_format($cap) . ' of ' . number_format((int)$r['Total']) . ' matches', 's' => SimpleXlsx::S_LABEL]];
        }

        $path = tempnam(sys_get_temp_dir(), 'population-explorer-');
        if ($path === false) {
            return ['Status' => ProcessingError('The export file could not be created.')];
        }
        try {
            $x = new SimpleXlsx();
            $x->addSheet('Population Explorer', $rows, ['colWidths' => $widths, 'freezeRow' => 1]);
            $x->writeToFile($path);
        } catch (Throwable $e) {
            logtrace('PopulationExplorer::BuildExport failure', $e->getMessage());
            @unlink($path);
            return ['Status' => ProcessingError('The export file could not be created.')];
        }
        return ['Status' => Success(), 'Path' => $path, 'Filename' => 'population-explorer-' . date('Y-m-d') . '.xlsx'];
    }

    /**
     * Normalise a raw DB cell by column type: numbers/bools to ints, NULL stays null.
     * Lifetime dues (LIFETIME_DATE) show as LIFETIME_LABEL in both JSON and xlsx.
     * Persona (only) is stripslashes()'d here; other text keeps its backslashes.
     */
    private function _cell(string $id, string $type, $v)
    {
        if ($v === null) {
            return null;
        }
        if ($type === 'number' || $type === 'bool') {
            return (int)$v;
        }
        if ($id === 'dues_through' && (string)$v === self::LIFETIME_DATE) {
            return self::LIFETIME_LABEL;
        }
        if ($id === 'persona') {
            return stripslashes((string)$v); // magic-quotes-era escapes; the one place, for JSON and xlsx
        }
        return (string)$v;
    }

    /** Encode {tree, columns} as a base64url JSON share-link payload. */
    public static function EncodeLink(array $state): string
    {
        return rtrim(strtr(base64_encode((string)json_encode($state)), '+/', '-_'), '=');
    }

    /**
     * Decode and re-validate a share-link payload. Never trusts the link: size
     * capped before decoding, tree re-run through NormalizeTree, columns
     * filtered to known column ids. Pass LoadKnownForScope() for the viewer's
     * scope; with no $known the viewer is treated as a non-officer.
     *
     * @return array ['ok'=>bool,'state'=>?array{tree:array,columns:string[]},'error'=>?string]
     */
    public static function DecodeLink(string $q, ?array $known = null): array
    {
        $fail = function (string $msg): array {
            return ['ok' => false, 'state' => null, 'error' => $msg];
        };
        if ($q === '' || strlen($q) > self::MAX_LINK_BYTES) {
            return $fail('That link is empty or too large to open.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $q)) {
            return $fail('That link is not valid.');
        }
        $raw = base64_decode(strtr($q, '-_', '+/'), true);
        if ($raw === false) {
            return $fail('That link is not valid.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['tree']) || !is_array($data['tree'])) {
            return $fail('That link is not valid.');
        }
        $pe = new self();
        try {
            $norm = $pe->NormalizeTree($data['tree'], $known);
        } catch (Throwable $e) {
            logtrace('PopulationExplorer::DecodeLink failure', $e->getMessage());
            return $fail('That link could not be opened.');
        }
        if (!$norm['ok']) {
            return $fail('That link has an invalid filter: ' . $norm['error']);
        }
        $cols = [];
        $defs = $pe->_columnDefs();
        foreach ((array)($data['columns'] ?? []) as $c) {
            if (is_string($c) && isset($defs[$c]) && !in_array($c, $cols, true)) {
                $cols[] = $c;
            }
        }
        return ['ok' => true, 'state' => ['tree' => $norm['tree'], 'columns' => $cols], 'error' => null];
    }

    /**
     * Criteria safe to send to the browser for this viewer context: no SQL, and no
     * restricted criteria unless $known['officer'] is exactly true.
     */
    public function PublicCriteria(array $known): array
    {
        $officer = ($known['officer'] ?? false) === true;
        $criteria = [];
        foreach ($this->_criteriaDefs($known) as $id => $def) {
            if (!empty($def['restricted']) && !$officer) {
                continue;
            }
            unset($def['sql'], $def['ladder']); // the ladder's SQL ids stay server-side
            $criteria[$id] = $def;
        }
        return $criteria;
    }

    /**
     * Registry safe to send to the browser: no closures / SQL, plus the option
     * lists the rule editor needs, limited to the viewer's chosen scope.
     * $isOfficer (IsScopeOfficer) decides whether restricted criteria are offered;
     * `officer` echoes it so the page can say why they are missing.
     * Throws RuntimeException when an option list cannot be read.
     */
    public function PublicRegistry(string $scopeType, int $scopeId, bool $isOfficer = false): array
    {
        $criteria = $this->PublicCriteria(['officer' => $isOfficer, 'ladders' => $this->LoadLadders($scopeType, $scopeId)]);
        $columns = [];
        foreach ($this->_columnDefs() as $id => $def) {
            $columns[$id] = $def;
        }

        $options = ['class' => [], 'award' => [], 'order' => [], 'park' => [], 'kingdom' => []];
        $r = $this->_select('SELECT class_id, name FROM ' . $this->_t('class') . ' ORDER BY name');
        while ($r->next()) {
            $options['class'][] = [(int)$r->class_id, (string)$r->name];
        }

        $orderPeerage = ['Knight', 'Master', 'Paragon', 'Squire', 'Page', 'Man-At-Arms'];
        foreach ($orderPeerage as $pe) {
            $options['order'][$pe] = [];
        }
        // Retired (deprecated) awards stay listed, labelled, so an old share link
        // that names one still shows its name instead of a bare id.
        $r = $this->_select('SELECT award_id, name, peerage, deprecate FROM ' . $this->_t('award') . ' ORDER BY deprecate, name');
        while ($r->next()) {
            $pe = (string)$r->peerage;
            $name = (string)$r->name . ((int)$r->deprecate === 1 ? ' (retired)' : '');
            $options['award'][] = [(int)$r->award_id, $name, $pe];
            if (isset($options['order'][$pe])) {
                $options['order'][$pe][] = [(int)$r->award_id, $name];
            }
        }

        if ($scopeType === 'Park') {
            $pid = (int)$scopeId;
            $r = $this->_select('SELECT p.park_id, p.name AS park_name, k.kingdom_id, k.name AS kingdom_name FROM ' . $this->_t('park') . ' p'
                . ' LEFT JOIN ' . $this->_t('kingdom') . ' k ON k.kingdom_id = p.kingdom_id WHERE p.park_id = ' . $pid);
            while ($r->next()) {
                $options['park'][] = [(int)$r->park_id, (string)$r->park_name];
                if ((int)$r->kingdom_id > 0) {
                    $options['kingdom'][] = [(int)$r->kingdom_id, (string)$r->kingdom_name];
                }
            }
        } else {
            $ids = $this->_scopeKingdomIds((int)$scopeId);
            if (count($ids) > 0) {
                $in = implode(',', $ids);
                $r = $this->_select('SELECT kingdom_id, name FROM ' . $this->_t('kingdom') . " WHERE kingdom_id IN ($in) ORDER BY name");
                while ($r->next()) {
                    $options['kingdom'][] = [(int)$r->kingdom_id, (string)$r->name];
                }
                $r = $this->_select('SELECT park_id, name FROM ' . $this->_t('park') . " WHERE kingdom_id IN ($in) AND active = 'Active' ORDER BY name");
                while ($r->next()) {
                    $options['park'][] = [(int)$r->park_id, (string)$r->name];
                }
            }
        }

        return ['criteria' => $criteria, 'columns' => $columns, 'options' => $options, 'officer' => $isOfficer];
    }
}
