<?php

class Ghettocache {

	function __construct() {
		
	}
	
	function get($call, $key, $lifetime) {
		$stat = @stat(DIR_CACHE . "$call.$key.cache");
		if ($stat === false)
			return false;
		if ($stat['mtime'] < time() - $lifetime)
			return false;
		
		logtrace("fetch ghettocache: " . DIR_CACHE . "$call.$key.cache", $content);
		return json_decode(file_get_contents(DIR_CACHE . "$call.$key.cache"), true);
	}
	
	function cache($call, $key, $content) {
		$cache = json_encode(utf8_encode_recursive($content), !JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
		if (json_last_error() != JSON_ERROR_NONE)
			die(json_last_error_msg());
		file_put_contents(DIR_CACHE . "$call.$key.cache", $cache, LOCK_EX);
		logtrace("put ghettocache: " . DIR_CACHE . "$call.$key.cache", $content);
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