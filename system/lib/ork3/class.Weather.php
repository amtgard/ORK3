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
		if ($stale) {
			$this->refresh_all_active_parks();
			$row = $this->read_row($park_id);
		}
		return $row;
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
			. 'current=temperature_2m,weather_code,is_day,wind_speed_10m&'
			. 'daily=temperature_2m_max,temperature_2m_min,precipitation_probability_max,weather_code&'
			. 'temperature_unit=fahrenheit&wind_speed_unit=mph&precipitation_unit=inch&'
			. 'timezone=auto&forecast_days=7';

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
		$sql = "SELECT p.park_id, p.latitude, p.longitude
		        FROM " . DB_PREFIX . "park p
		        WHERE p.latitude IS NOT NULL AND p.longitude IS NOT NULL
		          AND p.latitude != 0 AND p.longitude != 0
		          AND p.active = 'Active'
		          AND EXISTS (
		              SELECT 1 FROM " . DB_PREFIX . "attendance a
		              WHERE a.park_id = p.park_id AND a.date >= '$cutoff'
		          )
		        ORDER BY p.park_id";
		$rs = $this->db->query($sql);
		$out = array();
		if ($rs && $rs->size() > 0) {
			while ($rs->next()) {
				$out[] = array(
					'park_id'   => (int)$rs->park_id,
					'latitude'  => (float)$rs->latitude,
					'longitude' => (float)$rs->longitude,
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
