<?php

require_once ('class.yapo_result.php');

class yapo_mysql {
	private $resource_link;
	private $database;
	public $error;
	public $lastSql;

	public function query($sql) {
		$this->lastSql = $sql;
		$this->set_active();
		$result = mysql_query($sql, $this->resource_link);
		if ($result) {
			return new yapo_result($result);
		} else {
			return false;
		}
	}

	public function getInsertID() {
		return mysql_insert_id($this->resource_link);
	}
	
	public function describe_table($table) {
		$sql = "show columns from $table";
		$result = $this->query($sql);
		if ($result !== false) {
			$fields = array();
			$primary;
			do {
				array_push($fields, $result->Field);
				if ('PRI' == $result->Key) {
					$primary = $result->Field;
				}
			} while ($result->next());
			return array('primary'=>$primary, 'fields'=>$fields);
		} else {
			echo "Table $table could not be described: " . mysql_error();
			return false;
		}
	}
	
	private function set_active() {
		if ($this->resource_link) {
			mysql_select_db($this->database, $this->resource_link);
		}
	}	

	public function __construct($server, $database, $user, $password) {
		if ($this->resource_link = mysql_connect($server, $user, $password)) {
			$this->database = $database;
			$this->set_active();
			$this->error = false;
		} else {
			$this->error = "Could not connect to data base: $server, $database, $user, $password";
		}
	}

	public function __destruct() {
		@mysql_close($this->resource_link);
	}
}

?>
