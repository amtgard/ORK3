<?php

/**
 * Live attendance service — drives the rolling-24h "live view" dashboard.
 *
 * Two endpoints' worth of data:
 *   - stats(): per-park / per-event counts at three windows (day / 3h / 30m)
 *              plus park + event metadata for client-side rendering.
 *   - recent(): the most recent ~50 sign-ins, with first-ever flag, for the
 *               ticker.
 *
 * Time-zone note: ork_attendance.entered_at is DATETIME, written by PHP with
 * the Chicago default TZ, so digits are Chicago wall-clock. MySQL's NOW() is
 * UTC. We compute cutoffs in PHP (Chicago TZ) and pass them as literal strings
 * so the comparison is apples-to-apples.
 */
class Live extends Ork3 {

	const CACHE_TTL_STATS  = 30;   // seconds
	const CACHE_TTL_RECENT = 10;   // ticker can poll faster than the stats panel
	const RECENT_LIMIT     = 50;

	public function __construct() {
		parent::__construct();
	}

	/**
	 * Returns:
	 *   {
	 *     now:        'YYYY-MM-DD HH:MM:SS',         // chicago wall-clock at query time
	 *     active_3h:  <int>,                          // total signins in past 3h
	 *     parks:      { <park_id>: { name, kingdom, city, province, title, lat, lng, day, h3, m30 } },
	 *     events:     { <event_id>: { name, kingdom, lat, lng, coord_source, day, h3, m30 } }
	 *   }
	 */
	public function stats() {
		$key = Ork3::$Lib->ghettocache->key(array('stats'));
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.stats', $key, self::CACHE_TTL_STATS)) !== false) {
			return $cache;
		}

		$cutoff_24h = date('Y-m-d H:i:s', time() - 24 * 3600);
		$cutoff_3h  = date('Y-m-d H:i:s', time() -  3 * 3600);
		$cutoff_30m = date('Y-m-d H:i:s', time() -      1800);
		$now        = date('Y-m-d H:i:s');
		// Pre-filter on the indexed `date` column to avoid scanning 3M+ rows.
		// `entered_at` has no index; `date` does, so we narrow to the last two
		// calendar days first and then re-filter precisely by entered_at.
		$date_floor = date('Y-m-d', time() - 24 * 3600);

		$response = array(
			'now'       => $now,
			'active_3h' => 0,
			'parks'     => array(),
			'events'    => array(),
		);

		// Aggregate per (park_id, event_id). park_id=0 with an event_id rolls up
		// into events; park_id>0 rolls up into parks.
		$sql = "SELECT
			park_id,
			event_id,
			MAX(event_calendardetail_id) AS calendar_detail_id,
			COUNT(*)                                            AS day_count,
			SUM(CASE WHEN entered_at >= '" . $cutoff_3h  . "' THEN 1 ELSE 0 END) AS h3_count,
			SUM(CASE WHEN entered_at >= '" . $cutoff_30m . "' THEN 1 ELSE 0 END) AS m30_count
		FROM " . DB_PREFIX . "attendance
		WHERE date >= '" . $date_floor . "' AND entered_at >= '" . $cutoff_24h . "'
		GROUP BY park_id, event_id";

		$rs = $this->db->DataSet($sql);
		$park_ids  = array();
		$event_ids = array();
		if ($rs && $rs->Size() > 0) {
			while ($rs->Next()) {
				$pid = (int)$rs->park_id;
				$eid = (int)$rs->event_id;
				$day = (int)$rs->day_count;
				$h3  = (int)$rs->h3_count;
				$m30 = (int)$rs->m30_count;
				$response['active_3h'] += $h3;

				if ($pid > 0) {
					$response['parks'][$pid] = array(
						'day'  => $day,
						'h3'   => $h3,
						'm30'  => $m30,
					);
					$park_ids[] = $pid;
				} elseif ($eid > 0) {
					$response['events'][$eid] = array(
						'day'                => $day,
						'h3'                 => $h3,
						'm30'                => $m30,
						'calendar_detail_id' => (int)$rs->calendar_detail_id,
					);
					$event_ids[] = $eid;
				}
			}
		}

		// Hydrate park metadata
		if (!empty($park_ids)) {
			$ids_sql = implode(',', array_map('intval', $park_ids));
			$rs = $this->db->DataSet("
				SELECT p.park_id, p.name, p.latitude, p.longitude,
				       NULLIF(p.city, '') AS city, NULLIF(p.province, '') AS province,
				       pt.title, pt.class AS tier_class,
				       k.name AS kingdom_name
				FROM " . DB_PREFIX . "park p
				LEFT JOIN " . DB_PREFIX . "parktitle pt ON pt.parktitle_id = p.parktitle_id
				LEFT JOIN " . DB_PREFIX . "kingdom k   ON k.kingdom_id   = p.kingdom_id
				WHERE p.park_id IN ($ids_sql)");
			if ($rs && $rs->Size() > 0) {
				while ($rs->Next()) {
					$pid = (int)$rs->park_id;
					if (!isset($response['parks'][$pid])) continue;
					$response['parks'][$pid]['name']       = $rs->name;
					$response['parks'][$pid]['kingdom']    = $rs->kingdom_name;
					$response['parks'][$pid]['city']       = $rs->city;
					$response['parks'][$pid]['province']   = $rs->province;
					$response['parks'][$pid]['title']      = $rs->title;
					$response['parks'][$pid]['tier']       = (int)$rs->tier_class;
					$response['parks'][$pid]['lat']        = (float)$rs->latitude;
					$response['parks'][$pid]['lng']        = (float)$rs->longitude;
				}
			}
		}

		// Hydrate event metadata + resolve coords (own → at_park → none)
		if (!empty($event_ids)) {
			$ids_sql = implode(',', array_map('intval', $event_ids));
			$rs = $this->db->DataSet("
				SELECT e.event_id, e.name, k.name AS kingdom_name,
				       ecd.event_calendardetail_id, ecd.latitude AS ev_lat, ecd.longitude AS ev_lng,
				       ecd.at_park_id,
				       pat.latitude AS at_lat, pat.longitude AS at_lng
				FROM " . DB_PREFIX . "event e
				LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = e.kingdom_id
				LEFT JOIN " . DB_PREFIX . "event_calendardetail ecd ON ecd.event_id = e.event_id
				LEFT JOIN " . DB_PREFIX . "park pat ON pat.park_id = ecd.at_park_id
				WHERE e.event_id IN ($ids_sql)");
			if ($rs && $rs->Size() > 0) {
				// Multiple calendar_details per event may match — pick the one we
				// saw in the live signin stream (stored in calendar_detail_id).
				$by_eid = array();
				while ($rs->Next()) {
					$eid = (int)$rs->event_id;
					$cdid = (int)$rs->event_calendardetail_id;
					if (!isset($by_eid[$eid]) || $cdid === (int)($response['events'][$eid]['calendar_detail_id'] ?? 0)) {
						$by_eid[$eid] = array(
							'name'       => $rs->name,
							'kingdom'    => $rs->kingdom_name,
							'ev_lat'     => (float)$rs->ev_lat,
							'ev_lng'     => (float)$rs->ev_lng,
							'at_lat'     => $rs->at_lat !== null ? (float)$rs->at_lat : 0,
							'at_lng'     => $rs->at_lng !== null ? (float)$rs->at_lng : 0,
						);
					}
				}
				foreach ($by_eid as $eid => $em) {
					if (!isset($response['events'][$eid])) continue;
					$response['events'][$eid]['name']    = $em['name'];
					$response['events'][$eid]['kingdom'] = $em['kingdom'];
					if ($em['ev_lat'] != 0 && $em['ev_lng'] != 0) {
						$response['events'][$eid]['lat'] = $em['ev_lat'];
						$response['events'][$eid]['lng'] = $em['ev_lng'];
						$response['events'][$eid]['coord_source'] = 'own';
					} elseif ($em['at_lat'] != 0 && $em['at_lng'] != 0) {
						$response['events'][$eid]['lat'] = $em['at_lat'];
						$response['events'][$eid]['lng'] = $em['at_lng'];
						$response['events'][$eid]['coord_source'] = 'at_park';
					} else {
						$response['events'][$eid]['lat'] = 0;
						$response['events'][$eid]['lng'] = 0;
						$response['events'][$eid]['coord_source'] = 'none';
					}
				}
			}
		}

		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.stats', $key, $response);
	}

	/**
	 * Returns:
	 *   {
	 *     now:      'YYYY-MM-DD HH:MM:SS',
	 *     signins:  [ [ iso_time, park_id, event_id, calendar_detail_id, is_first_ever ], ... ],
	 *     parks:    { <park_id>:  { name, title } },     // for any park appearing in signins
	 *     events:   { <event_id>: { name } }              // for any event appearing in signins
	 *   }
	 *
	 * Parks/events maps included so the ticker can resolve names immediately
	 * even when /Live/stats (30s cache) hasn't refreshed to include a newly-
	 * active park yet.
	 */
	public function recent() {
		$key = Ork3::$Lib->ghettocache->key(array('recent'));
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.recent', $key, self::CACHE_TTL_RECENT)) !== false) {
			return $cache;
		}

		$cutoff_24h = date('Y-m-d H:i:s', time() - 24 * 3600);
		$now        = date('Y-m-d H:i:s');
		$limit      = (int)self::RECENT_LIMIT;
		// Pre-filter on the indexed `date` column (entered_at has no index).
		$date_floor = date('Y-m-d', time() - 24 * 3600);

		$rs = $this->db->DataSet("
			SELECT entered_at, park_id, event_id, event_calendardetail_id, mundane_id
			FROM " . DB_PREFIX . "attendance
			WHERE date >= '" . $date_floor . "' AND entered_at >= '" . $cutoff_24h . "'
			ORDER BY entered_at DESC
			LIMIT $limit");

		$signins = array();
		$mundane_ids = array();
		$park_ids    = array();
		$event_ids   = array();
		if ($rs && $rs->Size() > 0) {
			while ($rs->Next()) {
				$signins[] = array(
					'entered_at' => $rs->entered_at,
					'park_id'    => (int)$rs->park_id,
					'event_id'   => (int)$rs->event_id,
					'cdid'       => (int)$rs->event_calendardetail_id,
					'mundane_id' => (int)$rs->mundane_id,
				);
				$mundane_ids[(int)$rs->mundane_id] = 1;
				if ((int)$rs->park_id > 0)  $park_ids[(int)$rs->park_id]   = 1;
				if ((int)$rs->event_id > 0) $event_ids[(int)$rs->event_id] = 1;
			}
		}

		// Mark first-ever: each mundane's overall earliest signin equal to one in
		// the past 24h means they're new in this window.
		$first_ever = array();
		if (!empty($mundane_ids)) {
			$ids_sql = implode(',', array_keys($mundane_ids));
			$rs = $this->db->DataSet("
				SELECT mundane_id, MIN(entered_at) AS first_at
				FROM " . DB_PREFIX . "attendance
				WHERE mundane_id IN ($ids_sql)
				GROUP BY mundane_id
				HAVING first_at >= '" . $cutoff_24h . "'");
			if ($rs && $rs->Size() > 0) {
				while ($rs->Next()) {
					$first_ever[(int)$rs->mundane_id] = $rs->first_at;
				}
			}
		}

		// Minimal park/event metadata so the ticker can resolve names even when
		// /Live/stats hasn't refreshed yet to include a newly-active park.
		$parks_dict  = array();
		$events_dict = array();
		if (!empty($park_ids)) {
			$ids_sql = implode(',', array_keys($park_ids));
			$rs = $this->db->DataSet("
				SELECT p.park_id, p.name, pt.title
				FROM " . DB_PREFIX . "park p
				LEFT JOIN " . DB_PREFIX . "parktitle pt ON pt.parktitle_id = p.parktitle_id
				WHERE p.park_id IN ($ids_sql)");
			if ($rs && $rs->Size() > 0) {
				while ($rs->Next()) {
					$parks_dict[(int)$rs->park_id] = array(
						'name'  => $rs->name,
						'title' => $rs->title,
					);
				}
			}
		}
		if (!empty($event_ids)) {
			$ids_sql = implode(',', array_keys($event_ids));
			$rs = $this->db->DataSet("
				SELECT event_id, name FROM " . DB_PREFIX . "event WHERE event_id IN ($ids_sql)");
			if ($rs && $rs->Size() > 0) {
				while ($rs->Next()) {
					$events_dict[(int)$rs->event_id] = array('name' => $rs->name);
				}
			}
		}

		$out = array();
		foreach ($signins as $s) {
			$is_first = isset($first_ever[$s['mundane_id']]) && $first_ever[$s['mundane_id']] === $s['entered_at'] ? 1 : 0;
			// Compact tuple, mundane_id omitted on the wire (private)
			$out[] = array(
				gmdate('Y-m-d\TH:i:s\Z', strtotime($s['entered_at'])),
				$s['park_id'],
				$s['event_id'],
				$s['cdid'],
				$is_first,
			);
		}

		$response = array(
			'now'     => $now,
			'signins' => $out,
			'parks'   => $parks_dict,
			'events'  => $events_dict,
		);
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.recent', $key, $response);
	}
}
