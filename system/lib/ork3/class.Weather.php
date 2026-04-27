<?php

// Weather forecast helper. Uses Open-Meteo (no API key, worldwide, 16-day window).
// Server-side fetch with a small DB cache (ork_weather_cache, ~2h TTL by lookup logic).
class Weather extends Ork3 {

	const TTL_SECONDS = 7200;
	const FETCH_TIMEOUT_SECS = 4;
	const FORECAST_HORIZON_DAYS = 16;

	public function __construct() {
		parent::__construct();
		$this->cache = new yapo($this->db, DB_PREFIX . 'weather_cache');
	}

	private static function cacheKey($lat, $lng, $date) {
		return sha1(round((float)$lat, 3) . ':' . round((float)$lng, 3) . ':' . $date);
	}

	// Returns array { code, high_f, low_f, label, icon } or null when unavailable.
	public function GetForecast($lat, $lng, $date) {
		if (!is_numeric($lat) || !is_numeric($lng) || !$date) return null;

		$today = date('Y-m-d');
		// Out of forecast window? Bail early so we don't pile up empty cache rows.
		$daysAhead = (strtotime($date) - strtotime($today)) / 86400;
		if ($daysAhead > self::FORECAST_HORIZON_DAYS) return null;
		if ($daysAhead < -1) return null;

		$key = self::cacheKey($lat, $lng, $date);

		global $DB;
		$DB->Clear();
		$rs = $DB->DataSet("
			SELECT payload, fetched_at
			FROM " . DB_PREFIX . "weather_cache
			WHERE cache_key = '" . mysql_real_escape_string($key) . "' LIMIT 1");
		if ($rs && $rs->Next()) {
			$age = time() - strtotime((string)$rs->fetched_at);
			if ($age >= 0 && $age < self::TTL_SECONDS) {
				$decoded = json_decode((string)$rs->payload, true);
				if (is_array($decoded)) return self::shape($decoded);
			}
		}

		$payload = self::fetchOpenMeteo($lat, $lng, $date);
		if (!$payload) return null;

		// Upsert
		$DB->Clear();
		$DB->Execute("
			INSERT INTO " . DB_PREFIX . "weather_cache (cache_key, lat, lng, forecast_date, payload, fetched_at)
			VALUES (
				'" . mysql_real_escape_string($key) . "',
				" . (float)$lat . ",
				" . (float)$lng . ",
				'" . mysql_real_escape_string($date) . "',
				'" . mysql_real_escape_string(json_encode($payload)) . "',
				NOW()
			)
			ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()");

		return self::shape($payload);
	}

	private static function fetchOpenMeteo($lat, $lng, $date) {
		$url = 'https://api.open-meteo.com/v1/forecast?'
			. http_build_query([
				'latitude'         => $lat,
				'longitude'        => $lng,
				'daily'            => 'temperature_2m_max,temperature_2m_min,weather_code',
				'timezone'         => 'auto',
				'temperature_unit' => 'fahrenheit',
				'start_date'       => $date,
				'end_date'         => $date,
			]);

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => self::FETCH_TIMEOUT_SECS,
			CURLOPT_CONNECTTIMEOUT => self::FETCH_TIMEOUT_SECS,
			CURLOPT_USERAGENT      => 'ORK3-Weather/1.0',
		]);
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($code !== 200 || !$body) return null;
		$data = json_decode($body, true);
		if (!is_array($data) || empty($data['daily']) || empty($data['daily']['time'])) return null;

		// Slice the single requested day (start_date == end_date).
		$d = $data['daily'];
		$idx = 0;
		return [
			'date'   => $d['time'][$idx]                    ?? $date,
			'high'   => $d['temperature_2m_max'][$idx]      ?? null,
			'low'    => $d['temperature_2m_min'][$idx]      ?? null,
			'code'   => $d['weather_code'][$idx]            ?? null,
		];
	}

	private static function shape($payload) {
		$code = isset($payload['code']) ? (int)$payload['code'] : null;
		$map  = self::codeMap($code);
		return [
			'code'    => $code,
			'high_f'  => isset($payload['high']) && $payload['high'] !== null ? (int)round((float)$payload['high']) : null,
			'low_f'   => isset($payload['low'])  && $payload['low']  !== null ? (int)round((float)$payload['low'])  : null,
			'label'   => $map['label'],
			'icon'    => $map['icon'],
		];
	}

	// WMO code → emoji + short label. Unknown codes get a neutral cloud.
	private static function codeMap($code) {
		if ($code === 0)                       return ['icon' => '☀',  'label' => 'Clear'];
		if (in_array($code, [1, 2], true))     return ['icon' => '🌤', 'label' => 'Partly cloudy'];
		if ($code === 3)                       return ['icon' => '☁',  'label' => 'Cloudy'];
		if (in_array($code, [45, 48], true))   return ['icon' => '🌫', 'label' => 'Foggy'];
		if ($code !== null && $code >= 51 && $code <= 67)  return ['icon' => '🌧', 'label' => 'Rain'];
		if ($code !== null && $code >= 71 && $code <= 77)  return ['icon' => '❄',  'label' => 'Snow'];
		if ($code !== null && $code >= 80 && $code <= 82)  return ['icon' => '🌧', 'label' => 'Showers'];
		if ($code !== null && $code >= 95)                 return ['icon' => '⛈', 'label' => 'Thunderstorm'];
		return ['icon' => '🌥', 'label' => 'Forecast'];
	}
}
