<?php

class Ghettocache {

	public $memcache;

	function __construct() {
		$this->memcache = new Memcached();
		$this->memcache->addServer('localhost', 11211);
	}
	
	function get($call, $key, $lifetime) {
		$cached = $this->memcache->get("$call.$key");
		logtrace("fetch memcached: $call.$key.cache", $content);
		return $cached;
	}
	
	function cache($call, $key, $content) {
		$this->memcache->set("$call.$key", $content);
		return $content;
	}
	
	function key($request) {
		if (!is_array($request))
			return '';
		unset($request['Token']);
		return implode(".", $request);
	}
	

}

function utf8_encode_recursive ($array)
{
		$result = array();
		foreach ($array as $key => $value)
		{
				if (is_array($value))
				{
						$result[$key] = utf8_encode_recursive($value);
				}
				else if (is_string($value))
				{
						$result[$key] = utf8_encode($value);
				}
				else
				{
						$result[$key] = $value;
				}
		}
		return $result;
}