<?php

/*****************************
	array(
			signature,
			array( [class, ] method ),
			array(
					array( name, <post,get,request>, bool optional, <type>[, bool assoc] )
				)
		);
		
	<type>:
		numeric
		string
		bool
		json
		mixed

*****************************/

class JSONService {
	
	var $calls = array();
	
	public function __construct() {
	
	}
	
	public function Register($call) {
		$this->calls[$call[0]] = $call;
	}
	
	public function Service() {
		$action = $_GET['Action'];
		$c = null;
		if ('Reflection/Parameters' == $action) {
			if (array_key_exists('Method', $_REQUEST) && array_key_exists($_REQUEST['Method'], $this->calls)) {
				$ref = array(); $i = 0;
				foreach ($this->calls[$_REQUEST['Method']][2] as $k => $validator) {
					$ref[$i++] = array($validator[0], $validator[3]);
				}
				echo json_encode($ref);
			} else {
				echo json_encode(array("Success"=>false, "Detail"=>"Method does not exist or no method was specified: " . $_REQUEST['Method']));
			}
		} else if (array_key_exists($action, $this->calls)) {
			$param = array();
			foreach ($this->calls[$action][2] as $k => $validator) {
				switch (strtoupper($validator[1])) {
					case 'POST': 
						if (!$validator[2] && !array_key_exists($validator[0], $_POST)) echo json_encode(array("Success"=>false, "Detail"=>"Could not find required POST parameter. $validator[0]"));
						$v = array_key_exists($validator[0], $_POST)?$_POST[$validator[0]]:null;
						break;
					case 'GET': 
						if (!$validator[2] && !array_key_exists($validator[0], $_GET)) echo json_encode(array("Success"=>false, "Detail"=>"Could not find required GET parameter. $validator[0]"));
						$v = array_key_exists($validator[0], $_GET)?$_GET[$validator[0]]:null;
						break;
					case 'REQUEST': 
						if (!$validator[2] && !array_key_exists($validator[0], $_REQUEST)) echo json_encode(array("Success"=>false, "Detail"=>"Could not find required parameter. $validator[0]"));
						$v = array_key_exists($validator[0], $_REQUEST)?$_REQUEST[$validator[0]]:null;
						break;
				}
				switch (strtoupper($validator[3])) {
					case 'NUMERIC':
						if(!$validator[2] && !is_numeric($v)) echo json_encode(array("Success"=>false, "Detail"=>"Paramater could not be validated. $validator[0]:numeric")); break;
					case 'STRING':
						if(!$validator[2] && !is_string($v)) echo json_encode(array("Success"=>false, "Detail"=>"Paramater could not be validated. $validator[0]:string")); break;
					case 'JSON':
						$j = count($validator)==5?json_decode($v,$validator[4]!=0?true:false):json_decode($v);
						if($v === false) echo json_encode(array("Success"=>false, "Detail"=>"Paramater could not be validated. $validator[0]:json")); break;
				}
				$param[] = $v;
			}
			if (count($this->calls[$action][1]) == 1) {
				$func = $this->calls[$action][1][0];
				if (!function_exists($func)) {
					echo json_encode(array("Success"=>false, "Detail"=>"Could not find matching function call for signature. $method:".$this->calls[$action]));
				}
				echo json_encode(call_user_func_array($func, $param));
			} else {
				$class = $this->calls[$action][1][0];
				if (class_exists($class)) {
					$c = new $class();
					$method = $this->calls[$action][1][1];
					if (!method_exists($c, $method)) {
						echo json_encode(array("Success"=>false, "Detail"=>"Could not find matching method for signature. $action:$class->$method"));
					}
					echo json_encode(call_user_func_array(array($c, $method), $param));
				} else {
					echo json_encode(array("Success"=>false, "Detail"=>"Could not find matching class for signature. $action:$class"));
				}
			}
		} else {
			echo json_encode(array("Success"=>false, "Detail"=>"Action does not exist. $action"));
		}
	}
}

?>