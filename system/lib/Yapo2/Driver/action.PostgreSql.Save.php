<?php

class YapoPostgreSqlSave extends YapoSave {
		
	protected function typeMassage($field, $value) {
		if ($this->Core->__definition['Fields'][$field]['MajorType'] == 'USER-DEFINED') {
			if (!ctype_alnum($value))
				return (string)$value;
			return $value;
		} else {
			switch ($this->Core->__definition['Fields'][$field]['MinorType']) {
				case 'int4':
					if (!is_numeric($value) && !ctype_digit($value)) {
						return 0;
					}
					return $value;
				case 'time':
					if (!strtotime($value))
						return '0000-00-00 00:00:00';
					return $value;
				default:
					return $value;
			}
		}
	}
	
}

?>