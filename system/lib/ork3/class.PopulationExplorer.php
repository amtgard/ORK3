<?php

/**
 * Population Explorer: criteria/columns registry and filter-tree validator.
 *
 * The filter tree is untrusted client input. NormalizeTree() either returns a
 * canonical tree whose values are all ints / validated dates, or an error.
 * SQL compilation (the registry `sql` closures) is added separately.
 */
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

    /** Known class / award / peerage ids, read-only. */
    public function LoadKnown(): array
    {
        $known = ['class' => [], 'award' => [], 'peerage' => []];
        $r = $this->db->query('SELECT class_id FROM ' . DB_PREFIX . 'class');
        if ($r !== false) {
            while ($r->next()) {
                $known['class'][] = (int)$r->class_id;
            }
        }
        $r = $this->db->query('SELECT award_id, peerage FROM ' . DB_PREFIX . 'award');
        if ($r !== false) {
            while ($r->next()) {
                $known['award'][] = (int)$r->award_id;
                $known['peerage'][$r->peerage][] = (int)$r->award_id;
            }
        }
        return $known;
    }
}
