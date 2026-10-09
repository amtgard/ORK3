<?php

class Ghettocache
{
    public $memcache;
    public $lifetimes;
    public $prefix;

    public function __construct()
    {
        $this->memcache = new Memcached();
        $this->memcache->addServer('localhost', 11211);
        $this->lifetime = array();
        $this->prefix = defined('CACHE_HOST') ? CACHE_HOST : ($_SERVER['HTTP_HOST'] ?? 'ork');
    }

    public function get($call, $key, $lifetime)
    {
        //if (defined('CACHE_ENABLED') && CACHE_ENABLED == false) return false;
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

    public function cache($call, $key, $content)
    {
        $expiration = isset($this->lifetime["{$this->prefix}.$call.$key"]) ? $this->lifetime["{$this->prefix}.$call.$key"] : 300;
        $this->memcache->set("{$this->prefix}.$call.$key", $content, $expiration);
        logtrace("memcached expiration {$this->prefix}.$call.$key: ", $expiration);
        return $content;
    }

    public function bust($call, $key)
    {
        $this->memcache->delete("{$this->prefix}.$call.$key");
    }

    /**
     * Raw prefixed get for simple scalar counters (e.g. the tournament seq
     * cursor). Returns the stored value, or false on miss. Distinct from get(),
     * which is a memoization wrapper with inverted-lifetime bookkeeping.
     */
    public function counterGet($name)
    {
        return $this->memcache->get("{$this->prefix}.counter.$name");
    }

    /** Raw prefixed set for simple scalar counters, with explicit TTL seconds. */
    public function counterSet($name, $value, $ttl)
    {
        $this->memcache->set("{$this->prefix}.counter.$name", $value, $ttl);
        return $value;
    }

    /**
     * Atomically bump a scalar counter and return the new value.
     *
     * Uses Memcached::increment(), which is atomic server-side, so concurrent
     * callers (e.g. two reeves bumping the tournament realtime seq at once)
     * never lose an update. On a cold cache the key does not yet exist, so we
     * seed it with add() (which fails harmlessly if a racing caller already
     * seeded it) and then increment again to fold both bumps in. TTL is applied
     * on the seeding add(); Memcached::increment() cannot set a TTL, so the
     * lifetime is refreshed whenever the key is (re)seeded.
     */
    public function counterIncrement($name, $ttl)
    {
        $key = "{$this->prefix}.counter.$name";
        $new = $this->memcache->increment($key, 1);
        if ($new === false) {
            // Key missing (cold start or expired): seed at 1, then account for
            // any concurrent increment that lost the seeding race.
            if ($this->memcache->add($key, 1, $ttl)) {
                $new = 1;
            } else {
                $new = $this->memcache->increment($key, 1);
            }
        }
        return $new;
    }

    public function key($request)
    {
        if (!is_array($request)) {
            return '';
        }
        unset($request['Token']);
        return implode(".", $request);
    }

    // Bust the SearchService.Event cache for one event across the exact arg shapes
    // get_event_info() uses — draft-inclusive (detail page) and draft-exclusive
    // (listings/attendance). The cache is keyed on the full 12-arg call, so an
    // event edit / status change must mirror that here or it serves stale data.
    public function bust_event_search($event_id)
    {
        foreach (array(true, false) as $incl) {
            $k = $this->key(array(null, null, null, null, null, null, (int)$event_id, null, null, 1, 0, $incl));
            $this->bust('SearchService.Event', $k);
        }
    }


}

function utf8_encode_recursive($array)
{
    $result = array();
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $result[$key] = utf8_encode_recursive($value);
        } elseif (is_string($value)) {
            $result[$key] = utf8_encode($value);
        } else {
            $result[$key] = $value;
        }
    }
    return $result;
}
