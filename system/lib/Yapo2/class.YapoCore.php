<?php

class YapoCore
{
    public $__DB;
    public $__table;
    public $__definition;
    public $__record_set;
    //	var $__current_record;
    public $__field_actions;
    public $__field_alias;
    public $__left_joins;
    public $__mismatched_set_equals;
    public $__ordering;
    public $__pagination;
    public $__page;

    public $__Where;
    public $__Save;
    public $__Find;
    public $__Delete;

    public $__ERRORS = array();

    // Opt-in strict WHERE. See YapoWhere::GenerateSql -- by default, setting
    // the primary key discards every other field from the WHERE clause.
    // Declared here rather than assigned ad hoc because YapoCore::__set
    // forwards unknown properties to the DB handle as bind parameters.
    public $__strict_where = false;

    public function __construct(& $database, $table)
    {
        $this->__DB = & $database;
        $this->__table = $table;
        $this->__definition = $this->__DB->TableDescription($table);
        $this->__left_joins = array();
        $this->__field_alias = array();
        $this->__pagination = null;
        $this->__page = null;

        $this->clear();
    }

    public function init()
    {
        $this->__Where = new YapoWhere($this);

        $this->__Save = new YapoSave($this, $this->__Where);
        $this->__Find = new YapoFind($this, $this->__Where);
        $this->__Delete = new YapoDelete($this, $this->__Where);
    }

    public function Debug($debug)
    {
        $this->__DB->SetDebug($debug);
    }

    public function Clear()
    {
        $this->__field_actions = array();
        $this->__strict_where = false;
        //		$this->__current_record = null;
        $this->__record_set = null;
        $this->__mismatched_set_equals = false;
        $this->__ordering = array();
        $this->__pagination = null;
        $this->__page = null;
        $this->__field_values = array();
        $this->__DB->Clear();
    }

    public function Execute($sql)
    {
        $this->__DB->Execute($sql);
        $this->__ERRORS[] = $this->__record_set->__ERRORS;
    }

    public function DataSet($sql, $Data = null)
    {
        if (!is_null($Data)) {
            $this->SetData($Data);
        }
        $this->__record_set = $this->__DB->DataSet($sql);
        $this->__ERRORS[] = $this->__record_set->__ERROR;
    }

    public function Order($field, $ordering)
    {
        $this->__ordering[$field] = $ordering;
    }

    public function Comparator($field, $comparator, $value)
    {
        if (isset($this->__field_actions[$field]) && !is_array($this->__field_actions[$field])) {
            $this->__field_actions[$field] = array();
        }
        $this->__field_actions[$field][$comparator] = $value;
        if (Yapo::SET == $comparator &&
            isset($this->__field_actions[$field][Yapo::EQUALS]) &&
            $this->__field_actions[$field][Yapo::EQUALS] != $this->__field_actions[$field][Yapo::SET]) {
            $this->__mismatched_set_equals = true;
        }
    }

    public function SubSelect($type, $p, $yapo)
    {

    }

    public function Join($type, $yapo, $this_key, $other_key)
    {

    }

    public function GetLimit()
    {
        return array($this->__pagination, $this->__page);
    }

    public function Limit($pagination = 20, $page = 0)
    {
        $this->__pagination = $pagination;
        $this->__page = $page;
    }

    public function __set($field, $value)
    {
        if (is_object($value)) {
            die("you cannot insert an object.");
        }
        $this->__DB->$field = $value;
    }

    public function __isset($field)
    {
        return $this->__record_set->$field || isset($this->__field_values[$field]);
    }

    public function __get($field)
    {
        if (isset($this->__field_values[$field])) {
            return $this->__field_values[$field];
        } elseif (is_null($this->__record_set)) {
            throw new Exception("There is no active record set.");
        } else {
            return $this->__record_set->$field;
        }
    }

    public function HasActiveRecord()
    {
        return !is_null($this->__record_set);
    }

    public function PrimaryKeyIsSet()
    {
        return (isset($this->__field_actions[$this->GetPrimaryKeyField()][Yapo::SET]) || isset($this->__field_actions[$this->GetPrimaryKeyField()][Yapo::EQUALS]));
    }

    public function GetPrimaryKeyField()
    {
        return $this->__definition["PrimaryKey"];
    }

    public function GetLastInsertId()
    {
        if (is_null($this->__record_set)) {
            throw new Exception("There is no active record set.");
        } else {
            return $this->__DB->GetLastInsertId();
        }
    }

    public function Next()
    {
        if (is_null($this->__record_set)) {
            throw new Exception("There is no active record set.");
        } else {
            return $this->__record_set->Next();
        }
    }

    public function Size()
    {
        if (is_null($this->__record_set)) {
            throw new Exception("There is no active record set.");
        } else {
            return $this->__record_set->Size();
        }
    }

    public function SetData($Data)
    {
        $this->__DB->SetData($Data);
    }

    public function GetRawFields()
    {
        return $this->__definition['Fields'];
    }

    public function GetSelectFields()
    {
        $fields = array();
        foreach ($this->__definition['Fields'] as $field_name => $def) {
            $fields[] = $this->GetQualifiedName($field_name);
        }
        return $fields;
    }

    public function GetFieldSelectAlias($field_name)
    {
        return isset($this->__field_alias[$field_name]) ? ($field_name . ' as ' . $this->__field_alias[$field_name]) : $field_name;
    }

    public function GetQualifiedName($field_name, $delimiter = ".")
    {
        return "{$this->__table}$delimiter" . $this->GetFieldSelectAlias($field_name);
    }

    public function GetActiveFieldSet()
    {
        $fs = $this->__record_set->CurrentFieldSet();
        $rs = array();
        if (is_array($fs)) {
            foreach ($fs as $field => $value) {
                $rs[$this->GetQualifiedName($field)] = $value;
            }
        }
        return $rs;
    }

}
