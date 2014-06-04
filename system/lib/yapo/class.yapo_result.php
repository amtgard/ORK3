<?php

class yapo_result {
	private $__fields;
	private $__set;
	private $__error;
	private $__size;
	
	public function __construct($result_set) {
		if ($result_set) {
			if (!is_bool($result_set)) {
				$this->__set = $result_set;
				$this->__size = mysql_num_rows($this->__set);
				if (0 < mysql_num_rows($this->__set)) {
					$row = mysql_fetch_assoc($this->__set);
					$this->__fields = array_keys($row);
					$this->seek(0);
				}
			}
			$this->__error = false;
		} else {
			$this->__error = true;
		}
	}
	
	public function fields() {
		return $this->__fields;
	}
	
	public function isEmpty() {
		return (false==$this->size()||0==$this->size())?true:false;
	}

	public function size() {
		return $this->__size;
	}
	
	public function next() {
		if ($this->__set) {
			if ($row = mysql_fetch_assoc($this->__set)) {
				foreach ($this->__fields as $k => $field) {
					$this->$field = $row[$field];
				}
				return true;
			} else {
				return false;
			}
		} else {
			return false;
		}
	}
	
	public function seek($index) {
		if ($this->__set) {
			if (mysql_data_seek($this->__set, $index)) {
				$this->next();
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	public function __destruct() {
		if ($this->__set) {
			mysql_free_result($this->__set);
		}
	}
}

?>
