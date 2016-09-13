<?php

include_once(Yapo::$DIR_DRIVER . '/core.PostgreSql.php');
include_once(Yapo::$DIR . '/class.YapoDb.php');

class YapoPostgreSql extends YapoDb {
	
	function __construct($host, $dbname, $user, $password, $err_mode = PDO::ERRMODE_SILENT, $port = 5432) {
		$this->DBH = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
		$this->DBH->setAttribute(PDO::ATTR_ERRMODE, $err_mode);
	}
	
	public function TableExists($table) {
		$table = $this->DataSet("SELECT * FROM pg_catalog.pg_tables where tablename = '$table';");
		return $table->Size() == 1;
	}
	
	function TableDescription($table) {
		$this->Clear();
		$Keys = $this->DataSet("SELECT a.attnotnull as unique, i.indkey as key_name, a.attname as column_name, 
										format_type(a.atttypid, a.atttypmod) AS type, i.indisprimary as key
									FROM   pg_index i
									JOIN   pg_attribute a ON a.attrelid = i.indrelid
														 AND a.attnum = ANY(i.indkey)
									WHERE  i.indrelid = '$table'::regclass");
									
		$this->Clear();
		$Fields = $this->DataSet("select pg_get_serial_sequence('$table',column_name) as sequence, column_name as field, column_default as default, is_nullable as null, data_type as type, udt_name as udttype
									from INFORMATION_SCHEMA.COLUMNS where table_name = '$table';");
		$this->Clear();
		
		$keys = array();
		
		while ($Keys->Next()) {
			$keys[$Keys->column_name] = $Keys->key == '1';
		}
		
		
		$fields = array();
		$primary_key = false;
		$tableSequence = false;
		while ($Fields->Next()) {
			$fields[$Fields->field] = array(
					'MajorType' => $Fields->type,
					'MinorType' => $Fields->udttype,
					'Type' => $Fields->type,
					'Null' => $Fields->null=="NO"?false:true,
					'Key' => isset($keys[$Fields->field]),
					'Extra' => null
				);
			if (isset($keys[$Fields->field]) && $keys[$Fields->field] === true) {
				$primary_key = $Fields->field;
				if (strstr($Fields->sequence, ".") === false)
					throw new Exception("Yapo requires a sequence, which should be on the primary key.  If you created a sequence on the primary key, you may have to set the ownership of the sequence to the table field.  Try: alter sequence <sequence> owned by $table.$primary_key;");
				$seq = explode('.', trim($Fields->sequence));
				if (is_array($seq) && count($seq) > 0)
					$tableSequence = $seq[count($seq) - 1];
			}
		}
		
		if ($primary_key === false)
			throw new Exception("Yapo requires a primary key.");
		
		if ($tableSequence === false)
			throw new Exception("Yapo requires a sequence, which should be on the primary key.");
	
		$this->__definition = array("Keys" => $keys, "Fields" => $fields, "PrimaryKey" => $primary_key, "TableSequence" => $tableSequence);
		return $this->__definition;
	}	

	function GetCore($table) {
		return new YapoCorePostgreSql($this, $table);
	}
	
	function handle_errors($cnt, $Query) {
		if ($cnt==0) return true;
		switch ($Query->errorCode()) {
			case '00000': return true;
			default: 
				throw new Exception($Query->errorCode() . "\n" . 
										print_r($Query->errorInfo(), true) . "\n" .
										$this->__lastsql . "\n" .
										print_r($this->Data, true)
										);
				return false;
		}
	}
	
	function ValidateField($field_def, $value) {
		if (stristr($field_def['MajorType'], 'int') || 
			stristr($field_def['MajorType'], 'float') || 
			stristr($field_def['MajorType'], 'double') || 
			stristr($field_def['MajorType'], 'real') ||
			stristr($field_def['MajorType'], 'decimal') ||
			stristr($field_def['MajorType'], 'numeric')) {
			return $value;
		} else if (stristr($field_def['MajorType'], 'timestamp')) {
			return "'" . date("Y-m-d H:i:s", strtotime($value)) . "'";
		} else if (stristr($field_def['MajorType'], 'time')) {
			return "'" . date("H:i:s", strtotime($value)) . "'";
		}
	}

	function FixPrimarySequence($table, $sequence, $primaryKey) {
		$this->Clear();
		$this->Execute("SELECT setval('$sequence', COALESCE((SELECT MAX($table)+1 FROM $primaryKey), 1), false)");
	}
	
	function GetStructureDriver($structure, $factory, $values) {
		if (!file_exists(Yapo::$DIR_DRIVER . '/structure.PostgreSql.' . $structure . '.php')) {
			throw new Exception("Required driver $structure does not exist.");
		}
		include_once(Yapo::$DIR_DRIVER . '/structure.PostgreSql.' . $structure . '.php');
		$driver_class = 'Postgresql' . $structure;
		$params = array_merge((array)$driver_class, $values);
		return call_user_func_array($factory, $params);
	}

}

?>