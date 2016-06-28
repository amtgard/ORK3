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
		file_put_contents(DIR_CACHE . "$call.$key.cache", json_encode($content), LOCK_EX);
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