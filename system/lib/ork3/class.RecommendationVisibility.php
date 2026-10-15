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
    /** @var array<int,array> viewer => viewerGrants(), per request */
    private $grantMemo = [];
    /** @var array<string,string> "viewer|recAlias|mundaneAlias|scope" => predicate, per request */
    private $predicateMemo = [];

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

    /**
     * The one loader for a single rec's visibility facts: the canSee() input shape,
     * with KingdomId/ParkId from the RECIPIENT's mundane row, plus its id and whether
     * it is dismissed. One query. Null when the rec does not exist. Callers that act
     * on one rec by id use this (or canSeeRecId) instead of assembling the shape.
     *
     * Not memoized: write paths change monarchy_only/passed_to_local mid-request.
     */
    public function loadRec(int $recId): ?array
    {
        if ($recId <= 0) {
            return null;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT r.recommendations_id, r.mundane_id, r.recommended_by_id, r.monarchy_only, r.passed_to_local,
                    r.deleted_at, r.deleted_by, IFNULL(m.kingdom_id, 0) AS kingdom_id, IFNULL(m.park_id, 0) AS park_id
               FROM ' . DB_PREFIX . 'recommendations r
               LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = r.mundane_id
              WHERE r.recommendations_id = ' . $recId . ' LIMIT 1'
        );
        if (!$rs || !$rs->Next()) {
            return null;
        }

        return [
            'RecommendationsId' => (int) $rs->recommendations_id,
            'MundaneId'         => (int) $rs->mundane_id,
            'RecommendedById'   => (int) $rs->recommended_by_id,
            'KingdomId'         => (int) $rs->kingdom_id,
            'ParkId'            => (int) $rs->park_id,
            'MonarchyOnly'      => (int) $rs->monarchy_only,
            'PassedToLocal'     => (int) $rs->passed_to_local,
            // Same liveness reading as Player::LiveRecommendationClause.
            'Deleted'           => (int) $rs->deleted_by > 0,
        ];
    }

    /** canSee() for a rec id. A rec that does not exist is never visible. */
    public function canSeeRecId(int $viewer, int $recId): bool
    {
        $rec = $this->loadRec($recId);

        return $rec !== null && $this->canSee($viewer, $rec);
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
     * The viewer's officer grants, resolved once per request (Report's paged reader
     * builds three predicates per call, and the kingdom profile builds one per load).
     *
     *   officerKingdoms  every kingdom K with officerLevel(viewer, K, 0) = kingdom
     *   authKingdoms     every kingdom K with HasAuthority(viewer, AUTH_KINGDOM, K):
     *                    HasAuthority(AUTH_PARK, P) climbs to P's OWN kingdom, so this
     *                    covers the parks of K whatever the recipient's mundane row says
     *   authParks        parks with a direct HasAuthority(viewer, AUTH_PARK, P) grant
     *   seatParks        [kingdom_id, park_id] Monarch/Regent/PM park seats; canManage
     *                    matches a park seat only on BOTH ids, so these stay pairs
     *
     * Candidates come from the viewer's own authorization rows and seats, plus every
     * descendant principality (parent-kingdom authority covers them); each one is
     * confirmed through the same functions canSee() uses, so SQL and PHP agree.
     *
     * @return array{officerKingdoms:list<int>,authKingdoms:list<int>,authParks:list<int>,seatParks:list<array{0:int,1:int}>}
     */
    private function viewerGrants(int $viewer): array
    {
        if (isset($this->grantMemo[$viewer])) {
            return $this->grantMemo[$viewer];
        }
        $candKingdoms = [];
        $candParks    = [];
        $seatParks    = [];
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT a.kingdom_id, a.park_id, 0 AS seat FROM ' . DB_PREFIX . 'authorization a
              WHERE a.mundane_id = ' . $viewer . ' AND (a.kingdom_id > 0 OR a.park_id > 0)
             UNION
             SELECT o.kingdom_id, o.park_id, 1 AS seat FROM ' . DB_PREFIX . 'officer o
              WHERE o.mundane_id = ' . $viewer . ' AND o.kingdom_id > 0
                AND o.role IN (\'Monarch\',\'Regent\',\'Prime Minister\')'
        );
        if ($rs) {
            while ($rs->Next()) {
                $kid = (int) $rs->kingdom_id;
                $pid = (int) $rs->park_id;
                if ((int) $rs->seat === 1) {
                    if ($pid > 0) {
                        $seatParks[$kid . ':' . $pid] = [$kid, $pid];
                    } else {
                        $candKingdoms[$kid] = $kid;
                    }
                    continue;
                }
                if ($kid > 0) {
                    $candKingdoms[$kid] = $kid;
                }
                if ($pid > 0) {
                    $candParks[$pid] = $pid;
                }
            }
        }
        // Principalities under any candidate (bounded: the hierarchy is shallow, and
        // a corrupt cycle must not loop).
        $frontier = $candKingdoms;
        for ($depth = 0; $frontier && $depth < 10; $depth++) {
            $this->db->Clear();
            $ch = $this->db->DataSet(
                'SELECT kingdom_id FROM ' . DB_PREFIX . 'kingdom WHERE parent_kingdom_id IN (' . implode(',', $frontier) . ')'
            );
            $frontier = [];
            if ($ch) {
                while ($ch->Next()) {
                    $kid = (int) $ch->kingdom_id;
                    if ($kid > 0 && !isset($candKingdoms[$kid])) {
                        $candKingdoms[$kid] = $kid;
                        $frontier[$kid]     = $kid;
                    }
                }
            }
        }

        $auth = Ork3::$Lib->authorization;
        $officerKingdoms = [];
        $authKingdoms    = [];
        foreach ($candKingdoms as $kid) {
            if ($this->officerLevel($viewer, $kid, 0) === self::LEVEL_KINGDOM) {
                $officerKingdoms[] = $kid;
            }
            if ($auth->HasAuthority($viewer, AUTH_KINGDOM, $kid, AUTH_EDIT)) {
                $authKingdoms[] = $kid;
            }
        }
        $authParks = [];
        foreach ($candParks as $pid) {
            if ($auth->HasAuthority($viewer, AUTH_PARK, $pid, AUTH_EDIT)) {
                $authParks[] = $pid;
            }
        }
        sort($officerKingdoms);
        sort($authKingdoms);
        sort($authParks);
        ksort($seatParks);

        return $this->grantMemo[$viewer] = [
            'officerKingdoms' => $officerKingdoms,
            'authKingdoms'    => $authKingdoms,
            'authParks'       => $authParks,
            'seatParks'       => array_values($seatParks),
        ];
    }

    /**
     * SQL twin of canSee() for paged / counted readers: visible iff the row's
     * effective level is 0, or (viewer is not the recipient AND (admin OR author OR
     * officerLevel(viewer, m.kingdom_id, m.park_id) >= level)). The officer half
     * reads the recipient's OWN mundane kingdom and park, exactly as canSee() does, so
     * a recipient whose mundane.kingdom_id differs from their park's kingdom gets the
     * same verdict from both. $kingdomIds (the caller's scope) only adds candidate
     * kingdoms; the viewer's grants are found from their own rows (viewerGrants).
     *
     * Memoized per (viewer, aliases, scope) for the request.
     */
    public function sqlPredicate(int $viewer, string $recAlias, string $mundaneAlias, array $kingdomIds): string
    {
        $eff = self::effectiveLevelSql($recAlias);
        if ($viewer <= 0) {
            return "($eff = 0)";
        }
        $scope = array_values(array_unique(array_filter(array_map('intval', $kingdomIds), function ($k) {
            return $k > 0;
        })));
        sort($scope);
        $memoKey = $viewer . '|' . $recAlias . '|' . $mundaneAlias . '|' . implode(',', $scope);
        if (isset($this->predicateMemo[$memoKey])) {
            return $this->predicateMemo[$memoKey];
        }

        $notRecipient = "$recAlias.mundane_id <> $viewer";
        $parts = ["$eff = 0"];
        if ($this->isAdmin($viewer)) {
            $parts[] = $notRecipient;
            $this->db->Clear();

            return $this->predicateMemo[$memoKey] = '(' . implode(' OR ', $parts) . ')';
        }
        $parts[] = "($recAlias.recommended_by_id = $viewer AND $notRecipient)";

        $g = $this->viewerGrants($viewer);
        $officerKingdoms = $g['officerKingdoms'];
        foreach ($scope as $kid) {
            if (!in_array($kid, $officerKingdoms, true) && $this->officerLevel($viewer, $kid, 0) === self::LEVEL_KINGDOM) {
                $officerKingdoms[] = $kid;
            }
        }
        if ($officerKingdoms) {
            $parts[] = "($notRecipient AND $mundaneAlias.kingdom_id IN (" . implode(',', $officerKingdoms) . '))';
        }

        // Park level = Court::canManage(viewer, m.kingdom_id, m.park_id) beyond the
        // kingdom grants above: a park authorization (direct, or through authority
        // over the park's own kingdom), or a park seat filed under the recipient's
        // kingdom. officerLevel() treats kingdom 0 as "no officer", hence the guard.
        $parkTerms = [];
        if ($g['authParks']) {
            $parkTerms[] = "$mundaneAlias.park_id IN (" . implode(',', $g['authParks']) . ')';
        }
        if ($g['authKingdoms']) {
            $parkTerms[] = "$mundaneAlias.park_id IN (SELECT vp.park_id FROM " . DB_PREFIX . 'park vp WHERE vp.kingdom_id IN (' . implode(',', $g['authKingdoms']) . '))';
        }
        foreach ($g['seatParks'] as [$sk, $sp]) {
            $parkTerms[] = "($mundaneAlias.park_id = $sp AND $mundaneAlias.kingdom_id = $sk)";
        }
        if ($parkTerms) {
            $parts[] = "($notRecipient AND $eff = 1 AND $mundaneAlias.kingdom_id > 0 AND (" . implode(' OR ', $parkTerms) . '))';
        }
        // Callers build this predicate INLINE, after their own Clear(), on the shared
        // handle. The authority lookups above (yapo finds) leave bindings on it, which
        // would silently empty the caller's query — hand the handle back clean.
        $this->db->Clear();

        return $this->predicateMemo[$memoKey] = '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * Is $viewer an officer anywhere in kingdom $kingdomId — of the kingdom itself or
     * of any park in it? Gates whether the Kingdom page's Add Rec modal OFFERS the
     * Monarchy Only checkbox; the per-recipient answer is creatorLevel(), and the
     * server's create path stays the authority.
     */
    public function isOfficerInKingdom(int $viewer, int $kingdomId): bool
    {
        if ($viewer <= 0 || $kingdomId <= 0) {
            return false;
        }
        if ($this->officerLevel($viewer, $kingdomId, 0) === self::LEVEL_KINGDOM) {
            return true;
        }
        $g = $this->viewerGrants($viewer);
        $parks = $g['authParks'];
        foreach ($g['seatParks'] as [$sk, $sp]) {
            if ($sk === $kingdomId) {
                $parks[] = $sp;
            }
        }
        if (!$parks) {
            return false;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT park_id FROM ' . DB_PREFIX . 'park WHERE kingdom_id = ' . $kingdomId
            . ' AND park_id IN (' . implode(',', array_map('intval', array_unique($parks))) . ')'
        );
        $inKingdom = [];
        if ($rs) {
            while ($rs->Next()) {
                $inKingdom[] = (int) $rs->park_id;
            }
        }
        foreach ($inKingdom as $pid) {
            if ($this->officerLevel($viewer, $kingdomId, $pid) >= self::LEVEL_PARK) {
                return true;
            }
        }

        return false;
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
