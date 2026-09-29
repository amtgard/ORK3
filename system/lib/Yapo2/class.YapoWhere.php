<?php

include_once(__DIR__ . '/class.YapoAction.php');

class YapoWhere extends YapoAction
{
    public function __construct(& $Core)
    {
        parent::__construct($Core);
    }

    public function GenerateSql($params)
    {
        parent::GenerateSql($params);
        if (is_array($params)) {
            extract($params);
        }

        if (!isset($conjunction)) {
            $conjunction = 'and';
        }

        $where_clauses = array();
        $where_fields = array();
        foreach ($this->Core->__field_actions as $field => $action) {

            // ---------------------------------------------------------------
            // HAZARD: once the primary key is set, EVERY other field is dropped
            // from the WHERE clause. So this natural-looking idiom --
            //
            //     $obj->scope_id = $request['ScopeId'];  // intended ownership filter
            //     $obj->row_id   = $request['RowId'];    // primary key
            //     if ($obj->find()) { ...write... }
            //
            // -- collapses to "WHERE row_id = ?". The scope_id is NOT an
            // ownership filter and never was. Every "set FK + set PK -> find()"
            // written against this is a latent IDOR; it is the amplifier behind
            // the F006 / F009 / F009b cross-tenant defects.
            //
            // The rule to write against instead: never authorize on a scope
            // identifier supplied by the caller. Load the row by its primary key,
            // then re-read the FOUND ROW's owner and authorize against that.
            // Player::RemoveNote is the in-codebase model.
            //
            // The collapse is left as the DEFAULT because a great deal of
            // existing code depends on it -- flipping it globally would silently
            // turn currently-succeeding find()s into misses. Call
            // $yapo->strict_where() (after clear(), before setting fields) to
            // opt a single lookup into a full WHERE clause.
            //
            //Is this important, or just an optimization that got out of hand?
            //It's important ... Noah 10/4/2013
            // ---------------------------------------------------------------
            if (!$this->Core->__strict_where && $this->Core->PrimaryKeyIsSet()) {
                if ($field != $this->Core->GetPrimaryKeyField()) {
                    continue;
                }

            }

            foreach ($action as $comparator => $value) {
                switch ($comparator) {
                    case Yapo::NOT_EQ:
                    case Yapo::EQUALS:
                        $where_clauses[] = $this->Core->GetQualifiedName($field) . ($comparator == Yapo::EQUALS ? '=' : '!=') . " :where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_");
                        $where_fields["where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_")] = $value;
                        break;
                    case Yapo::IS_NULL:
                        $where_clauses[] = $this->Core->GetQualifiedName($field) . " is null ";
                        break;
                    case Yapo::NOT_LIKE:
                    case Yapo::LIKE:
                        $where_clauses[] = $this->Core->GetQualifiedName($field) . ($comparator == Yapo::NOT_LIKE ? ' not ' : '') . " like :where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_");
                        $where_fields["where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_")] = $value;
                        break;
                    case Yapo::GREATER:
                        $where_clauses[] = $this->Core->GetQualifiedName($field) . " > :where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_");
                        $where_fields["where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_")] = $value;
                        break;
                    case Yapo::LESS:
                        $where_clauses[] = $this->Core->GetQualifiedName($field) . " < :where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_");
                        $where_fields["where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_")] = $value;
                        break;
                    case Yapo::GREATER_EQ:
                        $where_clauses[] = $this->Core->GetQualifiedName($field) . " >= :where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_");
                        $where_fields["where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_")] = $value;
                        break;
                    case Yapo::LESS_EQ:
                        $where_clauses[] = $this->Core->GetQualifiedName($field) . " <= :where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_");
                        $where_fields["where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_")] = $value;
                        break;
                    case Yapo::NOT_IN:
                    case Yapo::IN:
                        $Core = $this->Core;
                        $where_clauses[] =
                            $this->Core->GetQualifiedName($field) . ($comparator == Yapo::NOT_IN ? ' not ' : '') . " in (" .
                                implode(
                                    ', ',
                                    array_map(
                                        function ($n) use ($field, $Core, $comparator) {
                                            return ":where_{$comparator}_" . $Core->GetQualifiedName($field, "_") . $n;
                                        },
                                        range(0, count($value) - 1, 1)
                                    )
                                ) .
                                ")";
                        $n = 0;
                        foreach ($value as $index => $v) {
                            $where_fields["where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_") . $n] = $v;
                            $n++;
                        }
                        break;
                }
            }
        }

        if (count($where_clauses) > 0) {
            return array('where ' . implode(" $conjunction ", $where_clauses), $where_fields);
        }
        return array("", array());
    }
}
