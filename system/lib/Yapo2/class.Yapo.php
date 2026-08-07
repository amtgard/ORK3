<?php


$dir = dir(__DIR__);
$files = array();
while (false !== ($entry = $dir->read())) {
    $files[] = $entry;
}
$dir->close();

$classfiles = preg_grep("/^class\.(Yapo.+)\.php$/", $files);

foreach ($classfiles as $index => $classfile) {
    include_once(__DIR__ . '/' . $classfile);
}

foreach ($classfiles as $index => $classfile) {
    preg_match("/^class\.(Yapo.+)\.php$/", $classfile, $matches);
    if (class_exists('Yapo' . $matches[1])) {
        $class = 'Yapo' . $matches[1];
        Lib::$Lib->$class = new $class();
    }
}


class Yapo
{
    public $__Core;
    public $__Save;
    public $__Find;
    public $__Delete;
    public $__Where;
    public $__LastSql;
    public $__ERRORS = array();

    public function __construct(& $database, $table)
    {
        $this->__Core = $database->GetCore($table);
        $this->__Core->init();
    }

    public function clear()
    {
        $this->__Core->Clear();
    }

    public function lastSql()
    {
        return $this->__LastSql;
    }

    public function save($all = false)
    {
        list($sql, $Data) = $this->__Core->__Save->GenerateSql(array('all' => $all));
        $this->__LastSql = $sql;
        $primary_key = $this->__Core->GetPrimaryKeyField();

        if (isset($this->__Core->$primary_key)) {
            $pk_id = $this->$primary_key;
        }

        $this->__Core->SetData($Data);
        $this->__Core->DataSet($sql);

        if ("insert" == $this->__Core->__Save->Mode) {
            $pk_id = $this->__Core->GetLastInsertId();
        }

        $this->Clear();
        $this->$primary_key = $pk_id;
        $this->Find();
        $this->Next();

        return $last_insert_id;
    }

    public const ASC = 'ASC';
    public const DESC = 'DESC';

    public function primarykey()
    {
        return $this->__Core->GetPrimaryKeyField();
    }

    public function order($field, $ordering)
    {
        $this->__Core->Order($field, $ordering);
    }

    public function debug($debug)
    {
        $this->__Core->Debug($debug);
    }

    public function size()
    {
        return $this->__Core->Size();
    }

    public function find()
    {
        list($sql, $Data) = $this->__Core->__Find->GenerateSql(array());
        $this->__LastSql = $sql;

        $this->__Core->SetData($Data);
        $this->__Core->DataSet($sql);

        $this->__Core->Next();

        return $this->__Core->Size();
    }

    public function delete()
    {
        list($sql, $Data) = $this->__Core->__Delete->GenerateSql(array());
        $this->__LastSql = $sql;

        $this->__Core->SetData($Data);
        $this->__Core->DataSet($sql);
    }

    public function query($sql, $Data)
    {
        $this->__Core->Query($sql, $Data);
    }

    /***************************************************************************

    Alternate comparison operators:
    ------------------------------
    $mytable->equals($field, $value);
    $mytable->set($field, $value);
    $mytable->like($field, $value);
    $mytable->greater($field, $value);
    $mytable->less($field, $value);
    $mytable->greater_eq($field, $value);
    $mytable->less_eq($field, $value);
    $mytable->comparator($field, YAPO::{COMPARATOR}, $value);
    $mytable->in($field, mixed);
    $mytable->match(mixed $fields, $value[, array booleans]);

    ***************************************************************************/

    public const EQUALS = 'eq';
    public const SET = 'set';
    public const LIKE = 'like';
    public const GREATER = 'gt';
    public const LESS = 'lt';
    public const GREATER_EQ = 'gte';
    public const LESS_EQ = 'lte';
    public const IN = 'in';
    public const MATCH = 'match';
    public const NOT_EQ = 'neq';
    public const NOT_LIKE = 'nlike';
    public const NOT_IN = 'nin';
    public const IS_NULL = 'is_null';

    public function equals($field, $value)
    {
        if (is_null($value)) {
            $this->comparator($field, Yapo::IS_NULL, $value);
        } else {
            $this->comparator($field, Yapo::EQUALS, $value);
        }
    }

    public function not_equals($field, $value)
    {
        $this->comparator($field, Yapo::NOT_EQ, $value);
    }

    public function set($field, $value)
    {
        $this->comparator($field, Yapo::SET, $value);
    }

    public function like($field, $value)
    {
        $this->comparator($field, Yapo::LIKE, $value);
    }

    public function not_like($field, $value)
    {
        $this->comparator($field, Yapo::NOT_LIKE, $value);
    }

    public function greater($field, $value)
    {
        $this->comparator($field, Yapo::GREATER, $value);
    }

    public function less($field, $value)
    {
        $this->comparator($field, Yapo::LESS, $value);
    }

    public function greaterEq($field, $value)
    {
        $this->comparator($field, Yapo::GREATER_EQ, $value);
    }

    public function lessEq($field, $value)
    {
        $this->comparator($field, Yapo::LESS_EQ, $value);
    }

    public function not_in($field, $value)
    {
        $this->comparator($field, Yapo::NOT_IN, $value);
    }

    public function in($field, $value)
    {
        if (is_object($value) && class_name($value) == 'Yapo') {
            $this->__Core->Subselect(Yapo::IN, $field, $value);
        } else {
            $this->comparator($field, Yapo::IN, $value);
        }
    }

    public function from($subselect)
    {
        if (is_object($subselect) && class_name($subselect) == 'Yapo') {
            $this->__Core->Subselect(Yapo::FROM, 0, $subselect);
        }
    }

    public function many($other)
    {
        if (is_object($other) && class_name($other) == 'Yapo') {
            $this->__Core->Join(Yapo::ONE2MANY, $other, $local_key, $other_key);
        }
    }

    public function match($field, $value, $booleans = null)
    {
        $this->comparator($field, Yapo::MATCH, array($value, $booleans));
    }

    public function comparator($field, $comparator, $value)
    {
        $this->__Core->Comparator($field, $comparator, $value);
    }

    public function limit($pagination = 20, $page = 0)
    {
        $this->__Core->Limit($pagination, $page);
    }

    public function next()
    {
        return $this->__Core->Next();
    }

    public function join($other_table, $on_this, $on_that, $cascade = false)
    {
        $this->__Core->__left_joins[$other_table->table] = array(
                'Table' => $other_table,
                'OnThis' => $on_this,
                'OnThat' => $on_that,
                'Cascade' => $cascade
            );
    }

    public function fields($raw = true)
    {
        return $this->__Core->GetRawFields();
    }

    public function alias($field, $alias)
    {
        $this->__Core->field_alias[$alias] = $field;
    }

    public function __set($field, $value)
    {
        if (is_object($value)) {
            debug_print_backtrace();
            die();
        }
        //if (!isset($value)) return;
        //$def = $this->__Core->__definition['Fields'][$field];
        //$value = $this->__Core->__DB->ValidateField($def, $value);
        $this->__Core->__field_values[$field] = $value;
        $this->Equals($field, $value);
        $this->Set($field, $value);
    }

    public function __get($field)
    {
        return $this->__Core->$field;
    }

    // PHP routes empty()/isset() through __isset(), never __get(). Without this
    // method every empty($yapo->field) returned TRUE and every isset() returned
    // FALSE no matter what the loaded row actually held -- which silently
    // disabled the "link already used" guard in Player::ValidateSelfRegLink,
    // where !empty($link->used_by) could never be true.
    public function __isset($field)
    {
        try {
            return !is_null($this->__Core->$field);
        } catch (Exception $e) {
            // No active record set yet.
            return false;
        }
    }

    protected function pkvalue($value = null)
    {
        $pk = $this->primarykey();
        if (!is_null($value)) {
            $this->$pk = $value;
        }
        return $this->$pk;
    }
}
