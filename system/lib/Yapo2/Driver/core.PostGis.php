<?php

include_once(Yapo::$DIR_DRIVER . '/core.PostgreSql.php');
include_once(Yapo::$DIR_DRIVER . '/action.PostGis.Save.php');

class YapoCorePostGis extends YapoCorePostgreSql {

	public function __construct(& $database, $table) {
		parent::__construct($database, $table);
	}
	
	public function GetSelectFields() {
		$fields = array();
		foreach ($this->__definition['Fields'] as $field_name => $def) {
			if (is_array($this->__select_only_fields) && count($this->__select_only_fields) > 0)
				if (!in_array($field_name, $this->__select_only_fields))
					continue;
			if (isset($this->__DB->__field_geometry[$field_name])) {
				switch ($this->__DB->__field_geometry[$field_name]) {
					case 'POINT':
					case 'LINESTRING':
					case 'POLYGON':
							$fields[] = "ST_AsGeoJSON(" . $this->GetQualifiedName($field_name, ".", true) . ") as \"$field_name\"";
						break;
					default:
						throw new Exception("Unsupported save geometry: " . $this->__DB->__field_geometry[$field_name]);
				}
			} else {
				$fields[] = $this->GetQualifiedName($field_name, ".", true);
			}
		}
		return array_merge($this->__other_fields, $fields);
	}
	
	public function init() {
		parent::init();
		$this->__Save = new YapoPostGisSave($this, $this->__Where);
	}
		
	public function GetFieldName($field_name, $proper_case = true) {
		if ($proper_case)
			return '"' . $this->GetFieldSelectAlias($field_name) . '"';
		else
			return $this->GetFieldSelectAlias($field_name);
	}
	
	public function GetQualifiedName($field_name, $delimiter = ".", $proper_case = false) {
		if ($proper_case)
			return "{$this->__table}$delimiter\"" . $this->GetFieldSelectAlias($field_name) . '"';
		else
			return "{$this->__table}$delimiter" . $this->GetFieldSelectAlias($field_name);
	}
	
	function __set($field, $value) {
		if (property_exists($this, $field))
			throw new Exception("You may not access protected members of Yapo Core: " . __FILE__ . ":" . __LINE__ . ": $field <- $value" . "\n\n" . print_r(debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT), true));
		if (is_object($value) && !get_class($value) == 'PostGisGeometry') die("Only PostGisGeometry is supported here.");
		$this->__DB->$field = $value;
		$this->__field_values[$field] = $value;
	}

}

?>