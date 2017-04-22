<?php

include_once(Yapo::$DIR_ACTIONS . '/class.YapoAction.php');

class YapoWhere extends YapoAction {
	function __construct(& $Core) {
		parent::__construct($Core);
	}
		
	function GenerateSql($params) {
		parent::GenerateSql($params);
		if (is_array($params))
			extract($params);
		
		if (!isset($conjunction)) $conjunction = 'and';
		
		$where_clauses = array();
		$where_fields = array();
		foreach ($this->Core->__field_actions as $field => $action) {
		
			if (isset($find_all) && $find_all)
				;
			else
				if ($this->Core->PrimaryKeyIsSet()) {
					//Is this important, or just an optimization that got out of hand?
					//It's important ... Noah 10/4/2013
					if ($field != $this->Core->GetPrimaryKeyField()) {
						continue;
					}
					
				}

			foreach ($action as $comparator => $value) {
				switch ($comparator) {
					case Yapo::NOT_EQ:
					case Yapo::EQUALS:
						$where_clauses[] = $this->Core->GetQualifiedName($field) . ($comparator==Yapo::EQUALS?'=':'!=') . " :where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_"); 
						$where_fields["where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_")] =//$value;
							new YapoFieldAlias(
									$this->Core->GetQualifiedName($field), 
									"where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_"), 
									$value);
						break;
					case Yapo::NOT_LIKE:
					case Yapo::LIKE: 
						$where_clauses[] = $this->Core->GetQualifiedName($field) . ($comparator==Yapo::NOT_LIKE?' not ':'') . " like :where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_"); 
						$where_fields["where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_")] = //$value;
							new YapoFieldAlias(
								$this->Core->GetQualifiedName($field), 
								"where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_"), 
								$value);
						break;
					case Yapo::GREATER: 
						$where_clauses[] = $this->Core->GetQualifiedName($field) . " > :where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_"); 
						$where_fields["where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_")] =//$value;
							new YapoFieldAlias($this->Core->GetQualifiedName($field), "where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_"), $value);
						break;
					case Yapo::LESS: 
						$where_clauses[] = $this->Core->GetQualifiedName($field) . " < :where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_"); 
						$where_fields["where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_")] =//$value;
							new YapoFieldAlias($this->Core->GetQualifiedName($field), "where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_"), $value);
						break;
					case Yapo::GREATER_EQ: 
						$where_clauses[] = $this->Core->GetQualifiedName($field) . " >= :where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_"); 
						$where_fields["where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_")] =//$value;
							new YapoFieldAlias($this->Core->GetQualifiedName($field), "where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_"), $value);
						break;
					case Yapo::LESS_EQ: 
						$where_clauses[] = $this->Core->GetQualifiedName($field) . " <= :where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_"); 
						$where_fields["where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_")] =//$value;
							new YapoFieldAlias($this->Core->GetQualifiedName($field), "where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_"), $value);
						break;
					case Yapo::NOT_IN:
					case Yapo::IN: 
						$Core = $this->Core;
						if (!is_array($value) || (is_array($value) && count($value) > 7)) {
							$where_clauses[] = $this->Core->GetQualifiedName($field) . ($comparator==Yapo::NOT_IN?' not ':'') . " in (" . 
								(is_array($value)?implode(',',$value):$value)
								. ")"; 
						} else if (is_array($value) && count($value) > 0) {
							$where_clauses[] = 
								$this->Core->GetQualifiedName($field) . ($comparator==Yapo::NOT_IN?' not ':'') . " in (" . 
									implode(', ', 
										array_map(
											function ($n) use ($field, $Core, $comparator) {
												return ":where_{$comparator}_" . $Core->GetQualifiedName($field, "_") . $n;
											}, 
											range(0,count($value)-1,1))) . 
									")"; 
							$n = 0;
							foreach ($value as $index => $v) {
								$where_fields["where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_") . $n] = //$value;
									new YapoFieldAlias($this->Core->GetQualifiedName($field), "where_{$comparator}_" . $this->Core->GetQualifiedName($field, "_") . $n, $v);
								$n++;
							}	
						} else if (is_array($value) && count($value) == 0) {
							$where_clauses[] = " 0 = 1 ";
						}
						break;
				}
			}
		}
		
		if (count($where_clauses) > 0)
			return array('where ' . implode(" $conjunction ", $where_clauses), $where_fields);
		return array("", array());
	}
}

?>