<?php

class Dangeraudit extends Ork3
{
    public function __construct()
    {
        parent::__construct();
        $this->audit = new yapo($this->db, DB_PREFIX . 'danger_audit');
    }

    public function audit($call, $parameters, $entity, $entity_id, $prior_state = null, $post_state = null)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($_SESSION['is_authorized_mundane_id']);
        $this->audit->clear();
        $this->audit->method_call = $call;
        $this->audit->parameters = json_encode($parameters);
        $this->audit->entity = $entity;
        $this->audit->entity_id = $entity_id;
        $this->audit->prior_state = json_encode($prior_state);
        $this->audit->post_state = json_encode($post_state);
        $this->audit->by_whom_id = $mundane_id;
        $this->audit->modified_at = date('Y-m-d H:i:s');
        $this->audit->save();
        // Yapo does not reliably persist entity_id (int column with DEFAULT 0).
        // Patch it directly after insert using the last-inserted PK.
        $eid = (int)$entity_id;
        if ($eid > 0) {
            $pk = (int)$this->audit->{$this->audit->primarykey()};
            if ($pk > 0) {
                // Clear leftover bind params from the audit save — without this,
                // PDO would bind them to this placeholder-free UPDATE and fail
                // silently (ERRMODE_WARNING), leaving entity_id at 0.
                $this->db->Clear();
                $this->db->Execute("UPDATE " . DB_PREFIX . "danger_audit SET entity_id = $eid WHERE danger_audit_id = $pk");
            }
        }
    }

    /**
     * Paginated danger audit log with filters (T-ADM-03).
     *
     * @param array{
     *   Start?: string,
     *   End?: string,
     *   MethodCall?: string,
     *   ByWhomId?: int,
     *   EntityId?: int,
     *   EntityType?: string,
     *   Page?: int,
     *   PerPage?: int
     * } $filters
     * @return array{total: int, rows: list<array<string, mixed>>, perPage: int}
     */
    public function ListAuditLog(array $filters): array
    {
        $page = max(1, (int) ($filters['Page'] ?? 1));
        $perPage = max(1, (int) ($filters['PerPage'] ?? 50));
        $offset = ($page - 1) * $perPage;

        $start = isset($filters['Start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['Start'])
            ? $filters['Start'] : date('Y-m-d', strtotime('-7 days'));
        $end = isset($filters['End']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['End'])
            ? $filters['End'] : date('Y-m-d');

        $methodFilter = trim($filters['MethodCall'] ?? '');
        $byWhomFilter = (int) ($filters['ByWhomId'] ?? 0);
        $entityFilter = (int) ($filters['EntityId'] ?? 0);
        $entityTypeFilter = trim($filters['EntityType'] ?? '');
        if (!in_array($entityTypeFilter, ['Player', 'Park', 'Kingdom', 'Event', 'Unit'], true)) {
            $entityTypeFilter = '';
        }

        $where = "da.modified_at >= '" . mysql_real_escape_string($start) . " 00:00:00'"
            . " AND da.modified_at <= '" . mysql_real_escape_string($end) . " 23:59:59'";
        if ($methodFilter !== '') {
            $where .= " AND da.method_call = '" . mysql_real_escape_string($methodFilter) . "'";
        }
        if ($byWhomFilter > 0) {
            $where .= ' AND da.by_whom_id = ' . $byWhomFilter;
        }
        if ($entityFilter > 0) {
            $where .= ' AND da.entity_id = ' . $entityFilter;
        }
        if ($entityFilter > 0 && $entityTypeFilter !== '') {
            $where .= " AND da.entity = '" . mysql_real_escape_string($entityTypeFilter) . "'";
        }

        $this->db->Clear();
        $cr = $this->db->DataSet('SELECT COUNT(*) AS cnt FROM ' . DB_PREFIX . "danger_audit da WHERE {$where}");
        $total = 0;
        if ($cr && $cr->Next()) {
            $total = (int) $cr->cnt;
        }

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT da.danger_audit_id, da.method_call, da.parameters,
			        da.prior_state, da.post_state, da.entity, da.entity_id,
			        da.by_whom_id, da.modified_at,
			        m.persona AS by_whom_persona
			 FROM ' . DB_PREFIX . "danger_audit da
			 LEFT JOIN " . DB_PREFIX . 'mundane m ON m.mundane_id = da.by_whom_id
			 WHERE ' . $where . "
			 ORDER BY da.modified_at DESC
			 LIMIT {$perPage} OFFSET {$offset}"
        );
        $rows = [];
        if ($rs && $rs->Size() > 0) {
            do {
                if (empty($rs->method_call)) {
                    continue;
                }
                $rows[] = [
                    'Id' => (int) $rs->danger_audit_id,
                    'MethodCall' => $rs->method_call,
                    'Parameters' => $rs->parameters,
                    'PriorState' => $rs->prior_state,
                    'PostState' => $rs->post_state,
                    'Entity' => $rs->entity,
                    'EntityId' => (int) $rs->entity_id,
                    'ByWhomId' => (int) $rs->by_whom_id,
                    'ByWhomPersona' => $rs->by_whom_persona,
                    'ModifiedAt' => $rs->modified_at,
                ];
            } while ($rs->Next());
        }

        return ['total' => $total, 'rows' => $rows, 'perPage' => $perPage];
    }

    /**
     * @return list<string>
     */
    public function ListAuditMethods(): array
    {
        $this->db->Clear();
        $mr = $this->db->DataSet('SELECT DISTINCT method_call FROM ' . DB_PREFIX . 'danger_audit ORDER BY method_call');
        $methods = [];
        if ($mr) {
            while ($mr->Next()) {
                $methods[] = $mr->method_call;
            }
        }

        return $methods;
    }

    /**
     * Batch-resolve entity names for audit log rendering (T-ADM-03 / R-18).
     *
     * @param list<array<string, mixed>> $auditRows
     * @return array{
     *   parkMap: array<int, string>,
     *   kingdomMap: array<int, string>,
     *   mundaneMap: array<int, string>,
     *   eventMap: array<int, string>,
     *   kawardMap: array<int, string>,
     *   unitMap: array<int, string>,
     *   classMap: array<int, string>,
     *   filterPlayerNames: array<int, string>,
     *   entityFilterName: string
     * }
     */
    public function ResolveAuditDisplayMaps(
        array $auditRows,
        int $byWhomFilter = 0,
        int $entityFilter = 0,
        string $entityTypeFilter = ''
    ): array {
        $parkIds = [];
        $kingdomIds = [];
        $mundaneIds = [];
        $eventIds = [];
        $kawardIds = [];
        $unitIds = [];

        foreach ($auditRows as $row) {
            foreach ([$row['Parameters'] ?? '', $row['PriorState'] ?? '', $row['PostState'] ?? ''] as $json) {
                $decoded = @json_decode($json, true);
                if (!is_array($decoded)) {
                    continue;
                }
                foreach (['park_id', 'at_park_id', 'ParkId', 'from_park_id', 'to_park_id'] as $key) {
                    if (!empty($decoded[$key])) {
                        $parkIds[(int) $decoded[$key]] = true;
                    }
                }
                foreach (['kingdom_id', 'at_kingdom_id', 'KingdomId', 'old_kingdom_id', 'new_kingdom_id', 'from_kingdom_id', 'to_kingdom_id'] as $key) {
                    if (!empty($decoded[$key])) {
                        $kingdomIds[(int) $decoded[$key]] = true;
                    }
                }
                foreach (['mundane_id', 'given_by_id', 'given_by', 'stripped_from', 'MundaneId', 'RecipientId', 'FromMundaneId', 'ToMundaneId', 'SuspendedById', 'recommended_by_id', 'SupporterMundaneId', 'supporter_mundane_id', 'from_mundane_id', 'to_mundane_id'] as $key) {
                    if (!empty($decoded[$key]) && is_numeric($decoded[$key])) {
                        $mundaneIds[(int) $decoded[$key]] = true;
                    }
                }
                foreach (['event_id', 'at_event_id', 'EventId'] as $key) {
                    if (!empty($decoded[$key]) && (int) $decoded[$key] > 0) {
                        $eventIds[(int) $decoded[$key]] = true;
                    }
                }
                foreach (['kingdomaward_id', 'KingdomAwardId'] as $key) {
                    if (!empty($decoded[$key])) {
                        $kawardIds[(int) $decoded[$key]] = true;
                    }
                }
                foreach (['unit_id', 'UnitId'] as $key) {
                    if (!empty($decoded[$key]) && (int) $decoded[$key] > 0) {
                        $unitIds[(int) $decoded[$key]] = true;
                    }
                }
            }
            if (!empty($row['EntityId'])) {
                $entityId = (int) $row['EntityId'];
                switch ($row['Entity'] ?? 'Player') {
                    case 'Park':
                        $parkIds[$entityId] = true;
                        break;
                    case 'Kingdom':
                        $kingdomIds[$entityId] = true;
                        break;
                    case 'Event':
                        $eventIds[$entityId] = true;
                        break;
                    case 'Unit':
                        $unitIds[$entityId] = true;
                        break;
                    default:
                        $mundaneIds[$entityId] = true;
                        break;
                }
            }
        }

        $parkMap = $this->fetchIdNameMap('park', 'park_id', array_keys($parkIds));
        $kingdomMap = $this->fetchIdNameMap('kingdom', 'kingdom_id', array_keys($kingdomIds));
        $mundaneMap = $this->fetchMundanePersonaMap(array_keys($mundaneIds));
        $eventMap = $this->fetchIdNameMap('event', 'event_id', array_keys($eventIds));
        $kawardMap = $this->fetchIdNameMap('kingdomaward', 'kingdomaward_id', array_keys($kawardIds));
        $unitMap = $this->fetchIdNameMap('unit', 'unit_id', array_keys($unitIds));
        $classMap = $this->fetchIdNameMap('class', 'class_id', [], 'ORDER BY class_id');

        $filterPlayerNames = [];
        $entityFilterName = '';
        $playerIds = $byWhomFilter > 0 ? [$byWhomFilter] : [];
        if ($entityFilter > 0 && ($entityTypeFilter === '' || $entityTypeFilter === 'Player')) {
            $playerIds[] = $entityFilter;
        }
        if (!empty($playerIds)) {
            $filterPlayerNames = $this->fetchMundanePersonaMap(array_unique($playerIds));
        }
        if ($entityFilter > 0) {
            if ($entityTypeFilter === 'Park') {
                $entityFilterName = $parkMap[$entityFilter] ?? $this->fetchSingleName('park', 'park_id', $entityFilter);
            } elseif ($entityTypeFilter === 'Kingdom') {
                $entityFilterName = $kingdomMap[$entityFilter] ?? $this->fetchSingleName('kingdom', 'kingdom_id', $entityFilter);
            } elseif ($entityTypeFilter === 'Event') {
                $entityFilterName = $eventMap[$entityFilter] ?? $this->fetchSingleName('event', 'event_id', $entityFilter);
            } elseif ($entityTypeFilter === 'Unit') {
                $entityFilterName = $unitMap[$entityFilter] ?? $this->fetchSingleName('unit', 'unit_id', $entityFilter);
            } elseif ($entityTypeFilter === '' || $entityTypeFilter === 'Player') {
                $entityFilterName = $filterPlayerNames[$entityFilter] ?? '';
            }
        }

        return [
            'parkMap' => $parkMap,
            'kingdomMap' => $kingdomMap,
            'mundaneMap' => $mundaneMap,
            'eventMap' => $eventMap,
            'kawardMap' => $kawardMap,
            'unitMap' => $unitMap,
            'classMap' => $classMap,
            'filterPlayerNames' => $filterPlayerNames,
            'entityFilterName' => $entityFilterName,
        ];
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function fetchIdNameMap(string $table, string $idColumn, array $ids, string $suffix = ''): array
    {
        if ($ids === [] && $suffix === '') {
            return [];
        }
        $this->db->Clear();
        if ($ids === []) {
            $rs = $this->db->DataSet('SELECT ' . $idColumn . ', name FROM ' . DB_PREFIX . $table . ' ' . $suffix);
        } else {
            $rs = $this->db->DataSet(
                'SELECT ' . $idColumn . ', name FROM ' . DB_PREFIX . $table
                . ' WHERE ' . $idColumn . ' IN (' . implode(',', array_map('intval', $ids)) . ')'
            );
        }
        $map = [];
        if ($rs && $rs->Size() > 0) {
            do {
                $map[(int) $rs->{$idColumn}] = (string) $rs->name;
            } while ($rs->Next());
        }

        return $map;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function fetchMundanePersonaMap(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT mundane_id, persona FROM ' . DB_PREFIX . 'mundane
             WHERE mundane_id IN (' . implode(',', array_map('intval', $ids)) . ')'
        );
        $map = [];
        if ($rs && $rs->Size() > 0) {
            do {
                $map[(int) $rs->mundane_id] = (string) $rs->persona;
            } while ($rs->Next());
        }

        return $map;
    }

    private function fetchSingleName(string $table, string $idColumn, int $id): string
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT name FROM ' . DB_PREFIX . $table . ' WHERE ' . $idColumn . ' = ' . (int) $id . ' LIMIT 1'
        );

        return ($rs && $rs->Next()) ? (string) $rs->name : '';
    }

}
