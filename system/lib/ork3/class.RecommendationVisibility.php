<?php

/**
 * Monarchy Only recommendations — the single owner of "who may see a hidden
 * recommendation". Every reader in system/lib/ork3 routes through here; nothing
 * else may restate the rule.
 *
 * Levels (ork_recommendations.monarchy_only): 0 public, 1 park-level (park and
 * kingdom officers), 2 kingdom-level (kingdom officers only). A kingdom-level rec
 * that has been passed to local is READ as park-level — the stored value is left
 * alone so un-passing restores it.
 *
 * "Officer" is Court::canManage measured against the RECIPIENT's kingdom and home
 * park; a principality's parent-kingdom officers count as kingdom officers.
 *
 * The viewer must come from a validated Token (viewerFromToken). This class is not
 * whitelisted in orkservice/Json, and callers must never feed it RequestedBy —
 * the JSON service lets anyone choose that value.
 */
class RecommendationVisibility extends Ork3
{
    public const LEVEL_PUBLIC  = 0;
    public const LEVEL_PARK    = 1;
    public const LEVEL_KINGDOM = 2;

    /** @var array<string,int> "viewer:kingdom:park" => officer level, per request */
    private $officerMemo = [];
    /** @var array<int,bool> */
    private $adminMemo = [];
    /** @var array<int,int> */
    private $parentMemo = [];

    /**
     * The stored level, normalised. Anything that is not 0, 1 or 2 is not a level
     * this feature ever writes, so it is read as kingdom-level: an unexpected value
     * must hide a rec from more people, never fewer (fail closed).
     */
    public static function storedLevel(int $stored): int
    {
        return ($stored >= self::LEVEL_PUBLIC && $stored <= self::LEVEL_KINGDOM) ? $stored : self::LEVEL_KINGDOM;
    }

    public static function effectiveLevel(int $stored, bool $passedToLocal): int
    {
        if ($stored === self::LEVEL_KINGDOM && $passedToLocal) {
            return self::LEVEL_PARK;
        }

        return self::storedLevel($stored);
    }

    /** SQL twin of effectiveLevel(), including its fail-closed reading of out-of-range values. */
    public static function effectiveLevelSql(string $recAlias): string
    {
        return "(CASE WHEN $recAlias.monarchy_only = 0 THEN 0"
            . " WHEN $recAlias.monarchy_only = 2 AND $recAlias.passed_to_local = 1 THEN 1"
            . " WHEN $recAlias.monarchy_only = 1 THEN 1 ELSE 2 END)";
    }

    public function viewerFromToken($token): int
    {
        if (!is_string($token) || $token === '') {
            return 0;
        }

        return (int) Ork3::$Lib->authorization->IsAuthorized($token);
    }

    private function isAdmin(int $viewer): bool
    {
        if ($viewer <= 0) {
            return false;
        }
        if (!isset($this->adminMemo[$viewer])) {
            $this->adminMemo[$viewer] = (bool) Ork3::$Lib->authorization->HasAuthority($viewer, AUTH_ADMIN, 0, AUTH_EDIT);
        }

        return $this->adminMemo[$viewer];
    }

    private function parentKingdom(int $kingdomId): int
    {
        if (!isset($this->parentMemo[$kingdomId])) {
            $this->db->Clear();
            $rs = $this->db->DataSet('SELECT parent_kingdom_id FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ' . $kingdomId . ' LIMIT 1');
            $this->parentMemo[$kingdomId] = ($rs && $rs->Next()) ? (int) $rs->parent_kingdom_id : 0;
        }

        return $this->parentMemo[$kingdomId];
    }

    public function kingdomOfPark(int $parkId): int
    {
        if ($parkId <= 0) {
            return 0;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet('SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ' . $parkId . ' LIMIT 1');

        return ($rs && $rs->Next()) ? (int) $rs->kingdom_id : 0;
    }

    public function officerLevel(int $viewer, int $kingdomId, int $parkId): int
    {
        if ($viewer <= 0 || $kingdomId <= 0) {
            return 0;
        }
        $key = $viewer . ':' . $kingdomId . ':' . $parkId;
        if (isset($this->officerMemo[$key])) {
            return $this->officerMemo[$key];
        }
        $court = Ork3::$Lib->court;
        $parent = $this->parentKingdom($kingdomId);
        if ($court->canManage($viewer, $kingdomId, 0) || ($parent > 0 && $court->canManage($viewer, $parent, 0))) {
            $level = self::LEVEL_KINGDOM;
        } elseif ($parkId > 0 && $court->canManage($viewer, $kingdomId, $parkId)) {
            $level = self::LEVEL_PARK;
        } else {
            $level = 0;
        }

        return $this->officerMemo[$key] = $level;
    }

    public function canSee(int $viewer, array $rec): bool
    {
        $eff = self::effectiveLevel((int) ($rec['MonarchyOnly'] ?? 0), !empty($rec['PassedToLocal']));
        if ($eff === self::LEVEL_PUBLIC) {
            return true;
        }
        if ($viewer <= 0 || $viewer === (int) ($rec['MundaneId'] ?? 0)) {
            return false;
        }
        if ($this->isAdmin($viewer) || $viewer === (int) ($rec['RecommendedById'] ?? 0)) {
            return true;
        }

        return $this->officerLevel($viewer, (int) ($rec['KingdomId'] ?? 0), (int) ($rec['ParkId'] ?? 0)) >= $eff;
    }

    /**
     * May $viewer lift (or otherwise manage) this rec's Monarchy Only flag? Measured
     * against the STORED level, not the effective one: a kingdom hide that has been
     * passed down to local reads as park-level, but lifting it stays the kingdom's
     * call. Authorship alone is never enough.
     */
    public function canManageHidden(int $viewer, array $rec): bool
    {
        $required = max(self::LEVEL_PARK, self::storedLevel((int) ($rec['MonarchyOnly'] ?? 0)));
        if ($viewer <= 0 || $viewer === (int) ($rec['MundaneId'] ?? 0)) {
            return false;
        }
        if ($this->isAdmin($viewer)) {
            return true;
        }

        return $this->officerLevel($viewer, (int) ($rec['KingdomId'] ?? 0), (int) ($rec['ParkId'] ?? 0)) >= $required;
    }

    public function filterRecommendations(array $response, int $viewer): array
    {
        if (empty($response['AwardRecommendations'])) {
            return $response;
        }
        $response['AwardRecommendations'] = array_values(array_filter(
            $response['AwardRecommendations'],
            function ($rec) use ($viewer) {
                return $this->canSee($viewer, $rec);
            }
        ));

        return $response;
    }

    /**
     * SQL twin of canSee() for paged / counted readers. $kingdomIds is the set of
     * recipient kingdoms the query can return (its scope); kingdom-officer status is
     * resolved for exactly those. Park-officer status is resolved from the viewer's
     * own grants and seats, which is a handful of rows.
     */
    public function sqlPredicate(int $viewer, string $recAlias, string $mundaneAlias, array $kingdomIds): string
    {
        $eff = self::effectiveLevelSql($recAlias);
        if ($viewer <= 0) {
            return "($eff = 0)";
        }
        $notRecipient = "$recAlias.mundane_id <> $viewer";
        $parts = ["$eff = 0"];
        if ($this->isAdmin($viewer)) {
            $parts[] = $notRecipient;

            return '(' . implode(' OR ', $parts) . ')';
        }
        $parts[] = "($recAlias.recommended_by_id = $viewer AND $notRecipient)";

        $officerKingdoms = [];
        foreach (array_unique(array_map('intval', $kingdomIds)) as $kid) {
            if ($kid > 0 && $this->officerLevel($viewer, $kid, 0) === self::LEVEL_KINGDOM) {
                $officerKingdoms[] = $kid;
            }
        }
        if ($officerKingdoms) {
            $parts[] = "($notRecipient AND $mundaneAlias.kingdom_id IN (" . implode(',', $officerKingdoms) . '))';
        }

        $officerParks = [];
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT a.park_id, p.kingdom_id FROM ' . DB_PREFIX . 'authorization a
               JOIN ' . DB_PREFIX . 'park p ON p.park_id = a.park_id
              WHERE a.mundane_id = ' . $viewer . ' AND a.park_id > 0
             UNION
             SELECT o.park_id, o.kingdom_id FROM ' . DB_PREFIX . 'officer o
              WHERE o.mundane_id = ' . $viewer . ' AND o.park_id > 0
                AND o.role IN (\'Monarch\',\'Regent\',\'Prime Minister\')'
        );
        if ($rs) {
            while ($rs->Next()) {
                $pid = (int) $rs->park_id;
                if ($this->officerLevel($viewer, (int) $rs->kingdom_id, $pid) >= self::LEVEL_PARK) {
                    $officerParks[$pid] = $pid;
                }
            }
        }
        if ($officerParks) {
            $parts[] = "($notRecipient AND $eff = 1 AND $mundaneAlias.park_id IN (" . implode(',', $officerParks) . '))';
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    public function creatorLevel(int $creator, int $recipientId): int
    {
        if ($creator <= 0 || $recipientId <= 0 || $creator === $recipientId) {
            return 0;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet('SELECT kingdom_id, park_id FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $recipientId . ' LIMIT 1');
        if (!$rs || !$rs->Next()) {
            return 0;
        }

        return $this->officerLevel($creator, (int) $rs->kingdom_id, (int) $rs->park_id);
    }
}
