<?php

class JsonServer {
    private $valid_class;
    private $valid_method;
    private $definitions;
    private $map;
    private $method;
    private $callback;
    
    public static $PRETTY;
    public $TRACE;
    public $MAP;
    public $METHOD;
    
    const TRACE_ON = 'trace_on';
    const TRACE_OFF = 'trace_off';
    const TRACE = 'trace';
    
    const PRETTY_PRINT = JSON_PRETTY_PRINT;
    const DEFAULT_PRINT = 0;
    
    const MAP_ANY = 0;
    const MAP_REQUIRE = 0;
    
    const METHOD_ANY = 0;
    const METHOD_REQUIRE = 1;
    const METHOD_POST = 'POST';
    const METHOD_PUT = 'PUT';
    const METHOD_GET = 'GET';
    const METHOD_DELETE = 'DELETE';

    const NUM = 'number';
    const BOOL = 'boolean';
    const ANY = 'var';
    const STR = 'string';
    const ARR = 'array';
    const OBJ = 'object';
    const DATE = 'date';
    
    // ERRORS
    const BAD_ARGUMENTS = 0;
    const RESTRICTED_CLASS = 1;
    const RESTRICTED_METHOD = 2;
    const NO_CLASS = 3;
    const NO_METHOD = 4;
    const BAD_CALL = 5;
    const INVALID_HTTP_REQUEST_METHOD = 6;

    /***************************************************************************
     *  
     * Valid classes & Valid methods are arrays of ground-truth classes and methods
     * that are legal calls on this instance.
     * 
     **************************************************************************/
     
    function __construct($valid_classes = null, $valid_methods = null) {
        $this->valid_class = is_array($valid_classes)?$valid_classes:array_keys(get_object_vars(Ork3::$Lib));
        $this->valid_method = is_array($valid_methods)?$valid_methods:array();
        $this->map = array();
        $this->method = array();
        $this->definitions = array();
        $this->callback = array();
        
        $this->TRACE = JsonServer::TRACE;
        JsonServer::$PRETTY = JsonServer::DEFAULT_PRINT;
        $this->MAP = JsonServer::MAP_ANY;
        $this->METHOD = JsonServer::METHOD_ANY;
    }
    
    /***************************************************************************
     *  
     * Echos a reasonable JSON encoding header to HTTP out
     * 
     **************************************************************************/
     
    public function JsonHeader() {
        header('Content-Type: application/json; charset=utf-8');
    }
    
    /***************************************************************************
     *  
     * Fetches the current call definition for a class/method route
     * 
     **************************************************************************/
     
    public function GetCallDefinition($class, $method) {
        return $this->get_call_definition($class, $method);
    }
    
    /***************************************************************************
     *  
     * Sets the current class/method callback or definition, two uses:
     * 1. $Json->Class('Method', $definition) sets the $definition to call Class/Method
     * 2. $Json->Class('Method', callable $callback) sets the given callback to call Class/Method
     * 
     **************************************************************************/
     
    public function __call($class, $arguments) {
        if (count($arguments) == 2) {
            if (is_callable($arguments[1])) {
                $this->callback["$class/$arguments[0]"] = $arguments[1];
                $definition = $this->get_default_definition($arguments[1]);
                $this->$class($arguments[0], $definition);
            } else if(trimlen($arguments[0]) > 0) {
                if (!isset($this->definitions[$class])) $this->definitions[$class] = array();
                $this->definitions[$class][$arguments[0]] = $arguments[1];
            }
        }
    }
    
    /***************************************************************************
     *  
     * Maps the ground truth $class/$method call to the given route
     * 
     **************************************************************************/
     
    public function MapRoute($class, $method, $route) {
        $this->map[$route] = "$class/$method";
    }
    
    /***************************************************************************
     *  
     * Defines the given http request method for the giveen $class/$method ground
     * truth call
     * 
     **************************************************************************/
     
    public function Method($class, $method, $http_method) {
        $this->method["$class/$method"] = $http_method;
    }

    /***************************************************************************
     *  
     * Instantiates and runs the server; outputs the result
     * 
     **************************************************************************/
     
    public function RunServer() {
		Ork3::$Lib->Request = new Request();
		if (isset(Ork3::$Lib->Request->call)) {
			$call_parts = explode('/', trim($this->_map(Ork3::$Lib->Request->call)));
			if (count($call_parts) == 2) {
				$this->call_endpoint($call_parts[0], $call_parts[1]);
				return;
			} else {
				echo JsonServer::Encode($this->error(JsonServer::BAD_CALL, "Call parameter must be in the form class/method or class $call_parts[0] does not exist.; "));
				return;
			}
		} else if (isset(Ork3::$Lib->Request->endpoints)) {
			echo JsonServer::Encode($this->get_classes());
			return;
	    } else if (isset(Ork3::$Lib->Request->list)) {
			if ($this->valid_class(Ork3::$Lib->Request->list)) {
				$methods = $this->get_methods(Ork3::$Lib->Request->list);
				echo JsonServer::Encode($methods);
				return;
			} else {
				echo JsonServer::Encode($this->error(JsonServer::NO_CLASS, "Class `{Ork3::$Lib->Request->list}` does not exist; "));
				return;
			}
		} else if (isset(Ork3::$Lib->Request->describe)) {
			$call_parts = explode('/', trim($this->_map(Ork3::$Lib->Request->describe)));
			if (count($call_parts) == 2 && class_exists($call_parts[0])) {
				$class = $call_parts[0];
				$method = $call_parts[1];
				$method_c = substr($method, -1) == '0'?substr($method, 0, -1):$method;
				$c = new $class();
				if (!$this->validate_method($class, $method_c)) {
					echo JsonServer::Encode($this->error(JsonServer::INVALID_HTTP_REQUEST_METHOD, "Method $method requires a different HTTP Request Method (1); "));
					return;
				}
				if (method_exists($c, $method_c)) {
					$definition = $this->get_call_definition($class, $method);
					echo JsonServer::Encode($definition);
					return;
				} else {
					echo JsonServer::Encode($this->error(JsonServer::NO_CLASS, "Class $class or method $method does not exist; "));
					return;
				}
			}
		}
      echo JsonServer::Encode($this->general_error(__LINE__));
    }
    
    /***************************************************************************
     * 
     * Static calls below
     * 
     **************************************************************************/
    

    public static function Decode($string, $associative_array = false) {
        $json = json_decode($string, $associative_array);
        return is_null($json)?false:$json;
    }
    
    public static function Encode($data) {
        return json_encode($data, JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE | JsonServer::$PRETTY | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    
    public static function Result($result, $status = true, $code = Errors::SUCCESS, $message ) {
        $r = array('Result' => $result, 'Status' => $status, 'Code' => $status?Errors::SUCCESS:$code );
        if (!$status && !is_null($message)) $r['ErrorMessage'] = $message;
        return $r;
    }
    
    /***************************************************************************
     * 
     * Private calls below
     * 
     **************************************************************************/
    
	private function call_endpoint($class, $method) {
		$c = new $class();
		$method_c = substr($method, -1) == '0'?substr($method, 0, -1):$method;
		if (!$this->validate_method($class, $method_c)) {
			echo JsonServer::Encode($this->error(JsonServer::INVALID_HTTP_REQUEST_METHOD, "Method $method requires a different HTTP Request Method (2); "));
			return;
		}
		if (method_exists($c, $method_c)) {
			ob_start();
			$output = $this->run_call($class, $method_c, $this->wrangle_parameters($this->get_call_definition($class, $method), substr($method, -1) == '0'));
			$trace = ob_get_contents();
			ob_end_clean();
			switch ($this->TRACE) {
				case JsonServer::TRACE_OFF:
						unset($output['Trace']);
					break;
				case JsonServer::TRACE_ON:
						$output['Trace'] = $trace; 
					break;
				default:
						if (!$output['Status']) $output['Trace'] = $trace; else unset($output['Trace']); 
					break;
			}
			echo JsonServer::Encode($output);
			return;
		} else {
			echo JsonServer::Encode($this->error(JsonServer::NO_CLASS, "Class $class or method $method does not exist; "));
			return;
		}
	}
	
	private function translate_static_analysis($class_name, $function) {
		$tokens = token_get_all(file_get_contents(DIR_ORK3 . "class.$class_name.php"));
		$bracket_count = 0;
		$state = 'function_search';
		$request_vals = array();
		foreach ($tokens as $tid => $ftoken) {
			if (is_array($ftoken) && $ftoken[1] == $function) {
				$state = 'first_bracket_hunt';
				for ($i = $tid + 1; $i < count($tokens); $i++) {
					$token = is_array($tokens[$i])?$tokens[$i][1]:$tokens[$i];
					switch ($token) {
						case '{': 
								$bracket_count++;
								$state = 'request_hunt';
							break;
						case '}':
								$bracket_count--;
							break;
						case '$request':
								if ($bracket_count > 0)
									$state = 'request_open_bracket_hunt';
							break;
						default:
								switch ($state) {
									case 'request_open_bracket_hunt':
											if ($token == '[') {
												$state = 'request_value_hunt';
											} else if (!ctype_space($token)) {
												$state = 'request_hunt';
											}
										break;
									case 'request_value_hunt':
											if (ctype_alnum(trim($token, " \t\n\r\0\x0B'\""))) {
												$request_vals[] = trim($token, " \t\n\r\0\x0B'\"");
												$state = 'request_hunt';
											}
									default:
										break;
								}
							break;
					}
					if ($state != 'first_bracket_hunt' && $bracket_count == 0) {
						break;
					}
				}
				break;
			}
		}
		return array_unique($request_vals);
	}
	
    private function parse_call($call) {
        
    }
    
    private function method_exists($class, $method) {
        
    }
    
    private function map_callback($class, $method) {
        return isset($this->callback["$class/$method"])?$this->callback["$class/$method"]:array($class, $method);
    }
    
    private function validate_method($class, $method) {
		$parameters = $this->get_call_definition($class, $method);
		if (ctype_lower(substr($method, 0, 1))) return false;
		if (strpos($method, '_') !== false) return false;
		if ($method == '__construct') return false;
        if ($this->METHOD == JsonServer::METHOD_ANY) return true;
        if ($this->METHOD == JsonServer::METHOD_REQUIRE) {
            $method = isset($this->method["$class/$method"])?$this->method["$class/$method"]:$this->_map("$class/$method");
            return stricmp($method, $_SERVER['REQUEST_METHOD'] == 0);
        }
        return $this->METHOD == $_SERVER['REQUEST_METHOD'];
    }
    
    private function _map($route) {
        if (in_array($route, $this->map) && $this->MAP == JsonServer::MAP_REQUIRE) {
            return false;
        }
        return isset($this->map[$route])?$this->map[$route]:$route;
    }
	
	private function get_classes() {
		return $this->valid_class;
	}
	
	private function get_methods($class) {
		$methods = get_class_methods(Ork3::$Lib->Request->list);
		$valid_methods = array();
		foreach ($methods as $k => $method) {
			if ($this->valid_method($method))
				$valid_methods[] = $method;
		}
		return $valid_methods;
	}
    
    private function valid_class($class) {
        if (count($this->valid_class) == 0) return true;
        return in_array($class, $this->valid_class);
    }
    
    private function valid_method($method) {
		if ($method == '__construct') return false;
		if (substr($method, 0, 1) == '_') return false;
        if (count($this->valid_method) == 0) return true;
        return in_array($method, $this->valid_method);
    }
    
    private function parameter_name($def) {
        return $def['UriName']!=$def['Name']?$def['UriName']:$def['Name'];
    }
    
    private function type_check($def, $value) {
        switch ($def['Type']) {
            case JsonServer::ANY: return true;
            case JsonServer::BOOL:
                return is_bool($value)?true:is_numeric($value);
            case JsonServer::NUM:
                return is_numeric($value);
            case JsonServer::STR: 
                return is_string($value);
            case JsonServer::ARR: 
                return is_array($value)?true:is_array(JsonServer::Decode($value . ' ', true));
            case JsonServer::OBJ: 
                return is_object($value)?true:is_object(JsonServer::Decode($value . ' '));
            case JsonServer::DATE: 
                return strtotime($value);
        }
    }
    
    private function convert_value($def, $value) {
        switch ($def['Type']) {
            case JsonServer::ANY: 
            case JsonServer::BOOL:
            case JsonServer::NUM:
            case JsonServer::STR: 
                return $value;
            case JsonServer::ARR: 
                return is_array($value)?$value:JsonServer::Decode($value, true);
            case JsonServer::OBJ: 
                return is_object($value)?$value:JsonServer::Decode($value);
            case JsonServer::DATE: 
                return strtotime($value);
        }
    }
    
    private function wrangle_parameters($definition, $zero_call = false) {
        if ($definition === false || !is_array($definition)) return false;
		$args = array();
		if ($zero_call) {
			$request = array();
			foreach ($definition as $parameter => $details) {
				if (!isset(Ork3::$Lib->Request->Request[$parameter]) && !$definition[$parameter]['Optional']) {
					echo "Parameter $parameter [" . $this->parameter_name($definition[$parameter]) . "] is not set and it is not optional; ";
					return false;
				} else if (!isset(Ork3::$Lib->Request->Request[$this->parameter_name($definition[$parameter])])) {
					continue;
				}
				if (!$this->type_check($definition[$parameter], Ork3::$Lib->Request->Request[$parameter])) {
					echo "Parameter is the wrong type; ";
					return false;
				}
				$request[$parameter] = $this->convert_value($definition[$parameter], Ork3::$Lib->Request->Request[$parameter]);
			}
			$args[] = $request;
		} else {
			$ordering = array();
			foreach ($definition as $parameter_name => $def) $ordering[$def['Order']] = $parameter_name;
			$args = array();
			$null_set = false;
			foreach ($ordering as $order => $parameter) {
				if (!isset(Ork3::$Lib->Request->Request[$this->parameter_name($definition[$parameter])]) && !$definition[$parameter]['Optional']) {
					echo "Parameter $parameter [" . $this->parameter_name($definition[$parameter]) . "] is not set and it is not optional; ";
					return false;
				} else if (!isset(Ork3::$Lib->Request->Request[$this->parameter_name($definition[$parameter])])) {
					$args[] = $definition[$parameter]['DefaultValue'];
					continue;
				}
				if (!$this->type_check($definition[$parameter], Ork3::$Lib->Request->Request[$this->parameter_name($definition[$parameter])])) {
					echo "Parameter is the wrong type; ";
					return false;
				}
				$args[] = $this->convert_value($definition[$parameter], Ork3::$Lib->Request->Request[$this->parameter_name($definition[$parameter])]);
			}
		}
		return $args;
    }
    
    private function get_default_definition($class, $method = null) {
		$definition = array();
		if (substr($method, -1) == '0') {
			$parameters = $this->translate_static_analysis($class, substr($method, 0, -1));
			foreach ($parameters as $k => $parameter) {
				$definition[$parameter] = array(
						'Optional' => false,
						'DefaultValue' => null,
						'Order' => $k,
						'Type' => 'var',
						'UriName' => $parameter,
						'Name' => $parameter
					);
			}
		} else {
			if (!class_exists($class) || !method_exists($class, $method))
				return false;
			$rm = is_null($method)?(new ReflectionFunction($class)):(new ReflectionMethod($class, $method));
			if ($rm) {
				$p = $rm->getParameters();
				foreach ($p as $k => $param) {
					if (is_null($method) || $rm->isPublic()) {
						$definition[$param->getName()] = array(
							'Optional' => $param->isOptional(),
							'DefaultValue' => $param->isOptional()?$param->getDefaultValue():null,
							'Order' => $param->getPosition(),
							'Type' => JsonServer::ANY,
							'UriName' => $param->getName(),
							'Name' => $param->getName());
					}
				}
			} else {
				return false;
			}
		}
		// perform some caching for the next call
		$this->$class($method, $definition);
		return $definition;
    }
    
    private function get_call_definition($class, $method) {
        if ($this->call_defined($class, $method))
            return $this->definitions[$class][$method];
		else
			return $this->get_default_definition($class, $method);
    }
    
    private function run_call($class, $method, $args) {
        if (!$this->valid_class($class))
            return $this->error(JsonServer::RESTRICTED_CLASS, "The class $class is not a callable object at this endpoint.");
        if (!$this->valid_method($method))
            return $this->error(JsonServer::RESTRICTED_METHOD, "The method $method is not a callable method at this endpoint.");
        if ($args === false) {
            return $this->error(JsonServer::BAD_ARGUMENTS, 'Missing arguments or arguments are mis-typed.');
        } else {
            list($c, $m) = $this->map_callback($class, $method);
            $instance = new $c();
            $result = call_user_func_array(array($instance, $m), $args);
            if (is_array($result)) {
                if (isset($result['Status']) && isset($result['Code'])) {
                    return $result;
                } else if (isset($result['Status']) && !isset($result['Code'])) {
                    $result['Code'] = $result['Status']?(Errors::SUCCESS):(Errors::GENERAL_ERROR);
					$result['Details'] = 'The status indicated failure but no code was returned.';
                    return $result;
                } else if (!isset($result['Status']) && isset($result['Code'])) {
                    $result['Status'] = $result['Code']==Errors::SUCCESS;
                    return $result;
                }
            }
            return array('Result'=>$result,'Status'=>true,'Code'=>Errors::SUCCESS);
        }
    } 
    
    private function error($code, $message, $trace = null) {
        return array('Result'=>null,'Status'=>false,'Code'=>Errors::CLASS_ERROR,'ClassCode'=>$code,'ErrorMessage'=>$message,'Trace'=>$trace);
    }
    
    private function general_error($line = 0) {
        return array('Result' => null, 'Status' => false, 'Code' => Errors::GENERAL_ERROR, 'Details' => 'A general error occured on line ' . $line);
    }
    
    private function call_defined($class, $method) {
        return isset($this->definitions[$class]) && is_array($this->definitions[$class]) && isset($this->definitions[$class][$method]) && is_array($this->definitions[$class][$method]);
    }

}

?>