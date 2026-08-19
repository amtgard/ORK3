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
			$method_key = isset($_REQUEST['Method']) ? $_REQUEST['Method'] : '';
			$ref_call = null;
			foreach ($this->calls as $ck => $cv) {
				if ($ck === $method_key) { $ref_call = $cv; break; }
			}
			if ($ref_call !== null) {
				$ref = array(); $i = 0;
				foreach ($ref_call[2] as $k => $validator) {
					$ref[$i++] = array($validator[0], $validator[3]);
				}
				echo json_encode($ref);
			} else {
				echo json_encode(array("Success"=>false, "Detail"=>"Method does not exist or no method was specified."));
			}
		} else if (array_key_exists($action, $this->calls)) {
			$call_def = null;
			foreach ($this->calls as $call_key => $call_val) {
				if ($call_key === $action) { $call_def = $call_val; break; }
			}
			$param = array();
			foreach ($call_def[2] as $k => $validator) {
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
			if (count($call_def[1]) == 1) {
				$func = $call_def[1][0];
				if (!is_callable($func)) {
					echo json_encode(array("Success"=>false, "Detail"=>"Could not find matching function call for signature."));
					return;
				}
				echo json_encode(call_user_func_array($func, $param));
			} else {
				$class = $call_def[1][0];
				if (class_exists($class)) {
					$c = new $class();
					$method = $call_def[1][1];
					if (!is_callable(array($c, $method))) {
						echo json_encode(array("Success"=>false, "Detail"=>"Could not find matching method for signature."));
						return;
					}
					echo json_encode(call_user_func_array(array($c, $method), $param));
				} else {
					echo json_encode(array("Success"=>false, "Detail"=>"Could not find matching class for signature."));
				}
			}
		} else {
			echo json_encode(array("Success"=>false, "Detail"=>"Action does not exist."));
		}
	}
}

?>