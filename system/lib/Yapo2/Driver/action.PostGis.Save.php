<?php

class YapoPostGisSave extends YapoPostgreSqlSave {
	
	protected function insert() {
		$sql = "insert into {$this->Core->__table} ";
		$insert_fields = array();
		foreach ($this->Core->__definition as $field => $definition) {
			if (isset($definition['Null']) && strtoupper($definition['Null']) == 'NO') {
				$insert_fields[$this->Core->GetFieldName($field, true)] = "";
			}
		}
		foreach ($this->Core->__field_actions as $field => $comparator) {
			if (isset($comparator[Yapo::SET])) {
				$insert_fields[$this->Core->GetFieldName($field, true)] = $comparator[Yapo::SET];
			}
		}
		$sql .= "(" . implode(", ", array_keys($insert_fields)) . ") values (";
		$fields = array();

		foreach ($insert_fields as $field => $value) {
//			$fields["insert_" . str_replace('.','_',$field)] = $value;
			$field = trim($field, '"');
			if (is_object($value) && get_class($value) == 'PostGisGeometry') {
				switch ($this->Core->__DB->__field_geometry[$field]) {
					case 'POINT':
							$sql .= "ST_SetSRID(ST_MakePoint(:long_insert_" . str_replace('.','_',$field) . ", :lat_insert_" . str_replace('.','_',$field) . "), :srid_insert_" . str_replace('.','_',$field) . "),";
							$fields["long_insert_" . str_replace('.','_',$field)] = new YapoFieldAlias($field, "long_insert_" . str_replace('.','_',$field), $value->x);
							$fields["lat_insert_" . str_replace('.','_',$field)] = new YapoFieldAlias($field, "lat_insert_" . str_replace('.','_',$field), $value->y);
							$fields["srid_insert_" . str_replace('.','_',$field)] = new YapoFieldAlias($field, "srid_insert_" . str_replace('.','_',$field), $this->Core->__DB->__field_srid[$field]);
						break;
					case 'LINESTRING':
							$sql .= "ST_GeomFromText(:linestring_insert_" . str_replace('.','_',$field) . ", :srid_insert_" . str_replace('.','_',$field) . "),";
							$fields["srid_insert_" . str_replace('.','_',$field)] = new YapoFieldAlias($field, "srid_insert_" . str_replace('.','_',$field), $this->Core->__DB->__field_srid[$field]);
							$linestring = '';
							if (is_string($value->elements))
								$linestring = $value->elements;
							else if (is_array($value->elements)) {
								$linestring = implode(',', array_map(function($e) { return "$e[0] $e[1]"; }, $value->elements));
							} else {
								throw new Exception("Value element is not in a supported format for linestring geometry.");
							}
							$fields["linestring_insert_" . str_replace('.','_',$field)] = new YapoFieldAlias($field, "linestring_insert_" . str_replace('.','_',$field), 'LINESTRING(' . $linestring . ')');
						break;
					case 'POLYGON':
							$sql .= "ST_GeomFromText(:polygon_insert_" . str_replace('.','_',$field) . ", :srid_insert_" . str_replace('.','_',$field) . "),";
							$fields["srid_insert_" . str_replace('.','_',$field)] = new YapoFieldAlias($field, "srid_insert_" . str_replace('.','_',$field), $this->Core->__DB->__field_srid[$field]);
							$polygon = '';
							if (count($value->elements) == 1 && is_string($value->elements[0]))
								$polygon = $value->elements[0];
							else if (is_array($value->elements[0]) && count($value->elements[0]) > 0 && is_array($value->elements[0][0])) {
								$polygon = implode(',', array_map(function($e) { return "$e[0] $e[1]"; }, $value->elements[0])) . ",{$value->elements[0][0][0]} {$value->elements[0][0][1]}";
							} else {
								throw new Exception("Value element is not in a supported format for polygon geometry.");
							}
							$fields["polygon_insert_" . str_replace('.','_',$field)] = new YapoFieldAlias($field, "polygon_insert_" . str_replace('.','_',$field), 'POLYGON((' . $polygon . '))');
						break;
					default:
						throw new Exception("Unsupported save geometry: " . $this->Core->__DB->__field_geometry[$field]);
				}
			} else {
				$value = $this->typeMassage($field, $value);
				$fields["insert_" . str_replace('.','_',$field)] = new YapoFieldAlias($field, "insert_" . str_replace('.','_',$field), $value);
				$sql .= ":insert_" . str_replace('.','_',$field) . ",";
			}
		}
		$sql = rtrim($sql, ',') . ")";
		return array($sql, $fields);
	}
	
	protected function update_base() {
		$sql = "update {$this->Core->__table} set ";
		$fields = array();
		$update_fields = array();
		foreach ($this->Core->__field_actions as $field => $comparator) {
			if (isset($comparator[Yapo::SET])) {
				$value = $comparator[Yapo::SET];
				if (is_object($value) && get_class($value) == 'PostGisGeometry') {
					switch ($this->Core->__DB->__field_geometry[$field]) {
						case 'POINT':
								$sql .= $this->Core->GetFieldName($field) . " = ST_SetSRID(ST_MakePoint(:long_update_" . str_replace('.','_',$field) . ", :lat_update_" . str_replace('.','_',$field) . "), :srid_update_" . str_replace('.','_',$field) . "),";
								$update_fields["long_update_" . str_replace('.','_',$field)] = new YapoFieldAlias($field, "long_update_" . str_replace('.','_',$field), $value->x);
								$update_fields["lat_update_" . str_replace('.','_',$field)] = new YapoFieldAlias($field, "lat_update_" . str_replace('.','_',$field), $value->y);
								$update_fields["srid_update_" . str_replace('.','_',$field)] = new YapoFieldAlias($field, "srid_update_" . str_replace('.','_',$field), $this->Core->__DB->__field_srid[$field]);
							break;
						case 'LINESTRING':
								$sql .= $this->Core->GetFieldName($field) . " = ST_GeomFromText(:linestring_update_" . str_replace('.','_',$field) . ", :srid_update_" . str_replace('.','_',$field) . "),";
								$update_fields["srid_update_" . str_replace('.','_',$field)] = new YapoFieldAlias($field, "srid_update_" . str_replace('.','_',$field), $this->Core->__DB->__field_srid[$field]);
								$linestring = '';
								if (is_string($value->elements))
									$linestring = $value->elements;
								else if (is_array($value->elements)) {
									$linestring = implode(',', array_map(function($e) { return "$e[0] $e[1]"; }, $value->elements));
								} else {
									throw new Exception("Value element is not in a supported format for linestring geometry.");
								}
								$update_fields["linestring_update_" . str_replace('.','_',$field)] = new YapoFieldAlias($field, "linestring_update_" . str_replace('.','_',$field), 'LINESTRING(' . $linestring . ')');
							break;
						case 'POLYGON':
								$sql .= $this->Core->GetFieldName($field) . " = ST_GeomFromText(:polygon_update_" . str_replace('.','_',$field) . ", :srid_update_" . str_replace('.','_',$field) . "),";
								$update_fields["srid_update_" . str_replace('.','_',$field)] = new YapoFieldAlias($field, "srid_update_" . str_replace('.','_',$field), $this->Core->__DB->__field_srid[$field]);
								$polygon = '';
								if (count($value->elements) == 1 && is_string($value->elements[0]))
									$polygon = $value->elements[0];
								else if (is_array($value->elements[0]) && count($value->elements[0]) > 0 && is_array($value->elements[0][0])) {
									$polygon = implode(',', array_map(function($e) { return "$e[0] $e[1]"; }, $value->elements[0])) . ",{$value->elements[0][0][0]} {$value->elements[0][0][1]}";
								} else {
									throw new Exception("Value element is not in a supported format for polygon geometry.");
								}
								$update_fields["polygon_update_" . str_replace('.','_',$field)] = new YapoFieldAlias($field, "polygon_update_" . str_replace('.','_',$field), 'POLYGON((' . $polygon . '))');
							break;
						default:
							throw new Exception("Unsupported save geometry: " . $this->Core->__DB->__field_geometry[$field]);
					}
				} else {
					$sql .= $this->Core->GetFieldName($field) . " = :update_" . $this->Core->GetQualifiedName($field, '_') . ","; 
					$update_fields["update_" . $this->Core->GetQualifiedName($field, '_')] = 
						new YapoFieldAlias($field, "update_" . $this->Core->GetQualifiedName($field, '_'), $this->typeMassage($field, $comparator[Yapo::SET]));
				}
			}
		}
		$sql = rtrim($sql, ',');
		return array($sql, $update_fields);
	}
}

?>