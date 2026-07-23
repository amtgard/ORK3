<?php

class Administration
{
    public function __construct()
    {
        global $DB;
        $this->db = $DB;
        $this->log = new yapo($this->db, DB_PREFIX . 'log');
        $this->trace = array();
    }

    /**

    https://amtgard.com/orkstage/orkservice/Json/?call=Authorization/Authorize&request[UserName]=username&request[Password]=password

    https://amtgard.com/orkstage/orkservice/Json/?call=Administration/PurgeLogs&Token=token

    https://amtgard.com/orkstage/orkservice/Json/?call=Administration/OptimizeTable&Token=token&Table[0]=ork_log&Table[1]=ork_attendance

    **/

    public function PurgeLogs($Token)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($Token)) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_CREATE)) {
            $date = date("Y-m-d H:i:s", time() - 60);
            $continue = true;
            $total = 0;
            while ($continue) {
                set_time_limit(60);
                $sql = "select count(log_id) as hits from " . DB_PREFIX . "log where action_time < '$date' order by log_id asc limit 50";
                $find = $this->db->query($sql);
                if (!$find->size()) {
                    break;
                }
                if ($find->hits < 50) {
                    $continue = false;
                }
                $total += $find->hits;
                $sql = "delete from " . DB_PREFIX . "log where action_time < '$date' order by log_id asc limit 50";
                $this->db->query($sql);
            }
            return Success($total);
        }
        return NoAuthorization();
    }

    public function OptimizeTable($Token, $Table = null)
    {
        $total = 0;
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($Token)) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_CREATE)) {
            if (is_null($Table)) {
                $tables = $this->db->query('show tables');

                $t = 'Tables_in_' . DB_DATABASE;
                while ($tables->next()) {
                    set_time_limit(60 * 60);
                    $this->db->query('optimize table "' . $tables->$t . '"');
                    $total++;
                }
            } elseif (is_array($Table) && count($Table > 0)) {
                foreach ($Table as $k => $t) {
                    set_time_limit(60 * 60);
                    $this->db->query('optimize table "' . $t . '"');
                    $total++;
                }
            }
            return Success($total);
        }
        return NoAuthorization();
    }

    /**
     * SHOW GLOBAL STATUS subset for server health panel (T-ADM-04).
     *
     * @param list<string> $wanted
     * @return array<string, string>
     */
    public function GetServerHealthDbStatus(array $wanted): array
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            "SHOW GLOBAL STATUS WHERE Variable_name IN ('" . implode("','", $wanted) . "')"
        );
        $dbStatus = [];
        if ($rs && $rs->Size() > 0) {
            do {
                $dbStatus[$rs->Variable_name] = $rs->Value;
            } while ($rs->Next());
        }

        return $dbStatus;
    }

    /**
     * @return list<array{id: int, user: mixed, host: string, command: mixed, time: int, state: string, info: string}>
     */
    public function GetServerHealthProcesses(int $limit = 20): array
    {
        $this->db->Clear();
        $pr = $this->db->DataSet(
            'SELECT ID, USER, HOST, COMMAND, TIME, STATE, LEFT(INFO, 300) AS INFO
			 FROM information_schema.PROCESSLIST
			 WHERE COMMAND != \'Sleep\'
			 ORDER BY TIME DESC
			 LIMIT ' . (int) $limit
        );
        $processes = [];
        if ($pr && $pr->Size() > 0) {
            do {
                $processes[] = [
                    'id' => (int) $pr->ID,
                    'user' => $pr->USER,
                    'host' => $pr->HOST ?? '',
                    'command' => $pr->COMMAND,
                    'time' => (int) $pr->TIME,
                    'state' => $pr->STATE ?? '',
                    'info' => $pr->INFO ?? '',
                ];
            } while ($pr->Next());
        }

        return $processes;
    }

    /**
     * Weather freshness buckets for active parks (T-ADM-04, T-ADM-08).
     *
     * @return array{
     *   total_active: int,
     *   fresh: int,
     *   aging: int,
     *   stale_row: int,
     *   oldest_min: ?int,
     *   events_upcoming: int,
     *   events_with_coords: int
     * }
     */
    public function GetServerHealthWeatherSummary(): array
    {
        $nowLocal = date('Y-m-d H:i:s');
        $cutoffFresh = date('Y-m-d H:i:s', time() - 90 * 60);
        $cutoffAging = date('Y-m-d H:i:s', time() - 4 * 3600);
        $p = DB_PREFIX;
        $wsql = "SELECT
			(SELECT COUNT(*) FROM {$p}park p WHERE p.active = 'Active'
			   AND EXISTS (SELECT 1 FROM {$p}attendance a
			               WHERE a.park_id = p.park_id AND a.date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY))
			) AS total_active,
			(SELECT COUNT(*) FROM {$p}park_weather pw
			   JOIN {$p}park p ON p.park_id = pw.park_id
			   WHERE p.active = 'Active'
			     AND pw.fetched_at >= '{$cutoffFresh}'
			) AS fresh,
			(SELECT COUNT(*) FROM {$p}park_weather pw
			   JOIN {$p}park p ON p.park_id = pw.park_id
			   WHERE p.active = 'Active'
			     AND pw.fetched_at >= '{$cutoffAging}'
			     AND pw.fetched_at <  '{$cutoffFresh}'
			) AS aging,
			(SELECT COUNT(*) FROM {$p}park_weather pw
			   JOIN {$p}park p ON p.park_id = pw.park_id
			   WHERE p.active = 'Active'
			     AND pw.fetched_at <  '{$cutoffAging}'
			     AND EXISTS (SELECT 1 FROM {$p}attendance a
			                 WHERE a.park_id = p.park_id AND a.date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY))
			) AS stale_row,
			(SELECT TIMESTAMPDIFF(MINUTE, MIN(pw.fetched_at), '{$nowLocal}')
			   FROM {$p}park_weather pw
			   JOIN {$p}park p ON p.park_id = pw.park_id
			   WHERE p.active = 'Active'
			     AND EXISTS (SELECT 1 FROM {$p}attendance a
			                 WHERE a.park_id = p.park_id AND a.date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY))
			) AS oldest_min,
			(SELECT COUNT(DISTINCT e.event_id)
			   FROM {$p}event e
			   JOIN {$p}event_calendardetail cd ON cd.event_id = e.event_id
			   WHERE DATE(cd.event_start) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
			) AS events_upcoming,
			(SELECT COUNT(DISTINCT e.event_id)
			   FROM {$p}event e
			   JOIN {$p}event_calendardetail cd ON cd.event_id = e.event_id
			   WHERE DATE(cd.event_start) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
			     AND cd.latitude IS NOT NULL AND cd.longitude IS NOT NULL
			     AND cd.latitude != 0 AND cd.longitude != 0
			) AS events_with_coords";

        $this->db->Clear();
        $wr = $this->db->DataSet($wsql);
        $stats = [
            'total_active' => 0,
            'fresh' => 0,
            'aging' => 0,
            'stale_row' => 0,
            'oldest_min' => null,
            'events_upcoming' => 0,
            'events_with_coords' => 0,
        ];
        if ($wr && $wr->Size() > 0 && $wr->Next()) {
            $stats['total_active'] = (int) $wr->total_active;
            $stats['fresh'] = (int) $wr->fresh;
            $stats['aging'] = (int) $wr->aging;
            $stats['stale_row'] = (int) $wr->stale_row;
            $stats['oldest_min'] = $wr->oldest_min !== null ? (int) $wr->oldest_min : null;
            $stats['events_upcoming'] = (int) $wr->events_upcoming;
            $stats['events_with_coords'] = (int) $wr->events_with_coords;
        }

        return $stats;
    }

    /**
     * @return list<array{KingdomId: int, KingdomName: string}>
     */
    public function GetActiveKingdomLoadTestTargets(int $limit = 10): array
    {
        $this->db->Clear();
        $kr = $this->db->DataSet(
            'SELECT kingdom_id, name FROM ' . DB_PREFIX . "kingdom WHERE active='Active' ORDER BY name LIMIT " . (int) $limit
        );
        $targets = [];
        if ($kr && $kr->Size() > 0) {
            do {
                $targets[] = ['KingdomId' => (int) $kr->kingdom_id, 'KingdomName' => $kr->name];
            } while ($kr->Next());
        }

        return $targets;
    }

    /**
     * Global ORK admin grants with last login and last credit (T-ADM-02).
     * Requires Token + global AUTH_ADMIN (same gate as PurgeLogs).
     *
     * @return list<array<string, mixed>>|array{Status: mixed, Error?: mixed, Detail?: mixed}
     */
    public function GetGlobalAdminGrants($Token = null): array
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($Token ?? '')) <= 0
            || !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_CREATE)) {
            return NoAuthorization();
        }

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT a.authorization_id, a.mundane_id, a.role, a.modified,
                    m.persona, m.username, m.given_name, m.surname,
                    DATE_SUB(m.token_expires, INTERVAL 72 HOUR) AS last_login,
                    lc.last_credit
             FROM ' . DB_PREFIX . 'authorization a
             LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = a.mundane_id
             LEFT JOIN (
                 SELECT mundane_id, MAX(date) AS last_credit
                 FROM ' . DB_PREFIX . 'attendance
                 WHERE credits > 0
                 GROUP BY mundane_id
             ) lc ON lc.mundane_id = a.mundane_id
             WHERE a.role = \'admin\'
               AND a.kingdom_id = 0 AND a.park_id = 0 AND a.event_id = 0 AND a.unit_id = 0
             ORDER BY m.persona'
        );
        $adminAuths = [];
        if ($rs) {
            while ($rs->Next()) {
                $adminAuths[] = [
                    'AuthorizationId' => (int) $rs->authorization_id,
                    'MundaneId' => (int) $rs->mundane_id,
                    'Modified' => $rs->modified,
                    'Persona' => $rs->persona,
                    'UserName' => $rs->username,
                    'GivenName' => $rs->given_name,
                    'Surname' => $rs->surname,
                    'LastLogin' => $rs->last_login,
                    'LastCredit' => $rs->last_credit,
                ];
            }
        }

        return $adminAuths;
    }

    /**
     * @return list<array{KingdomId: int, KingdomName: string}>
     */
    public function GetActiveKingdomsForPermissions(): array
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT k.kingdom_id, k.name AS kingdom_name
             FROM ' . DB_PREFIX . "kingdom k
             WHERE k.active = 'Active' AND k.parent_kingdom_id = 0
             ORDER BY k.name"
        );
        $kingdoms = [];
        if ($rs) {
            while ($rs->Next()) {
                $kingdoms[] = ['KingdomId' => (int) $rs->kingdom_id, 'KingdomName' => $rs->kingdom_name];
            }
        }

        return $kingdoms;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function GetScopedAuths(string $type, int $id): array
    {
        $scopeColMap = ['Kingdom' => 'a.kingdom_id', 'Park' => 'a.park_id', 'Event' => 'a.event_id'];
        if (!isset($scopeColMap[$type])) {
            return [];
        }
        $scopeCol = $scopeColMap[$type];
        $eid = (int) $id;
        $this->db->Clear();
        $rs = $this->db->DataSet(
            "SELECT a.authorization_id, a.mundane_id, a.role, a.modified,
                    m.persona, m.username, m.given_name, m.surname, m.restricted,
                    o.role AS officer_role, o.officer_id
             FROM " . DB_PREFIX . "authorization a
             LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = a.mundane_id
             LEFT JOIN " . DB_PREFIX . "officer o ON o.authorization_id = a.authorization_id
             WHERE {$scopeCol} = {$eid}
             ORDER BY m.persona"
        );
        $auths = [];
        if ($rs) {
            while ($rs->Next()) {
                $auths[] = [
                    'AuthorizationId' => (int) $rs->authorization_id,
                    'MundaneId' => (int) $rs->mundane_id,
                    'Role' => $rs->role,
                    'Modified' => $rs->modified,
                    'Persona' => $rs->persona,
                    'UserName' => $rs->username,
                    'GivenName' => $rs->given_name,
                    'Surname' => $rs->surname,
                    'OfficerRole' => $rs->officer_role,
                    'OfficerId' => $rs->officer_id,
                ];
            }
        }

        return $auths;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function GetKingdomParkAuths(int $kingdomId): array
    {
        $eid = (int) $kingdomId;
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT a.authorization_id, a.mundane_id, a.park_id, a.role, a.modified,
                    p.name AS park_name, m.persona, m.username, m.given_name, m.surname, m.restricted,
                    o.role AS officer_role, o.officer_id
             FROM ' . DB_PREFIX . 'authorization a
             JOIN ' . DB_PREFIX . 'park p ON p.park_id = a.park_id
             LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = a.mundane_id
             LEFT JOIN ' . DB_PREFIX . 'officer o ON o.authorization_id = a.authorization_id
             WHERE p.kingdom_id = ' . $eid . '
             ORDER BY p.name, m.persona'
        );
        $parkAuths = [];
        if ($rs) {
            while ($rs->Next()) {
                $parkAuths[] = [
                    'AuthorizationId' => (int) $rs->authorization_id,
                    'MundaneId' => (int) $rs->mundane_id,
                    'ParkId' => (int) $rs->park_id,
                    'ParkName' => $rs->park_name,
                    'Role' => $rs->role,
                    'Modified' => $rs->modified,
                    'Persona' => $rs->persona,
                    'UserName' => $rs->username,
                    'GivenName' => $rs->given_name,
                    'Surname' => $rs->surname,
                    'OfficerRole' => $rs->officer_role,
                    'OfficerId' => $rs->officer_id,
                ];
            }
        }

        return $parkAuths;
    }

    /**
     * @return array{
     *   creator: ?array{MundaneId: int, Persona: mixed, GivenName: mixed, Surname: mixed},
     *   parkAuths: list<array<string, mixed>>,
     *   kingdomAuths: list<array<string, mixed>>,
     *   parkName: string,
     *   kingdomName: string,
     *   parkId: int,
     *   kingdomId: int
     * }
     */
    public function GetEventInheritedPermissions(int $eventId): array
    {
        $eid = (int) $eventId;
        $eventCreator = null;
        $inheritedParkAuths = [];
        $inheritedKingdomAuths = [];
        $inheritedParkName = '';
        $inheritedKingdomName = '';
        $evParkId = 0;
        $evKingdomId = 0;

        $this->db->Clear();
        $evRow = $this->db->DataSet(
            'SELECT e.mundane_id AS creator_id, e.park_id AS ev_park_id, e.kingdom_id AS ev_kingdom_id,
                    m.persona AS creator_persona, m.given_name, m.surname,
                    p.name AS park_name, k.name AS kingdom_name
             FROM ' . DB_PREFIX . 'event e
             LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = e.mundane_id
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = e.park_id
             LEFT JOIN ' . DB_PREFIX . 'kingdom k ON k.kingdom_id = e.kingdom_id
             WHERE e.event_id = ' . $eid . ' LIMIT 1'
        );
        if ($evRow && $evRow->Next()) {
            $evParkId = (int) $evRow->ev_park_id;
            $evKingdomId = (int) $evRow->ev_kingdom_id;
            $inheritedParkName = $evRow->park_name ?? '';
            $inheritedKingdomName = $evRow->kingdom_name ?? '';
            if ((int) $evRow->creator_id > 0) {
                $eventCreator = [
                    'MundaneId' => (int) $evRow->creator_id,
                    'Persona' => $evRow->creator_persona,
                    'GivenName' => $evRow->given_name,
                    'Surname' => $evRow->surname,
                ];
            }
        }

        if ($evParkId > 0) {
            $this->db->Clear();
            $rs = $this->db->DataSet(
                'SELECT a.authorization_id, a.mundane_id, a.role,
                        m.persona, m.given_name, m.surname,
                        o.role AS officer_role
                 FROM ' . DB_PREFIX . 'authorization a
                 LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = a.mundane_id
                 LEFT JOIN ' . DB_PREFIX . 'officer o ON o.authorization_id = a.authorization_id
                 WHERE a.park_id = ' . $evParkId . '
                 ORDER BY a.role DESC, m.persona'
            );
            if ($rs) {
                while ($rs->Next()) {
                    $inheritedParkAuths[] = [
                        'MundaneId' => (int) $rs->mundane_id,
                        'Role' => $rs->role,
                        'Persona' => $rs->persona,
                        'GivenName' => $rs->given_name,
                        'Surname' => $rs->surname,
                        'OfficerRole' => $rs->officer_role,
                    ];
                }
            }
        }

        if ($evKingdomId > 0) {
            $this->db->Clear();
            $rs = $this->db->DataSet(
                'SELECT a.authorization_id, a.mundane_id, a.role,
                        m.persona, m.given_name, m.surname,
                        o.role AS officer_role
                 FROM ' . DB_PREFIX . 'authorization a
                 LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = a.mundane_id
                 LEFT JOIN ' . DB_PREFIX . 'officer o ON o.authorization_id = a.authorization_id
                 WHERE a.kingdom_id = ' . $evKingdomId . '
                 ORDER BY a.role DESC, m.persona'
            );
            if ($rs) {
                while ($rs->Next()) {
                    $inheritedKingdomAuths[] = [
                        'MundaneId' => (int) $rs->mundane_id,
                        'Role' => $rs->role,
                        'Persona' => $rs->persona,
                        'GivenName' => $rs->given_name,
                        'Surname' => $rs->surname,
                        'OfficerRole' => $rs->officer_role,
                    ];
                }
            }
        }

        return [
            'creator' => $eventCreator,
            'parkAuths' => $inheritedParkAuths,
            'kingdomAuths' => $inheritedKingdomAuths,
            'parkName' => $inheritedParkName,
            'kingdomName' => $inheritedKingdomName,
            'parkId' => $evParkId,
            'kingdomId' => $evKingdomId,
        ];
    }

    /**
     * All kingdom id => name pairs ordered by name (admin inactive parks filter).
     *
     * @return array<int, string>
     */
    public function ListAllKingdomNames(): array
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT kingdom_id, name FROM ' . DB_PREFIX . 'kingdom ORDER BY name'
        );
        $kingdoms = [];
        if ($rs && $rs->Size() > 0) {
            do {
                $kingdoms[(int) $rs->kingdom_id] = (string) $rs->name;
            } while ($rs->Next());
        }

        return $kingdoms;
    }
}
