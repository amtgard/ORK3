<?php

/**
 * Kingdom profile reads and AJAX helpers (DS-06 / R-06).
 * Controllers emit JSON/HTML only; SQL lives here.
 */
class KingdomProfile extends Ork3
{
    private function statsKingdom(): Kingdom
    {
        return Ork3::$Lib->kingdom;
    }

    /**
     * @return array{monarch: int, regent: int}
     */
    public function GetRoyalOfficerIds(int $kingdomId): array
    {
        $monarchId = 0;
        $regentId = 0;
        $kid = (int) $kingdomId;
        $this->db->Clear();
        $mRes = $this->db->DataSet(
            'SELECT mundane_id FROM ' . DB_PREFIX . "officer WHERE kingdom_id = {$kid} AND park_id = 0 AND role = 'Monarch' AND mundane_id > 0 ORDER BY officer_id DESC LIMIT 1"
        );
        if ($mRes && $mRes->Next()) {
            $monarchId = (int) $mRes->mundane_id;
        }
        $this->db->Clear();
        $rRes = $this->db->DataSet(
            'SELECT mundane_id FROM ' . DB_PREFIX . "officer WHERE kingdom_id = {$kid} AND park_id = 0 AND role = 'Regent' AND mundane_id > 0 ORDER BY officer_id DESC LIMIT 1"
        );
        if ($rRes && $rRes->Next()) {
            $regentId = (int) $rRes->mundane_id;
        }

        return ['monarch' => $monarchId, 'regent' => $regentId];
    }

    public function GetParkKingdomId(int $parkId): int
    {
        if ($parkId <= 0) {
            return 0;
        }
        $this->db->Clear();
        $row = $this->db->DataSet(
            'SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ' . (int) $parkId . ' LIMIT 1'
        );

        return ($row && $row->Next()) ? (int) $row->kingdom_id : 0;
    }

    public function GetKingdomPlayerCount(int $kingdomId): int
    {
        $kid = (int) $kingdomId;
        $cacheKey = Ork3::$Lib->ghettocache->key(['KingdomId' => $kid]);
        $cached = Ork3::$Lib->ghettocache->get('KingdomProfile.player_count', $cacheKey, 600);
        if ($cached !== false) {
            return (int) $cached;
        }

        $this->db->Clear();
        $row = $this->db->DataSet(
            'SELECT COUNT(*) AS n FROM ' . DB_PREFIX . 'mundane m
             INNER JOIN ' . DB_PREFIX . 'park p ON p.park_id = m.park_id AND p.kingdom_id = ' . $kid . '
             WHERE m.suspended = 0 AND m.active = 1'
        );
        $count = ($row && $row->Next()) ? (int) $row->n : 0;
        Ork3::$Lib->ghettocache->cache('KingdomProfile.player_count', $cacheKey, $count);

        return $count;
    }

    public function GetUserHomeParkId(int $mundaneId): int
    {
        if ($mundaneId <= 0) {
            return 0;
        }
        $this->db->Clear();
        $upRow = $this->db->DataSet(
            'SELECT park_id FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $mundaneId . ' LIMIT 1'
        );

        return ($upRow && $upRow->Next() && $upRow->park_id) ? (int) $upRow->park_id : 0;
    }

    public function HasParkCreateAuthInKingdom(int $mundaneId, int $kingdomId): bool
    {
        if ($mundaneId <= 0 || $kingdomId <= 0) {
            return false;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . "authorization a
             JOIN " . DB_PREFIX . "park p ON p.park_id = a.park_id AND p.active = 'Active'
             WHERE a.mundane_id = " . (int) $mundaneId . "
               AND a.role = '" . AUTH_CREATE . "'
               AND p.kingdom_id = " . (int) $kingdomId . '
             LIMIT 1'
        );

        return (bool) ($rs && $rs->Next());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function GetKingdomParkDays(int $kingdomId): array
    {
        $kid = (int) $kingdomId;
        $this->db->Clear();
        $pdResult = $this->db->DataSet(
            "SELECT pd.parkday_id, pd.park_id, pd.recurrence, pd.week_day,
                    pd.week_of_month, pd.month_day, pd.start_date, pd.week_interval, pd.time, pd.purpose,
                    p.name AS park_name, p.abbreviation AS park_abbr
             FROM " . DB_PREFIX . 'parkday pd
             JOIN ' . DB_PREFIX . "park p ON p.park_id = pd.park_id
             WHERE p.kingdom_id = {$kid} AND p.active = 'Active'
             ORDER BY p.name, pd.week_day, pd.time"
        );
        $parkDays = [];
        while ($pdResult && $pdResult->Next()) {
            $parkDays[] = $this->formatParkDayRow($pdResult);
        }

        return $parkDays;
    }

    /**
     * @return array{players: list<array<string, mixed>>}
     */
    public function GetKingdomPlayersRoster(int $kingdomId): array
    {
        $kid = (int) $kingdomId;
        $cacheKey = Ork3::$Lib->ghettocache->key(['KingdomId' => $kid]);
        $cached = Ork3::$Lib->ghettocache->get('KingdomProfile.GetKingdomPlayersRoster', $cacheKey, 1200);
        if ($cached !== false) {
            return $cached;
        }

        $kpSql = "SELECT m.mundane_id, m.persona, m.has_image, m.has_heraldry, m.restricted,
                COALESCE(m.given_name, '')                          AS given_name,
                COALESCE(m.surname, '')                             AS surname,
                COALESCE(sub.last_signin, '1970-01-01')             AS last_signin,
                COALESCE(sub.signin_count, 0)                       AS signin_count,
                c.name                                              AS last_class,
                hp.name                                             AS park_name,
                GROUP_CONCAT(DISTINCT o.role ORDER BY o.role SEPARATOR ', ') AS officer_roles
            FROM " . DB_PREFIX . 'mundane m
            INNER JOIN ' . DB_PREFIX . "park hp ON hp.park_id = m.park_id AND hp.kingdom_id = {$kid}
            LEFT JOIN (
                SELECT a.mundane_id,
                    MAX(a.date) AS last_signin,
                    MAX(CASE WHEN a.kingdom_id = {$kid} THEN a.date END) AS last_signin_in_kingdom,
                    SUM(a.date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)) AS signin_count
                FROM " . DB_PREFIX . 'attendance a
                INNER JOIN ' . DB_PREFIX . "mundane mm
                    ON mm.mundane_id = a.mundane_id
                   AND mm.kingdom_id = {$kid}
                   AND mm.suspended = 0 AND mm.active = 1
                GROUP BY a.mundane_id
            ) sub ON sub.mundane_id = m.mundane_id
            LEFT JOIN " . DB_PREFIX . 'attendance la
                ON la.mundane_id = m.mundane_id
               AND la.date       = sub.last_signin_in_kingdom
               AND la.kingdom_id = ' . $kid . '
            LEFT JOIN ' . DB_PREFIX . 'class c ON la.class_id = c.class_id
            LEFT JOIN ' . DB_PREFIX . 'officer o ON o.mundane_id = m.mundane_id AND o.park_id = m.park_id
            WHERE m.suspended = 0 AND m.active = 1
            GROUP BY m.mundane_id
            ORDER BY m.persona';

        $this->db->Clear();
        $r = $this->db->DataSet($kpSql);
        $players = [];
        while ($r && $r->Next()) {
            $mid = (int) $r->mundane_id;
            $midPad = sprintf('%06d', $mid);
            $hasImg = (int) $r->has_image > 0;
            $hasHer = (int) $r->has_heraldry > 0;
            $herUrl = $hasHer ? HTTP_PLAYER_HERALDRY . Common::resolve_image_ext(DIR_PLAYER_HERALDRY, $midPad) : null;
            $imgUrl = $hasImg ? HTTP_PLAYER_IMAGE . Common::resolve_image_ext(DIR_PLAYER_IMAGE, $midPad) : ($hasHer ? $herUrl : null);
            $mn = ((int) $r->restricted === 0) ? trim($r->given_name . ' ' . $r->surname) : '';
            $players[] = [
                'id' => $mid,
                'persona' => $r->persona,
                'mundaneName' => $mn,
                'parkName' => $r->park_name,
                'signinCount' => (int) $r->signin_count,
                'lastSignin' => $r->last_signin,
                'lastClass' => $r->last_class,
                'officerRoles' => $r->officer_roles,
                'avatarUrl' => $imgUrl,
                'heraldryUrl' => $herUrl,
            ];
        }

        $payload = ['players' => $players];
        Ork3::$Lib->ghettocache->cache('KingdomProfile.GetKingdomPlayersRoster', $cacheKey, $payload);

        return $payload;
    }

    /**
     * @param array{KingdomId?: int, MundaneId?: int, IsAdmin?: bool} $request
     * @return list<array<string, mixed>>
     */
    public function GetKingdomEventSummary(array $request): array
    {
        $bundle = $this->buildProfileEventBundle(
            (int) ($request['KingdomId'] ?? 0),
            (int) ($request['MundaneId'] ?? 0),
            (bool) ($request['IsAdmin'] ?? false),
            includeCalendarItems: true,
            includeMapCoords: false,
        );

        return $bundle['event_summary'];
    }

    /**
     * Profile page event bundle: summary rows, map pins, has-more flag.
     *
     * @return array{event_summary: list<array<string, mixed>>, knEventMapLocations: list<array<string, mixed>>, knEventMapNoLocCount: int, HasMoreEvents: bool}
     */
    public function buildProfileEventBundle(int $kingdomId, int $mundaneId, bool $isAdmin, bool $includeCalendarItems = true, bool $includeMapCoords = true): array
    {
        $kid = (int) $kingdomId;
        $statsEvtKids = implode(',', array_map('intval', $this->statsKingdom()->GetStatsKingdomIds($kid)));
        $royals = $this->GetRoyalOfficerIds($kid);
        $monarchId = $royals['monarch'];
        $regentId = $royals['regent'];

        if ($monarchId > 0 || $regentId > 0) {
            $royalIds = implode(',', array_filter([$monarchId, $regentId], static fn ($id) => $id > 0));
            $royalSelectCols = 'COALESCE(royal.monarch_rsvp,0) AS monarch_rsvp, COALESCE(royal.regent_rsvp,0) AS regent_rsvp';
            $royalJoinSql = 'LEFT JOIN (SELECT event_calendardetail_id, SUM(mundane_id = ' . $monarchId . ') AS monarch_rsvp, SUM(mundane_id = ' . $regentId . ') AS regent_rsvp FROM ' . DB_PREFIX . 'event_rsvp WHERE mundane_id IN (' . $royalIds . ') GROUP BY event_calendardetail_id) royal ON royal.event_calendardetail_id = cd.event_calendardetail_id';
        } else {
            $royalSelectCols = '0 AS monarch_rsvp, 0 AS regent_rsvp';
            $royalJoinSql = '';
        }

        $draftClause = $this->draftClause($mundaneId, $isAdmin);
        $myRsvpSubq = $mundaneId > 0
            ? '(SELECT status FROM ' . DB_PREFIX . 'event_rsvp WHERE event_calendardetail_id = cd.event_calendardetail_id AND mundane_id = ' . (int) $mundaneId . ' LIMIT 1)'
            : 'NULL';

        $evtSql = '
            SELECT e.event_id, e.name, e.park_id, e.status, e.mundane_id AS event_creator,
                   p.name AS park_name, p.abbreviation AS park_abbr,
                   cd.event_start, cd.event_calendardetail_id AS next_detail_id, e.has_heraldry,
                   COALESCE(rsvp.rsvp_going, 0) AS rsvp_going,
                   COALESCE(rsvp.rsvp_interested, 0) AS rsvp_interested,
                   ' . $royalSelectCols . ',
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
            ' . $royalJoinSql . '
            WHERE e.kingdom_id IN (' . $statsEvtKids . ')
              ' . $draftClause . '
            ORDER BY cd.event_start, p.name, e.name';

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
                'NextDetailId' => (int) $evtResult->next_detail_id,
                'HasHeraldry' => (int) $evtResult->has_heraldry,
                'ParkAbbr' => $evtResult->park_abbr,
                'RsvpGoing' => (int) $evtResult->rsvp_going,
                'RsvpInterested' => (int) $evtResult->rsvp_interested,
                'MonarchRsvp' => (int) $evtResult->monarch_rsvp,
                'RegentRsvp' => (int) $evtResult->regent_rsvp,
                'MyRsvp' => (string) ($evtResult->my_rsvp ?? ''),
                'Status' => $rowStatus,
                '_IsParkEvent' => (int) $evtResult->park_id > 0,
            ];
        }

        if ($includeCalendarItems) {
            $eventSummary = array_merge($eventSummary, $this->calendarItemsForProfile($kid, $mundaneId));
            usort($eventSummary, static fn ($a, $b) => strcmp($a['NextDate'] ?? '', $b['NextDate'] ?? ''));
        }

        $knEventMapLocs = [];
        $knMapNoLocCount = 0;
        if ($includeMapCoords) {
            [$knEventMapLocs, $knMapNoLocCount] = $this->buildMapLocations($eventSummary);
        }

        $this->db->Clear();
        $moreRes = $this->db->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . 'event_calendardetail cd
             JOIN ' . DB_PREFIX . 'event e ON e.event_id = cd.event_id
             WHERE e.kingdom_id IN (' . $statsEvtKids . ')
               AND cd.event_start >  DATE_ADD(NOW(), INTERVAL 12 MONTH)
               AND cd.event_start <= DATE_ADD(NOW(), INTERVAL 120 MONTH)
             LIMIT 1'
        );
        $hasMore = (bool) ($moreRes && $moreRes->Size() > 0);

        return [
            'event_summary' => $eventSummary,
            'knEventMapLocations' => $knEventMapLocs,
            'knEventMapNoLocCount' => $knMapNoLocCount,
            'HasMoreEvents' => $hasMore,
        ];
    }

    /**
     * @return array{Window: int, StartMonths: int, EndMonths: int, Count: int, HasMore: bool, FallbackHeraldry: string, Uir: string, Events: list<array<string, mixed>>}
     */
    public function GetPaginatedKingdomEvents(int $kingdomId, int $window): array
    {
        $kid = (int) $kingdomId;
        if ($window < 1) {
            $window = 1;
        }
        if ($window > 10) {
            $window = 10;
        }
        $startMonths = $window * 12;
        $endMonths = $startMonths + 12;
        $statsEvtKids = implode(',', array_map('intval', $this->statsKingdom()->GetStatsKingdomIds($kid)));
        $fallbackHeraldry = HTTP_EVENT_HERALDRY . '00000.jpg';

        $evtSql = 'SELECT e.event_id, e.name, e.park_id, p.name AS park_name, p.abbreviation AS park_abbr,
                   cd.event_start, cd.event_calendardetail_id AS next_detail_id, e.has_heraldry,
                   (SELECT COUNT(*) FROM ' . DB_PREFIX . 'event_rsvp WHERE event_calendardetail_id = cd.event_calendardetail_id AND status = \'going\') AS rsvp_going,
                   (SELECT COUNT(*) FROM ' . DB_PREFIX . 'event_rsvp WHERE event_calendardetail_id = cd.event_calendardetail_id AND status = \'interested\') AS rsvp_interested
            FROM ' . DB_PREFIX . 'event e
            LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = e.park_id
            JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_id = e.event_id
                AND cd.event_start >  DATE_ADD(NOW(), INTERVAL ' . $startMonths . ' MONTH)
                AND cd.event_start <= DATE_ADD(NOW(), INTERVAL ' . $endMonths . ' MONTH)
            WHERE e.kingdom_id IN (' . $statsEvtKids . ')
            ORDER BY cd.event_start, p.name, e.name';

        $this->db->Clear();
        $evtResult = $this->db->DataSet($evtSql);
        $events = [];
        while ($evtResult && $evtResult->Next()) {
            $eid = (int) ($evtResult->event_id ?? 0);
            if (!$eid) {
                continue;
            }
            $start = $evtResult->event_start;
            $events[] = [
                'EventId' => $eid,
                'Name' => $evtResult->name,
                'ParkName' => $evtResult->park_name,
                'NextDate' => $start,
                'NextDateText' => ($start && $start !== '0000-00-00 00:00:00' && $start !== '0000-00-00')
                    ? date('M j, Y', strtotime($start)) : '',
                'NextDetailId' => (int) $evtResult->next_detail_id,
                'HasHeraldry' => (int) $evtResult->has_heraldry,
                'HeraldryUrl' => ((int) $evtResult->has_heraldry === 1)
                    ? HTTP_EVENT_HERALDRY . Common::resolve_image_ext(DIR_EVENT_HERALDRY, sprintf('%05d', $eid))
                    : $fallbackHeraldry,
                'ParkAbbr' => $evtResult->park_abbr,
                'RsvpGoing' => (int) $evtResult->rsvp_going,
                'RsvpInterested' => (int) $evtResult->rsvp_interested,
                'IsParkEvent' => (int) $evtResult->park_id > 0,
            ];
        }

        $hasMore = false;
        if ($window < 10) {
            $_nextStart = $endMonths;
            $this->db->Clear();
            $_more = $this->db->DataSet(
                'SELECT 1 FROM ' . DB_PREFIX . 'event_calendardetail cd
                 JOIN ' . DB_PREFIX . 'event e ON e.event_id = cd.event_id
                 WHERE e.kingdom_id IN (' . $statsEvtKids . ')
                   AND cd.event_start >  DATE_ADD(NOW(), INTERVAL ' . $_nextStart . ' MONTH)
                   AND cd.event_start <= DATE_ADD(NOW(), INTERVAL 120 MONTH)
                 LIMIT 1'
            );
            $hasMore = (bool) ($_more && $_more->Size() > 0);
        }

        return [
            'Window' => $window,
            'StartMonths' => $startMonths,
            'EndMonths' => $endMonths,
            'Count' => count($events),
            'HasMore' => $hasMore,
            'FallbackHeraldry' => $fallbackHeraldry,
            'Uir' => UIR,
            'Events' => $events,
        ];
    }

    public function ExportKingdomEventsIcs(int $kingdomId, string $kingdomName = ''): string
    {
        $kid = (int) $kingdomId;
        $statsEvtKids = implode(',', array_map('intval', $this->statsKingdom()->GetStatsKingdomIds($kid)));
        if ($kingdomName === '') {
            $details = $this->statsKingdom()->GetKingdomShortInfo(['KingdomId' => $kid]);
            $kingdomName = (string) ($details['KingdomInfo']['KingdomName'] ?? 'Kingdom');
            if ($kingdomName === '') {
                $kingdomName = 'Kingdom';
            }
        }

        $sql = 'SELECT e.event_id, e.name, p.name AS park_name,
                   cd.event_calendardetail_id, cd.event_start, cd.event_end,
                   cd.description, cd.url,
                   cd.address, cd.city, cd.province, cd.postal_code, cd.country
            FROM ' . DB_PREFIX . 'event e
            LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = e.park_id
            JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_id = e.event_id
                AND cd.event_start >= CURDATE()
                AND cd.event_start <= DATE_ADD(NOW(), INTERVAL 12 MONTH)
            WHERE e.kingdom_id IN (' . $statsEvtKids . ')
            ORDER BY cd.event_start ASC';

        $this->db->Clear();
        $result = $this->db->DataSet($sql);

        $lines = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:-//ORK3//Amtgard ORK//EN';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';
        $lines[] = self::icsFold('X-WR-CALNAME:' . self::icsEscape($kingdomName) . ' Events');

        while ($result && $result->Next()) {
            if ((int) $result->event_calendardetail_id === 0) {
                continue;
            }
            $dtstart = self::icsDt($result->event_start);
            $rawEnd = $result->event_end;
            $dtend = (!empty($rawEnd) && $rawEnd !== '0000-00-00 00:00:00')
                ? self::icsDt($rawEnd)
                : self::icsDtPlus1hr($result->event_start);

            $uid = 'event-' . (int) $result->event_id . '-' . (int) $result->event_calendardetail_id . '@ork3';
            $location = self::icsLocation($result->address, $result->city, $result->province, $result->postal_code, $result->country);
            $dtstamp = gmdate('Ymd\THis\Z');

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = self::icsFold('UID:' . $uid);
            $lines[] = 'DTSTAMP:' . $dtstamp;
            $lines[] = 'DTSTART:' . $dtstart;
            $lines[] = 'DTEND:' . $dtend;
            $lines[] = self::icsFold('SUMMARY:' . self::icsEscape($result->name));
            if (!empty($result->description)) {
                $lines[] = self::icsFold('DESCRIPTION:' . self::icsEscape(strip_tags($result->description)));
            }
            if (!empty($location)) {
                $lines[] = self::icsFold('LOCATION:' . self::icsEscape($location));
            }
            if (!empty($result->url)) {
                $lines[] = self::icsFold('URL:' . self::icsEscape(preg_replace('/[\r\n]/', '', $result->url)));
            }
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    public function AuthorizeMovePlayer(int $uid, int $playerKingdomId, int $destKingdomId): bool
    {
        $canSource = $playerKingdomId > 0
            && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $playerKingdomId, AUTH_EDIT);
        $canDest = $destKingdomId > 0
            && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $destKingdomId, AUTH_EDIT);

        return $canSource || $canDest;
    }

    public function SetAwardRecsPublic(int $kingdomId, bool $public): void
    {
        $value = json_encode($public ? '1' : '0');
        $kid = (int) $kingdomId;
        $this->db->Clear();
        $existing = $this->db->DataSet(
            'SELECT configuration_id FROM ' . DB_PREFIX . "configuration WHERE type='Kingdom' AND id={$kid} AND `key`='AwardRecsPublic' LIMIT 1"
        );
        if ($existing && $existing->Next()) {
            $cid = (int) $existing->configuration_id;
            $this->db->Clear();
            $this->db->Execute(
                'UPDATE ' . DB_PREFIX . "configuration SET value='" . $value . "', modified=NOW() WHERE configuration_id={$cid}"
            );
        } else {
            $this->db->Clear();
            $this->db->Execute(
                'INSERT INTO ' . DB_PREFIX . "configuration (type, var_type, id, `key`, value, user_setting, allowed_values, modified)
                 VALUES ('Kingdom', 'fixed', {$kid}, 'AwardRecsPublic', '" . $value . "', 1, 'null', NOW())"
            );
        }
    }

    public function CheckKingdomAbbreviationTaken(string $abbr, int $excludeKingdomId = 0): bool
    {
        return $this->GetKingdomAbbreviationConflict($abbr, $excludeKingdomId) !== null;
    }

    public function GetKingdomAbbreviationConflict(string $abbr, int $excludeKingdomId = 0): ?string
    {
        $abbr = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($abbr)));
        if ($abbr === '') {
            return null;
        }
        $excludeClause = $excludeKingdomId > 0 ? ' AND kingdom_id != ' . (int) $excludeKingdomId : '';
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT kingdom_id, name FROM ' . DB_PREFIX . "kingdom WHERE abbreviation = '{$abbr}'{$excludeClause} LIMIT 1"
        );

        return ($rs && $rs->Next()) ? (string) $rs->name : null;
    }

    /**
     * @return array{kingdom_id: int, suspended: bool, suspended_by_id: int}
     */
    public function GetPlayerSuspensionContext(int $mundaneId): array
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT kingdom_id, suspended_by_id, suspended FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $mundaneId . ' LIMIT 1'
        );
        if (!$rs || !$rs->Next()) {
            return ['kingdom_id' => 0, 'suspended' => false, 'suspended_by_id' => 0];
        }

        return [
            'kingdom_id' => (int) $rs->kingdom_id,
            'suspended' => (bool) $rs->suspended,
            'suspended_by_id' => (int) $rs->suspended_by_id,
        ];
    }

    /**
     * FullCalendar feed for KingdomAjax::calendar.
     *
     * @return list<array<string, mixed>>
     */
    public function GetKingdomCalendarFeed(int $kingdomId, string $start, string $end, int $mundaneId = 0, bool $isAdmin = false): array
    {
        $kid = (int) $kingdomId;
        $statsEvtKids = implode(',', array_map('intval', $this->statsKingdom()->GetStatsKingdomIds($kid)));
        $events = [];

        $familyRoyals = [];
        $allRoyalIds = [];
        $this->db->Clear();
        $royRes = $this->db->DataSet(
            'SELECT o.kingdom_id, o.role, o.mundane_id FROM ' . DB_PREFIX . 'officer o
             INNER JOIN (SELECT kingdom_id, role, MAX(officer_id) AS max_oid FROM ' . DB_PREFIX . "officer
                         WHERE kingdom_id IN ({$statsEvtKids}) AND park_id = 0 AND role IN ('Monarch','Regent') AND mundane_id > 0
                         GROUP BY kingdom_id, role) latest ON latest.max_oid = o.officer_id"
        );
        while ($royRes && $royRes->Next()) {
            $rk = (int) $royRes->kingdom_id;
            $rid = (int) $royRes->mundane_id;
            if (!isset($familyRoyals[$rk])) {
                $familyRoyals[$rk] = ['monarch' => 0, 'regent' => 0];
            }
            $familyRoyals[$rk][$royRes->role === 'Monarch' ? 'monarch' : 'regent'] = $rid;
            if ($rid > 0) {
                $allRoyalIds[$rid] = true;
            }
        }
        $kingdomMonarchId = $familyRoyals[$kid]['monarch'] ?? 0;
        $kingdomRegentId = $familyRoyals[$kid]['regent'] ?? 0;

        if (!empty($allRoyalIds)) {
            $royalIdList = implode(',', array_map('intval', array_keys($allRoyalIds)));
            $royalSelectCols = 'royal.royal_rsvps AS royal_rsvps';
            $royalJoinSql = 'LEFT JOIN (SELECT event_calendardetail_id, GROUP_CONCAT(mundane_id) AS royal_rsvps FROM ' . DB_PREFIX . 'event_rsvp WHERE mundane_id IN (' . $royalIdList . ') GROUP BY event_calendardetail_id) royal ON royal.event_calendardetail_id = cd.event_calendardetail_id';
        } else {
            $royalSelectCols = 'NULL AS royal_rsvps';
            $royalJoinSql = '';
        }

        $kn_draftClause = $isAdmin ? '' : ($mundaneId > 0 ? "AND (e.status = 'published' OR e.mundane_id = {$mundaneId})" : "AND e.status = 'published'");

        $evtSql = 'SELECT e.event_id, e.name, e.park_id, e.kingdom_id, e.status, e.mundane_id AS event_creator,
                   p.abbreviation AS park_abbr,
                   cd.event_start, cd.event_end, cd.event_calendardetail_id AS detail_id,
                   ' . $royalSelectCols . '
            FROM ' . DB_PREFIX . 'event e
            LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = e.park_id
            INNER JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_id = e.event_id
            ' . $royalJoinSql . '
            WHERE e.kingdom_id IN (' . $statsEvtKids . ')
              AND cd.event_start >= \'' . $start . '\'
              AND cd.event_start < \'' . $end . '\'
              ' . $kn_draftClause . '
            ORDER BY cd.event_start';

        $this->db->Clear();
        $evtResult = $this->db->DataSet($evtSql);
        while ($evtResult && $evtResult->Next()) {
            $evStatus = (string) ($evtResult->status ?? 'published');
            if ($evStatus !== 'published' && !$isAdmin && (int) $evtResult->event_creator !== $mundaneId) {
                $canEditRow = ($mundaneId > 0) && Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_EVENT, (int) $evtResult->event_id, AUTH_EDIT);
                if (!$canEditRow) {
                    continue;
                }
            }
            $isPark = (int) $evtResult->park_id > 0;
            $abbr = ($isPark && $evtResult->park_abbr) ? $evtResult->park_abbr . ': ' : '';
            $eid = (int) $evtResult->event_id;
            $did = (int) $evtResult->detail_id;
            $evKid = (int) $evtResult->kingdom_id;
            $rsvpRaw = (string) ($evtResult->royal_rsvps ?? '');
            $royal = [];
            if ($rsvpRaw !== '') {
                $rsvpSet = array_flip(array_map('intval', explode(',', $rsvpRaw)));
                if ($kingdomMonarchId && isset($rsvpSet[$kingdomMonarchId])) {
                    $royal['km'] = true;
                }
                if ($kingdomRegentId && isset($rsvpSet[$kingdomRegentId])) {
                    $royal['kr'] = true;
                }
                if ($evKid !== $kid && isset($familyRoyals[$evKid])) {
                    $pmId = $familyRoyals[$evKid]['monarch'];
                    $prId = $familyRoyals[$evKid]['regent'];
                    if ($pmId && isset($rsvpSet[$pmId])) {
                        $royal['pm'] = true;
                    }
                    if ($prId && isset($rsvpSet[$prId])) {
                        $royal['pr'] = true;
                    }
                }
            }
            $ev = [
                'title' => $abbr . $evtResult->name,
                'start' => $evtResult->event_start,
                'url' => $did ? UIR . "Event/detail/{$eid}/{$did}" : '',
                'color' => $isPark ? '#6b46c1' : '#0891b2',
                'type' => $isPark ? 'park-event' : 'kingdom-event',
                'extendedProps' => [
                    'eventId' => $eid,
                    'detailId' => $did,
                    'isDraft' => $evStatus === 'draft',
                ],
            ];
            if (!empty($royal)) {
                $ev['extendedProps']['royalPresence'] = $royal;
            }
            $endRaw = $evtResult->event_end ?? '';
            if ($endRaw && substr($endRaw, 0, 10) > substr($evtResult->event_start, 0, 10)) {
                $endDt = new DateTime(substr($endRaw, 0, 10));
                $endDt->modify('+1 day');
                $ev['end'] = $endDt->format('Y-m-d');
            }
            $events[] = $ev;
        }

        $events = array_merge($events, $this->calendarItemsForFeed($kid, $start, $end, $mundaneId));
        $events = array_merge($events, $this->expandParkDaysForFeed($kid, $start, $end));

        return $events;
    }

    public function HasRoyalOfficers(int $kingdomId): bool
    {
        $this->db->Clear();
        $royRes = $this->db->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . "officer WHERE kingdom_id = " . (int) $kingdomId . " AND park_id = 0 AND role IN ('Monarch','Regent') AND mundane_id > 0 LIMIT 1"
        );

        return (bool) ($royRes && $royRes->Next());
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
    private function calendarItemsForProfile(int $kingdomId, int $mundaneId): array
    {
        $kid = (int) $kingdomId;
        $ciSql = "
            SELECT ci.calendar_item_id, ci.name, ci.description, ci.all_day, ci.park_id, ci.is_officer_only, ci.is_locals_only, ci.color,
                   ci.event_start, ci.event_end, p.name AS park_name, p.abbreviation AS park_abbr
            FROM " . DB_PREFIX . 'calendar_item ci
            LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = ci.park_id
            WHERE ci.kingdom_id = ' . $kid . "
              AND ci.event_end >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              AND ci.event_start <= DATE_ADD(NOW(), INTERVAL 12 MONTH)
            ORDER BY ci.event_start";
        $this->db->Clear();
        $ciResult = $this->db->DataSet($ciSql);
        $items = [];
        while ($ciResult && $ciResult->Next()) {
            $ci_isOfficerOnly = (int) $ciResult->is_officer_only;
            $ci_isLocalsOnly = (int) $ciResult->is_locals_only;
            if (!CalendarItem::CanSee($mundaneId, $kid, (int) $ciResult->park_id, $ci_isOfficerOnly, $ci_isLocalsOnly)) {
                continue;
            }
            $items[] = [
                'CalendarItemId' => (int) $ciResult->calendar_item_id,
                'Name' => $ciResult->name,
                'ParkName' => $ciResult->park_name,
                'ParkAbbr' => $ciResult->park_abbr,
                'NextDate' => $ciResult->event_start,
                'NextEndDate' => $ciResult->event_end,
                'AllDay' => (int) $ciResult->all_day,
                'Description' => $ciResult->description,
                'IsOfficerOnly' => $ci_isOfficerOnly,
                'IsLocalsOnly' => $ci_isLocalsOnly,
                'Color' => $ciResult->color ?: '#64748b',
                'ColorText' => CalendarItem::TextColorFor($ciResult->color ?: '#64748b'),
                '_IsCalendarItem' => true,
                '_IsParkEvent' => (int) $ciResult->park_id > 0,
            ];
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $eventSummary
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function buildMapLocations(array $eventSummary): array
    {
        $nowStamp = time();
        $horizonStamp = $nowStamp + (90 * 86400);
        $knEventMapLocs = [];
        $knMapNoLocCount = 0;
        $mapDetailIds = [];
        $mapEventIds = [];
        foreach ($eventSummary as $_evt) {
            if (empty($_evt['EventId'])) {
                continue;
            }
            $startTs = strtotime($_evt['NextDate'] ?? '');
            if (!$startTs || $startTs > $horizonStamp) {
                continue;
            }
            $mapDetailIds[] = (int) ($_evt['NextDetailId'] ?? 0);
            $mapEventIds[] = (int) $_evt['EventId'];
        }

        $cdCoordMap = [];
        if (!empty($mapDetailIds)) {
            $detailIdList = implode(',', array_map('intval', $mapDetailIds));
            $this->db->Clear();
            $cdBatch = $this->db->DataSet(
                'SELECT cd.event_calendardetail_id, cd.location AS event_loc, cd.at_park_id,
                        p.latitude AS at_park_lat, p.longitude AS at_park_lng
                 FROM ' . DB_PREFIX . 'event_calendardetail cd
                 LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = cd.at_park_id
                 WHERE cd.event_calendardetail_id IN (' . $detailIdList . ')'
            );
            while ($cdBatch && $cdBatch->Next()) {
                $cdCoordMap[(int) $cdBatch->event_calendardetail_id] = [
                    'event_loc' => (string) ($cdBatch->event_loc ?? ''),
                    'at_park_lat' => $cdBatch->at_park_lat,
                    'at_park_lng' => $cdBatch->at_park_lng,
                ];
            }
        }

        $evtParkCoordMap = [];
        if (!empty($mapEventIds)) {
            $eventIdList = implode(',', array_map('intval', $mapEventIds));
            $this->db->Clear();
            $epBatch = $this->db->DataSet(
                'SELECT e.event_id, p.latitude, p.longitude FROM ' . DB_PREFIX . 'event e
                 LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = e.park_id
                 WHERE e.event_id IN (' . $eventIdList . ')'
            );
            while ($epBatch && $epBatch->Next()) {
                $evtParkCoordMap[(int) $epBatch->event_id] = [
                    'latitude' => $epBatch->latitude,
                    'longitude' => $epBatch->longitude,
                ];
            }
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

            if ($lat === null && (int) ($_evt['EventId'] ?? 0) > 0) {
                $eid = (int) $_evt['EventId'];
                if (isset($evtParkCoordMap[$eid]) && is_numeric($evtParkCoordMap[$eid]['latitude'] ?? null) && (float) $evtParkCoordMap[$eid]['latitude'] != 0) {
                    $lat = (float) $evtParkCoordMap[$eid]['latitude'];
                    $lng = (float) $evtParkCoordMap[$eid]['longitude'];
                }
            }

            if ($lat !== null && $startTs >= ($nowStamp - 86400)) {
                $knEventMapLocs[] = [
                    'event_id' => (int) $_evt['EventId'],
                    'event_calendardetail_id' => (int) ($_evt['NextDetailId'] ?? 0),
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
                $knMapNoLocCount++;
            }
        }

        return [$knEventMapLocs, $knMapNoLocCount];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function calendarItemsForFeed(int $kingdomId, string $start, string $end, int $mundaneId): array
    {
        $kid = (int) $kingdomId;
        $ciSql = "
            SELECT ci.calendar_item_id, ci.name, ci.description, ci.all_day,
                   ci.event_start, ci.event_end, ci.park_id, ci.kingdom_id,
                   ci.is_officer_only, ci.is_locals_only, ci.color,
                   p.abbreviation AS park_abbr
            FROM " . DB_PREFIX . 'calendar_item ci
            LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = ci.park_id
            WHERE ci.kingdom_id = ' . $kid . "
              AND ci.event_start < '{$end}'
              AND ci.event_end   >= '{$start}'
            ORDER BY ci.event_start";
        $this->db->Clear();
        $ciResult = $this->db->DataSet($ciSql);
        $events = [];
        while ($ciResult && $ciResult->Next()) {
            $ci_isOfficerOnly = (int) $ciResult->is_officer_only;
            $ci_isLocalsOnly = (int) $ciResult->is_locals_only;
            if (!CalendarItem::CanSee($mundaneId, (int) $ciResult->kingdom_id, (int) $ciResult->park_id, $ci_isOfficerOnly, $ci_isLocalsOnly)) {
                continue;
            }
            $isPark = (int) $ciResult->park_id > 0;
            $abbr = ($isPark && $ciResult->park_abbr) ? $ciResult->park_abbr . ': ' : '';
            $allDay = (int) $ciResult->all_day === 1;
            $ev = [
                'title' => $abbr . $ciResult->name,
                'start' => $allDay ? substr($ciResult->event_start, 0, 10) : $ciResult->event_start,
                'color' => $ciResult->color ?: '#64748b',
                'textColor' => CalendarItem::TextColorFor($ciResult->color ?: '#64748b'),
                'type' => 'calendar-item',
                'allDay' => $allDay,
                'extendedProps' => [
                    'calendarItemId' => (int) $ciResult->calendar_item_id,
                    'description' => (string) $ciResult->description,
                    'parkId' => (int) $ciResult->park_id,
                    'kingdomId' => (int) $ciResult->kingdom_id,
                    'parkAbbr' => $ciResult->park_abbr ?? '',
                    'rawStart' => $ciResult->event_start,
                    'rawEnd' => $ciResult->event_end,
                ],
            ];
            $startDate = substr($ciResult->event_start, 0, 10);
            $endDate = substr($ciResult->event_end, 0, 10);
            if ($endDate > $startDate) {
                $endDt = new DateTime($endDate);
                if ($allDay) {
                    $endDt->modify('+1 day');
                }
                $ev['end'] = $allDay ? $endDt->format('Y-m-d') : $ciResult->event_end;
            } elseif (!$allDay) {
                $ev['end'] = $ciResult->event_end;
            }
            $events[] = $ev;
        }

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function expandParkDaysForFeed(int $kingdomId, string $start, string $end): array
    {
        $kid = (int) $kingdomId;
        $this->db->Clear();
        $pdResult = $this->db->DataSet(
            'SELECT pd.park_id, pd.recurrence, pd.week_day, pd.week_of_month,
                    pd.month_day, pd.start_date, pd.week_interval, pd.time, pd.purpose, p.abbreviation AS park_abbr
             FROM ' . DB_PREFIX . 'parkday pd
             JOIN ' . DB_PREFIX . "park p ON p.park_id = pd.park_id
             WHERE p.kingdom_id = {$kid} AND p.active = 'Active'"
        );
        $events = [];
        $dayNames = ['Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6];
        $rangeStart = new DateTime($start);
        $rangeEnd = new DateTime($end);
        while ($pdResult && $pdResult->Next()) {
            $purposeLabel = match ($pdResult->purpose) {
                'fighter-practice' => 'Fighter Practice',
                'arts-day' => 'A&S Day',
                'park-day' => 'Park Day',
                default => ucwords(str_replace('-', ' ', (string) $pdResult->purpose)),
            };
            $abbr = $pdResult->park_abbr ? $pdResult->park_abbr . ': ' : '';
            $title = $abbr . $purposeLabel;
            $url = UIR . 'Park/profile/' . (int) $pdResult->park_id;
            $timeStr = ($pdResult->time && $pdResult->time !== '00:00:00') ? 'T' . $pdResult->time : '';
            $rec = $pdResult->recurrence;

            if ($rec === 'weekly') {
                $targetWd = $dayNames[$pdResult->week_day] ?? -1;
                if ($targetWd < 0) {
                    continue;
                }
                $cur = clone $rangeStart;
                while ((int) $cur->format('w') !== $targetWd) {
                    $cur->modify('+1 day');
                }
                while ($cur < $rangeEnd) {
                    $events[] = ['title' => $title, 'start' => $cur->format('Y-m-d') . $timeStr, 'url' => $url, 'color' => '#b7791f', 'type' => 'park-day'];
                    $cur->modify('+7 days');
                }
            } elseif ($rec === 'week-of-month') {
                $targetWd = $dayNames[$pdResult->week_day] ?? -1;
                $nth = (int) $pdResult->week_of_month;
                if ($targetWd < 0 || $nth < 1) {
                    continue;
                }
                $curMonth = clone $rangeStart;
                $curMonth->modify('first day of this month');
                while ($curMonth < $rangeEnd) {
                    $cnt = 0;
                    $cur = clone $curMonth;
                    $mn = (int) $curMonth->format('n');
                    while ((int) $cur->format('n') === $mn) {
                        if ((int) $cur->format('w') === $targetWd && ++$cnt === $nth) {
                            if ($cur >= $rangeStart && $cur < $rangeEnd) {
                                $events[] = ['title' => $title, 'start' => $cur->format('Y-m-d') . $timeStr, 'url' => $url, 'color' => '#b7791f', 'type' => 'park-day'];
                            }
                            break;
                        }
                        $cur->modify('+1 day');
                    }
                    $curMonth->modify('first day of next month');
                }
            } elseif ($rec === 'monthly') {
                $dayNum = (int) $pdResult->month_day;
                if ($dayNum < 1) {
                    continue;
                }
                $curMonth = clone $rangeStart;
                $curMonth->modify('first day of this month');
                while ($curMonth < $rangeEnd) {
                    $mEnd = clone $curMonth;
                    $mEnd->modify('last day of this month');
                    $d = min($dayNum, (int) $mEnd->format('d'));
                    $cur = new DateTime($curMonth->format('Y-m-') . sprintf('%02d', $d));
                    if ($cur >= $rangeStart && $cur < $rangeEnd) {
                        $events[] = ['title' => $title, 'start' => $cur->format('Y-m-d') . $timeStr, 'url' => $url, 'color' => '#b7791f', 'type' => 'park-day'];
                    }
                    $curMonth->modify('first day of next month');
                }
            } elseif ($rec === 'every-x-weeks') {
                $occs = Park::ExpandEveryXWeeks($pdResult->start_date, (int) $pdResult->week_interval, $rangeStart, $rangeEnd);
                foreach ($occs as $occ) {
                    $events[] = ['title' => $title, 'start' => $occ . $timeStr, 'url' => $url, 'color' => '#b7791f', 'type' => 'park-day'];
                }
            }
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatParkDayRow($pdResult): array
    {
        switch ($pdResult->recurrence) {
            case 'weekly':
                $recText = 'Every ' . $pdResult->week_day;
                break;
            case 'week-of-month':
                $n = (int) $pdResult->week_of_month;
                $sfx = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'][$n % 10] ?? 'th');
                $recText = 'Every ' . $n . $sfx . ' ' . $pdResult->week_day;
                break;
            case 'every-x-weeks':
                $wi = (int) $pdResult->week_interval;
                $recText = ($wi === 2) ? 'Every other ' . $pdResult->week_day : 'Every ' . $wi . ' weeks on ' . $pdResult->week_day . 's';
                break;
            case 'monthly':
                $recText = 'Monthly, day ' . (int) $pdResult->month_day;
                break;
            default:
                $recText = ucfirst((string) $pdResult->recurrence);
        }
        $purposeLabel = match ($pdResult->purpose) {
            'fighter-practice' => 'Fighter Practice',
            'arts-day' => 'A&S Day',
            'park-day' => 'Park Day',
            default => ucwords(str_replace('-', ' ', (string) $pdResult->purpose)),
        };

        return [
            'ParkDayId' => (int) $pdResult->parkday_id,
            'ParkId' => (int) $pdResult->park_id,
            'ParkName' => $pdResult->park_name,
            'ParkAbbr' => $pdResult->park_abbr,
            'Schedule' => $recText,
            'Purpose' => $purposeLabel,
            'Time' => $pdResult->time,
            'Recurrence' => $pdResult->recurrence,
            'WeekDay' => $pdResult->week_day,
            'WeekOfMonth' => (int) $pdResult->week_of_month,
            'MonthDay' => (int) $pdResult->month_day,
        ];
    }

    private static function icsDt(string $str): string
    {
        return gmdate('Ymd\THis\Z', strtotime($str));
    }

    private static function icsDtPlus1hr(string $str): string
    {
        return gmdate('Ymd\THis\Z', strtotime($str) + 3600);
    }

    private static function icsEscape(string $str): string
    {
        $str = str_replace('\\', '\\\\', $str);
        $str = str_replace(';', '\;', $str);
        $str = str_replace(',', '\,', $str);
        $str = str_replace(["\r\n", "\r", "\n"], '\\n', $str);

        return $str;
    }

    private static function icsFold(string $line): string
    {
        $out = '';
        while (strlen($line) > 75) {
            $out .= substr($line, 0, 75) . "\r\n ";
            $line = substr($line, 75);
        }

        return $out . $line;
    }

    private static function icsLocation($address, $city, $province, $postal, $country): string
    {
        $parts = array_filter([$address, $city, $province, $postal, $country], 'strlen');

        return implode(', ', $parts);
    }
}
