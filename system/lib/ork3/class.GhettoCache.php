<?php

class Ghettocache {

	public $memcache;
	public $lifetimes;
	public $prefix;

	function __construct() {
		$this->memcache = new Memcached();
		$this->memcache->addServer('localhost', 11211);
		$this->lifetime = array();
		$this->prefix = $_SERVER[ 'HTTP_HOST' ];
	}
	
	function get($call, $key, $lifetime) {
		if (defined('CACHE_ENABLED') && CACHE_ENABLED == false) return false;
		$cached = $this->memcache->get("{$this->prefix}.$call.$key");
		logtrace("fetch memcached: {$this->prefix}.$call.$key", $cached);

		/**
		 * OK, so the lifetime parameter in GhettoCache is inverted, but the call pattern looks like this:
		 * 
		 * if (cache.get) then return cache;
		 * cache.cache(content);
		 * return content;
		 * 
		 * So, during the call to cache() we've already seen the key and the lifetime that's requested ...
		 * 
		 */
		$this->lifetime["{$this->prefix}.$call.$key"] = $lifetime;
		return $cached;
	}
	
	function cache($call, $key, $content) {
		$expiration = isset($this->lifetime["{$this->prefix}.$call.$key"]) ? $this->lifetime["{$this->prefix}.$call.$key"] : 300;
		$this->memcache->set("{$this->prefix}.$call.$key", $content, $expiration);
		logtrace("memcached expiration {$this->prefix}.$call.$key: ", $expiration);
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