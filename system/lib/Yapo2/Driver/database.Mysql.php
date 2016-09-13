<?php

include_once(Yapo::$DIR_DRIVER . '/core.Mysql.php');
include_once(Yapo::$DIR . '/class.YapoDb.php');

class YapoMysql extends YapoDb {

	function __construct($host, $dbname, $user, $password, $err_mode = PDO::ERRMODE_SILENT) {
		$this->DBH = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $password, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8'", PDO::MYSQL_ATTR_LOCAL_INFILE => true));
		$this->DBH->setAttribute(PDO::ATTR_ERRMODE, $err_mode);
	}
	
	public function TableExists($table) {
		$table = $this->DataSet("show tables like '$table'");
		return $table->Size() == 1;
	}
	
	function TableDescription($table) {
		$Keys = $this->DataSet("SHOW KEYS IN $table");
		$Fields = $this->DataSet("describe $table");
		$this->Clear();
		
		$keys = array();
		
		while ($Keys->Next()) {
			if (!isset($keys[$Keys->Key_name]) || !is_array($keys[$Keys->Key_name]))
				$keys[$Keys->Key_name] = array('Unique'=>!$Keys->Non_unique,'Columns'=>array());
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
					'Null' => $Fields->Null=="NO"?false:true,
					'Key' => $Fields->Key,
					'Extra' => $Fields->Extra
				);
			if (strtoupper($Fields->Key) == 'PRI') $primary_key = $Fields->Field;
		}
		
		return array("Keys" => $keys, "Fields" => $fields, "PrimaryKey" => $primary_key);
	}	
	
	function GetCore($table) {
		return new YapoCoreMysql($this, $table);
	}
		
	function SetAliasedField($field, $alias, $value) {
		$this->Data[":$alias"] = $value;
	}
	
	function SetAliasedData($Data) {
		$this->Data = array();
		foreach ($Data as $d => $fieldinfo) {
			$this->SetAliasedField($fieldinfo->field, $fieldinfo->alias, $fieldinfo->value);
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
		} else if (strtoupper($field_def['MajorType']) == 'TIME') {
			return "'" . date("H:i:s", strtotime($value)) . "'";
		} else if (stristr($field_def['MajorType'], 'time')) {
			return "'" . date("Y-m-d H:i:s", strtotime($value)) . "'";
		} else if (stristr($field_def['MajorType'], 'date')) {
			return "'" . date("Y-m-d", strtotime($value)) . "'";
		} else if (strtoupper($field_def['MajorType']) == 'YEAR') {
			return "'" . date("Y", strtotime($value)) . "'";
		} else if (stristr($field_def['MajorType'], 'text')) {
			// incomplete
		}
	}
	
	function GetStructureDriver($structure, $factory, $values) {
		if (!file_exists(Yapo::$DIR_DRIVER . '/structure.Mysql.' . $structure . '.php')) {
			throw new Exception("Required driver $structure does not exist.");
		}
		include_once(Yapo::$DIR_DRIVER . '/structure.Mysql.' . $structure . '.php');
		$driver_class = 'Mysql' . $structure;
		$params = array_merge((array)$driver_class, $values);
		return call_user_func_array($factory, $params);
	}

}


?>
