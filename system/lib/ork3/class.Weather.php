<?php

/**
 * Park weather cache backed by Open-Meteo (https://open-meteo.com/).
 *
 * Strategy:
 *   - One row per park in ork_park_weather.
 *   - refresh_all_active_parks() batches every active park's coords into a
 *     single multi-coord Open-Meteo request (one HTTP call total). Intended
 *     to be invoked from cron every ~30 minutes.
 *   - for_park($id) reads the cached row. If absent or stale (>STALE_MIN),
 *     it triggers a refresh inline so any caller can be lazy without needing
 *     cron in the loop.
 *
 * Attribution: Open-Meteo licenses data under CC-BY 4.0. Templates rendering
 * weather data must include a link to https://open-meteo.com/ next to the
 * displayed values (see the small "ⓘ" attribution link in the UI partials).
 */
class Weather extends Ork3 {

	// Considered "active" if any signin happened in the past N days
	const ACTIVE_DAYS = 60;

	// Cache row age (minutes) after which we'll re-fetch on a read
	const STALE_MIN = 90;

	// Open-Meteo endpoints (no API key required)
	const URL_FORECAST = 'https://api.open-meteo.com/v1/forecast';
	const URL_ARCHIVE  = 'https://archive-api.open-meteo.com/v1/archive';

	// Open-Meteo's archive (ERA5) has roughly a 5-day lag — dates closer than
	// this to today don't have archive data yet. We refuse to query for them.
	const ARCHIVE_LAG_DAYS = 5;

	// Past weather is immutable, but memcached caps TTL at 30 days before
	// it interprets the value as a Unix timestamp. 30 days is fine: cold
	// re-fetches cost essentially nothing and the API is unlimited at our
	// scale (a few thousand calls/year).
	const ARCHIVE_TTL_SEC = 2592000; // 30 days

	/**
	 * Read the cached weather row for a park. Triggers an inline refresh of
	 * ALL active parks if this park's row is missing or older than STALE_MIN.
	 * Returns null if the park has no coords or weather couldn't be fetched.
	 */
	public function for_park($park_id) {
		$park_id = (int)$park_id;
		if ($park_id <= 0) return null;

		$row = $this->read_row($park_id);
		$stale = !$row || strtotime($row['fetched_at']) < (time() - self::STALE_MIN * 60);
		if ($stale && $this->is_active_park($park_id)) {
			// Only refresh for parks in the active set. Pages for dormant parks
			// stay fast (no Open-Meteo round trip); they just don't show weather.
			// When a dormant park gets a fresh signin, the active filter picks
			// it up automatically on the next refresh — no code change needed.
			$this->refresh_all_active_parks();
			$row = $this->read_row($park_id);
		} elseif ($stale) {
			// Dormant park with a stale row from when it was active. Don't
			// surface months-old data to the UI; just show nothing. The row
			// stays in the table (cheap) and will get overwritten if the park
			// reactivates and the next refresh picks it up.
			return null;
		}
		return $row;
	}

	/**
	 * Pull a single day's forecast out of the cached forecast_json for a
	 * park. Returns null if the date is outside the 7-day window we cached,
	 * or if the park has no weather row at all.
	 *
	 * Returned shape: ['date'=>'YYYY-MM-DD','code'=>int,'hi_f'=>float,'lo_f'=>float,'precip_pct'=>int]
	 */
	public function forecast_for_date($park_id, $date) {
		$row = $this->for_park($park_id);
		if (!$row || empty($row['forecast_json'])) return null;
		$wx = json_decode($row['forecast_json'], true);
		if (!is_array($wx) || empty($wx['daily']['time'])) return null;
		$idx = array_search($date, $wx['daily']['time'], true);
		if ($idx === false) return null;
		return array(
			'date'         => $date,
			'code'         => isset($wx['daily']['weather_code'][$idx])                  ? (int)$wx['daily']['weather_code'][$idx]                  : null,
			'hi_f'         => isset($wx['daily']['temperature_2m_max'][$idx])            ? (float)$wx['daily']['temperature_2m_max'][$idx]          : null,
			'lo_f'         => isset($wx['daily']['temperature_2m_min'][$idx])            ? (float)$wx['daily']['temperature_2m_min'][$idx]          : null,
			'app_hi_f'     => isset($wx['daily']['apparent_temperature_max'][$idx])      ? (float)$wx['daily']['apparent_temperature_max'][$idx]    : null,
			'app_lo_f'     => isset($wx['daily']['apparent_temperature_min'][$idx])      ? (float)$wx['daily']['apparent_temperature_min'][$idx]    : null,
			'uv_max'       => isset($wx['daily']['uv_index_max'][$idx])                  ? (float)$wx['daily']['uv_index_max'][$idx]                : null,
			'gusts_max'    => isset($wx['daily']['wind_gusts_10m_max'][$idx])            ? (float)$wx['daily']['wind_gusts_10m_max'][$idx]          : null,
			'precip_pct'   => isset($wx['daily']['precipitation_probability_max'][$idx]) ? (int)$wx['daily']['precipitation_probability_max'][$idx] : null,
		);
	}

	/**
	 * Derive safety badges for a single forecast row.
	 *
	 * Two tiers:
	 *  - WARNINGS (absolute, universal): things that are biologically dangerous
	 *    no matter where you live — heat stroke territory, frostbite territory,
	 *    lightning, severe wind, extreme UV. Same thresholds for Texas as
	 *    Ottawa as Florida.
	 *  - CAUTIONS (relative): "this day is notably hotter/colder/windier than
	 *    the surrounding week here." Solves the Texas-vs-Ottawa problem
	 *    without per-park config — a steady-cold Ottawa Sunday is normal and
	 *    flagless; a cold snap below the local week's average lights up.
	 *
	 * @param array      $f         Single-day forecast (from forecast_for_date)
	 * @param array|null $allDaily  Optional: the 'daily' block from forecast_json
	 *                              for week-relative comparisons. Without it,
	 *                              only the absolute warnings fire.
	 * @param string|null $forDate  Optional: the date string for $f (skips it
	 *                              when computing the week's average so we're
	 *                              comparing today against the other days).
	 */
	public static function safety_badges($f, $allDaily = null, $forDate = null) {
		$out = array();
		$code = isset($f['code'])      ? (int)$f['code']        : null;
		$ah   = isset($f['app_hi_f'])  ? (float)$f['app_hi_f']  : null;
		$al   = isset($f['app_lo_f'])  ? (float)$f['app_lo_f']  : null;
		$uv   = isset($f['uv_max'])    ? (float)$f['uv_max']    : null;
		$g    = isset($f['gusts_max']) ? (float)$f['gusts_max'] : null;

		// ---- WARNINGS — absolute, biologically dangerous everywhere ----
		if ($ah !== null && $ah >= 103)                   { $out[] = array('icon'=>'🥵','label'=>'Extreme heat',   'severity'=>'warning'); }
		if ($al !== null && $al <=   0)                   { $out[] = array('icon'=>'🥶','label'=>'Frostbite risk', 'severity'=>'warning'); }
		if ($code !== null && $code >= 95 && $code <= 99) { $out[] = array('icon'=>'⛈️','label'=>'Thunderstorms',  'severity'=>'warning'); }
		if ($g  !== null && $g  >= 40)                    { $out[] = array('icon'=>'💨','label'=>'Severe wind',    'severity'=>'warning'); }
		if ($uv !== null && $uv >= 10)                    { $out[] = array('icon'=>'🌞','label'=>'Very high UV',   'severity'=>'warning'); }

		// ---- CAUTIONS — relative to the local 7-day forecast ----
		if (is_array($allDaily) && !empty($allDaily['time'])) {
			$idxToday = $forDate !== null ? array_search($forDate, $allDaily['time'], true) : false;
			$peers = array_keys($allDaily['time']);
			if ($idxToday !== false) {
				$peers = array_values(array_diff($peers, array($idxToday)));
			}
			$avg = function($key) use ($allDaily, $peers) {
				$vals = array();
				foreach ($peers as $i) {
					if (isset($allDaily[$key][$i]) && is_numeric($allDaily[$key][$i])) $vals[] = (float)$allDaily[$key][$i];
				}
				return $vals ? array_sum($vals) / count($vals) : null;
			};
			$avgHi  = $avg('apparent_temperature_max');
			$avgGus = $avg('wind_gusts_10m_max');
			// Combine a *relative* deviation with an *absolute* floor so the
			// caution only fires when the day is BOTH unusual for the week AND
			// objectively notable. Use apparent HIGH for both warm/cool — park
			// days happen during daytime, so the overnight low isn't the
			// signal that matters.
			if ($ah !== null && $avgHi  !== null && ($ah - $avgHi) >=  10 && $ah >= 75)   { $out[] = array('icon'=>'🔥','label'=>'Warm day','severity'=>'caution'); }
			if ($ah !== null && $avgHi  !== null && ($avgHi - $ah) >=  10 && $ah <= 60)   { $out[] = array('icon'=>'❄️','label'=>'Cool day','severity'=>'caution'); }
			if ($g  !== null && $avgGus !== null && ($g  - $avgGus) >= 15)               { $out[] = array('icon'=>'💨','label'=>'Windy day','severity'=>'caution'); }
		}
		return $out;
	}

	/**
	 * Forecast for an arbitrary lat/lng. Used by callers whose location isn't
	 * a park (e.g., kingdom-level events with their own venue coords). Cached
	 * in GhettoCache for 30 minutes — matches the cron refresh cadence for
	 * the park-based forecast cache, so coord-based one-offs stay roughly as
	 * fresh as the park ones. Returns the same shape as forecast_for_date.
	 */
	public function forecast_for_coords($lat, $lng, $date) {
		if (!is_numeric($lat) || !is_numeric($lng)) return null;
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
		$lat = number_format((float)$lat, 4, '.', '');
		$lng = number_format((float)$lng, 4, '.', '');

		$key = Ork3::$Lib->ghettocache->key(array($lat, $lng));
		$cached = Ork3::$Lib->ghettocache->get(__CLASS__ . '.forecast_for_coords', $key, 30 * 60);
		if ($cached === false) {
			$url = self::URL_FORECAST . '?latitude=' . $lat . '&longitude=' . $lng
				. '&daily=temperature_2m_max,temperature_2m_min,apparent_temperature_max,apparent_temperature_min,'
					. 'precipitation_probability_max,weather_code,uv_index_max,wind_gusts_10m_max'
				. '&temperature_unit=fahrenheit&wind_speed_unit=mph&precipitation_unit=inch&timezone=auto&forecast_days=14';
			$json = $this->http_get($url);
			if ($json === false) return null;
			$cached = @json_decode($json, true);
			if (!is_array($cached)) return null;
			Ork3::$Lib->ghettocache->cache(__CLASS__ . '.forecast_for_coords', $key, $cached);
		}
		if (empty($cached['daily']['time'])) return null;
		$idx = array_search($date, $cached['daily']['time'], true);
		if ($idx === false) return null;
		return array(
			'date'         => $date,
			'code'         => isset($cached['daily']['weather_code'][$idx])              ? (int)$cached['daily']['weather_code'][$idx]              : null,
			'hi_f'         => isset($cached['daily']['temperature_2m_max'][$idx])        ? (float)$cached['daily']['temperature_2m_max'][$idx]      : null,
			'lo_f'         => isset($cached['daily']['temperature_2m_min'][$idx])        ? (float)$cached['daily']['temperature_2m_min'][$idx]      : null,
			'app_hi_f'     => isset($cached['daily']['apparent_temperature_max'][$idx])  ? (float)$cached['daily']['apparent_temperature_max'][$idx]: null,
			'app_lo_f'     => isset($cached['daily']['apparent_temperature_min'][$idx])  ? (float)$cached['daily']['apparent_temperature_min'][$idx]: null,
			'uv_max'       => isset($cached['daily']['uv_index_max'][$idx])              ? (float)$cached['daily']['uv_index_max'][$idx]            : null,
			'gusts_max'    => isset($cached['daily']['wind_gusts_10m_max'][$idx])        ? (float)$cached['daily']['wind_gusts_10m_max'][$idx]      : null,
			'precip_pct'   => isset($cached['daily']['precipitation_probability_max'][$idx]) ? (int)$cached['daily']['precipitation_probability_max'][$idx] : null,
		);
	}

	/**
	 * Historic weather for a past date at a park. No DB row; just GhettoCache.
	 *
	 *  - Refuses dates inside the ARCHIVE_LAG_DAYS window (Open-Meteo's
	 *    ERA5 reanalysis isn't published until ~5 days after the fact).
	 *  - Refuses future dates (use forecast_for_date for those).
	 *  - Refuses bad input — invalid date or non-existent park.
	 *  - Cached for ARCHIVE_TTL_SEC (30 days) — past weather is immutable,
	 *    re-fetch cost is negligible.
	 *
	 * Returns the same shape as forecast_for_date() plus null fields where
	 * the archive endpoint doesn't expose data (e.g., precip_pct — that's
	 * a forecast concept, not an archive one; we substitute precip_inches).
	 */
	public function archive_for_date($park_id, $date) {
		$coords = $this->coords_for_park($park_id);
		if ($coords === null) return null;
		return $this->archive_for_coords($coords[0], $coords[1], $date);
	}

	/**
	 * Coord-based variant. Used when a caller has lat/lng directly (e.g., an
	 * event venue that isn't a park). Same caching, same shape as
	 * archive_for_date.
	 */
	public function archive_for_coords($lat, $lng, $date) {
		if (!is_numeric($lat) || !is_numeric($lng)) return null;
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
		if ($date >= date('Y-m-d', time() - self::ARCHIVE_LAG_DAYS * 86400)) return null;
		if ($date < '1940-01-01') return null;

		// Round coords to 4 decimal places (~11m precision) for cache hit rate;
		// raw values would key cache uniquely per micro-jitter.
		$lat = number_format((float)$lat, 4, '.', '');
		$lng = number_format((float)$lng, 4, '.', '');

		$key = Ork3::$Lib->ghettocache->key(array($lat, $lng, $date));
		$cached = Ork3::$Lib->ghettocache->get(__CLASS__ . '.archive_for_coords', $key, self::ARCHIVE_TTL_SEC);
		if ($cached !== false) return $cached;

		$url = self::URL_ARCHIVE . '?latitude=' . $lat . '&longitude=' . $lng
			. '&start_date=' . $date . '&end_date=' . $date
			. '&daily=temperature_2m_max,temperature_2m_min,apparent_temperature_max,apparent_temperature_min,'
				. 'precipitation_sum,weather_code,wind_gusts_10m_max'
			. '&temperature_unit=fahrenheit&wind_speed_unit=mph&precipitation_unit=inch&timezone=auto';

		$json = $this->http_get($url);
		if ($json === false) return null;  // do NOT cache a transient failure
		$d = @json_decode($json, true);
		if (!is_array($d) || empty($d['daily']['time'])) {
			return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.archive_for_coords', $key, null);
		}

		$result = array(
			'date'           => $date,
			'code'           => isset($d['daily']['weather_code'][0])              ? (int)$d['daily']['weather_code'][0]              : null,
			'hi_f'           => isset($d['daily']['temperature_2m_max'][0])        ? (float)$d['daily']['temperature_2m_max'][0]      : null,
			'lo_f'           => isset($d['daily']['temperature_2m_min'][0])        ? (float)$d['daily']['temperature_2m_min'][0]      : null,
			'app_hi_f'       => isset($d['daily']['apparent_temperature_max'][0]) ? (float)$d['daily']['apparent_temperature_max'][0] : null,
			'app_lo_f'       => isset($d['daily']['apparent_temperature_min'][0]) ? (float)$d['daily']['apparent_temperature_min'][0] : null,
			'precip_inches'  => isset($d['daily']['precipitation_sum'][0])        ? (float)$d['daily']['precipitation_sum'][0]        : null,
			'gusts_max'      => isset($d['daily']['wind_gusts_10m_max'][0])       ? (float)$d['daily']['wind_gusts_10m_max'][0]       : null,
		);
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.archive_for_coords', $key, $result);
	}

	/**
	 * Convenience: look up a park's forecast for a date AND its 7-day daily
	 * block in one call, return the badges for that date with week-relative
	 * cautions enabled. Returns [] if no data.
	 */
	public function badges_for_date($park_id, $date) {
		$row = $this->for_park($park_id);
		if (!$row || empty($row['forecast_json'])) return array();
		$wx = json_decode($row['forecast_json'], true);
		if (!is_array($wx) || empty($wx['daily']['time'])) return array();
		$f = $this->forecast_for_date($park_id, $date);
		if (!$f) return array();
		return self::safety_badges($f, $wx['daily'], $date);
	}

	/**
	 * Batch variant — one query for a list of park IDs. Returns an assoc
	 * keyed by park_id. If any returned row is missing or older than
	 * STALE_MIN, triggers ONE refresh and re-reads. Caller pays at most one
	 * Open-Meteo round trip regardless of how many parks they ask about.
	 */
	public function for_parks(array $park_ids) {
		$ids = array_filter(array_map('intval', $park_ids), function($i) { return $i > 0; });
		if (empty($ids)) return array();
		$rows = $this->read_rows($ids);
		$threshold = time() - self::STALE_MIN * 60;
		$stale = (count($rows) !== count($ids));
		if (!$stale) {
			foreach ($rows as $r) {
				if (strtotime($r['fetched_at']) < $threshold) { $stale = true; break; }
			}
		}
		if ($stale) {
			$this->refresh_all_active_parks();
			$rows = $this->read_rows($ids);
		}
		return $rows;
	}

	private function is_active_park($park_id) {
		$cutoff = date('Y-m-d', time() - self::ACTIVE_DAYS * 86400);
		$rs = $this->db->query("SELECT 1 FROM " . DB_PREFIX . "attendance
			WHERE park_id = " . (int)$park_id . " AND date >= '$cutoff' LIMIT 1");
		return ($rs && $rs->size() > 0);
	}

	/**
	 * Resolve a park's coordinates with fallback: prefer the scalar lat/lng
	 * columns, then parse the `location` blob (Google geocode response, same
	 * source the park map uses). A surprising number of high-traffic parks
	 * have populated `location` but zero scalars — this read-time fallback
	 * brings them into the weather feature without backfilling.
	 *
	 * Note: the `location` column is stored backslash-escaped (e.g.
	 * `{\"bounds\":{...}}`) so SQL JSON_VALUE returns NULL. We do the
	 * parsing in PHP after stripslashes() to recover the real JSON.
	 *
	 * Returns [lat, lng] or null if no usable coords.
	 */
	public function coords_for_park($park_id) {
		$park_id = (int)$park_id;
		if ($park_id <= 0) return null;
		$rs = $this->db->query("SELECT latitude, longitude, location FROM " . DB_PREFIX . "park WHERE park_id = $park_id LIMIT 1");
		if (!$rs || $rs->size() === 0) return null;
		$rs->next();
		$lat = (float)$rs->latitude;
		$lng = (float)$rs->longitude;
		if ($lat != 0.0 && $lng != 0.0) return array($lat, $lng);
		return $this->parse_location_blob($rs->location);
	}

	/**
	 * Park IDs that have an in-person park day on the given date.
	 * Evaluates all three recurrence flavors (weekly, week-of-month, monthly).
	 * Excludes online-marked entries — only in-field play counts.
	 */
	public function parks_playing_on($date) {
		$ts        = strtotime($date);
		if ($ts === false) return array();
		$dow_name  = date('l', $ts);          // e.g., "Sunday" — matches ork_parkday.week_day enum
		$dom       = (int)date('j', $ts);     // day-of-month for monthly recurrence
		$wom       = (int)ceil($dom / 7);     // 1..5 — which Nth-weekday of the month today is

		$sql = "SELECT DISTINCT park_id FROM " . DB_PREFIX . "parkday WHERE online = 0 AND (
			(recurrence = 'weekly'        AND week_day = '$dow_name') OR
			(recurrence = 'week-of-month' AND week_day = '$dow_name' AND week_of_month = $wom) OR
			(recurrence = 'monthly'       AND month_day = $dom)
		)";
		$rs = $this->db->query($sql);
		$out = array();
		if ($rs && $rs->size() > 0) {
			while ($rs->next()) $out[] = (int)$rs->park_id;
		}
		return $out;
	}

	/**
	 * Build the structured data the Weather-page rundown renders from.
	 * Returns a single associative array with:
	 *   - badge_counts:    [label => count]   total parks flagged per badge type today
	 *   - badge_kingdoms:  [label => [kingdom_name => count]]   distribution
	 *   - standout_park:   ['park_id','name','kingdom','flags' => [...]]  worst-hit park
	 *   - total_parks:     int                covered active parks
	 *   - event_count:     int                events scheduled in next 7 days
	 *   - event_concerning:int                events whose start day has a warning
	 *   - date:            'YYYY-MM-DD'
	 *
	 * No external calls — reads ork_park_weather + ork_event.
	 */
	public function daily_summary($date) {
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

		// Scope the rundown to parks with an in-person park day on $date.
		// The whole point of the Rundown is to answer "where will weather
		// matter today" — a 113°F forecast at a park with no scheduled play
		// is not decision-relevant for anyone reading.
		$playing_ids = $this->parks_playing_on($date);
		if (empty($playing_ids)) {
			return array(
				'date' => $date, 'total_parks' => 0,
				'badge_counts' => array(), 'badge_kingdoms' => array(),
				'standout_park' => null, 'event_count' => 0, 'event_concerning' => 0,
				'no_play_today' => true,
			);
		}
		$ids_sql = implode(',', array_map('intval', $playing_ids));
		$rs = $this->db->query("SELECT pw.park_id, pw.forecast_json, p.name AS park_name, k.name AS kingdom_name
			FROM " . DB_PREFIX . "park_weather pw
			JOIN " . DB_PREFIX . "park p ON p.park_id = pw.park_id
			LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = p.kingdom_id
			WHERE pw.park_id IN ($ids_sql)");

		$out = array(
			'date'              => $date,
			'badge_counts'      => array(),
			'badge_kingdoms'    => array(),
			'standout_park'     => null,
			'total_parks'       => 0,
			'event_count'       => 0,
			'event_concerning'  => 0,
		);
		$standoutScore = -1;
		// Severity score for "worst-hit" picking — picks the park with the
		// nastiest single warning, breaks ties by total flag count.
		$severity = array(
			'Extreme heat'    => 100,
			'Frostbite risk'  => 100,
			'Thunderstorms'   =>  80,
			'Severe wind'     =>  60,
			'Very high UV'    =>  40,
			'Warm day'        =>  10,
			'Cool day'        =>  10,
			'Windy day'       =>   5,
		);

		if ($rs && $rs->size() > 0) {
			while ($rs->next()) {
				$out['total_parks']++;
				if (empty($rs->forecast_json)) continue;
				$wx = json_decode($rs->forecast_json, true);
				if (!is_array($wx) || empty($wx['daily']['time'])) continue;
				$idx = array_search($date, $wx['daily']['time'], true);
				if ($idx === false) continue;
				$f = array(
					'code'         => isset($wx['daily']['weather_code'][$idx])              ? (int)$wx['daily']['weather_code'][$idx]              : null,
					'hi_f'         => isset($wx['daily']['temperature_2m_max'][$idx])        ? (float)$wx['daily']['temperature_2m_max'][$idx]      : null,
					'app_hi_f'     => isset($wx['daily']['apparent_temperature_max'][$idx])  ? (float)$wx['daily']['apparent_temperature_max'][$idx]: null,
					'app_lo_f'     => isset($wx['daily']['apparent_temperature_min'][$idx])  ? (float)$wx['daily']['apparent_temperature_min'][$idx]: null,
					'uv_max'       => isset($wx['daily']['uv_index_max'][$idx])              ? (float)$wx['daily']['uv_index_max'][$idx]            : null,
					'gusts_max'    => isset($wx['daily']['wind_gusts_10m_max'][$idx])        ? (float)$wx['daily']['wind_gusts_10m_max'][$idx]      : null,
				);
				// Compute warning-tier badges only (skip relative cautions for the rundown — they're noisy in aggregate).
				$badges = self::safety_badges($f, null, $date);
				$badges = array_filter($badges, function($b) { return $b['severity'] === 'warning'; });
				if (empty($badges)) continue;

				$score = 0;
				$labels = array();
				foreach ($badges as $b) {
					$lbl = $b['label'];
					$labels[] = $lbl;
					$score   += isset($severity[$lbl]) ? $severity[$lbl] : 0;
					$out['badge_counts'][$lbl] = ($out['badge_counts'][$lbl] ?? 0) + 1;
					if (!empty($rs->kingdom_name)) {
						$k = $rs->kingdom_name;
						$out['badge_kingdoms'][$lbl][$k] = ($out['badge_kingdoms'][$lbl][$k] ?? 0) + 1;
					}
				}
				if ($score > $standoutScore) {
					$standoutScore = $score;
					$out['standout_park'] = array(
						'park_id' => (int)$rs->park_id,
						'name'    => $rs->park_name,
						'kingdom' => $rs->kingdom_name,
						'flags'   => array_values(array_unique($labels)),
						'app_hi_f'=> $f['app_hi_f'],
					);
				}
			}
		}

		// Event counts. Concerning = event's start day forecast carries any warning badge.
		$cutoff = date('Y-m-d', time() + 7 * 86400);
		$today  = date('Y-m-d');
		$er = $this->db->query("SELECT e.event_id, e.park_id, cd.event_start, cd.event_calendardetail_id
			FROM " . DB_PREFIX . "event e
			JOIN " . DB_PREFIX . "event_calendardetail cd ON cd.event_id = e.event_id
			WHERE DATE(cd.event_start) >= '$today' AND DATE(cd.event_start) <= '$cutoff'");
		if ($er && $er->size() > 0) {
			while ($er->next()) {
				$out['event_count']++;
				$d = substr($er->event_start, 0, 10);
				$evFC = $this->forecast_for_date((int)$er->park_id, $d);
				if (!$evFC) continue;
				$bs = self::safety_badges($evFC, null, $d);
				$warn = array_filter($bs, function($b) { return $b['severity'] === 'warning'; });
				if (!empty($warn)) $out['event_concerning']++;
			}
		}
		return $out;
	}

	/**
	 * Upcoming events in the next $days, each enriched with the start-day
	 * forecast (or null if outside the cache window). Sorted by start date.
	 */
	public function upcoming_events_with_forecast($days = 7) {
		$today  = date('Y-m-d');
		$cutoff = date('Y-m-d', time() + (int)$days * 86400);
		$rs = $this->db->query("SELECT e.event_id, e.name AS event_name, e.park_id, e.kingdom_id,
		         cd.event_start, cd.event_end, cd.event_calendardetail_id, cd.at_park_id,
		         cd.latitude AS ev_lat, cd.longitude AS ev_lng,
		         p.name AS park_name, k.name AS kingdom_name
			FROM " . DB_PREFIX . "event e
			JOIN " . DB_PREFIX . "event_calendardetail cd ON cd.event_id = e.event_id
			LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = e.park_id
			LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = e.kingdom_id
			WHERE DATE(cd.event_start) >= '$today' AND DATE(cd.event_start) <= '$cutoff'
			ORDER BY cd.event_start ASC");
		$out = array();
		if ($rs && $rs->size() > 0) {
			while ($rs->next()) {
				$d = substr($rs->event_start, 0, 10);
				// Coord priority for forecast:
				//   1. event_calendardetail's own coords (event-only venue)
				//   2. at_park (host park)
				//   3. owning park
				$fc = null;
				$evLat = (float)$rs->ev_lat;
				$evLng = (float)$rs->ev_lng;
				if ($evLat != 0.0 && $evLng != 0.0) {
					$fc = $this->forecast_for_coords($evLat, $evLng, $d);
				}
				$pid = (int)$rs->at_park_id ?: (int)$rs->park_id;
				if (!$fc && $pid > 0) {
					$fc = $this->forecast_for_date($pid, $d);
				}
				$badges = $fc ? self::safety_badges($fc, null, $d) : array();
				$out[] = array(
					'event_id'                => (int)$rs->event_id,
					'name'                    => $rs->event_name,
					'park_id'                 => $pid,
					'park_name'               => $rs->park_name,
					'kingdom_name'            => $rs->kingdom_name,
					'event_start'             => $rs->event_start,
					'event_end'               => $rs->event_end,
					'event_calendardetail_id' => (int)$rs->event_calendardetail_id,
					'forecast'                => $fc,
					'badges'                  => $badges,
				);
			}
		}
		return $out;
	}

	/**
	 * Convert "The Kingdom of X" / "The Empire of X" etc. to a short form
	 * suitable for the rundown narrative — see the design notes in
	 * follow_ups.md. Excludes "Freeholds of Amtgard" entirely (scatter, not
	 * a region).
	 */
	public static function short_kingdom($name) {
		if (!$name) return '';
		if (stripos($name, 'Freeholds') !== false) return '';  // not a region
		// "The Celestial Kingdom" — special case (no "X of Y" structure)
		if (preg_match('/^The (.+ Kingdom)$/i', $name, $m)) return 'the ' . $m[1];
		// Strip leading "The " then any rank-of[-the] prefix
		$n = preg_replace('/^The /i', '', $name);
		$n = preg_replace('/^(Kingdom|Empire|Principality) of /i', '', $n);
		return $n;
	}

	private function parse_location_blob($loc) {
		if (!$loc) return null;
		$d = @json_decode($loc, true);
		if (!is_array($d)) $d = @json_decode(stripslashes($loc), true);
		if (!is_array($d)) return null;
		$lat = $d['location']['lat'] ?? null;
		$lng = $d['location']['lng'] ?? null;
		if (!is_numeric($lat) || !is_numeric($lng)) return null;
		$lat = (float)$lat; $lng = (float)$lng;
		if ($lat == 0.0 || $lng == 0.0) return null;
		return array($lat, $lng);
	}

	/**
	 * Cheap boolean version of coords_for_park() for template eligibility checks.
	 */
	public function park_has_coords($park_id) {
		return $this->coords_for_park($park_id) !== null;
	}

	/**
	 * Bulk refresh: one HTTP call to Open-Meteo for every active park's
	 * coords, then upsert each park's row. Safe to call from cron OR from a
	 * lazy fallback (callers shouldn't notice the difference beyond latency).
	 *
	 * Returns the number of parks refreshed, or 0 on any failure.
	 */
	public function refresh_all_active_parks() {
		$parks = $this->active_parks_with_coords();
		if (empty($parks)) return 0;

		$lats = array();
		$lngs = array();
		foreach ($parks as $p) {
			$lats[] = number_format((float)$p['latitude'],  4, '.', '');
			$lngs[] = number_format((float)$p['longitude'], 4, '.', '');
		}

		$url = self::URL_FORECAST . '?'
			. 'latitude='  . implode(',', $lats) . '&'
			. 'longitude=' . implode(',', $lngs) . '&'
			. 'current=temperature_2m,apparent_temperature,weather_code,is_day,wind_speed_10m&'
			. 'daily=temperature_2m_max,temperature_2m_min,apparent_temperature_max,apparent_temperature_min,'
				. 'precipitation_probability_max,weather_code,uv_index_max,wind_gusts_10m_max&'
			. 'temperature_unit=fahrenheit&wind_speed_unit=mph&precipitation_unit=inch&'
			. 'timezone=auto&forecast_days=14';

		$json = $this->http_get($url);
		if ($json === false) return 0;

		$decoded = json_decode($json, true);
		if (!is_array($decoded)) return 0;

		// Open-Meteo returns either a single object (one coord) or an array of
		// objects (multi-coord). Normalize to array.
		$results = isset($decoded[0]) ? $decoded : array($decoded);
		if (count($results) !== count($parks)) {
			// Mismatched response — refuse to write partial rows.
			return 0;
		}

		$now = date('Y-m-d H:i:s');
		$updated = 0;
		foreach ($parks as $i => $p) {
			$wx = $results[$i] ?? null;
			if (!is_array($wx)) continue;

			$current = $wx['current'] ?? array();
			$daily   = $wx['daily']   ?? array();

			$today_high = isset($daily['temperature_2m_max'][0])      ? (float)$daily['temperature_2m_max'][0]      : null;
			$today_low  = isset($daily['temperature_2m_min'][0])      ? (float)$daily['temperature_2m_min'][0]      : null;
			$today_pp   = isset($daily['precipitation_probability_max'][0]) ? (int)$daily['precipitation_probability_max'][0] : null;

			$cur_temp = isset($current['temperature_2m']) ? (float)$current['temperature_2m'] : null;
			$cur_code = isset($current['weather_code'])   ? (int)$current['weather_code']     : null;
			$cur_day  = isset($current['is_day'])         ? (int)(bool)$current['is_day']     : null;
			$cur_wind = isset($current['wind_speed_10m']) ? (float)$current['wind_speed_10m'] : null;

			$this->upsert_row($p['park_id'], $now, array(
				'current_temp_f'   => $cur_temp,
				'current_code'     => $cur_code,
				'current_is_day'   => $cur_day,
				'current_wind_mph' => $cur_wind,
				'today_high_f'     => $today_high,
				'today_low_f'      => $today_low,
				'today_precip_pct' => $today_pp,
				'forecast_json'    => json_encode($wx),
			));
			$updated++;
		}
		return $updated;
	}

	// ---- internals --------------------------------------------------------

	private function active_parks_with_coords() {
		$cutoff = date('Y-m-d', time() - self::ACTIVE_DAYS * 86400);
		// Pull all active parks with recent attendance, resolve coords in PHP
		// (scalar first, then the location-JSON blob — same source the park
		// map uses). Done in PHP because the blob is stored backslash-escaped
		// so SQL JSON_VALUE returns NULL on it.
		$sql = "SELECT p.park_id, p.latitude, p.longitude, p.location
		        FROM " . DB_PREFIX . "park p
		        WHERE p.active = 'Active'
		          AND EXISTS (
		              SELECT 1 FROM " . DB_PREFIX . "attendance a
		              WHERE a.park_id = p.park_id AND a.date >= '$cutoff'
		          )
		        ORDER BY p.park_id";
		$rs = $this->db->query($sql);
		$out = array();
		if ($rs && $rs->size() > 0) {
			while ($rs->next()) {
				$lat = (float)$rs->latitude;
				$lng = (float)$rs->longitude;
				if ($lat == 0.0 || $lng == 0.0) {
					$parsed = $this->parse_location_blob($rs->location);
					if ($parsed === null) continue;
					list($lat, $lng) = $parsed;
				}
				$out[] = array(
					'park_id'   => (int)$rs->park_id,
					'latitude'  => $lat,
					'longitude' => $lng,
				);
			}
		}
		return $out;
	}

	private function read_row($park_id) {
		$rows = $this->read_rows(array((int)$park_id));
		return $rows[(int)$park_id] ?? null;
	}

	private function read_rows(array $park_ids) {
		$ids_sql = implode(',', array_map('intval', $park_ids));
		if ($ids_sql === '') return array();
		$rs = $this->db->query("SELECT * FROM " . DB_PREFIX . "park_weather WHERE park_id IN ($ids_sql)");
		$out = array();
		if ($rs && $rs->size() > 0) {
			while ($rs->next()) {
				$out[(int)$rs->park_id] = array(
					'park_id'          => (int)$rs->park_id,
					'fetched_at'       => $rs->fetched_at,
					'current_temp_f'   => $rs->current_temp_f   !== null ? (float)$rs->current_temp_f : null,
					'current_code'     => $rs->current_code     !== null ? (int)$rs->current_code     : null,
					'current_is_day'   => $rs->current_is_day   !== null ? (int)$rs->current_is_day   : null,
					'current_wind_mph' => $rs->current_wind_mph !== null ? (float)$rs->current_wind_mph : null,
					'today_high_f'     => $rs->today_high_f     !== null ? (float)$rs->today_high_f   : null,
					'today_low_f'      => $rs->today_low_f      !== null ? (float)$rs->today_low_f    : null,
					'today_precip_pct' => $rs->today_precip_pct !== null ? (int)$rs->today_precip_pct : null,
					'forecast_json'    => $rs->forecast_json,
				);
			}
		}
		return $out;
	}

	private function upsert_row($park_id, $fetched_at, $fields) {
		$sets = array();
		$cols = array('park_id', 'fetched_at');
		$vals = array((int)$park_id, "'" . mysql_real_escape_string($fetched_at) . "'");
		foreach ($fields as $col => $val) {
			$cols[] = $col;
			if ($val === null) {
				$vals[] = 'NULL';
				$sets[] = "`$col` = NULL";
			} elseif (is_string($val)) {
				$esc = "'" . mysql_real_escape_string($val) . "'";
				$vals[] = $esc;
				$sets[] = "`$col` = $esc";
			} else {
				$vals[] = $val;
				$sets[] = "`$col` = $val";
			}
		}
		$sets[] = "`fetched_at` = '" . mysql_real_escape_string($fetched_at) . "'";
		$sql = "INSERT INTO " . DB_PREFIX . "park_weather (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ") "
		     . "ON DUPLICATE KEY UPDATE " . implode(', ', $sets);
		$this->db->query($sql);
	}

	private function http_get($url) {
		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 15);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
			curl_setopt($ch, CURLOPT_USERAGENT, 'ORK3 weather refresh (https://ork.amtgard.com)');
			$body = curl_exec($ch);
			$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			if ($http !== 200) return false;
			return $body;
		}
		return @file_get_contents($url);
	}
}
