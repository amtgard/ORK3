<?php

include_once('class.YapoResultSet.php');

class YapoDb {

	private $DBH;
	
	private $Data;
	
	protected $Debug = false;
	
	var $__ERRORS = array();
	
	var $__SetupParameters = array();
	
	function __construct($host, $dbname, $user, $password) {
		$this->__SetupParameters = array("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $password);
		$this->DBH = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $password);
		$this->DBH->exec('set names utf8');
		$this->DBH->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
	}
	
	function SetDebug($debug) {
		$this->Debug = $debug;
	}
	
	function TableDescription($table) {
		$Keys = $this->DataSet("SHOW KEYS IN $table");
		$Fields = $this->DataSet("describe $table");
		$this->Clear();
		
		$keys = array();
		
		while ($Keys->Next()) {
			if (!is_array($keys[$Keys->Key_name]))
				$keys[$Keys->Key_name] = array('Unique'=>!$Keys->Non_unique,'Columns'=>array());
			$keys[$Keys->Key_name]['Columns'][] = $Keys->Column_name;
		}
		
		
		$fields = array();
		$primary_key = false;
		while ($Fields->Next()) {
			preg_match("/(.+)\((.+)\)/", $Fields->Type, $matches);
			$fields[$Fields->Field] = array(
					'MajorType' => $matches[1],
					'MinorType' => $matches[2],
					'Type' => $Fields->Type,
					'Null' => $Fields->Null=="NO"?false:true,
					'Key' => $Fields->Key,
					'Extra' => $Fields->Extra
				);
			if (strtoupper($Fields->Key) == 'PRI') $primary_key = $Fields->Field;
		}
		
		return array("Keys" => $keys, "Fields" => $fields, "PrimaryKey" => $primary_key);
	}	
	
	function GetLastInsertId() {
		return $this->DBH->lastInsertId();
	}
	
	function GetCore($table) {
		return new YapoCore($this, $table);
	}
	
	function Query($sql, $DataSet = null) {
		if (is_array($DataSet))
			$this->SetData($DataSet);
		return $this->DataSet($sql);
	}
	
	function Execute($sql) {
		if ($this->Debug) {
			echo $sql;
			print_r($this->Data);
		}
		$cnt = 3;
		do {
			$Query = $this->DBH->prepare($sql);
			if (count($this->Data) > 0)
				$Query->execute($this->Data);
			else
				$Query->execute();
			$failed = $this->handle_errors($cnt--, $Query);
		} while (!$failed);
	}
	
	function DataSet($sql) {
		if ($this->Debug) {
			echo $sql;
			print_r($this->Data);
		}
		$cnt = 3;
		do {
			$Query = $this->DBH->prepare($sql);
			if (count($this->Data) > 0)
				$Query->execute($this->Data);
			else
				$Query->execute();
			$failed = $this->handle_errors($cnt--, $Query);
		} while (!$failed);
		return new YapoResultSet($Query, $sql);
	}
	
	function handle_errors($cnt, $Query) {
		if ($cnt==0) return true;
		switch ($Query->errorCode()) {
			case '00000': return true;
			case 'HY200':
					$this->DBH = new PDO($this->__SetupParameters[0], $this->__SetupParameters[1], $this->__SetupParameters[2]);
					$this->DBH->exec('set names utf8');
					$this->DBH->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
				return false;
			default: return true;
		}
	}

	function Clear() {
		$this->Data = array();
	}
	
	function __set($field, $value) {
		$this->Data[":$field"] = $value;
	}
	
	function SetData($Data) {
		$this->Data = $Data;
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

}


?>