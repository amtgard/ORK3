<?php

// Local NOAA solar-time computation. No external API.
// Returns sunrise / sunset / civil twilight start & end as local-time strings (e.g. "5:42 AM").
// $lat, $lng in decimal degrees; $date as 'Y-m-d'; $timezone a PHP timezone string (default America/New_York).
class SolarTimes {

	// Returns ['sunrise'=>'5:42 AM', 'sunset'=>'8:31 PM', 'twilight_start'=>'6:08 AM', 'twilight_end'=>'8:55 PM']
	// or null when no valid sunrise/sunset on this date (polar night/day).
	public static function ForDate($lat, $lng, $date, $timezone = null) {
		if (!is_numeric($lat) || !is_numeric($lng) || !$date) return null;

		$tz = $timezone ?: date_default_timezone_get() ?: 'America/New_York';
		try {
			$dtz = new DateTimeZone($tz);
			$dt  = new DateTime($date . ' 12:00:00', $dtz);
		} catch (Exception $e) { return null; }

		// Standard sunrise/sunset formulas (NOAA/Almanac), zenith 90°50'.
		$sr = self::computeEvent($lat, $lng, $dt, 90 + (50/60), true,  $dtz);
		$ss = self::computeEvent($lat, $lng, $dt, 90 + (50/60), false, $dtz);
		// Civil twilight uses zenith 96°.
		$ts = self::computeEvent($lat, $lng, $dt, 96.0,         true,  $dtz);
		$te = self::computeEvent($lat, $lng, $dt, 96.0,         false, $dtz);

		if (!$sr || !$ss) return null;

		return [
			'sunrise'        => self::fmt($sr),
			'sunset'         => self::fmt($ss),
			'twilight_start' => $ts ? self::fmt($ts) : null,
			'twilight_end'   => $te ? self::fmt($te) : null,
		];
	}

	private static function fmt(DateTime $d) {
		return $d->format('g:i A');
	}

	// Returns DateTime in $tz on $date or null when the sun never reaches $zenith on this day.
	private static function computeEvent($lat, $lng, DateTime $date, $zenith, $isRise, DateTimeZone $tz) {
		$rad = M_PI / 180.0;
		$deg = 180.0 / M_PI;

		// 1. Day of year
		$N = (int)$date->format('z') + 1;

		// 2. Approximate hour
		$lngHour = $lng / 15.0;
		$tApprox = $isRise ? ($N + ((6 - $lngHour) / 24.0)) : ($N + ((18 - $lngHour) / 24.0));

		// 3. Sun's mean anomaly
		$M = (0.9856 * $tApprox) - 3.289;

		// 4. Sun's true longitude
		$L = $M + (1.916 * sin($M * $rad)) + (0.020 * sin(2 * $M * $rad)) + 282.634;
		$L = self::normalize($L, 360);

		// 5. Right ascension (and quadrant adjust)
		$RA = atan(0.91764 * tan($L * $rad)) * $deg;
		$RA = self::normalize($RA, 360);
		$Lquad  = floor($L  / 90) * 90;
		$RAquad = floor($RA / 90) * 90;
		$RA = $RA + ($Lquad - $RAquad);
		$RA = $RA / 15.0;

		// 6. Declination
		$sinDec = 0.39782 * sin($L * $rad);
		$cosDec = cos(asin($sinDec));

		// 7. Local hour angle
		$cosH = (cos($zenith * $rad) - ($sinDec * sin($lat * $rad))) / ($cosDec * cos($lat * $rad));
		if ($cosH > 1 || $cosH < -1) return null; // sun never reaches zenith

		$H = $isRise ? (360 - acos($cosH) * $deg) : (acos($cosH) * $deg);
		$H = $H / 15.0;

		// 8. Local mean time
		$T = $H + $RA - (0.06571 * $tApprox) - 6.622;

		// 9. Adjust to UTC
		$UT = $T - $lngHour;
		$UT = self::normalize($UT, 24);

		// Build UTC datetime, then shift into requested timezone.
		$secs = (int)round($UT * 3600);
		$utcDate = new DateTime($date->format('Y-m-d') . ' 00:00:00', new DateTimeZone('UTC'));
		$utcDate->modify('+' . $secs . ' seconds');
		$utcDate->setTimezone($tz);
		return $utcDate;
	}

	private static function normalize($value, $period) {
		$value = fmod($value, $period);
		if ($value < 0) $value += $period;
		return $value;
	}
}
