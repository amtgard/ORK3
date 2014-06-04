<?php

require_once('class.yapo_mysql.php');

/* */

class yapo {
	private $__database;
	private $__fields = array();
	private $__primary_key;
	private $__table;
	private $__one = array();
	private $__many = array();
	private $__relationship = false;
	private $__result_set = null;
	private $__last_error;
	private $__last_sql;

	public function lastSql() {
		return $this->__last_sql;
	}
	
	public function delete() {
		$primaryKey = $this->__primary_key;
		if (is_null($this->$primaryKey)) { // delete a set
			//echo "set deletion not supported yet";
			$search_terms = array();
			// Create WHERE sub-clauses
			if (is_array($clause_terms)) foreach($clause_terms as $field => $value) {
				$first_conjunction = array_slice($value,0,1);
				$first_clause_key = array_keys($first_conjunction);
				$first_conjunction = $first_conjunction[$first_clause_key[0]]['conjunction'];
				$search_terms[$field] = array('clause' => ' ('.substr(implode(" ",array_map(array('yapo','map_search_terms'),array_keys($value),$value)), strlen($first_conjunction) + 1).') ');
				$clause_term_conjunction = $field.'_term_with_conjunction';
				$search_terms[$field]['conjunction'] = $this->$clause_term_conjunction;
				unset($this->$clause_term_conjunction);
			}
			// Bundle all clauses & search terms together
			foreach ($this->__fields as $field => $value) {
				if (isset($clause_terms[$field])) continue;
				if ($this->$field != $this->htmldec($value)) {
					$search_terms[$field] = array('value' => $this->s_prep($this->htmldec($this->$field)), 'term' => '=' );
				} else if (!is_null($this->$field) && is_null($value)) {
					$search_terms[$field] = array('value' => $this->s_prep($this->htmldec($this->$field)), 'term' => '=' );
				}
				$term_field = $field.'_term';
				if (isset($this->$term_field)) {
					$search_terms[$field]['term'] = $this->$term_field;
					unset($this->$term_field);
				}
				
				$field_conjunction = $field.'_conjunction';
				if (isset($this->$field_conjunction)) {
					$search_terms[$field]['conjunction'] = $this->$field_conjunction;
					unset($this->$field_conjunction);
				} else if (isset($search_terms[$field])) {
					$search_terms[$field]['conjunction'] = $conjunction;
				}
			}
			$sql = 'delete from '.$this->__table;
			if (0 < count($search_terms)) {
				$sql .= ' where 1 and '.implode(" ",array_map(array('yapo','map_search_terms'),array_keys($search_terms),$search_terms));
			}
		} else { // delete a particular record
			$sql = 'delete from '.$this->__table.' where `'.$primaryKey."` = '".$this->$primaryKey."'";
		}
		mysql_query($sql);
	}
	
	public function size() {
		if (is_null($this->__result_set)) return 0;
		return $this->__result_set->size();
	}
	
	public function intersection($ifield, $list, $src_table, $src_field) {
		$dirty_fields = $this->get_dirty_fields(false);
		$sql = 'delete from '.$this->__table.' 
				where `'.$this->__primary_key.'`='.$this->{$this->__primary_key}.' and 
					'.$ifield.' not in ('.implode(',',$list).')';
		//$this->__database->query($sql);
					
		echo $sql;
					
		unset($dirty_fields[$ifield]);
		
		$sql = 	'insert into '.$this->__table.' (`'.implode('`,`',array_keys($dirty_fields)).'`,`'.$ifield.'`) 
				select `'.implode('`,`',$dirty_fields).'`,`'.$ifield.'` 
					from '.$src_table.' 
					where `'.$src_field.'` in ('.implode(',',$list).') and 
						`'.$src_field.'` not in 
							select `'.$ifield.'` from '.$this->table.' where `'.$this->__primary_key.'`='.$this->{$this->__primary_key}.' and `'.$ifield.'` in ('.implode(',',$list).')';

		echo $sql;
		//$this->__database->query($sql);
	}
	
	private function htmlenc($string) {
		return htmlspecialchars($string, ENT_QUOTES);
	}
	
	private function htmldec($string) {
		//if (!is_string($string)) return '';
		return htmlspecialchars_decode($string, ENT_QUOTES);
	}
	
	function s_prep($string) {
		return mysql_real_escape_string($string);
	}
	
	public function update() {
		// Set Update...crap
		$dirty_fields = $this->get_dirty_fields(false);
		$values = array();
		foreach ($dirty_fields as $field => $v) {
			$values[$field] = $this->__fields[$field];
		}
		$sql = 'update  '.$this->__table.' 
					set '.implode(', ',array_map(create_function('$field, $value','return "`$field` = \"".mysql_real_escape_string($value)."\"";'), array_keys($dirty_fields), $dirty_fields)).' 
					where '.implode(' and ',array_map(create_function('$field, $value','return "`$field` = \"".mysql_real_escape_string($value)."\"";'), array_keys($dirty_fields), $values));
		echo $sql."\n\n";
	}
	
	public function save() {
		$primaryKey = $this->__primary_key;
		if (is_null($this->$primaryKey)) { // insert new
			$values = array();
			foreach ($this->__fields as $field => $value) {
				array_push($values, $this->s_prep($this->htmldec($this->$field)));
			}
			$sql = 'insert into '.$this->__table.' (`'.implode('`, `',array_keys($this->__fields)).'`) values ("'.implode('", "',$values).'")';
			$this->__last_sql = $sql;
			//echo $sql."\n\n";
			if($this->__database->query($sql)) {
				$this->clear();
				$this->$primaryKey = $this->__database->getInsertID();
				$this->find();
				return true;
			} else {
				return false;
			}
		} else { // update old
			$primaryKeyValue = $this->__fields[$this->__primary_key];
			unset($this->__fields[$this->__primary_key]);
			
			$dirty_fields = $this->get_dirty_fields();
			
			$sql = 'update  '.$this->__table.' set '.implode(', ',array_map(create_function('$field, $value','return "`$field` = \"".mysql_real_escape_string($value)."\"";'), array_keys($dirty_fields), $dirty_fields)).' where '.$this->__primary_key.'="'.$primaryKeyValue.'"';
			$this->__last_sql = $sql;
			//echo $sql."\n\n";
			$this->__fields[$this->__primary_key] = $primaryKeyValue;
			if ($this->__database->query($sql)) {
				return true;
			} else {
				return false;
			}
		}
	}
	
	private function get_dirty_fields($include_primary = true) {
		$dirty_fields = array();
		foreach ($this->__fields as $field => $value) {
			if ($this->$field != $this->htmldec($value)) {
				if ($include_primary) { 
					$dirty_fields[$field] = $this->s_prep($this->htmldec($this->$field));
				} else if ($field != $this->__primary_key) {
					$dirty_fields[$field] = $this->s_prep($this->htmldec($this->$field));
				}
			}
		}
		return $dirty_fields;
	}
	
	private function map_search_terms($field, $value) {
		return $value[conjunction].((isset($value[clause]))?$value[clause]:(" `$field` $value[term] ".("IN"==$value[term]?$value[value]:"\"$value[value]\"")));
	}
	
	public function find($order_by=null, $conjunction='AND', $limit = null, $showsql = false) {
		$search_terms = array();
		$clause_terms = array();
		/*
			Package up all the clauses that must be bundled together in the WHERE statement
		*/
		foreach ($this->__fields as $field => $value) {
			$field_term_with = $field.'_term_with';
			$target_with = $this->$field_term_with.'_term_with';
			$this->$target_with = $this->$field_term_with;
			if (isset($this->$field_term_with)) {
				if ($this->$field != $this->htmldec($value)) {
					$clause_terms[$this->$field_term_with][$field] = array('value' => $this->s_prep($this->htmldec($this->$field)), 'term' => '=' );
				} else if (!is_null($this->$field) && is_null($value)) {
					$clause_terms[$this->$field_term_with][$field] = array('value' => $this->s_prep($this->htmldec($this->$field)), 'term' => '=' );
				}
				$term_field = $field.'_term';
				if (isset($this->$term_field)) {
					$clause_terms[$this->$field_term_with][$field]['term'] = $this->$term_field;
					unset($this->$term_field);
				}
				
				$field_conjunction = $field.'_conjunction';
				if (isset($this->$field_conjunction)) {
					$clause_terms[$this->$field_term_with][$field]['conjunction'] = $this->$field_conjunction;
					unset($this->$field_conjunction);
				} else if (isset($clause_terms[$this->$field_term_with][$field])) {
					$clause_terms[$this->$field_term_with][$field]['conjunction'] = $conjunction;
				}
				
				unset($this->field_term_with);
				$this->$field = $this->__fields[$this->$field];
			}
		}
		// Create WHERE sub-clauses
		foreach($clause_terms as $field => $value) {
			$first_conjunction = array_slice($value,0,1);
			$first_clause_key = array_keys($first_conjunction);
			$first_conjunction = $first_conjunction[$first_clause_key[0]]['conjunction'];
			$search_terms[$field] = array('clause' => ' ('.substr(implode(" ",array_map(array('yapo','map_search_terms'),array_keys($value),$value)), strlen($first_conjunction) + 1).') ');
			$clause_term_conjunction = $field.'_term_with_conjunction';
			$search_terms[$field]['conjunction'] = $this->$clause_term_conjunction;
			unset($this->$clause_term_conjunction);
		}
		// Bundle all clauses & search terms together
		foreach ($this->__fields as $field => $value) {
			if (isset($clause_terms[$field])) continue;
			if ($this->$field != $this->htmldec($value)) {
				$search_terms[$field] = array('value' => $this->s_prep($this->htmldec($this->$field)), 'term' => '=' );
			} else if (!is_null($this->$field) && is_null($value)) {
				$search_terms[$field] = array('value' => $this->s_prep($this->htmldec($this->$field)), 'term' => '=' );
			}
			$term_field = $field.'_term';
			if (isset($this->$term_field)) {
				$search_terms[$field]['term'] = $this->$term_field;
				unset($this->$term_field);
			}
			
			$field_conjunction = $field.'_conjunction';
			if (isset($this->$field_conjunction)) {
				$search_terms[$field]['conjunction'] = $this->$field_conjunction;
				unset($this->$field_conjunction);
			} else if (isset($search_terms[$field])) {
				$search_terms[$field]['conjunction'] = $conjunction;
			}
		}
		$sql = 'select `'.implode('`, `', array_keys($this->__fields)).'` from '.$this->__table;
		if (0 < count($search_terms)) {
			$sql .= ' where '.substr(implode(" ",array_map(array('yapo','map_search_terms'),array_keys($search_terms),$search_terms)),strlen($conjunction));
		}
		if (is_array($order_by)) {
			$sql .= " order by ".implode(', ',$order_by);
		}
		if (!is_null($limit)) {
			$sql .= " limit $limit";
		}
 		if (!($this->__result_set = $this->__database->query($sql))) {
			echo 'ERROR IN SQL:'.$sql."\n\n".mysql_error();
			return false;
		}
		if ($showsql) echo $sql."\n";
		if (!$this->__result_set->isEmpty()) {
			$this->clear();
			$this->next();
			return true;
		} else {
			return false;
		}
	}
	
	public function next() {
		if (!is_null($this->__result_set)) {
			$primary_key = $this->__primary_key;
			if (is_null($this->$primary_key)) { //new set
				$this->transferSetFields();
				return false;
			} else { //increment old set
				$result = $this->__result_set->next();
				$this->transferSetFields();
				return $result;
			}
		} else {
			return false;
		}
	}

	private function transferSetFields() {
		if (!is_null($this->__result_set) && !$this->__result_set->isEmpty()) {
			foreach ($this->__fields as $field => $value) {
				$this->$field =  stripcslashes(str_replace('\r\n', "\n", $this->htmldec($this->__result_set->$field)));
				$this->__fields[$field] = stripcslashes($this->htmldec($this->__result_set->$field));
			}
		}
	}
	
	public function set_relationship($as=null, $key=null, $aggressive=false, $cascade=true) {
		if (is_null($as)) {
			$as = $this->__table;
		}
		if (is_null($key)) {
			$key = $this->__primary_key;
		}
		$this->__relationship = array('as'=>$as, 'key'=>$key, 'aggressive'=>$aggressive, 'cascade'=>$cascade);
	}
	
	public function get_alias() {
		if ($this->__relationship) {
			return $this->__relationship['as'];
		} else {
			return $this->__table;
		}
	}
	
	public function is_aggressive() {
		if ($this->__relationship) {
			return $this->__relationship['aggressive'];
		} else {
			return null;
		}
	}
	
	public function is_cascading() {
		if ($this->__relationship) {
			return $this->__relationship['cascade'];
		} else {
			return null;
		}
	}

	public function get_key() {
		if ($this->__relationship) {
			return $this->__relationship['key'];
		} else {
			return null;
		}
	}
	
	public function relationship_is_set() {
		if ($this->__relationship) {
			return true;
		} else {
			return false;
		}
	}
	
	public function add_one($y, $local_key=null) {
		$this->add_table($this->__one, $y, $local_key);
	}
	
	public function add_many($y, $local_key=null) {
		$this->add_table($this->__many, $y, $local_key);
	}

	private function add_table(& $arr, $y, $local_key) {
		if (is_null($local_key)) {
			$local_key = $this->__primary_key;
		}
		if (!$y->relationship_is_set()) {
			$y->set_relationship();
		}
		$arr[$y->get_alias()]=$local_key;
		$table = $y->get_alias();
		$this->$table = $y;
	}
	
	public function clear() {
		$primary_key = $this->__primary_key;
		$this->$primary_key = null;
		foreach ($this->__fields as $field => $value) {
			$this->$field = null;
			$this->__fields[$field] = null;
		}
	}
	
	public function get_fields() {
		$field_list = array();
		foreach ($this->__one as $table => $local_key) {
			if ($this->$table->is_aggressive()) {
				$field_list = array_merge($field_list, $this->$table->get_fields());
			}
		}
		foreach ($this->__many as $table => $local_key) {
			if ($this->$table->is_aggressive()) {
				$field_list = array_merge($field_list, $this->$table->get_fields());
			}
		}
		return array_merge($field_list, array_map(create_function('$i','return '.$this->get_alias().'.".".$i;'), array_keys($this->__fields)));
	}
	
	public function get_local_fields() {
		return array_keys($this->__fields);
	}
	
	public function get_tables() {
		$table_list = array();
		foreach ($this->__one as $table => $local_key) {
			if ($this->$table->is_aggressive()) {
				$table_list[$this->$table->get_alias()] = $local_key;
			}
		}
		foreach ($this->__many as $table => $local_key) {
			if ($this->$table->is_aggressive()) {
				$table_list[$this->$table->get_alias()] = $local_key;
			}
		}
		return $table_list;
	}
	
	private function get_find_where() {

	}
	
	public function __construct (& $m, $table) {
		$this->__database = & $m;
		$this->__table = $table;
		$table_data = $this->__database->describe_table($table);
		$this->__primary_key = $table_data['primary'];
		foreach ($table_data['fields'] as $k => $field) {
			$this->$field = null;
			$this->__fields[$field] = false;
		}
	}
	
	public function name() {
		return $this->__table;
	}
	
	public function get_html($lvl=1) {
		$html;
		if ($this->__relationship) {
			$html = "<h$lvl>$this->__table as ".$this->get_alias()."</h$lvl>";
		} else {
			$html = "<h$lvl>$this->__table</h$lvl>";
		}
		$html .= "<ul>";
		foreach ($this->__fields as $field=>$dirty) {
			if ($dirty) {
				$html .= "<li>$field = $dirty</li>";
			} else {
				$html .= "<li>$field</li>";
			}
		}
		$lvl++;
		$html .= "</ul><h$lvl>Has One</h$lvl><ul>";
		foreach ($this->__one as $alias => $local_key) {
			$html .= "<li><b>On $local_key</b>".$this->$alias->get_html()."</li>";
		}
		$html .= "</ul><h$lvl>Has Many</h$lvl><ul>";
		foreach ($this->__many as $alias => $local_key) {
			$html .= "<li><b>On $local_key</b>".$this->$alias->get_html()."</li>";
		}
		$html .= "</ul>";
		return $html;
	}
	
}

?>
