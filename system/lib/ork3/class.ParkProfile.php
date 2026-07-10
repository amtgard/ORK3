<?php

/**
 * Park profile reads and AJAX helpers (DS-07 / R-07).
 * Controllers emit JSON/HTML only; SQL lives here.
 */
class ParkProfile extends Ork3
{
    /**
     * @param array{ParkId?: int, KingdomId?: int, MundaneId?: int, IsAdmin?: bool} $request
     * @return list<array<string, mixed>>
     */
    public function GetParkEventSummary(array $request): array
    {
        $bundle = $this->buildProfileEventBundle(
            (int) ($request['ParkId'] ?? 0),
            (int) ($request['KingdomId'] ?? 0),
            (int) ($request['MundaneId'] ?? 0),
            (bool) ($request['IsAdmin'] ?? false),
        );

        return $bundle['event_summary'];
    }

    /**
     * Profile page event bundle: summary rows, map pins, no-loc count.
     *
     * @return array{event_summary: list<array<string, mixed>>, pkEventMapLocations: list<array<string, mixed>>, pkEventMapNoLocCount: int}
     */
    public function buildProfileEventBundle(int $parkId, int $kingdomId, int $mundaneId, bool $isAdmin): array
    {
        $pid = (int) $parkId;
        $kid = (int) $kingdomId;
        $draftClause = $this->draftClause($mundaneId, $isAdmin);
        $myRsvpSubq = $mundaneId > 0
            ? '(SELECT status FROM ' . DB_PREFIX . 'event_rsvp WHERE event_calendardetail_id = cd.event_calendardetail_id AND mundane_id = ' . (int) $mundaneId . ' LIMIT 1)'
            : 'NULL';

        $evtSql = '
            SELECT e.event_id, e.name, e.status, e.mundane_id AS event_creator, p.name AS park_name,
                   cd.event_start, cd.event_end, cd.event_calendardetail_id AS next_detail_id, e.has_heraldry,
                   COALESCE(rsvp.rsvp_going, 0) AS rsvp_going,
                   COALESCE(rsvp.rsvp_interested, 0) AS rsvp_interested,
                   ' . $myRsvpSubq . ' AS my_rsvp
            FROM ' . DB_PREFIX . 'event e
            LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = e.park_id
            JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_id = e.event_id
                AND cd.event_start >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                AND cd.event_start <= DATE_ADD(NOW(), INTERVAL 12 MONTH)
            LEFT JOIN (
                SELECT event_calendardetail_id,
                    SUM(status = \'going\') AS rsvp_going,
                    SUM(status = \'interested\') AS rsvp_interested
                FROM ' . DB_PREFIX . 'event_rsvp
                GROUP BY event_calendardetail_id
            ) rsvp ON rsvp.event_calendardetail_id = cd.event_calendardetail_id
            WHERE (e.park_id = ' . $pid . ' OR cd.at_park_id = ' . $pid . ')
              ' . $draftClause . '
            ORDER BY cd.event_start, e.name';

        $this->db->Clear();
        $evtResult = $this->db->DataSet($evtSql);
        $eventSummary = [];
        while ($evtResult && $evtResult->Next()) {
            $eid = (int) ($evtResult->event_id ?? 0);
            if (!$eid) {
                continue;
            }
            $rowStatus = (string) ($evtResult->status ?? 'published');
            if (!$this->canSeeDraftEventRow($rowStatus, $mundaneId, $isAdmin, (int) $evtResult->event_creator, $eid)) {
                continue;
            }
            $eventSummary[] = [
                'EventId' => $eid,
                'Name' => $evtResult->name,
                'ParkName' => $evtResult->park_name,
                'NextDate' => $evtResult->event_start,
                'NextEndDate' => $evtResult->event_end,
                'NextDetailId' => (int) $evtResult->next_detail_id,
                'HasHeraldry' => (int) $evtResult->has_heraldry,
                'RsvpGoing' => (int) $evtResult->rsvp_going,
                'RsvpInterested' => (int) $evtResult->rsvp_interested,
                'MyRsvp' => (string) ($evtResult->my_rsvp ?? ''),
                'Status' => $rowStatus,
            ];
        }

        $eventSummary = array_merge($eventSummary, $this->calendarItemsForParkProfile($pid, $kid, $mundaneId));
        usort($eventSummary, static fn ($a, $b) => strcmp($a['NextDate'] ?? '', $b['NextDate'] ?? ''));

        [$pkEventMapLocations, $pkEventMapNoLocCount] = $this->buildParkMapLocations($eventSummary, $pid);

        return [
            'event_summary' => $eventSummary,
            'pkEventMapLocations' => $pkEventMapLocations,
            'pkEventMapNoLocCount' => $pkEventMapNoLocCount,
        ];
    }

    /**
     * Batch-fetch detail coords (avoids N+1 on park profile map).
     *
     * @param list<int> $detailIds
     * @return array<int, array{event_loc: string, at_park_lat: mixed, at_park_lng: mixed}>
     */
    public function GetBatchDetailCoords(array $detailIds): array
    {
        if ($detailIds === []) {
            return [];
        }

        $detailIdList = implode(',', array_map('intval', $detailIds));
        $this->db->Clear();
        $cdBatch = $this->db->DataSet(
            'SELECT cd.event_calendardetail_id, cd.location AS event_loc, cd.at_park_id,
                    p.latitude AS at_park_lat, p.longitude AS at_park_lng
             FROM ' . DB_PREFIX . 'event_calendardetail cd
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = cd.at_park_id
             WHERE cd.event_calendardetail_id IN (' . $detailIdList . ')'
        );
        $map = [];
        while ($cdBatch && $cdBatch->Next()) {
            $map[(int) $cdBatch->event_calendardetail_id] = [
                'event_loc' => (string) ($cdBatch->event_loc ?? ''),
                'at_park_lat' => $cdBatch->at_park_lat,
                'at_park_lng' => $cdBatch->at_park_lng,
            ];
        }

        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function GetParkPlayersRoster(int $parkId): array
    {
        $pid = (int) $parkId;
        $cacheKey = Ork3::$Lib->ghettocache->key(['ParkId' => $pid]);
        $cached = Ork3::$Lib->ghettocache->get('ParkProfile.GetParkPlayersRoster', $cacheKey, 1200);
        if ($cached !== false) {
            return $cached;
        }

        $rosterSql = "
            SELECT
                m.mundane_id,
                m.persona,
                m.has_image,
                m.has_heraldry,
                m.restricted,
                COALESCE(m.given_name, '') AS given_name,
                COALESCE(m.surname, '')    AS surname,
                COALESCE(sub.last_signin, '1970-01-01')         AS last_signin,
                COALESCE(sub.last_signin_at_park, '1970-01-01') AS last_signin_at_park,
                COUNT(DISTINCT a6.date) AS signin_count,
                c.name AS last_class,
                GROUP_CONCAT(DISTINCT o.role ORDER BY o.role SEPARATOR ', ') AS officer_roles
            FROM " . DB_PREFIX . 'mundane m
            LEFT JOIN (
                SELECT a.mundane_id,
                    MAX(a.date) AS last_signin,
                    MAX(CASE WHEN a.park_id = ' . $pid . ' THEN a.date END) AS last_signin_at_park
                FROM ' . DB_PREFIX . 'attendance a
                INNER JOIN ' . DB_PREFIX . 'mundane mm
                    ON mm.mundane_id = a.mundane_id
                   AND mm.park_id = ' . $pid . '
                   AND mm.suspended = 0 AND mm.active = 1
                GROUP BY a.mundane_id
            ) sub ON sub.mundane_id = m.mundane_id
            LEFT JOIN ' . DB_PREFIX . 'attendance a6 ON a6.mundane_id = m.mundane_id
                AND a6.date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            LEFT JOIN ' . DB_PREFIX . 'attendance la ON la.mundane_id = m.mundane_id
                AND la.park_id = ' . $pid . '
                AND la.date    = sub.last_signin_at_park
            LEFT JOIN ' . DB_PREFIX . 'class c ON la.class_id = c.class_id
            LEFT JOIN ' . DB_PREFIX . 'officer o ON o.mundane_id = m.mundane_id AND o.park_id = ' . $pid . "
            WHERE m.park_id = {$pid}
              AND m.suspended = 0
              AND m.active = 1
            GROUP BY m.mundane_id
            ORDER BY m.persona";

        $this->db->Clear();
        $rosterResult = $this->db->DataSet($rosterSql);
        $players = [];
        while ($rosterResult && $rosterResult->Next()) {
            $mid = (int) $rosterResult->mundane_id;
            if ($mid <= 0) {
                continue;
            }
            $mn = ((int) $rosterResult->restricted === 0) ? trim($rosterResult->given_name . ' ' . $rosterResult->surname) : '';
            $players[] = [
                'MundaneId' => $mid,
                'Persona' => $rosterResult->persona,
                'MundaneName' => $mn,
                'HasImage' => (int) $rosterResult->has_image > 0,
                'HasHeraldry' => (int) $rosterResult->has_heraldry > 0,
                'SigninCount' => (int) $rosterResult->signin_count,
                'LastSignin' => $rosterResult->last_signin,
                'LastSigninAtPark' => $rosterResult->last_signin_at_park,
                'LastClass' => $rosterResult->last_class,
                'OfficerRoles' => $rosterResult->officer_roles,
            ];
        }

        Ork3::$Lib->ghettocache->cache('ParkProfile.GetParkPlayersRoster', $cacheKey, $players);

        return $players;
    }

    /**
     * @return array{MonthlyAvg: float, WeeklyAvg: float}
     */
    public function GetParkAttendanceAverages(int $parkId): array
    {
        $pid = (int) $parkId;
        $monthlyAvg = 0.0;
        $this->db->Clear();
        $maResult = $this->db->DataSet(
            'SELECT AVG(monthly_unique) AS avg_per_month FROM (
                SELECT COUNT(DISTINCT a.mundane_id) AS monthly_unique
                FROM ' . DB_PREFIX . 'attendance a
                WHERE a.park_id = ' . $pid . '
                  AND a.date > DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
                  AND a.mundane_id > 0
                GROUP BY a.date_year, a.date_month
            ) sub'
        );
        if ($maResult && $maResult->Next()) {
            $_avg = (float) $maResult->avg_per_month;
            if ($_avg > 0) {
                $monthlyAvg = round($_avg, 1);
            }
        }

        $wkStart = date('Y-m-d', strtotime('-6 month'));
        $wkEnd = date('Y-m-d');
        $wkCount = max(1, (int) ceil((strtotime($wkEnd) - strtotime($wkStart)) / (7 * 86400)));
        $weeklyAvg = 0.0;
        $escapedWkStart = mysql_real_escape_string($wkStart);
        $escapedWkEnd = mysql_real_escape_string($wkEnd);
        $this->db->Clear();
        $waResult = $this->db->DataSet(
            'SELECT COUNT(*) AS player_weeks FROM (
                SELECT a.mundane_id
                FROM ' . DB_PREFIX . 'attendance a
                WHERE a.park_id = ' . $pid . "
                  AND a.date >= '{$escapedWkStart}'
                  AND a.date <= '{$escapedWkEnd}'
                  AND a.mundane_id > 0
                GROUP BY a.date_year, a.date_week3, a.mundane_id
            ) sub"
        );
        if ($waResult && $waResult->Next()) {
            $_wk = (int) $waResult->player_weeks;
            if ($_wk > 0) {
                $weeklyAvg = round($_wk / $wkCount, 2);
            }
        }

        return ['MonthlyAvg' => $monthlyAvg, 'WeeklyAvg' => $weeklyAvg];
    }

    public function CheckParkAbbreviationTaken(int $kingdomId, string $abbr, int $excludeParkId = 0): bool
    {
        $abbr = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($abbr)));
        if ($abbr === '') {
            return false;
        }
        $excludeClause = $excludeParkId > 0 ? ' AND park_id != ' . (int) $excludeParkId : '';
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT park_id FROM ' . DB_PREFIX . "park WHERE abbreviation = '{$abbr}' AND kingdom_id = " . (int) $kingdomId . "{$excludeClause} LIMIT 1"
        );

        return (bool) ($rs && $rs->Next());
    }

    private function draftClause(int $mundaneId, bool $isAdmin): string
    {
        if ($isAdmin || $mundaneId === 0) {
            return $isAdmin ? '' : "AND e.status = 'published'";
        }

        return "AND (e.status = 'published' OR e.mundane_id = {$mundaneId} OR EXISTS (SELECT 1 FROM "
            . DB_PREFIX . 'event_staff es JOIN ' . DB_PREFIX
            . 'event_calendardetail cds ON cds.event_calendardetail_id = es.event_calendardetail_id WHERE cds.event_id = e.event_id AND es.mundane_id = '
            . $mundaneId . '))';
    }

    private function canSeeDraftEventRow(string $rowStatus, int $mundaneId, bool $isAdmin, int $creatorId, int $eventId): bool
    {
        if ($rowStatus === 'published' || $isAdmin || $creatorId === $mundaneId) {
            return true;
        }
        $canEditRow = $mundaneId > 0 && Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_EVENT, $eventId, AUTH_EDIT);
        if (!$canEditRow && $mundaneId > 0) {
            $this->db->Clear();
            $_staffRow = $this->db->DataSet(
                'SELECT 1 FROM ' . DB_PREFIX . 'event_staff es JOIN ' . DB_PREFIX
                . 'event_calendardetail cds ON cds.event_calendardetail_id = es.event_calendardetail_id WHERE cds.event_id = '
                . $eventId . ' AND es.mundane_id = ' . $mundaneId . ' LIMIT 1'
            );
            $canEditRow = (bool) ($_staffRow && $_staffRow->Next());
        }

        return $canEditRow;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function calendarItemsForParkProfile(int $parkId, int $kingdomId, int $mundaneId): array
    {
        $pid = (int) $parkId;
        $kid = (int) $kingdomId;
        $ciSql = "
            SELECT ci.calendar_item_id, ci.name, ci.description, ci.all_day, ci.is_officer_only, ci.is_locals_only, ci.color,
                   ci.event_start, ci.event_end, ci.park_id, ci.kingdom_id,
                   p.name AS park_name, p.abbreviation AS park_abbr, k.abbreviation AS kingdom_abbr
            FROM " . DB_PREFIX . 'calendar_item ci
            LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = ci.park_id
            LEFT JOIN ' . DB_PREFIX . 'kingdom k ON k.kingdom_id = ci.kingdom_id
            WHERE (ci.park_id = ' . $pid . ' OR (ci.park_id = 0 AND ci.kingdom_id = ' . $kid . '))
              AND ci.event_end >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              AND ci.event_start <= DATE_ADD(NOW(), INTERVAL 12 MONTH)
            ORDER BY ci.event_start';
        $this->db->Clear();
        $ciResult = $this->db->DataSet($ciSql);
        $items = [];
        while ($ciResult && $ciResult->Next()) {
            $ci_isOfficerOnly = (int) $ciResult->is_officer_only;
            $ci_isLocalsOnly = (int) $ciResult->is_locals_only;
            if (!CalendarItem::CanSee($mundaneId, (int) $ciResult->kingdom_id, (int) $ciResult->park_id, $ci_isOfficerOnly, $ci_isLocalsOnly)) {
                continue;
            }
            $items[] = [
                'CalendarItemId' => (int) $ciResult->calendar_item_id,
                'Name' => $ciResult->name,
                'ParkName' => $ciResult->park_name,
                'ParkAbbr' => $ciResult->park_abbr,
                'KingdomAbbr' => $ciResult->kingdom_abbr,
                'NextDate' => $ciResult->event_start,
                'NextEndDate' => $ciResult->event_end,
                'AllDay' => (int) $ciResult->all_day,
                'Description' => $ciResult->description,
                'IsOfficerOnly' => $ci_isOfficerOnly,
                'IsLocalsOnly' => $ci_isLocalsOnly,
                'Color' => $ciResult->color ?: '#64748b',
                'ColorText' => CalendarItem::TextColorFor($ciResult->color ?: '#64748b'),
                '_IsCalendarItem' => true,
                '_IsKingdomLevel' => (int) $ciResult->park_id === 0,
            ];
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $eventSummary
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function buildParkMapLocations(array $eventSummary, int $parkId): array
    {
        $nowStamp = time();
        $horizonStamp = $nowStamp + (90 * 86400);
        $pkEventMapLocs = [];
        $pkMapNoLocCount = 0;
        $mapDetailIds = [];
        foreach ($eventSummary as $_evt) {
            if (empty($_evt['EventId'])) {
                continue;
            }
            $startTs = strtotime($_evt['NextDate'] ?? '');
            if (!$startTs || $startTs > $horizonStamp) {
                continue;
            }
            $mapDetailIds[] = (int) ($_evt['NextDetailId'] ?? 0);
        }

        $cdCoordMap = $this->GetBatchDetailCoords($mapDetailIds);

        $hostLat = null;
        $hostLng = null;
        $this->db->Clear();
        $hostRow = $this->db->DataSet(
            'SELECT latitude, longitude FROM ' . DB_PREFIX . 'park WHERE park_id = ' . (int) $parkId . ' LIMIT 1'
        );
        if ($hostRow && $hostRow->Next() && is_numeric($hostRow->latitude) && (float) $hostRow->latitude != 0) {
            $hostLat = (float) $hostRow->latitude;
            $hostLng = (float) $hostRow->longitude;
        }

        foreach ($eventSummary as $_evt) {
            if (empty($_evt['EventId'])) {
                continue;
            }
            $startTs = strtotime($_evt['NextDate'] ?? '');
            if (!$startTs || $startTs > $horizonStamp) {
                continue;
            }

            $lat = null;
            $lng = null;
            $detailId = (int) ($_evt['NextDetailId'] ?? 0);
            if (isset($cdCoordMap[$detailId])) {
                $cdData = $cdCoordMap[$detailId];
                $rawLoc = $cdData['event_loc'];
                if ($rawLoc) {
                    $loc = @json_decode(stripslashes($rawLoc));
                    if ($loc) {
                        $pt = isset($loc->location) ? $loc->location
                            : (isset($loc->bounds->northeast) ? $loc->bounds->northeast : null);
                        if ($pt && is_numeric($pt->lat ?? null) && is_numeric($pt->lng ?? null)) {
                            $lat = (float) $pt->lat;
                            $lng = (float) $pt->lng;
                        }
                    }
                }
                if ($lat === null && is_numeric($cdData['at_park_lat']) && is_numeric($cdData['at_park_lng'])
                    && (float) $cdData['at_park_lat'] != 0) {
                    $lat = (float) $cdData['at_park_lat'];
                    $lng = (float) $cdData['at_park_lng'];
                }
            }

            if ($lat === null && $hostLat !== null) {
                $lat = $hostLat;
                $lng = $hostLng;
            }

            if ($lat !== null && $startTs >= ($nowStamp - 86400)) {
                $pkEventMapLocs[] = [
                    'event_id' => (int) $_evt['EventId'],
                    'event_calendardetail_id' => $detailId,
                    'name' => $_evt['Name'],
                    'date' => date('Y-m-d', $startTs),
                    'date_label' => date('M j, Y', $startTs),
                    'park_name' => $_evt['ParkName'] ?? '',
                    'lat' => $lat,
                    'lng' => $lng,
                    'my_rsvp' => $_evt['MyRsvp'] ?? '',
                    'going' => (int) ($_evt['RsvpGoing'] ?? 0),
                    'interested' => (int) ($_evt['RsvpInterested'] ?? 0),
                    'is_draft' => (($_evt['Status'] ?? 'published') === 'draft'),
                ];
            } elseif ($lat === null && $startTs <= $horizonStamp && $startTs >= ($nowStamp - 86400)) {
                $pkMapNoLocCount++;
            }
        }

        return [$pkEventMapLocs, $pkMapNoLocCount];
    }
}
