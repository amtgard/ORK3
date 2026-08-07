<?php

include_once('class.YapoDb.php');

class YapoMysql extends YapoDb
{
    private $DBH;

    private $Data;

    private static $schema_cache = [];

    // Transaction nesting state (see BeginTrans below).
    private $__trans_depth = 0;

    private $__trans_failed = false;

    public function __construct($host, $dbname, $user, $password)
    {
        $this->DBH = new PDO("mysql:host=$host;dbname=$dbname", $user, $password);
        $this->DBH->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
    }

    public function TableDescription($table)
    {
        if (isset(self::$schema_cache[$table])) {
            return self::$schema_cache[$table];
        }
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch('yapo_schema_' . $table, $found);
            // Treat empty Fields as a poisoned entry (DESCRIBE failed when it was cached) —
            // drop it and re-fetch.
            if ($found && !empty($cached['Fields'])) {
                self::$schema_cache[$table] = $cached;
                return $cached;
            }
            if ($found) {
                apcu_delete('yapo_schema_' . $table);
            }
        }
        // Clear any leftover bound parameters before DESCRIBE/SHOW KEYS so PDO doesn't
        // try to bind them to these placeholder-free queries (which causes them to fail
        // silently and return 0 rows — at which point we'd cache an empty schema and
        // poison every subsequent INSERT/UPDATE/find on this table for 24 hours).
        $this->Clear();
        $Keys = $this->DataSet("SHOW KEYS IN $table");
        $this->Clear();
        $Fields = $this->DataSet("describe $table");
        $this->Clear();

        $keys = array();

        while ($Keys->Next()) {
            if (!isset($keys[$Keys->Key_name]) || !is_array($keys[$Keys->Key_name])) {
                $keys[$Keys->Key_name] = array('Unique' => !$Keys->Non_unique,'Columns' => array());
            }
            $keys[$Keys->Key_name]['Columns'][] = $Keys->Column_name;
        }


        $fields = array();
        $primary_key = false;
        while ($Fields->Next()) {
            preg_match("/(.+)\((.+)\)/", $Fields->Type, $matches);
            $fields[$Fields->Field] = array(
                    'MajorType' => count($matches) < 3 ? $Fields->Type : $matches[1],
                    'MinorType' => count($matches) < 3 ? $Fields->Type : $matches[2],
                    'Type' => $Fields->Type,
                    'Null' => $Fields->Null == "NO" ? false : true,
                    'Key' => $Fields->Key,
                    'Extra' => $Fields->Extra
                );
            if (strtoupper($Fields->Key) == 'PRI') {
                $primary_key = $Fields->Field;
            }
        }

        $result = array("Keys" => $keys, "Fields" => $fields, "PrimaryKey" => $primary_key);
        // Never cache an empty Fields array — in either tier. An empty schema means
        // DESCRIBE failed (table missing mid-migration, leftover bound params, etc.).
        // Caching it would poison every subsequent save/find on this table for the
        // life of the process, with PDO ERRMODE_WARNING swallowing the error.
        if (!empty($fields)) {
            self::$schema_cache[$table] = $result;
            if (function_exists('apcu_store')) {
                apcu_store('yapo_schema_' . $table, $result, 86400);
            }
        }
        return $result;
    }

    public function GetLastInsertId()
    {
        return $this->DBH->lastInsertId();
    }

    public function GetCore($table)
    {
        return new YapoCoreMysql($this, $table);
    }

    public function Execute($sql)
    {
        if ($this->Debug) {
            echo $sql;
            print_r($this->Data);
        }
        $cnt = 3;
        do {
            $Query = $this->DBH->prepare($sql);
            if (count($this->Data) > 0) {
                $Query->execute($this->Data);
            } else {
                $Query->execute();
            }
            $failed = $this->handle_errors($cnt--, $Query);
        } while (!$failed);
    }

    public function DataSet($sql)
    {
        if ($this->Debug) {
            echo $sql;
            print_r($this->Data);
        }
        $cnt = 3;
        do {
            $Query = $this->DBH->prepare($sql);
            if (is_countable($this->Data) && count($this->Data) > 0) {
                $Query->execute($this->Data);
            } else {
                $Query->execute();
            }
            $failed = $this->handle_errors($cnt--, $Query);
        } while (!$failed);
        return new YapoResultSet($Query, $sql);
    }

    // Execute() reports nothing: PDO runs in ERRMODE_WARNING and handle_errors()
    // only loops on a dropped connection, so a failed statement is indistinguishable
    // from a successful one. Query() is no better -- it always returns a
    // YapoResultSet object, so the "if (!$this->db->query($sql)) { throw ... }"
    // idiom in the merge routines could never fire. ExecuteChecked returns false
    // when the statement really failed, so destructive multi-statement work can
    // abort and roll back instead of half-completing.
    public function ExecuteChecked($sql)
    {
        if ($this->Debug) {
            echo $sql;
            print_r($this->Data);
        }
        $cnt = 3;
        do {
            $Query = $this->DBH->prepare($sql);
            if ($Query === false) {
                return false;
            }
            if (is_countable($this->Data) && count($this->Data) > 0) {
                $ok = $Query->execute($this->Data);
            } else {
                $ok = $Query->execute();
            }
            $failed = $this->handle_errors($cnt--, $Query);
        } while (!$failed);
        return $ok && in_array($Query->errorCode(), array('00000', null), true);
    }

    // Transaction control. These have to live HERE, not on YapoDb: $DBH is
    // declared private in both classes, so a YapoDb method operating on
    // $this->DBH sees YapoDb's own (null) handle when $this is a YapoMysql.
    // Callers were already using BeginTrans()/CommitTrans()/RollbackTrans()
    // (Unit::MergeUnits), which fataled with "Call to undefined method
    // YapoMysql::BeginTrans()" on the first statement -- so that whole code path
    // had never run.
    //
    // Nested calls are tolerated: an inner Begin/Commit pair is a no-op so a
    // helper that opens its own transaction does not commit its caller's work
    // early, and a rollback anywhere unwinds the whole outermost transaction.
    public function BeginTrans()
    {
        if ($this->__trans_depth === 0) {
            $this->__trans_failed = false;
            if (!$this->DBH->inTransaction()) {
                $this->DBH->beginTransaction();
            }
        }
        $this->__trans_depth++;
        return true;
    }

    public function CommitTrans()
    {
        if ($this->__trans_depth === 0) {
            return false;
        }
        $this->__trans_depth--;
        if ($this->__trans_depth > 0) {
            return true;
        }
        if ($this->__trans_failed) {
            $this->__trans_failed = false;
            if ($this->DBH->inTransaction()) {
                $this->DBH->rollBack();
            }
            return false;
        }
        return $this->DBH->inTransaction() ? $this->DBH->commit() : true;
    }

    public function RollbackTrans()
    {
        if ($this->__trans_depth === 0) {
            return false;
        }
        $this->__trans_depth--;
        if ($this->__trans_depth > 0) {
            // An inner scope failed; remember it so the outermost commit rolls back.
            $this->__trans_failed = true;
            return true;
        }
        $this->__trans_failed = false;
        return $this->DBH->inTransaction() ? $this->DBH->rollBack() : true;
    }

    public function InTrans()
    {
        return $this->DBH->inTransaction();
    }

    public function Clear()
    {
        $this->Data = array();
    }

    public function __set($field, $value)
    {
        if (is_object($value)) {
            die("you cannot insert an object.");
        }
        $this->Data[":$field"] = $value;
    }

    public function SetData($Data)
    {
        $this->Data = $Data;
    }

    public function ValidateField($field_def, $value)
    {
        if (stristr($field_def['MajorType'], 'int') ||
            stristr($field_def['MajorType'], 'float') ||
            stristr($field_def['MajorType'], 'double') ||
            stristr($field_def['MajorType'], 'real') ||
            stristr($field_def['MajorType'], 'decimal') ||
            stristr($field_def['MajorType'], 'numeric')) {
            return $value;
        } elseif (strtoupper($field_def['MajorType']) == 'TIME' ||
                    stristr($field_def['MajorType'], 'timestamp')) {
            if (is_numeric($value)) {
                return date("Y-m-d H:i:s", $value);
            } else {
                return date("Y-m-d H:i:s", strtotime($value));
            }
        } elseif (stristr($field_def['MajorType'], 'date')) {
            if (is_numeric($value)) {
                return date("Y-m-d", $value);
            } else {
                return date("Y-m-d", strtotime($value));
            }
        } elseif (strtoupper($field_def['MajorType']) == 'YEAR') {
            return date("Y", strtotime($value));
        } elseif (stristr($field_def['MajorType'], 'text')) {
            // incomplete
        }
    }

}
