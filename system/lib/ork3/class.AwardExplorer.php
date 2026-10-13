<?php

/**
 * Award Explorer: criteria/columns registry and filter-tree validator.
 *
 * Sibling of PopulationExplorer, not a mode of it. The difference is the GRAIN:
 * Population Explorer returns one row per player; this returns one row per
 * GRANT (ork_awards), so a player appears once per award they hold.
 *
 * The filter tree is untrusted client input. NormalizeTree() either returns a
 * canonical tree whose values are all ints / validated dates, or an error.
 * CompileTree() / ColumnSelectSql() turn a canonical tree into a SQL boolean
 * over alias `a` (ork_awards) and its joins. Only ints and validated dates ever
 * reach SQL; the one free-text path (Search) is whitelisted in _searchClause().
 *
 * Why the tree handling is duplicated from PopulationExplorer rather than
 * shared: the owner chose a separate explorer over generalising the existing
 * one, which matches the house pattern (beltline and park-attendance explorers
 * are each their own). The cost is that a fix to the normaliser must be applied
 * in both. Revisit if a third explorer appears.
 */
class AwardExplorer extends Ork3
{
    public const MAX_DEPTH = 6;
    public const MAX_LEAVES = 40;
    public const MAX_LIST = 100;
    public const MAX_ROWS = 5000;
    public const MAX_LINK_BYTES = 8192;
    /** Share-link URL parameter. Not `q`: Google Analytics logs any `q` as a site search. */
    public const LINK_PARAM = 'ae';
    public const MAX_MONTHS = 600;
    public const MAX_RANK = 99;
    public const MAX_SEARCH = 64;
    /** MariaDB max_statement_time (seconds) for the row and COUNT queries. */
    public const STATEMENT_TIMEOUT_S = 10;
    /** RuntimeException code _select() uses for a statement killed by the timeout. */
    public const TIMEOUT_CODE = 1969;
    public const TIMEOUT_MESSAGE = 'This query took too long — narrow your filter.';
    /** Named-lock prefix: one Run at a time per player, per database. */
    public const RUN_LOCK_PREFIX = 'ae:';
    public const LOCK_NAME_MAX = 64;
    public const BUSY_MESSAGE = 'Another Award Explorer run of yours is still in progress — please wait for it to finish.';

    /**
     * Default date window. ork_awards is 397k rows; the last 12 months is ~31k
     * (8%). Opening on a bounded window keeps the common case cheap and makes
     * "everything, ever" a deliberate act rather than the default.
     */
    public const DEFAULT_WINDOW_MONTHS = 12;

    public const OPS_CMP = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'between'];
    public const OPS_SET = ['is', 'is_not', 'in', 'not_in'];
    public const OPS_BOOL = ['is'];
    public const SQL_CMP = ['eq' => '=', 'ne' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];

    /** A date the ORK stores for "no date recorded". 5,951 rows on production carry it. */
    public const ZERO_DATE = '0000-00-00';

    /**
     * ork_award.peerage, in the order the enum declares it. This is the answer to
     * "show me all Knights / Paragons / Masters" without anyone knowing an id.
     *
     * The ONLY peerage strings that reach SQL come from this array. A request names
     * a value, it is matched against this list with a strict comparison, and the
     * element FROM HERE is what is emitted -- the caller's own string never is.
     */
    public const PEERAGE = [
        'Knight', 'Master', 'Paragon', 'Squire', 'Man-At-Arms',
        'Page', 'Lords-Page', 'Apprentice', 'Kingdom-Level-Award', 'None',
    ];

    public function __construct()
    {
        parent::__construct();
    }

    // ---------------------------------------------------------------- registry

    public function Registry(array $known = []): array
    {
        return [
            'criteria' => $this->_criteria($known),
            'columns'  => $this->_columnDefs(),
            'options'  => $this->LoadOptions(),
        ];
    }

    private function _opsFor(string $type): array
    {
        switch ($type) {
            case 'enum_set':
                return self::OPS_SET;
            case 'peerage_set':
                return self::OPS_SET;
            case 'bool':
                return self::OPS_BOOL;
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
            'nullable' => false,
            'sql'      => null,
        ], $extra);
    }

    private function _criteria(array $known = []): array
    {
        $list = $this->_criteriaDefs($known);
        foreach ($list as $id => &$def) {
            $def['sql'] = $this->_criterionSql($id, $def);
        }
        unset($def);
        return $list;
    }

    private function _criteriaDefs(array $known = []): array
    {
        return [
            'date' => $this->_crit('Award date', 'When', 'date', [
                'note' => 'Awards with no recorded date are not matched. Use "Date is unknown" to find those.',
            ]),
            'date_unknown' => $this->_crit('Date is unknown', 'When', 'bool', [
                'note' => 'The ORK stores 0000-00-00 when no date was recorded.',
            ]),
            'months_ago' => $this->_crit('Awarded in the last N months', 'When', 'number', [
                'param' => true,
                'operands' => ['is'],
                'min' => 1,
                'max' => self::MAX_MONTHS,
            ]),
            'award' => $this->_crit('Award', 'Which award', 'enum_set', [
                'set' => 'kingdomaward',
                'note' => "This kingdom's own row of the award.",
            ]),
            'base_award' => $this->_crit('Award (any kingdom)', 'Which award', 'enum_set', [
                'set' => 'award',
                'note' => 'Matches every kingdom\'s row of the same catalog award.',
            ]),
            'is_ladder' => $this->_crit('Is a ladder award', 'Which award', 'bool', [
                'note' => 'Includes kingdom-original ladders, which carry no catalog row.',
            ]),
            'is_title' => $this->_crit('Is a title', 'Which award', 'bool'),
            'peerage' => $this->_crit('Peerage', 'Which award', 'peerage_set', [
                'values' => self::PEERAGE,
                'note' => 'Knighthoods, masterhoods and paragons, by the award\'s peerage class.',
            ]),
            'rank' => $this->_crit('Rank', 'Which award', 'number', [
                'min' => 0,
                'max' => self::MAX_RANK,
                'note' => 'Rank 0 means unranked, not "rank zero".',
            ]),
            'kingdom' => $this->_crit('Kingdom (on the award)', 'Where', 'enum_set', [
                'set' => 'kingdom',
                'note' => 'The kingdom recorded on the award, not where the recipient is today.',
            ]),
            'park' => $this->_crit('Park (on the award)', 'Where', 'enum_set', [
                'set' => 'park',
                'note' => 'The park recorded on the award. Someone who has since moved still matches here.',
            ]),
            'recipient' => $this->_crit('Recipient', 'Who', 'enum_set', [
                'set' => 'mundane',
                'note' => 'By player id. Use the search box to find awards by persona text.',
            ]),
            'given_by' => $this->_crit('Given by', 'Who', 'enum_set', ['set' => 'mundane']),
            'revoked' => $this->_crit('Revoked', 'Status', 'bool', [
                'note' => 'Revoked awards are excluded unless you ask for them.',
            ]),
            'stripped' => $this->_crit('Stripped from a player', 'Status', 'bool', [
                'note' => 'revoke_award clears the recipient and records them in stripped_from.',
            ]),
        ];
    }

    private function _col(string $label, string $group, bool $default = false): array
    {
        return ['label' => $label, 'group' => $group, 'default' => $default];
    }

    private function _columnDefs(): array
    {
        return [
            // Order here is the order of the columns in the table, so the defaults
            // read as a sentence: on this date, this person, of this park, received
            // this award at this rank, from this person.
            'date'              => $this->_col('Date', 'When', true),
            'recipient'         => $this->_col('Recipient', 'Who', true),
            // Default, because the Park filter matches on THIS. Filtering on Park and
            // then being shown the recipient's CURRENT park looks like a broken filter
            // when in fact the person has simply transferred since.
            'park'              => $this->_col('Park (on the award)', 'Where', true),
            'award_name'        => $this->_col('Award', 'Which award', true),
            'rank'              => $this->_col('Rank', 'Which award', true),
            'given_by'          => $this->_col('Given by', 'Who', true),
            'kingdom'           => $this->_col('Kingdom (on the award)', 'Where'),
            // "today" vs "on the award" is the whole point of these two labels.
            'recipient_park'    => $this->_col('Recipient park today', 'Who'),
            'recipient_kingdom' => $this->_col('Recipient kingdom today', 'Who'),
            'award_type'        => $this->_col('Type', 'Which award'),
            'note'              => $this->_col('Note', 'Detail'),
            'revoked'           => $this->_col('Revoked', 'Status'),
            'entered_at'        => $this->_col('Entered at', 'Detail'),
        ];
    }

    // ---------------------------------------------------------------- validation

    public function NormalizeTree(array $tree, ?array $known = null): array
    {
        $known = $known ?? $this->LoadKnown();
        $registry = $this->_criteriaDefs($known);
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
            return $this->_err('Unknown criterion', $path);
        }
        $def = $registry[$c];
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
                return $this->_normScalar($v, $o, 'Value must be a valid date (YYYY-MM-DD)', function ($x) {
                    return (is_string($x) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $x)
                        && checkdate((int)substr($x, 5, 2), (int)substr($x, 8, 2), (int)substr($x, 0, 4))) ? $x : null;
                }, 'date');
            case 'number':
                $min = isset($def['min']) ? (int)$def['min'] : null;
                $max = isset($def['max']) ? (int)$def['max'] : null;
                $msg = $min === null
                    ? 'Value must be a number'
                    : ($max === null
                        ? 'Value must be a whole number, ' . $min . ' or more'
                        : 'Value must be a whole number from ' . $min . ' to ' . $max);
                return $this->_normScalar($v, $o, $msg, function ($x) use ($min, $max) {
                    $n = $this->_int($x, true);
                    if ($n === null) {
                        return null;
                    }
                    if ($min !== null && $n < $min) {
                        return null;
                    }
                    if ($max !== null && $n > $max) {
                        return null;
                    }
                    return $n;
                }, 'number');
            case 'bool':
                return $this->_normBool($v);
            case 'enum_set':
                return $this->_normSet($def, $o, $v, $known);
            case 'peerage_set':
                return $this->_normPeerage($o, $v);
        }
        return ['error' => 'Unsupported criterion type'];
    }

    private function _normScalar($v, string $o, string $msg, callable $check, string $type): array
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
            // Either order accepted; the pair is sorted so Run, share links and
            // export all see [lo, hi]. ISO dates sort correctly as strings.
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
        // mundane ids are not pre-loaded: 184k rows is too many to ship to the
        // client or hold for a membership test. A non-existent id simply matches
        // nothing, which is the same outcome as rejecting it.
        $allowed = $def['set'] === 'mundane' ? null : ($known[$def['set']] ?? null);
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

    /**
     * Peerage values, matched strictly against self::PEERAGE. Returns the matched
     * CONSTANT elements, never the caller's strings, so nothing a caller typed can
     * reach the SQL builder even if the comparison were later loosened.
     */
    private function _normPeerage(string $o, $v): array
    {
        $single = ($o === 'is' || $o === 'is_not');
        $list = is_array($v) ? $v : [$v];
        if ($single && count($list) !== 1) {
            return ['error' => 'Choose one peerage'];
        }
        if (!$list) {
            return ['error' => 'The peerage list is empty'];
        }
        if (count($list) > count(self::PEERAGE)) {
            return ['error' => 'Too many peerage values'];
        }
        $out = [];
        foreach ($list as $item) {
            if (!is_string($item)) {
                return ['error' => 'Each peerage must be one of the known values'];
            }
            $i = array_search($item, self::PEERAGE, true);
            if ($i === false) {
                return ['error' => 'Unknown peerage: ' . (strlen($item) > 30 ? 'value too long' : htmlspecialchars($item))];
            }
            if (!in_array(self::PEERAGE[$i], $out, true)) {
                $out[] = self::PEERAGE[$i];
            }
        }
        return ['v' => $single ? $out[0] : $out];
    }

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
     * Known ids for membership checks, read-only.
     *
     * Throws RuntimeException when the database cannot be read: an empty set
     * would make every rule fail validation with a misleading "unknown id"
     * message, so a DB failure must surface as a DB failure.
     */
    public function LoadKnown(): array
    {
        $known = ['kingdomaward' => [], 'award' => [], 'kingdom' => [], 'park' => []];
        $sets = [
            'kingdomaward' => 'SELECT kingdomaward_id AS id FROM ' . $this->_t('kingdomaward'),
            'award'        => 'SELECT award_id AS id FROM ' . $this->_t('award'),
            'kingdom'      => 'SELECT kingdom_id AS id FROM ' . $this->_t('kingdom'),
            'park'         => 'SELECT park_id AS id FROM ' . $this->_t('park'),
        ];
        foreach ($sets as $key => $sql) {
            $rs = $this->_select($sql);
            while ($rs->next()) {
                $known[$key][] = (int)$rs->id;
            }
        }
        return $known;
    }

    /**
     * Named option lists for the pickers, so nobody has to know an id.
     *
     * Only the small sets are shipped: base award (149), kingdom (37) and park
     * (1,101) come to about 40 KB together, which is worth paying once on a
     * logged-in report page. ork_kingdomaward is NOT here -- 4,874 rows, and the
     * "Award (any kingdom)" picker answers the same question better anyway, since
     * someone looking for Order of the Warrior wants every kingdom's row of it.
     *
     * Deprecated awards are left out of the picker. They remain valid as ids, so
     * an old share link still resolves; they are simply not offered as new choices.
     */
    public function LoadOptions(): array
    {
        $out = ['award' => [], 'kingdom' => [], 'park' => [], 'peerage' => []];
        foreach (self::PEERAGE as $p) {
            $out['peerage'][] = ['id' => $p, 'name' => $p === 'None' ? 'None (ordinary award)' : $p];
        }
        $sets = [
            'award'   => 'SELECT award_id AS id, name FROM ' . $this->_t('award') . ' WHERE deprecate = 0 ORDER BY name',
            'kingdom' => 'SELECT kingdom_id AS id, name FROM ' . $this->_t('kingdom') . ' ORDER BY name',
            'park'    => 'SELECT park_id AS id, name FROM ' . $this->_t('park') . ' ORDER BY name',
        ];
        foreach ($sets as $key => $sql) {
            try {
                $rs = $this->_select($sql);
                while ($rs->next()) {
                    $out[$key][] = ['id' => (int)$rs->id, 'name' => stripslashes((string)$rs->name)];
                }
            } catch (Throwable $e) {
                // A missing picker degrades to typing an id; it is not worth failing
                // the whole page over.
                logtrace('AwardExplorer::LoadOptions ' . $key . ' failure', $e->getMessage());
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- execution helpers

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

    protected function _timeoutClause(): string
    {
        $t = $this->_statementTimeout();
        if (!is_finite($t) || $t <= 0) {
            $t = (float)self::STATEMENT_TIMEOUT_S;
        }
        $n = rtrim(rtrim(sprintf('%.6F', max($t, 0.000001)), '0'), '.');
        return 'SET STATEMENT max_statement_time=' . $n . ' FOR ';
    }

    private function _t(string $name): string
    {
        return DB_PREFIX . $name;
    }

    // ---------------------------------------------------------------- SQL compilation

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

    /** A real recorded date: excludes the 0000-00-00 placeholder and NULL. */
    private function _datedExpr(): string
    {
        return "(a.date IS NOT NULL AND a.date <> '" . self::ZERO_DATE . "')";
    }

    /**
     * Ladder classification MUST come from Award::sqlEffectiveIsLadder, never from
     * `rank > 0`. A kingdom-original ladder has no ork_award row, so neither
     * is_ladder column is ever 1 for it and only the curated list recognises it.
     */
    private function _isLadderExpr(): string
    {
        return Award::sqlEffectiveIsLadder('ka', 'w');
    }

    private function _isTitleExpr(): string
    {
        return 'GREATEST(COALESCE(ka.is_title, 0), COALESCE(w.is_title, 0))';
    }

    private function _criterionSql(string $id, array $def): callable
    {
        switch ($id) {
            case 'date':
                return function (string $o, $v) {
                    // A date comparison implies a real date: without this the zero
                    // dates sort and compare as "before everything", so "before 2020"
                    // would quietly return 5,951 undated rows.
                    return '(' . $this->_datedExpr() . ' AND ' . $this->_cmp('a.date', 'date', $o, $v) . ')';
                };
            case 'date_unknown':
                return function (string $o, $v) {
                    return ((int)$v === 1 ? 'NOT ' : '') . $this->_datedExpr();
                };
            case 'months_ago':
                return function (string $o, $v, ?int $p) {
                    $n = (int)$p;
                    if ($n < 1 || $n > self::MAX_MONTHS) {
                        throw new InvalidArgumentException('bad months');
                    }
                    return '(' . $this->_datedExpr()
                        . ' AND a.date >= DATE_SUB(CURDATE(), INTERVAL ' . $n . ' MONTH)'
                        . ' AND a.date <= CURDATE())';
                };
            case 'award':
                return function (string $o, $v) {
                    return $this->_set('a.kingdomaward_id', $o, $v);
                };
            case 'base_award':
                return function (string $o, $v) {
                    return $this->_set('COALESCE(ka.award_id, a.award_id)', $o, $v);
                };
            case 'is_ladder':
                return function (string $o, $v) {
                    return '(' . $this->_isLadderExpr() . ') = ' . ((int)$v === 1 ? '1' : '0');
                };
            case 'is_title':
                return function (string $o, $v) {
                    return '(' . $this->_isTitleExpr() . ') = ' . ((int)$v === 1 ? '1' : '0');
                };
            case 'peerage':
                return function (string $o, $v) {
                    // Values were matched against self::PEERAGE during normalisation,
                    // so these literals are this class's own constants.
                    $vals = (array)$v;
                    foreach ($vals as $x) {
                        if (!in_array($x, self::PEERAGE, true)) {
                            throw new InvalidArgumentException('bad peerage');
                        }
                    }
                    $list = "'" . implode("','", $vals) . "'";
                    $neg = ($o === 'is_not' || $o === 'not_in') ? 'NOT ' : '';
                    return $neg . "(w.peerage IN ($list))";
                };
            case 'rank':
                return function (string $o, $v) {
                    return $this->_cmp('COALESCE(a.rank, 0)', 'number', $o, $v);
                };
            case 'kingdom':
                return function (string $o, $v) {
                    return $this->_set('COALESCE(a.kingdom_id, 0)', $o, $v);
                };
            case 'park':
                return function (string $o, $v) {
                    return $this->_set('COALESCE(a.park_id, 0)', $o, $v);
                };
            case 'recipient':
                return function (string $o, $v) {
                    return $this->_set('a.mundane_id', $o, $v);
                };
            case 'given_by':
                return function (string $o, $v) {
                    return $this->_set('a.given_by_id', $o, $v);
                };
            case 'revoked':
                return function (string $o, $v) {
                    return 'COALESCE(a.revoked, 0) = ' . ((int)$v === 1 ? '1' : '0');
                };
            case 'stripped':
                return function (string $o, $v) {
                    return ((int)$v === 1 ? '' : 'NOT ') . '(COALESCE(a.mundane_id, 0) = 0)';
                };
        }
        throw new InvalidArgumentException('no SQL for criterion ' . $id);
    }

    public function CompileTree(array $normalizedTree, array $known = []): string
    {
        $registry = $this->_criteria($known);
        if (empty($normalizedTree['children'])) {
            return '1=1';
        }
        return $this->_compileNode($normalizedTree, $registry);
    }

    private function _compileNode(array $node, array $registry): string
    {
        if (array_key_exists('children', $node)) {
            $parts = [];
            foreach ($node['children'] as $child) {
                $parts[] = $this->_compileNode($child, $registry);
            }
            if (!$parts) {
                return '1=1';
            }
            return '(' . implode($node['op'] === 'OR' ? ' OR ' : ' AND ', $parts) . ')';
        }
        $def = $registry[$node['c']] ?? null;
        if ($def === null || !is_callable($def['sql'])) {
            throw new InvalidArgumentException('unknown criterion in compile');
        }
        return '(' . $def['sql']($node['o'], $node['v'], $node['p'] ?? null) . ')';
    }

    // ---------------------------------------------------------------- columns

    /**
     * Award display name. custom_name wins: some rows carry their own name rather
     * than the kingdomaward's, and that is what the recipient was actually given.
     */
    private function _awardNameExpr(): string
    {
        return "NULLIF(a.custom_name, '')";
    }

    public function ColumnSelectSql(string $colId): string
    {
        switch ($colId) {
            case 'date':
                return "IF(" . $this->_datedExpr() . ", DATE_FORMAT(a.date, '%Y-%m-%d'), '') AS date";
            case 'recipient':
                return "COALESCE(m.persona, sm.persona, '') AS recipient";
            case 'recipient_park':
                return "COALESCE(mp.name, '') AS recipient_park";
            case 'recipient_kingdom':
                return "COALESCE(mk.name, '') AS recipient_kingdom";
            case 'award_name':
                return 'COALESCE(' . $this->_awardNameExpr() . ", ka.name, w.name, '') AS award_name";
            case 'rank':
                return 'COALESCE(a.rank, 0) AS rank';
            case 'award_type':
                return 'CASE WHEN (' . $this->_isTitleExpr() . ') = 1 THEN \'Title\''
                     . ' WHEN (' . $this->_isLadderExpr() . ') = 1 THEN \'Ladder\''
                     . " ELSE 'Award' END AS award_type";
            case 'given_by':
                return "COALESCE(g.persona, '') AS given_by";
            case 'kingdom':
                return "COALESCE(k.name, '') AS kingdom";
            case 'park':
                return "COALESCE(p.name, '') AS park";
            case 'note':
                return "COALESCE(a.note, '') AS note";
            case 'revoked':
                return 'COALESCE(a.revoked, 0) AS revoked';
            case 'entered_at':
                return "COALESCE(DATE_FORMAT(a.entered_at, '%Y-%m-%d'), '') AS entered_at";
        }
        throw new InvalidArgumentException('unknown column ' . $colId);
    }

    /**
     * The id a displayed column links to, or null when it links nowhere.
     *
     * Selected alongside the column itself so the client can build a profile link
     * without a second lookup. Recipient falls back to stripped_from: a stripped
     * grant still names who held it, and that person still has a profile.
     */
    private function _columnIdSql(string $colId): ?string
    {
        switch ($colId) {
            case 'recipient':
                return 'COALESCE(NULLIF(a.mundane_id, 0), a.stripped_from, 0) AS recipient_id';
            case 'recipient_park':
                return 'COALESCE(m.park_id, 0) AS recipient_park_id';
            case 'recipient_kingdom':
                return 'COALESCE(m.kingdom_id, 0) AS recipient_kingdom_id';
            case 'given_by':
                return 'COALESCE(a.given_by_id, 0) AS given_by_id';
            case 'kingdom':
                return 'COALESCE(a.kingdom_id, 0) AS kingdom_id';
            case 'park':
                return 'COALESCE(a.park_id, 0) AS park_id';
        }
        return null;
    }

    /** Row key the id arrives under for a linkable column. */
    private function _columnIdKey(string $colId): ?string
    {
        $sql = $this->_columnIdSql($colId);
        if ($sql === null) {
            return null;
        }
        return substr($sql, strrpos($sql, ' ') + 1);
    }

    /**
     * The joins every query uses. `sm` covers stripped rows: revoke_award sets
     * mundane_id = 0 and records the original holder in stripped_from, so without
     * it those 3,079 rows show an empty recipient.
     */
    private function _fromSql(): string
    {
        return ' FROM ' . $this->_t('awards') . ' a'
            . ' LEFT JOIN ' . $this->_t('mundane') . ' m  ON m.mundane_id  = a.mundane_id'
            . ' LEFT JOIN ' . $this->_t('mundane') . ' sm ON sm.mundane_id = a.stripped_from'
            . ' LEFT JOIN ' . $this->_t('park') . ' mp ON mp.park_id = m.park_id'
            . ' LEFT JOIN ' . $this->_t('kingdom') . ' mk ON mk.kingdom_id = m.kingdom_id'
            . ' LEFT JOIN ' . $this->_t('kingdomaward') . ' ka ON ka.kingdomaward_id = a.kingdomaward_id'
            . ' LEFT JOIN ' . $this->_t('award') . ' w  ON w.award_id = ka.award_id'
            . ' LEFT JOIN ' . $this->_t('mundane') . ' g  ON g.mundane_id = a.given_by_id'
            . ' LEFT JOIN ' . $this->_t('kingdom') . ' k  ON k.kingdom_id = a.kingdom_id'
            . ' LEFT JOIN ' . $this->_t('park') . ' p  ON p.park_id = a.park_id';
    }

    /**
     * Free-text search over recipient persona and award name.
     *
     * The ONE place a string reaches SQL, so it is whitelisted rather than escaped:
     * anything outside the allowed set is dropped, and the result is length-capped.
     * Personas legitimately carry apostrophes, hyphens and accents, so the filter
     * keeps letters (including Unicode), digits, space, apostrophe, hyphen and dot.
     */
    private function _searchClause(string $search): string
    {
        $s = preg_replace('/[^\p{L}\p{N} \'\-\.]/u', '', $search);
        $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
        if ($s === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            $s = mb_substr($s, 0, self::MAX_SEARCH);
        } else {
            $s = substr($s, 0, self::MAX_SEARCH);
        }
        // Apostrophes are doubled for SQL; % and _ cannot survive the whitelist,
        // so there is nothing left to escape for LIKE.
        $lit = "'%" . str_replace("'", "''", $s) . "%'";
        return '(COALESCE(m.persona, sm.persona, \'\') LIKE ' . $lit
            . ' OR COALESCE(' . $this->_awardNameExpr() . ', ka.name, w.name, \'\') LIKE ' . $lit . ')';
    }

    /** Lock name, database-qualified so staging and production never block each other. */
    public function RunLockName(int $mundaneId): string
    {
        $name = self::RUN_LOCK_PREFIX . DB_DATABASE . ':' . (int)$mundaneId;
        return substr($name, 0, self::LOCK_NAME_MAX);
    }

    // ---------------------------------------------------------------- run

    /** Column ids that are valid, in registry order, defaulting when none are asked for. */
    public function ResolveColumns(array $wanted): array
    {
        $defs = $this->_columnDefs();
        $out = [];
        foreach ($wanted as $id) {
            if (is_string($id) && isset($defs[$id]) && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        if ($out) {
            return $out;
        }
        foreach ($defs as $id => $d) {
            if (!empty($d['default'])) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * Run a normalized tree and return rows.
     *
     * Three things are applied OUTSIDE the user's filter tree, because they are
     * defaults rather than rules and must not be defeated by an OR group:
     *   - revoked and stripped rows are excluded unless explicitly included
     *   - the search text
     * A user who wants revoked rows asks for them with $opts, not by writing
     * `Revoked IS yes` inside an OR, which would otherwise widen the whole query.
     *
     * @return array{rows: list<array<string,mixed>>, columns: list<string>, capped: bool, total: ?int}
     */
    public function RunRows(array $normalizedTree, array $columns, array $opts = [], array $known = []): array
    {
        $cols = $this->ResolveColumns($columns);
        $where = [$this->CompileTree($normalizedTree, $known)];

        if (empty($opts['include_revoked'])) {
            $where[] = 'COALESCE(a.revoked, 0) = 0';
        }
        if (empty($opts['include_stripped'])) {
            $where[] = 'COALESCE(a.mundane_id, 0) <> 0';
        }
        if (isset($opts['search']) && is_string($opts['search']) && $opts['search'] !== '') {
            $clause = $this->_searchClause($opts['search']);
            if ($clause !== '') {
                $where[] = $clause;
            }
        }

        $limit = isset($opts['limit']) ? max(1, min((int)$opts['limit'], self::MAX_ROWS)) : 200;
        $offset = isset($opts['offset']) ? max(0, (int)$opts['offset']) : 0;

        $select = [];
        $idKeys = [];
        foreach ($cols as $id) {
            $select[] = $this->ColumnSelectSql($id);
            $idSql = $this->_columnIdSql($id);
            if ($idSql !== null) {
                $select[] = $idSql;
                $idKeys[] = $this->_columnIdKey($id);
            }
        }
        // awards_id is the tiebreaker, not decoration: `date` is a DATE, so a court
        // that records thirty awards on one day gives thirty identical sort keys and
        // paging would repeat or skip rows without it.
        $sql = 'SELECT a.awards_id AS awards_id, ' . implode(', ', $select)
            . $this->_fromSql()
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . $this->_datedExpr() . ' DESC, a.date DESC, a.awards_id DESC'
            . ' LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset;

        $rs = $this->_select($sql, true);
        $rows = [];
        while ($rs->next()) {
            $row = ['AwardsId' => (int)$rs->awards_id];
            foreach ($cols as $id) {
                $row[$id] = $rs->{$id};
            }
            foreach ($idKeys as $k) {
                $row[$k] = (int)$rs->{$k};
            }
            $rows[] = $row;
        }
        // One extra row was asked for: its presence means there is another page,
        // without paying for a COUNT on every run.
        $capped = count($rows) > $limit;
        if ($capped) {
            array_pop($rows);
        }
        return ['rows' => $rows, 'columns' => $cols, 'capped' => $capped, 'total' => null];
    }

    // ---------------------------------------------------------------- service entry

    /**
     * Display name for an opening scope. '' when the type is not one we know or
     * the id names nothing, which the caller treats as "no opening filter"
     * rather than as an error worth a banner.
     */
    public function ScopeName(string $scopeType, int $scopeId): string
    {
        if (!valid_id($scopeId)) {
            return '';
        }
        if ($scopeType === 'Park') {
            $sql = 'SELECT name FROM ' . $this->_t('park') . ' WHERE park_id = ' . (int)$scopeId . ' LIMIT 1';
        } elseif ($scopeType === 'Kingdom') {
            $sql = 'SELECT name FROM ' . $this->_t('kingdom') . ' WHERE kingdom_id = ' . (int)$scopeId . ' LIMIT 1';
        } else {
            return '';
        }
        try {
            $rs = $this->_select($sql);
            return $rs->next() ? stripslashes((string)$rs->name) : '';
        } catch (Throwable $e) {
            logtrace('AwardExplorer::ScopeName failure', $e->getMessage());
            return '';
        }
    }

    /**
     * Login gate. Award data is public, but the report is not open to anonymous
     * callers: 397k rows behind a filter builder is exactly what a crawler would
     * walk. There is no scope to authorize beyond that -- any logged-in viewer may
     * see any award in any kingdom.
     */
    public function Authorize(string $token): ?array
    {
        if (!valid_id(Ork3::$Lib->authorization->IsAuthorized($token))) {
            return BadToken();
        }
        return null;
    }

    /**
     * Service entry: authorize, take the per-player run lock, validate the tree,
     * run. Mirrors PopulationExplorer::Run so the controller and the JS speak one
     * shape across both reports.
     */
    public function Run(array $request): array
    {
        $started = microtime(true);
        $token = is_string($request['Token'] ?? null) ? $request['Token'] : '';

        $denied = $this->Authorize($token);
        if ($denied !== null) {
            return ['Status' => $denied];
        }

        // One run at a time per player: a second request while one is still going is
        // refused at once rather than queued behind it.
        $lock = $this->_acquireRunLock($token);
        if ($lock === null) {
            return ['Status' => ProcessingError(self::BUSY_MESSAGE), 'Busy' => true];
        }
        try {
            return $this->_runAuthorized($request, $started);
        } finally {
            $this->_releaseRunLock($lock);
        }
    }

    private function _runAuthorized(array $request, float $started): array
    {
        try {
            $known = $this->LoadKnown();
        } catch (Throwable $e) {
            logtrace('AwardExplorer::Run known failure', $e->getMessage());
            return ['Status' => ProcessingError('The award list could not be read. Please try again.')];
        }

        $tree = is_array($request['Tree'] ?? null) ? $request['Tree'] : ['op' => 'AND', 'children' => []];
        $norm = $this->NormalizeTree($tree, $known);
        if (!$norm['ok']) {
            return ['Status' => InvalidParameter(null, $norm['error']), 'Path' => $norm['path'] ?? []];
        }

        $opts = [
            'include_revoked'  => !empty($request['IncludeRevoked']),
            'include_stripped' => !empty($request['IncludeStripped']),
            'search'           => is_string($request['Search'] ?? null) ? $request['Search'] : '',
            'limit'            => $this->_int($request['Limit'] ?? null) ?? 200,
            'offset'           => $this->_int($request['Offset'] ?? null) ?? 0,
        ];
        $columns = is_array($request['Columns'] ?? null) ? $request['Columns'] : [];

        try {
            $out = $this->RunRows($norm['tree'], $columns, $opts, $known);
            // Only paid for when asked: a COUNT over 397k rows is the expensive half
            // of a run, and the "is there another page" answer already comes free
            // from RunRows asking for one extra row.
            $total = !empty($request['WithTotal']) ? $this->Count($norm['tree'], $opts, $known) : null;
        } catch (RuntimeException $e) {
            if ($e->getCode() === self::TIMEOUT_CODE) {
                return ['Status' => ProcessingError(self::TIMEOUT_MESSAGE), 'Timeout' => true];
            }
            logtrace('AwardExplorer::Run query failure', $e->getMessage());
            return ['Status' => ProcessingError('The awards could not be read. Please try again.')];
        } catch (Throwable $e) {
            logtrace('AwardExplorer::Run failure', $e->getMessage());
            return ['Status' => ProcessingError('The awards could not be read. Please try again.')];
        }

        return [
            'Status'    => Success(),
            'Columns'   => $out['columns'],
            'Rows'      => $out['rows'],
            'HasMore'   => $out['capped'],
            'Total'     => $total,
            'ElapsedMs' => (int)round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * GET_LOCK on this request's connection; MariaDB drops it if the connection
     * ends. Returns the lock name, null when another connection holds it, or ''
     * when no lock could be taken (a DB error) -- the run then goes ahead
     * unguarded, since the lock spares the database, it does not protect data.
     */
    private function _acquireRunLock(string $token): ?string
    {
        try {
            $mid = (int)Ork3::$Lib->authorization->IsAuthorized($token);
            if ($mid <= 0) {
                return '';
            }
            $name = $this->RunLockName($mid);
            $r = $this->_select("SELECT GET_LOCK('" . $name . "', 0) AS got");
            $got = $r->next() ? $r->got : null;
            if ($got === null) {
                return '';
            }
            return (int)$got === 1 ? $name : null;
        } catch (Throwable $e) {
            logtrace('AwardExplorer::Run lock failure', $e->getMessage());
            return '';
        }
    }

    private function _releaseRunLock(string $name): void
    {
        if ($name === '') {
            return;
        }
        try {
            $this->_select("SELECT RELEASE_LOCK('" . $name . "') AS r");
        } catch (Throwable $e) {
            logtrace('AwardExplorer::Run unlock failure', $e->getMessage());
        }
    }

    /** Total matching grants. Separate from Run: only worth paying for when asked. */
    public function Count(array $normalizedTree, array $opts = [], array $known = []): int
    {
        $where = [$this->CompileTree($normalizedTree, $known)];
        if (empty($opts['include_revoked'])) {
            $where[] = 'COALESCE(a.revoked, 0) = 0';
        }
        if (empty($opts['include_stripped'])) {
            $where[] = 'COALESCE(a.mundane_id, 0) <> 0';
        }
        if (isset($opts['search']) && is_string($opts['search']) && $opts['search'] !== '') {
            $clause = $this->_searchClause($opts['search']);
            if ($clause !== '') {
                $where[] = $clause;
            }
        }
        $rs = $this->_select(
            'SELECT COUNT(*) AS n' . $this->_fromSql() . ' WHERE ' . implode(' AND ', $where),
            true
        );
        return $rs->next() ? (int)$rs->n : 0;
    }
}
