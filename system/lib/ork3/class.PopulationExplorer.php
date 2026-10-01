<?php

/**
 * Population Explorer: criteria/columns registry and filter-tree validator.
 *
 * The filter tree is untrusted client input. NormalizeTree() either returns a
 * canonical tree whose values are all ints / validated dates, or an error.
 * CompileTree() / ColumnSelectSql() turn a canonical tree into a SQL boolean
 * over alias `m` (ork_mundane). Only ints and validated dates ever reach SQL.
 */
require_once __DIR__ . '/../vendor/SimpleXlsx.php';

class PopulationExplorer extends Ork3
{
    public const MAX_DEPTH = 6;
    public const MAX_LEAVES = 40;
    public const MAX_LIST = 100;
    public const MAX_ROWS = 5000;
    public const MAX_LINK_BYTES = 8192;
    public const MAX_MONTHS = 60;

    public const OPS_CMP = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'between'];
    public const OPS_SET = ['is', 'is_not', 'in', 'not_in'];
    public const OPS_PEER = ['has_any', 'has_all', 'has_none', 'is'];
    public const OPS_BOOL = ['is'];
    public const SQL_CMP = ['eq' => '=', 'ne' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];

    public function __construct()
    {
        parent::__construct();
    }

    public function Registry(): array
    {
        return ['criteria' => $this->_criteria(), 'columns' => $this->_columns()];
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
            'sql'      => null,
        ], $extra);
    }

    private function _criteria(): array
    {
        $list = $this->_criteriaDefs();
        foreach ($list as $id => &$def) {
            $def['sql'] = $this->_criterionSql($id, $def);
        }
        unset($def);
        return $list;
    }

    private function _criteriaDefs(): array
    {
        return [
            'last_signin'           => $this->_crit('Last sign-in', 'Activity', 'date'),
            'player_since'          => $this->_crit('Player since', 'Activity', 'date'),
            'signins_last_n_months' => $this->_crit('Sign-ins in last N months', 'Activity', 'number', ['param' => true]),
            'total_signins'         => $this->_crit('Total sign-ins', 'Activity', 'number'),
            'last_class'            => $this->_crit('Last class played', 'Activity', 'enum_set', ['set' => 'class']),
            'classes_last_n_months' => $this->_crit('Classes played in last N months', 'Activity', 'enum_set', ['set' => 'class', 'param' => true]),
            'home_kingdom'          => $this->_crit('Home kingdom', 'Location', 'enum_set', ['set' => 'kingdom']),
            'home_park'             => $this->_crit('Home park', 'Location', 'enum_set', ['set' => 'park']),
            'last_signin_park'      => $this->_crit('Last sign-in park', 'Location', 'enum_set', ['set' => 'park']),
            'dues_paid'             => $this->_crit('Dues paid', 'Status', 'bool'),
            'dues_through'          => $this->_crit('Dues paid through', 'Status', 'date'),
            'waivered'              => $this->_crit('Waivered', 'Status', 'bool'),
            'active'                => $this->_crit('Active', 'Status', 'bool'),
            'suspended'             => $this->_crit('Suspended', 'Status', 'bool'),
            'banned'                => $this->_crit('Banned', 'Status', 'bool'),
            'knighthood'            => $this->_crit('Knighthood', 'Peerage', 'peerage_set', ['peerage' => ['Knight']]),
            'masterhood'            => $this->_crit('Masterhood', 'Peerage', 'peerage_set', ['peerage' => ['Master']]),
            'paragon'               => $this->_crit('Paragon', 'Peerage', 'peerage_set', ['peerage' => ['Paragon']]),
            'lesser_peerage'        => $this->_crit('Lesser peerage', 'Peerage', 'peerage_set', ['peerage' => ['Squire', 'Page', 'Man-At-Arms']]),
            'has_award'             => $this->_crit('Has award', 'Awards', 'enum_set', ['set' => 'award']),
            'award_count'           => $this->_crit('Award count', 'Awards', 'number'),
            'award_date_any'        => $this->_crit('Any award received date', 'Awards', 'date'),
            'reeve_qualified'       => $this->_crit('Reeve qualified', 'Qualifications', 'bool'),
            'corpora_qualified'     => $this->_crit('Corpora qualified', 'Qualifications', 'bool'),
        ];
    }

    private function _col(string $label, string $group, string $type, bool $default = false): array
    {
        return ['label' => $label, 'group' => $group, 'type' => $type, 'default' => $default, 'sql' => null];
    }

    private function _columns(): array
    {
        $list = $this->_columnDefs();
        foreach ($list as $id => &$def) {
            $def['sql'] = function (array $ctx) use ($id): string {
                return $this->_columnSql($id, $ctx);
            };
        }
        unset($def);
        return $list;
    }

    private function _columnDefs(): array
    {
        return [
            'persona'           => $this->_col('Persona', 'Player', 'text', true),
            'home_park'         => $this->_col('Home park', 'Location', 'text', true),
            'home_kingdom'      => $this->_col('Home kingdom', 'Location', 'text'),
            'last_signin'       => $this->_col('Last sign-in', 'Activity', 'date', true),
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
     * @return array ['ok'=>true,'tree'=>array] or ['ok'=>false,'error'=>string,'path'=>int[]]
     */
    public function NormalizeTree(array $tree, ?array $known = null): array
    {
        $known = $known ?? $this->LoadKnown();
        $registry = $this->_criteria();
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
                return $this->_normScalar($v, $o, 'date', 'Value must be a valid date (YYYY-MM-DD)', function ($x) {
                    return (is_string($x) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $x)
                        && checkdate((int)substr($x, 5, 2), (int)substr($x, 8, 2), (int)substr($x, 0, 4))) ? $x : null;
                });
            case 'number':
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
            if ($a > $b) {
                return ['error' => 'Between range must be in ascending order'];
            }
            return ['v' => [$a, $b]];
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
     */
    public function LoadKnown(): array
    {
        $known = ['class' => [], 'award' => [], 'peerage' => []];
        $r = $this->db->query('SELECT class_id FROM ' . DB_PREFIX . 'class');
        if ($r === false || $r === null) {
            throw new RuntimeException('Could not read class list');
        }
        while ($r->next()) {
            $known['class'][] = (int)$r->class_id;
        }
        $r = $this->db->query('SELECT award_id, peerage FROM ' . DB_PREFIX . 'award');
        if ($r === false || $r === null) {
            throw new RuntimeException('Could not read award list');
        }
        while ($r->next()) {
            $id = (int)$r->award_id;
            $known['award'][] = $id;
            $pe = trim((string)$r->peerage);
            if ($pe !== '') {
                $known['peerage'][$pe][] = $id;
            }
        }
        return $known;
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

    private function _lastSigninExpr(): string
    {
        return $this->_attSub('MAX(a.date)');
    }

    private function _playerSinceExpr(): string
    {
        return 'COALESCE(m.player_since_override, ' . $this->_attSub('MIN(a.date)') . ')';
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

    private function _lastSigninParkExpr(): string
    {
        return $this->_attSub('a.park_id', '', 'ORDER BY a.date DESC, a.attendance_id DESC LIMIT 1');
    }

    private function _duesFrom(array $ctx): string
    {
        $scope = isset($ctx['accountScope']) && $ctx['accountScope'] !== '' ? $ctx['accountScope'] : '1=0';
        return 'FROM ' . $this->_t('split') . ' s JOIN ' . $this->_t('account') . ' ac ON ac.account_id = s.account_id'
            . ' WHERE s.src_mundane_id = m.mundane_id AND s.is_dues = 1 AND (' . $scope . ')';
    }

    private function _duesThroughExpr(array $ctx): string
    {
        return '(SELECT MAX(s.dues_through) ' . $this->_duesFrom($ctx) . ')';
    }

    private function _duesPaidExists(array $ctx): string
    {
        return 'EXISTS (SELECT 1 ' . $this->_duesFrom($ctx) . ' AND s.dues_through >= CURDATE())';
    }

    /**
     * Held awards for the player in alias `m`. `aw` is the EFFECTIVE award: a
     * Custom Title aliased to a peerage award (awards.alias_award_id) counts as
     * its alias target, matching Report::PlayerAwards / BeltlineData
     * (COALESCE(alias.peerage, a.peerage)). Revoked and stripped awards never count.
     */
    private function _heldAwardsFrom(string $extraWhere = ''): string
    {
        return 'FROM ' . $this->_t('awards') . ' w'
            . ' LEFT JOIN ' . $this->_t('kingdomaward') . ' ka ON ka.kingdomaward_id = w.kingdomaward_id'
            . ' JOIN ' . $this->_t('award') . ' aw ON aw.award_id = COALESCE(NULLIF(w.alias_award_id, 0), NULLIF(w.award_id, 0), ka.award_id)'
            . ' WHERE w.mundane_id = m.mundane_id AND w.revoked = 0 AND COALESCE(w.stripped_from, 0) = 0'
            . ($extraWhere !== '' ? ' ' . $extraWhere : '');
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

    private function _awardSetSql(string $o, $v, ?string $peerageFilter): string
    {
        // Peerage criteria only (has_award passes null): `is` takes yes|no.
        if ($peerageFilter !== null && $o === 'is') {
            return ($v ? 'EXISTS' : 'NOT EXISTS') . ' (SELECT 1 ' . $this->_heldAwardsFrom('AND ' . $peerageFilter) . ')';
        }
        // has_award is an enum_set criterion, so also accept the set operands.
        $map = ['in' => 'has_any', 'is' => 'has_any', 'not_in' => 'has_none', 'is_not' => 'has_none'];
        $o = $map[$o] ?? $o;
        $ids = array_values(array_unique(array_map('intval', (array)$v)));
        $in = 'aw.award_id IN (' . implode(',', $ids) . ')';
        switch ($o) {
            case 'has_any':
                return 'EXISTS (SELECT 1 ' . $this->_heldAwardsFrom('AND ' . $in) . ')';
            case 'has_none':
                return 'NOT EXISTS (SELECT 1 ' . $this->_heldAwardsFrom('AND ' . $in) . ')';
            case 'has_all':
                return '(SELECT COUNT(DISTINCT aw.award_id) ' . $this->_heldAwardsFrom('AND ' . $in) . ') = ' . count($ids);
        }
        throw new InvalidArgumentException('bad operand');
    }

    /** @return callable function(string $o, $v, ?int $p, array $ctx): string */
    private function _criterionSql(string $id, array $def): callable
    {
        return function (string $o, $v, ?int $p, array $ctx) use ($id, $def): string {
            switch ($id) {
                case 'last_signin':
                    return $this->_cmp($this->_lastSigninExpr(), 'date', $o, $v);
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
                    return $this->_awardSetSql($o, $v, $this->_peerageIn($def['peerage']));
                case 'has_award':
                    return $this->_awardSetSql($o, $v, null);
                case 'award_count':
                    return $this->_cmp('(SELECT COUNT(*) ' . $this->_heldAwardsFrom() . ')', 'number', $o, $v);
                case 'award_date_any':
                    return 'EXISTS (SELECT 1 ' . $this->_heldAwardsFrom('AND ' . $this->_cmp('w.date', 'date', $o, $v)) . ')';
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
                return "(SELECT GROUP_CONCAT(DISTINCT aw.name ORDER BY aw.name SEPARATOR ', ') "
                    . $this->_heldAwardsFrom("AND aw.peerage = '$pe'") . ')';
            case 'award_count':
                return '(SELECT COUNT(*) ' . $this->_heldAwardsFrom() . ')';
            case 'reeve_qualified':
                return 'CASE WHEN ' . $this->_qualifiedExpr('reeve') . ' THEN 1 ELSE 0 END';
            case 'corpora_qualified':
                return 'CASE WHEN ' . $this->_qualifiedExpr('corpora') . ' THEN 1 ELSE 0 END';
        }
        throw new InvalidArgumentException('unknown column');
    }

    /**
     * Compile a normalized tree (from NormalizeTree) into a SQL boolean over alias `m`.
     * ctx['accountScope'] is a SQL boolean over alias `ac` (ork_account) for dues rules.
     */
    public function CompileTree(array $normalizedTree, array $ctx = []): string
    {
        $registry = $this->_criteria();
        if (empty($normalizedTree['children'])) {
            return '1=1';
        }
        return $this->_compileNode($normalizedTree, $registry, $ctx);
    }

    private function _compileNode(array $node, array $registry, array $ctx): string
    {
        if (isset($node['children'])) {
            $parts = [];
            foreach ($node['children'] as $child) {
                $parts[] = $this->_compileNode($child, $registry, $ctx);
            }
            if (count($parts) === 0) {
                return '1=1';
            }
            return '(' . implode($node['op'] === 'OR' ? ' OR ' : ' AND ', $parts) . ')';
        }
        $fn = $registry[$node['c']]['sql'];
        return '(' . $fn($node['o'], $node['v'], $node['p'] ?? null, $ctx) . ')';
    }

    // ---------------------------------------------------------------- scope, authorization, execution

    /**
     * Same gate as Report::_authorizeKingdomParkReportScope (private there, so
     * mirrored here): global admin, or kingdom EDIT, or park CREATE.
     *
     * @return array|null null when allowed, else an error Status array
     */
    public function AuthorizeScope(string $token, string $scopeType, int $scopeId): ?array
    {
        if ($scopeType !== 'Kingdom' && $scopeType !== 'Park') {
            return InvalidParameter('Scope type must be Kingdom or Park.');
        }
        if (!valid_id($scopeId)) {
            return InvalidParameter('Scope id is required.');
        }
        $actorId = Ork3::$Lib->authorization->IsAuthorized($token);
        if (!valid_id($actorId)) {
            return BadToken();
        }
        $auth = Ork3::$Lib->authorization;
        if ($auth->HasAuthority($actorId, AUTH_ADMIN, 0, AUTH_ADMIN)
            || $auth->HasAuthority($actorId, AUTH_ADMIN, 0, AUTH_CREATE)) {
            return null;
        }
        if ($scopeType === 'Park') {
            if ($auth->HasAuthority($actorId, AUTH_PARK, $scopeId, AUTH_CREATE)) {
                return null;
            }
        } elseif ($auth->HasAuthority($actorId, AUTH_KINGDOM, $scopeId, AUTH_EDIT)) {
            return null;
        }
        return NoAuthorization();
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

    /** @return array [mundane scope clause over m, account scope clause over ac] */
    private function _scopeClauses(string $scopeType, int $scopeId): array
    {
        if ($scopeType === 'Park') {
            return ['m.park_id = ' . $scopeId, '(ac.park_id = ' . $scopeId . ')'];
        }
        $ids = $this->_scopeKingdomIds($scopeId);
        if (count($ids) === 0) {
            return ['1=0', '(1=0)'];
        }
        $in = implode(',', $ids);
        return [
            "m.kingdom_id IN ($in)",
            "(ac.kingdom_id IN ($in) OR ac.park_id IN (SELECT park_id FROM " . $this->_t('park') . " WHERE kingdom_id IN ($in)))",
        ];
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

        // Columns: whitelist, persona always first, request order, no duplicates.
        $registryCols = $this->_columns();
        $colIds = ['persona'];
        foreach ((array)($request['Columns'] ?? []) as $c) {
            if (is_string($c) && isset($registryCols[$c]) && !in_array($c, $colIds, true)) {
                $colIds[] = $c;
            }
        }

        try {
            $known = $this->LoadKnown();
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

        list($scopeSql, $accountScope) = $this->_scopeClauses($scopeType, $scopeId);
        $ctx = ['accountScope' => $accountScope];
        try {
            $treeSql = $this->CompileTree($norm['tree'], $ctx);
            $selects = ['m.mundane_id AS mundane_id'];
            foreach ($colIds as $id) {
                $selects[] = '(' . $this->ColumnSelectSql($id, $ctx) . ') AS c_' . $id;
            }
        } catch (InvalidArgumentException $e) {
            return ['Status' => InvalidParameter($e->getMessage())];
        }

        $where = 'WHERE (' . $scopeSql . ') AND (' . $treeSql . ')';
        $sql = 'SELECT ' . implode(', ', $selects)
            . ' FROM ' . $this->_t('mundane') . ' m'
            . ' LEFT JOIN ' . $this->_t('kingdom') . ' k ON k.kingdom_id = m.kingdom_id'
            . ' LEFT JOIN ' . $this->_t('park') . ' p ON p.park_id = m.park_id '
            . $where . ' GROUP BY m.mundane_id ORDER BY m.persona, m.mundane_id LIMIT ' . (int)$cap;
        $countSql = 'SELECT COUNT(*) AS n FROM ' . $this->_t('mundane') . ' m ' . $where;
        logtrace('PopulationExplorer::Run', [$sql, $countSql]);

        $rows = [];
        $total = 0;
        $scopeTotal = 0;
        try {
            $r = $this->db->query($sql);
            if ($r === false || $r === null) {
                throw new RuntimeException('query failed');
            }
            while ($r->next()) {
                $row = ['MundaneId' => (int)$r->mundane_id];
                foreach ($colIds as $id) {
                    $field = 'c_' . $id;
                    $row[$id] = $this->_cell($registryCols[$id]['type'], $r->$field);
                }
                $rows[] = $row;
            }
            $c = $this->db->query($countSql);
            if ($c === false || $c === null || !$c->next()) {
                throw new RuntimeException('count failed');
            }
            $total = (int)$c->n;
            // Everyone in the authorized scope, ignoring the tree (for "% of scope").
            $this->db->Clear();
            $sc = $this->db->query('SELECT COUNT(*) AS n FROM ' . $this->_t('mundane') . ' m WHERE (' . $scopeSql . ')');
            if ($sc === false || $sc === null || !$sc->next()) {
                throw new RuntimeException('scope count failed');
            }
            $scopeTotal = (int)$sc->n;
        } catch (Throwable $e) {
            logtrace('PopulationExplorer::Run failure', $e->getMessage());
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
        $r = $this->Run($request);
        if (($r['Status']['Status'] ?? 1) != 0) {
            $out = ['Status' => $r['Status']];
            if (isset($r['RulePath'])) {
                $out['RulePath'] = $r['RulePath'];
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
                    $line[] = ['v' => stripslashes((string)$v), 't' => 's'];
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

    /** Normalise a raw DB cell by column type: numbers/bools to ints, NULL stays null. */
    private function _cell(string $type, $v)
    {
        if ($v === null) {
            return null;
        }
        if ($type === 'number' || $type === 'bool') {
            return (int)$v;
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
     * filtered to known column ids.
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
     * Registry safe to send to the browser: no closures / SQL, plus the option
     * lists the rule editor needs, limited to the viewer's chosen scope.
     */
    public function PublicRegistry(string $scopeType, int $scopeId): array
    {
        $criteria = [];
        foreach ($this->_criteriaDefs() as $id => $def) {
            unset($def['sql']);
            $criteria[$id] = $def;
        }
        $columns = [];
        foreach ($this->_columnDefs() as $id => $def) {
            unset($def['sql']);
            $columns[$id] = $def;
        }

        $options = ['class' => [], 'award' => [], 'order' => [], 'park' => [], 'kingdom' => []];
        $r = $this->db->query('SELECT class_id, name FROM ' . $this->_t('class') . ' ORDER BY name');
        while ($r && $r->next()) {
            $options['class'][] = [(int)$r->class_id, (string)$r->name];
        }

        $orderPeerage = ['Knight', 'Master', 'Paragon', 'Squire', 'Page', 'Man-At-Arms'];
        foreach ($orderPeerage as $pe) {
            $options['order'][$pe] = [];
        }
        $r = $this->db->query('SELECT award_id, name, peerage FROM ' . $this->_t('award') . ' WHERE deprecate = 0 ORDER BY name');
        while ($r && $r->next()) {
            $pe = (string)$r->peerage;
            $options['award'][] = [(int)$r->award_id, (string)$r->name, $pe];
            if (isset($options['order'][$pe])) {
                $options['order'][$pe][] = [(int)$r->award_id, (string)$r->name];
            }
        }

        if ($scopeType === 'Park') {
            $pid = (int)$scopeId;
            $r = $this->db->query('SELECT p.park_id, p.name AS park_name, k.kingdom_id, k.name AS kingdom_name FROM ' . $this->_t('park') . ' p'
                . ' LEFT JOIN ' . $this->_t('kingdom') . ' k ON k.kingdom_id = p.kingdom_id WHERE p.park_id = ' . $pid);
            while ($r && $r->next()) {
                $options['park'][] = [(int)$r->park_id, (string)$r->park_name];
                if ((int)$r->kingdom_id > 0) {
                    $options['kingdom'][] = [(int)$r->kingdom_id, (string)$r->kingdom_name];
                }
            }
        } else {
            $ids = $this->_scopeKingdomIds((int)$scopeId);
            if (count($ids) > 0) {
                $in = implode(',', $ids);
                $r = $this->db->query('SELECT kingdom_id, name FROM ' . $this->_t('kingdom') . " WHERE kingdom_id IN ($in) ORDER BY name");
                while ($r && $r->next()) {
                    $options['kingdom'][] = [(int)$r->kingdom_id, (string)$r->name];
                }
                $r = $this->db->query('SELECT park_id, name FROM ' . $this->_t('park') . " WHERE kingdom_id IN ($in) AND active = 'Active' ORDER BY name");
                while ($r && $r->next()) {
                    $options['park'][] = [(int)$r->park_id, (string)$r->name];
                }
            }
        }

        return ['criteria' => $criteria, 'columns' => $columns, 'options' => $options];
    }
}
