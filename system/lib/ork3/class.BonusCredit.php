<?php

/**
 * Bonus credits: class credits that are NOT attendance.
 *
 * A credit in the ORK has always been an attendance row, and everything that
 * counts attendance (voting eligibility, park and kingdom tallies, the recap,
 * the explorers) reads that table. A bonus credit is "a credit for filling in
 * the survey" or "a credit for being purple": it counts toward the player's
 * class levels and nowhere else. So it lives in its own tables, and the ONLY
 * reader of the credits is Player::GetPlayerClasses(), which folds them in
 * next to the class-reconciliation offset. Nothing that counts attendance can
 * see them.
 *
 * A credit is granted FOR a reason: the named thing an officer picks in the
 * Bonus Credits modal. Reasons are defined at KINGDOM level (a principality
 * counts as a kingdom) or AMTGARD-WIDE (scope 'ork', by ORK admins); parks do
 * not create them, they hand them out. An Amtgard-wide credit reaches a
 * kingdom only once that kingdom ADOPTS it (kingdom CREATE, or an ORK admin on
 * its behalf; both audited); a parent's adoption covers its principalities.
 *
 * Authority is kingdom-gated at both ends. Creating, archiving or restoring
 * a reason needs CREATE on its kingdom (admin-level). Granting one needs EDIT on the
 * player's park (the test entering attendance needs; kingdom officers hold it
 * for every park in their kingdom) AND the player's home must lie inside the
 * reason's kingdom or one of its principalities. So a Kingdom A officer
 * cannot credit a Kingdom B player, a park can only credit its own, and an
 * ORK-wide survey becomes a per-kingdom decision.
 *
 * A kingdom can also name GRANTERS (ork_bonus_credit_granter, modelled on the
 * qualification tests' manager list): people who may grant and remove credits
 * anywhere in that kingdom without holding a kingdom authorization row, so the
 * person running a survey need not be given kingdom EDIT. A granter holds the
 * EDIT-level powers only: they cannot define, archive or restore a credit, and
 * cannot appoint other granters. A granter row covers either every credit of
 * the kingdom (reason_id 0, the default) or one specific credit, so the person
 * running a survey can be limited to that survey's credit. Only kingdom CREATE
 * appoints or removes one.
 *
 * A reason is granted once per player unless marked repeatable. An automated
 * source (a survey) is a reason with source_type/source_id set, so "once per
 * survey" is the same rule.
 *
 * Not registered on Ork3::$Lib on purpose: the JSON gateway exposes every
 * public method of a $Lib class, and these take a caller uid, not a token.
 * Callers reach it through Model_BonusCredit.
 *
 * Envelope: ['Status' => 0, ...] ok; 1 bad request; 3 not authorized.
 */
class BonusCredit
{
    public const SOURCE_MANUAL = 'manual';

    /** The most one grant may be worth; a level is 10+ credits, so this is a hard cap on fat fingers. */
    public const MAX_CREDITS = 10.0;

    /** Nothing in the ORK predates this. */
    private const EARLIEST_DATE = '1983-01-01';

    private $db;

    public function __construct()
    {
        global $DB;
        $this->db = $DB;
    }

    // -----------------------------------------------------------------------
    // Authority and coverage
    // -----------------------------------------------------------------------

    /** A global ORK admin: an unscoped admin authorization row. */
    public function isOrkAdmin(int $uid): bool
    {
        return $uid > 0 && (bool) Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN);
    }

    /** May $uid appoint or remove this kingdom's granters? CREATE on it (ADMIN passes, EDIT does not). Granters cannot. */
    public function canAppoint(int $uid, int $kingdomId): bool
    {
        if ($uid <= 0 || !valid_id($kingdomId)) {
            return false;
        }
        return (bool) Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kingdomId, AUTH_CREATE);
    }

    /**
     * May $uid define credits for this kingdom? CREATE on it (which ADMIN
     * satisfies, EDIT does not): defining what a credit is worth is an
     * admin-level call, while handing one out is ordinary EDIT work. Granters
     * do not qualify.
     */
    public function canDefine(int $uid, string $scopeType, int $scopeId): bool
    {
        if ($uid <= 0) {
            return false;
        }
        if ($scopeType === 'ork') {
            return $this->isOrkAdmin($uid);
        }
        return $scopeType === 'kingdom' && valid_id($scopeId) && $this->canAppoint($uid, $scopeId);
    }

    /** May $uid hand out credits anywhere in this kingdom? EDIT on it, or a granter of it. The kingdom page's modal opens on this. */
    public function canGrantInKingdom(int $uid, int $kingdomId): bool
    {
        if ($uid <= 0 || !valid_id($kingdomId)) {
            return false;
        }
        return (bool) Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kingdomId, AUTH_EDIT)
            || $this->isGranter($uid, $kingdomId);
    }

    /**
     * May $uid hand out credits to this park's players? EDIT on the park, as
     * for attendance (kingdom officers inherit it), or a granter of the park's
     * kingdom or of a kingdom above it.
     */
    public function canGrant(int $uid, int $parkId): bool
    {
        if ($uid <= 0 || !valid_id($parkId)) {
            return false;
        }
        if (Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, $parkId, AUTH_EDIT)) {
            return true;
        }
        foreach ($this->kingdomAncestry($this->parkKingdom($parkId)) as $kid) {
            if ($this->isGranter($uid, $kid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Is $uid a granter for this kingdom? With $reasonId, for that credit in
     * particular: an all-credits row (reason_id 0) or a row naming the credit.
     * Without it, any row at all (enough to open the grant tools).
     */
    public function isGranter(int $uid, int $kingdomId, int $reasonId = 0): bool
    {
        if ($uid <= 0 || !valid_id($kingdomId)) {
            return false;
        }
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT 1 AS ok FROM ' . DB_PREFIX . 'bonus_credit_granter
             WHERE kingdom_id = ' . (int) $kingdomId . ' AND mundane_id = ' . (int) $uid
             . ($reasonId > 0 ? ' AND reason_id IN (0, ' . (int) $reasonId . ')' : '') . '
             LIMIT 1'
        );
        return (bool) ($r && $r->Next());
    }

    /**
     * May $uid grant THIS credit to a player at THIS park? EDIT on the park,
     * or a granter row for the credit's kingdom that covers the credit.
     */
    public function canGrantReason(int $uid, int $parkId, array $reason): bool
    {
        if ($uid <= 0 || !valid_id($parkId)) {
            return false;
        }
        if (Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, $parkId, AUTH_EDIT)) {
            return true;
        }
        $rid = (int) ($reason['ReasonId'] ?? 0);
        if (($reason['ScopeType'] ?? '') === 'kingdom') {
            return $this->isGranter($uid, (int) $reason['ScopeId'], $rid);
        }
        if (($reason['ScopeType'] ?? '') === 'ork') {
            // A kingdom's granters can be scoped to an adopted Amtgard-wide credit.
            foreach ($this->kingdomAncestry($this->parkKingdom($parkId)) as $kid) {
                if ($this->isGranter($uid, $kid, $rid)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** The credits $uid may actually grant at this park: all of them with park EDIT, else those their granter rows cover. */
    public function grantableReasonsForPark(int $uid, int $parkId): array
    {
        $all = $this->reasonsForPark($parkId);
        if (Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, $parkId, AUTH_EDIT)) {
            return $all;
        }
        return array_values(array_filter($all, function ($rs) use ($uid, $parkId) {
            return $this->canGrantReason($uid, $parkId, $rs);
        }));
    }

    /**
     * The credits grantable in a kingdom: its own (archived too on request) plus
     * the Amtgard-wide ones it or a kingdom above it has adopted. All of them
     * with kingdom EDIT, else those $uid's granter rows cover.
     */
    public function grantableReasonsInKingdom(int $uid, int $kingdomId, bool $includeArchived = false): array
    {
        $all = $this->reasonsForKingdom($kingdomId, $includeArchived);
        if (Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kingdomId, AUTH_EDIT)) {
            return $all;
        }
        return array_values(array_filter($all, function ($rs) use ($uid, $kingdomId) {
            return $this->isGranter($uid, $kingdomId, (int) $rs['ReasonId']);
        }));
    }

    // -----------------------------------------------------------------------
    // Granters
    // -----------------------------------------------------------------------

    /** A kingdom's granters, by persona. */
    public function granters(int $kingdomId): array
    {
        if (!valid_id($kingdomId)) {
            return [];
        }
        $out = [];
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT bm.granter_id, bm.mundane_id, bm.reason_id, bm.added_by, bm.added_at, m.persona, p.name AS park_name,
                    a.persona AS added_by_persona, rs.name AS reason_name, rs.archived_at AS reason_archived_at
             FROM ' . DB_PREFIX . 'bonus_credit_granter bm
             LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = bm.mundane_id
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = m.park_id
             LEFT JOIN ' . DB_PREFIX . 'mundane a ON a.mundane_id = bm.added_by
             LEFT JOIN ' . DB_PREFIX . 'bonus_credit_reason rs ON rs.reason_id = bm.reason_id
             WHERE bm.kingdom_id = ' . (int) $kingdomId . '
             ORDER BY m.persona, bm.reason_id'
        );
        while ($r && $r->Next()) {
            $out[] = [
                'GranterId'      => (int) $r->granter_id,
                'MundaneId'      => (int) $r->mundane_id,
                'Persona'        => (string) ($r->persona ?? ''),
                'Park'           => (string) ($r->park_name ?? ''),
                'ReasonId'       => (int) $r->reason_id,
                'ReasonName'     => (int) $r->reason_id > 0 ? (string) ($r->reason_name ?? '') : '',
                'AllCredits'     => (int) $r->reason_id === 0,
                // A per-credit row whose credit is archived is dormant: shown so it can be removed, live again on restore.
                'ReasonArchived' => (int) $r->reason_id > 0 && !empty($r->reason_archived_at),
                'AddedBy'        => (int) $r->added_by,
                'AddedByPersona' => (string) ($r->added_by_persona ?? ''),
                'AddedAt'        => (string) $r->added_at,
            ];
        }
        return $out;
    }

    /**
     * Appoint a granter for every credit of the kingdom ($reasonId 0) or for
     * one credit. Kingdom CREATE only. An all-credits appointment replaces the
     * player's per-credit rows; a per-credit one under an existing all-credits
     * row is a no-op. Idempotent.
     */
    public function addGranter(int $uid, int $kingdomId, int $mundaneId, int $reasonId = 0): array
    {
        if (!valid_id($kingdomId) || !$this->orgExists('kingdom', $kingdomId)) {
            return $this->fail('That kingdom does not exist.');
        }
        if ($uid <= 0 || !$this->canAppoint($uid, $kingdomId)) {
            return $this->denied('Only kingdom officers with CREATE authority can appoint granters.');
        }
        $player = $this->playerRow($mundaneId);
        if ($player === null) {
            return $this->fail('Player not found.');
        }
        $reasonId = max(0, $reasonId);
        $reason = null;
        if ($reasonId > 0) {
            $reason = $this->reason($reasonId);
            $own     = $reason !== null && $reason['ScopeType'] === 'kingdom' && $reason['ScopeId'] === $kingdomId;
            $adopted = $reason !== null && $reason['ScopeType'] === 'ork' && $this->isAdoptedIn($reasonId, $this->kingdomAncestry($kingdomId));
            if (!$own && !$adopted) {
                return $this->fail('That credit is not one of this kingdom\'s (an Amtgard-wide credit must be adopted first).');
            }
        }
        if ($this->isGranter($mundaneId, $kingdomId, $reasonId > 0 ? $reasonId : 0) && ($reasonId > 0 || $this->hasAllCreditsRow($mundaneId, $kingdomId))) {
            return ['Status' => 0, 'Granters' => $this->granters($kingdomId)];
        }
        if ($reasonId === 0) {
            // All credits subsumes any per-credit rows this player had.
            $this->db->Clear();
            $this->db->ExecuteChecked('DELETE FROM ' . DB_PREFIX . 'bonus_credit_granter WHERE kingdom_id = ' . (int) $kingdomId . ' AND mundane_id = ' . (int) $mundaneId);
        }
        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'INSERT INTO ' . DB_PREFIX . 'bonus_credit_granter (kingdom_id, mundane_id, reason_id, added_by, added_at)
             VALUES (' . (int) $kingdomId . ', ' . (int) $mundaneId . ', ' . (int) $reasonId . ', ' . $uid . ', NOW())'
        );
        if ($ok === false) {
            return $this->fail('Could not add the granter.');
        }
        $this->audit(
            'addGranter',
            ['KingdomId' => $kingdomId, 'MundaneId' => $mundaneId, 'ReasonId' => $reasonId, 'by_whom_id' => $uid],
            'Kingdom',
            $kingdomId,
            null,
            ['MundaneId' => $mundaneId, 'Persona' => $player['persona'], 'ReasonId' => $reasonId, 'ReasonName' => $reason['Name'] ?? '', 'AllCredits' => $reasonId === 0]
        );
        return ['Status' => 0, 'Granters' => $this->granters($kingdomId)];
    }

    /** Remove one granter row by id. Kingdom CREATE on that row's kingdom. Idempotent. */
    public function removeGranter(int $uid, int $kingdomId, int $granterId): array
    {
        if (!valid_id($kingdomId)) {
            return $this->fail('That kingdom does not exist.');
        }
        if ($uid <= 0 || !$this->canAppoint($uid, $kingdomId)) {
            return $this->denied('Only kingdom officers with CREATE authority can remove granters.');
        }
        $row = null;
        foreach ($this->granters($kingdomId) as $g) {
            if ($g['GranterId'] === $granterId) {
                $row = $g;
            }
        }
        if ($row === null) {
            return ['Status' => 0, 'Granters' => $this->granters($kingdomId)];
        }
        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'DELETE FROM ' . DB_PREFIX . 'bonus_credit_granter WHERE granter_id = ' . (int) $granterId . ' AND kingdom_id = ' . (int) $kingdomId
        );
        if ($ok === false) {
            return $this->fail('Could not remove the granter.');
        }
        $this->audit(
            'removeGranter',
            ['KingdomId' => $kingdomId, 'GranterId' => $granterId, 'by_whom_id' => $uid],
            'Kingdom',
            $kingdomId,
            ['MundaneId' => $row['MundaneId'], 'Persona' => $row['Persona'], 'ReasonId' => $row['ReasonId'], 'ReasonName' => $row['ReasonName'], 'AllCredits' => $row['AllCredits']],
            null
        );
        return ['Status' => 0, 'Granters' => $this->granters($kingdomId)];
    }

    private function hasAllCreditsRow(int $mundaneId, int $kingdomId): bool
    {
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT 1 AS ok FROM ' . DB_PREFIX . 'bonus_credit_granter
             WHERE kingdom_id = ' . (int) $kingdomId . ' AND mundane_id = ' . (int) $mundaneId . ' AND reason_id = 0 LIMIT 1'
        );
        return (bool) ($r && $r->Next());
    }

    /**
     * Does a reason's scope contain the player's home? A kingdom covers
     * players whose home kingdom is it or one of its principalities; 'ork'
     * (reserved) covers everyone with a home. PURE given the family list.
     *
     * @param array{park_id:int, kingdom_id:int} $player
     * @param int[] $kingdomFamily  the kingdom and every principality under it
     */
    public static function covers(string $scopeType, int $scopeId, array $player, array $kingdomFamily = []): bool
    {
        $home = (int) ($player['kingdom_id'] ?? 0);
        if ($scopeType === 'ork') {
            return $home > 0;
        }
        if ($scopeType === 'kingdom') {
            return $home > 0 && $scopeId > 0 && ($home === $scopeId || in_array($home, $kingdomFamily, true));
        }
        return false;
    }

    /** A kingdom and every principality under it, to depth 5. */
    public function kingdomFamily(int $kingdomId): array
    {
        if (!valid_id($kingdomId)) {
            return [];
        }
        $ids      = [$kingdomId => true];
        $frontier = [$kingdomId];
        for ($depth = 0; $frontier && $depth < 5; $depth++) {
            $next = [];
            $this->db->Clear();
            $r = $this->db->DataSet(
                'SELECT kingdom_id FROM ' . DB_PREFIX . 'kingdom
                 WHERE parent_kingdom_id IN (' . implode(',', array_map('intval', $frontier)) . ')'
            );
            while ($r && $r->Next()) {
                $cid = (int) $r->kingdom_id;
                if (!isset($ids[$cid])) {
                    $ids[$cid] = true;
                    $next[]    = $cid;
                }
            }
            $frontier = $next;
        }
        return array_keys($ids);
    }

    /** A kingdom and every kingdom above it (a principality's parent chain), to depth 5. Nearest first. */
    public function kingdomAncestry(int $kingdomId): array
    {
        $chain = [];
        $cur   = $kingdomId;
        for ($depth = 0; valid_id($cur) && $depth < 6 && !in_array($cur, $chain, true); $depth++) {
            $chain[] = $cur;
            $this->db->Clear();
            $r = $this->db->DataSet('SELECT parent_kingdom_id FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ' . (int) $cur);
            $cur = ($r && $r->Next()) ? (int) $r->parent_kingdom_id : 0;
        }
        return $chain;
    }

    // -----------------------------------------------------------------------
    // Reasons: the things credits are granted for
    // -----------------------------------------------------------------------

    /**
     * The reasons an org offers, name order; archived ones only on request.
     *
     * @return list<array<string, mixed>>
     */
    public function reasons(string $scopeType, int $scopeId, bool $includeArchived = false): array
    {
        if ($scopeType === 'ork') {
            return $this->reasonRows('r.scope_type = \'ork\'' . ($includeArchived ? '' : ' AND r.archived_at IS NULL'));
        }
        if ($scopeType !== 'kingdom' || !valid_id($scopeId)) {
            return [];
        }
        return $this->reasonRows(
            'r.scope_type = \'kingdom\' AND r.scope_id = ' . (int) $scopeId . ($includeArchived ? '' : ' AND r.archived_at IS NULL')
        );
    }

    /**
     * The credits a park may hand out: those of its kingdom and of every
     * kingdom above it (a principality's parks get the parent kingdom's too),
     * plus the Amtgard-wide ones any of those kingdoms adopted. Live only.
     */
    public function reasonsForPark(int $parkId): array
    {
        $kingdomId = $this->parkKingdom($parkId);
        return $kingdomId > 0 ? $this->reasonsForKingdom($kingdomId, false) : [];
    }

    /** A kingdom's own credits plus the Amtgard-wide ones adopted by it or a kingdom above it. */
    public function reasonsForKingdom(int $kingdomId, bool $includeArchived = false): array
    {
        if (!valid_id($kingdomId)) {
            return [];
        }
        $chain = implode(',', array_map('intval', $this->kingdomAncestry($kingdomId)));
        return $this->reasonRows(
            '((r.scope_type = \'kingdom\' AND r.scope_id IN (' . $chain . '))
              OR (r.scope_type = \'ork\' AND r.reason_id IN (
                    SELECT ad.reason_id FROM ' . DB_PREFIX . 'bonus_credit_adoption ad
                    WHERE ad.kingdom_id IN (' . $chain . ') AND ad.withdrawn_at IS NULL)))'
            . ($includeArchived ? '' : ' AND r.archived_at IS NULL')
        );
    }

    // -----------------------------------------------------------------------
    // Adoption: how an Amtgard-wide credit reaches a kingdom
    // -----------------------------------------------------------------------

    /** Is this Amtgard-wide credit adopted by any of these kingdoms (a player's kingdom and those above it)? */
    public function isAdoptedIn(int $reasonId, array $kingdomIds): bool
    {
        $ids = array_filter(array_map('intval', $kingdomIds));
        if (!valid_id($reasonId) || !$ids) {
            return false;
        }
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT 1 AS ok FROM ' . DB_PREFIX . 'bonus_credit_adoption
             WHERE reason_id = ' . (int) $reasonId . ' AND kingdom_id IN (' . implode(',', $ids) . ') AND withdrawn_at IS NULL LIMIT 1'
        );
        return (bool) ($r && $r->Next());
    }

    /** Every live Amtgard-wide credit, each marked with whether this kingdom (itself, not a parent) has adopted it. */
    public function orkCreditsFor(int $kingdomId): array
    {
        $out = [];
        foreach ($this->reasons('ork', 0) as $rs) {
            $ad = $this->adoptionRow($rs['ReasonId'], $kingdomId);
            $rs['Adopted']          = $ad !== null && $ad['WithdrawnAt'] === null;
            $rs['AdoptedByPersona'] = $rs['Adopted'] ? $ad['AdoptedByPersona'] : '';
            $rs['AdoptedAt']        = $rs['Adopted'] ? $ad['AdoptedAt'] : null;
            $rs['InheritedFrom']    = '';
            if (!$rs['Adopted']) {
                foreach (array_slice($this->kingdomAncestry($kingdomId), 1) as $parent) {
                    if ($this->isAdoptedIn($rs['ReasonId'], [$parent])) {
                        $rs['InheritedFrom'] = $this->kingdomName($parent);
                        break;
                    }
                }
            }
            $out[] = $rs;
        }
        return $out;
    }

    /** The kingdoms that have adopted an Amtgard-wide credit (live adoptions), by name. */
    public function adoptions(int $reasonId): array
    {
        if (!valid_id($reasonId)) {
            return [];
        }
        $out = [];
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT ad.adoption_id, ad.kingdom_id, ad.adopted_by, ad.adopted_at, k.name AS kingdom_name, a.persona AS adopted_by_persona
             FROM ' . DB_PREFIX . 'bonus_credit_adoption ad
             LEFT JOIN ' . DB_PREFIX . 'kingdom k ON k.kingdom_id = ad.kingdom_id
             LEFT JOIN ' . DB_PREFIX . 'mundane a ON a.mundane_id = ad.adopted_by
             WHERE ad.reason_id = ' . (int) $reasonId . ' AND ad.withdrawn_at IS NULL
             ORDER BY k.name'
        );
        while ($r && $r->Next()) {
            $out[] = [
                'AdoptionId'       => (int) $r->adoption_id,
                'KingdomId'        => (int) $r->kingdom_id,
                'KingdomName'      => (string) ($r->kingdom_name ?? ''),
                'AdoptedBy'        => (int) $r->adopted_by,
                'AdoptedByPersona' => (string) ($r->adopted_by_persona ?? ''),
                'AdoptedAt'        => (string) $r->adopted_at,
            ];
        }
        return $out;
    }

    /** Adopt an Amtgard-wide credit for a kingdom. Kingdom CREATE, or an ORK admin on its behalf. Idempotent. */
    public function adopt(int $uid, int $reasonId, int $kingdomId): array
    {
        return $this->setAdopted($uid, $reasonId, $kingdomId, true);
    }

    /** Withdraw a kingdom's adoption. Same authority. Credits already granted stay. Idempotent. */
    public function withdraw(int $uid, int $reasonId, int $kingdomId): array
    {
        return $this->setAdopted($uid, $reasonId, $kingdomId, false);
    }

    private function setAdopted(int $uid, int $reasonId, int $kingdomId, bool $adopt): array
    {
        $reason = $this->reason($reasonId);
        if ($reason === null || $reason['ScopeType'] !== 'ork') {
            return $this->fail('That is not an Amtgard-wide credit.');
        }
        if (!valid_id($kingdomId) || !$this->orgExists('kingdom', $kingdomId)) {
            return $this->fail('That kingdom does not exist.');
        }
        if ($uid <= 0 || !($this->canAppoint($uid, $kingdomId) || $this->isOrkAdmin($uid))) {
            return $this->denied('Only kingdom officers with CREATE authority, or an ORK admin, can adopt or withdraw a credit for a kingdom.');
        }
        if ($adopt && $reason['ArchivedAt'] !== null) {
            return $this->fail('That credit is archived and cannot be adopted.');
        }
        $row  = $this->adoptionRow($reasonId, $kingdomId);
        $live = $row !== null && $row['WithdrawnAt'] === null;
        if ($adopt === $live) {
            return ['Status' => 0, 'Adopted' => $live];
        }
        $this->db->Clear();
        if ($adopt && $row === null) {
            $ok = $this->db->ExecuteChecked(
                'INSERT INTO ' . DB_PREFIX . 'bonus_credit_adoption (reason_id, kingdom_id, adopted_by, adopted_at)
                 VALUES (' . (int) $reasonId . ', ' . (int) $kingdomId . ', ' . $uid . ', NOW())'
            );
        } elseif ($adopt) {
            $ok = $this->db->ExecuteChecked(
                'UPDATE ' . DB_PREFIX . 'bonus_credit_adoption
                 SET adopted_by = ' . $uid . ', adopted_at = NOW(), withdrawn_by = NULL, withdrawn_at = NULL
                 WHERE reason_id = ' . (int) $reasonId . ' AND kingdom_id = ' . (int) $kingdomId
            );
        } else {
            $ok = $this->db->ExecuteChecked(
                'UPDATE ' . DB_PREFIX . 'bonus_credit_adoption
                 SET withdrawn_by = ' . $uid . ', withdrawn_at = NOW()
                 WHERE reason_id = ' . (int) $reasonId . ' AND kingdom_id = ' . (int) $kingdomId
            );
        }
        if ($ok === false) {
            return $this->fail('Could not update the adoption.');
        }
        $this->audit(
            $adopt ? 'adopt' : 'withdraw',
            ['ReasonId' => $reasonId, 'KingdomId' => $kingdomId, 'by_whom_id' => $uid, 'as_ork_admin' => !$this->canAppoint($uid, $kingdomId)],
            'Kingdom',
            $kingdomId,
            ['ReasonName' => $reason['Name'], 'Adopted' => $live],
            ['ReasonName' => $reason['Name'], 'Adopted' => $adopt]
        );
        return ['Status' => 0, 'Adopted' => $adopt];
    }

    private function adoptionRow(int $reasonId, int $kingdomId): ?array
    {
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT ad.*, a.persona AS adopted_by_persona FROM ' . DB_PREFIX . 'bonus_credit_adoption ad
             LEFT JOIN ' . DB_PREFIX . 'mundane a ON a.mundane_id = ad.adopted_by
             WHERE ad.reason_id = ' . (int) $reasonId . ' AND ad.kingdom_id = ' . (int) $kingdomId
        );
        if ($r && $r->Next()) {
            $f = $r->CurrentFieldSet();
            return [
                'AdoptedBy'        => (int) $f['adopted_by'],
                'AdoptedByPersona' => (string) ($f['adopted_by_persona'] ?? ''),
                'AdoptedAt'        => (string) $f['adopted_at'],
                'WithdrawnAt'      => ($f['withdrawn_at'] === null || $f['withdrawn_at'] === '') ? null : (string) $f['withdrawn_at'],
            ];
        }
        return null;
    }

    private function kingdomName(int $kingdomId): string
    {
        $this->db->Clear();
        $r = $this->db->DataSet('SELECT name FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ' . (int) $kingdomId);
        return ($r && $r->Next()) ? (string) $r->name : '';
    }

    private function reasonRows(string $where): array
    {
        $out = [];
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT r.*, k.name AS scope_name,
                    (SELECT COUNT(*) FROM ' . DB_PREFIX . 'bonus_credit b WHERE b.reason_id = r.reason_id AND b.revoked_at IS NULL) AS granted,
                    (SELECT COUNT(*) FROM ' . DB_PREFIX . 'bonus_credit_adoption ad WHERE ad.reason_id = r.reason_id AND ad.withdrawn_at IS NULL) AS adoptions
             FROM ' . DB_PREFIX . 'bonus_credit_reason r
             LEFT JOIN ' . DB_PREFIX . 'kingdom k ON r.scope_type = \'kingdom\' AND k.kingdom_id = r.scope_id
             WHERE ' . $where . '
             ORDER BY r.archived_at IS NOT NULL, r.name'
        );
        while ($r && $r->Next()) {
            $out[] = $this->reasonFromRow($r->CurrentFieldSet());
        }
        return $out;
    }

    /** One reason, or null. */
    public function reason(int $reasonId): ?array
    {
        if (!valid_id($reasonId)) {
            return null;
        }
        $this->db->Clear();
        $rows = $this->reasonRows('r.reason_id = ' . (int) $reasonId);
        return $rows[0] ?? null;
    }

    /** The reason an automated source owns at this scope, or null. */
    public function reasonForSource(string $scopeType, int $scopeId, string $sourceType, int $sourceId): ?array
    {
        if (!in_array($scopeType, ['kingdom', 'ork'], true) || ($scopeType === 'kingdom' && !valid_id($scopeId)) || $sourceId <= 0) {
            return null;
        }
        $rows = $this->reasonRows(
            'r.scope_type = \'' . $scopeType . '\' AND r.scope_id = ' . ($scopeType === 'ork' ? 0 : (int) $scopeId) . '
             AND r.source_type = \'' . $this->esc($sourceType) . '\' AND r.source_id = ' . (int) $sourceId
        );
        return $rows[0] ?? null;
    }

    /**
     * Create a reason. $r keys: ScopeType, ScopeId, Name, Description,
     * DefaultCredits (default 1), Repeatable (default 0), SourceType (default
     * 'manual'), SourceId (default 0). Names are unique per org; recreating an
     * archived name restores it instead.
     *
     * @return array{Status:int, Error?:string, ReasonId?:int, Reason?:array}
     */
    public function createReason(int $uid, array $r): array
    {
        $scopeType  = (string) ($r['ScopeType'] ?? '');
        $scopeId    = (int) ($r['ScopeId'] ?? 0);
        $name       = trim(preg_replace('/\s+/', ' ', (string) ($r['Name'] ?? '')));
        $desc       = trim((string) ($r['Description'] ?? ''));
        $repeatable = $this->truthy($r['Repeatable'] ?? 0) ? 1 : 0;
        $sourceType = trim((string) ($r['SourceType'] ?? self::SOURCE_MANUAL));
        $sourceId   = (int) ($r['SourceId'] ?? 0);

        if ($scopeType === 'ork') {
            $scopeId = 0;
        } elseif ($scopeType !== 'kingdom' || !valid_id($scopeId)) {
            return $this->fail('Credits are defined by a kingdom or Amtgard-wide; choose which.');
        }
        if ($uid <= 0 || !$this->canDefine($uid, $scopeType, $scopeId)) {
            return $this->denied($scopeType === 'ork' ? 'Only ORK admins can set up Amtgard-wide credits.' : 'Only kingdom officers with CREATE authority can set up credits.');
        }
        if (!$this->orgExists($scopeType, $scopeId)) {
            return $this->fail('That kingdom does not exist.');
        }
        if ($name === '' || mb_strlen($name) > 100) {
            return $this->fail('Give the credit a name (up to 100 characters).');
        }
        $credits = self::normalizeCredits($r['DefaultCredits'] ?? 1);
        if ($credits === null) {
            return $this->fail('Default credits must be between 0.25 and ' . (int) self::MAX_CREDITS . ', in quarters.');
        }
        if ($sourceType === '' || strlen($sourceType) > 20 || !preg_match('/^[a-z][a-z0-9_]*$/', $sourceType)) {
            return $this->fail('Bad credit source.');
        }
        if ($sourceId < 0 || ($sourceType === self::SOURCE_MANUAL && $sourceId !== 0)) {
            return $this->fail('Bad credit source id.');
        }
        if ($sourceType !== self::SOURCE_MANUAL && $sourceId > 0 && $this->reasonForSource($scopeType, $scopeId, $sourceType, $sourceId) !== null) {
            return $this->fail('That source already has a credit set up for this kingdom.');
        }
        $desc = mb_substr($desc, 0, 500);

        // The same name again: a live one is a duplicate, an archived one comes back.
        $this->db->Clear();
        $ex = $this->db->DataSet(
            'SELECT reason_id, archived_at FROM ' . DB_PREFIX . 'bonus_credit_reason
             WHERE scope_type = \'' . $scopeType . '\' AND scope_id = ' . $scopeId . ' AND name = \'' . $this->esc($name) . '\''
        );
        if ($ex && $ex->Next()) {
            $existingId = (int) $ex->reason_id;
            if ($ex->archived_at === null || $ex->archived_at === '') {
                return $this->fail('This kingdom already has a credit with that name.');
            }
            $this->db->Clear();
            $this->db->ExecuteChecked(
                'UPDATE ' . DB_PREFIX . 'bonus_credit_reason
                 SET archived_by = NULL, archived_at = NULL,
                     description = \'' . $this->esc($desc) . '\', default_credits = ' . number_format($credits, 2, '.', '') . ', repeatable = ' . $repeatable . '
                 WHERE reason_id = ' . $existingId
            );
            $restored = $this->reason($existingId);
            $this->audit('restoreReason', ['ReasonId' => $existingId, 'by_whom_id' => $uid], 'Kingdom', $scopeId, ['archived' => true], $restored);
            return ['Status' => 0, 'ReasonId' => $existingId, 'Reason' => $restored, 'Restored' => true];
        }

        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'INSERT INTO ' . DB_PREFIX . 'bonus_credit_reason
             (scope_type, scope_id, name, description, default_credits, repeatable, source_type, source_id, created_by, created_at)
             VALUES (\'' . $scopeType . '\', ' . $scopeId . ', \'' . $this->esc($name) . '\', \'' . $this->esc($desc) . '\',
                     ' . number_format($credits, 2, '.', '') . ', ' . $repeatable . ', \'' . $this->esc($sourceType) . '\', ' . $sourceId . ',
                     ' . $uid . ', NOW())'
        );
        $id = $ok ? $this->lastInsertId() : 0;
        if ($id <= 0) {
            return $this->fail('Could not save the credit.');
        }
        $created = $this->reason($id);
        $this->audit('createReason', ['Name' => $name, 'ScopeId' => $scopeId, 'by_whom_id' => $uid], 'Kingdom', $scopeId, null, $created);
        return ['Status' => 0, 'ReasonId' => $id, 'Reason' => $created];
    }

    /** Stop offering a reason. Credits already granted for it are untouched. Idempotent. */
    public function archiveReason(int $uid, int $reasonId): array
    {
        return $this->setReasonArchived($uid, $reasonId, true);
    }

    /** Offer an archived reason again. Idempotent. */
    public function restoreReason(int $uid, int $reasonId): array
    {
        return $this->setReasonArchived($uid, $reasonId, false);
    }

    private function setReasonArchived(int $uid, int $reasonId, bool $archived): array
    {
        $reason = $this->reason($reasonId);
        if ($reason === null) {
            return $this->fail('Credit not found.');
        }
        if ($uid <= 0 || !$this->canDefine($uid, $reason['ScopeType'], $reason['ScopeId'])) {
            return $this->denied($reason['ScopeType'] === 'ork'
                ? 'Only ORK admins can change an Amtgard-wide credit.'
                : 'Only the defining kingdom\'s officers with CREATE authority can change this credit.');
        }
        if ($archived === ($reason['ArchivedAt'] !== null)) {
            return ['Status' => 0, 'ReasonId' => $reasonId, 'Reason' => $reason];
        }
        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'UPDATE ' . DB_PREFIX . 'bonus_credit_reason
             SET archived_by = ' . ($archived ? $uid : 'NULL') . ', archived_at = ' . ($archived ? 'NOW()' : 'NULL') . '
             WHERE reason_id = ' . (int) $reasonId
        );
        if ($ok === false) {
            return $this->fail('Could not update the credit.');
        }
        $after = $this->reason($reasonId);
        $this->audit($archived ? 'archiveReason' : 'restoreReason', ['ReasonId' => $reasonId, 'by_whom_id' => $uid], 'Kingdom', $reason['ScopeId'], $reason, $after);
        return ['Status' => 0, 'ReasonId' => $reasonId, 'Reason' => $after];
    }

    // -----------------------------------------------------------------------
    // Credits
    // -----------------------------------------------------------------------

    /**
     * Grant a credit for a reason. $r keys: MundaneId, ClassId, ReasonId,
     * Date (Y-m-d, default today), Note.
     *
     * The amount is the reason's, always: the kingdom defined what the credit
     * is worth, and a grant cannot override it (Ken, 2026-10-14). A different
     * value is a different credit. Anything sent as Credits is ignored.
     *
     * @return array{Status:int, Error?:string, BonusCreditId?:int, Credit?:array}
     */
    public function grant(int $uid, array $r): array
    {
        $mundaneId = (int) ($r['MundaneId'] ?? 0);
        $classId   = (int) ($r['ClassId'] ?? 0);
        $reasonId  = (int) ($r['ReasonId'] ?? 0);
        $note      = trim((string) ($r['Note'] ?? ''));
        $date      = trim((string) ($r['Date'] ?? date('Y-m-d')));

        $reason = $this->reason($reasonId);
        if ($reason === null) {
            return $this->fail('Choose what the credit is for.');
        }
        if ($reason['ArchivedAt'] !== null) {
            return $this->fail('That credit is no longer offered.');
        }
        $player = $this->playerRow($mundaneId);
        if ($player === null) {
            return $this->fail('Player not found.');
        }
        if ($uid <= 0 || !$this->canGrantReason($uid, $player['park_id'], $reason)) {
            return $this->denied('You do not have authority to grant this credit to this player.');
        }
        $family = $reason['ScopeType'] === 'kingdom' ? $this->kingdomFamily($reason['ScopeId']) : [];
        if (!self::covers($reason['ScopeType'], $reason['ScopeId'], $player, $family)) {
            return $this->denied('This credit belongs to another kingdom; it can only go to that kingdom\'s own players.');
        }
        if ($reason['ScopeType'] === 'ork' && !$this->isAdoptedIn($reason['ReasonId'], $this->kingdomAncestry($player['kingdom_id']))) {
            return $this->denied('This player\'s kingdom has not adopted this Amtgard-wide credit.');
        }
        if (!$this->classActive($classId)) {
            return $this->fail('Choose an active class for the credit.');
        }
        $credits = self::normalizeCredits($reason['DefaultCredits']);
        if ($credits === null) {
            return $this->fail('This credit has no usable amount; ask a kingdom officer to fix it.');
        }
        $date = self::normalizeDate($date);
        if ($date === null) {
            return $this->fail('That date is not usable: use YYYY-MM-DD, not in the future.');
        }
        if (!$reason['Repeatable'] && $this->liveForReason($reasonId, $mundaneId) > 0) {
            return $this->fail(($player['persona'] !== '' ? $player['persona'] : 'This player') . ' already has “' . $reason['Name'] . '”, which can only be earned once.');
        }
        $note = mb_substr($note, 0, 200);

        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'INSERT INTO ' . DB_PREFIX . 'bonus_credit
             (mundane_id, class_id, credits, credit_date, reason_id, note, granted_by, granted_at)
             VALUES (' . $mundaneId . ', ' . $classId . ', ' . number_format($credits, 2, '.', '') . ', \'' . $date . '\',
                     ' . $reasonId . ', \'' . $this->esc($note) . '\', ' . $uid . ', NOW())'
        );
        $id = $ok ? $this->lastInsertId() : 0;
        if ($id <= 0) {
            return $this->fail('Could not save the credit.');
        }
        $this->bust($mundaneId);
        $credit = $this->credit($id);
        $this->audit('grant', ['MundaneId' => $mundaneId, 'ReasonId' => $reasonId, 'ClassId' => $classId, 'by_whom_id' => $uid], 'Player', $mundaneId, null, $credit);
        return ['Status' => 0, 'BonusCreditId' => $id, 'Credit' => $credit];
    }

    /**
     * Revoke a credit. The same authority as granting it, on the reason's
     * scope: a park cannot undo a kingdom's credit. Idempotent.
     */
    public function revoke(int $uid, int $bonusCreditId, string $note = ''): array
    {
        $row = $this->credit($bonusCreditId);
        if ($row === null) {
            return $this->fail('Credit not found.');
        }
        $player = $this->playerRow((int) $row['MundaneId']);
        $reason = $this->reason((int) $row['ReasonId']) ?? ['ScopeType' => $row['ScopeType'], 'ScopeId' => $row['ScopeId'], 'ReasonId' => $row['ReasonId']];
        if ($uid <= 0 || $player === null || !$this->canGrantReason($uid, $player['park_id'], $reason)) {
            return $this->denied('You do not have authority to remove this credit.');
        }
        if ($row['RevokedAt'] !== null) {
            return ['Status' => 0, 'BonusCreditId' => $bonusCreditId];
        }
        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'UPDATE ' . DB_PREFIX . 'bonus_credit
             SET revoked_by = ' . $uid . ', revoked_at = NOW(), revoke_note = \'' . $this->esc(mb_substr(trim($note), 0, 200)) . '\'
             WHERE bonus_credit_id = ' . (int) $bonusCreditId . ' AND revoked_at IS NULL'
        );
        if ($ok === false) {
            return $this->fail('Could not revoke the credit.');
        }
        $this->bust($row['MundaneId']);
        $this->audit('revoke', ['BonusCreditId' => $bonusCreditId, 'Note' => $note, 'by_whom_id' => $uid], 'Player', (int) $row['MundaneId'], $row, $this->credit($bonusCreditId));
        return ['Status' => 0, 'BonusCreditId' => $bonusCreditId];
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /** Live credits per class: [class_id => credits]. The figure GetPlayerClasses folds in. */
    public function totalsByClass(int $mundaneId): array
    {
        if (!valid_id($mundaneId)) {
            return [];
        }
        $out = [];
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT class_id, SUM(credits) AS credits FROM ' . DB_PREFIX . 'bonus_credit
             WHERE mundane_id = ' . (int) $mundaneId . ' AND revoked_at IS NULL
             GROUP BY class_id'
        );
        while ($r && $r->Next()) {
            $out[(int) $r->class_id] = (float) $r->credits;
        }
        return $out;
    }

    /** A player's credits, newest first. Revoked ones only on request. */
    public function forPlayer(int $mundaneId, bool $includeRevoked = false): array
    {
        if (!valid_id($mundaneId)) {
            return [];
        }
        return $this->credits('b.mundane_id = ' . (int) $mundaneId . ($includeRevoked ? '' : ' AND b.revoked_at IS NULL'), 500);
    }

    /** Who has a reason's credit, newest first: the modal's "already granted" list. A park host sees its own players only. */
    public function forReason(int $reasonId, int $parkId = 0, int $limit = 200, int $kingdomId = 0): array
    {
        if (!valid_id($reasonId)) {
            return [];
        }
        $where = 'b.reason_id = ' . (int) $reasonId . ' AND b.revoked_at IS NULL';
        if ($parkId > 0) {
            $where .= ' AND m.park_id = ' . (int) $parkId;
        } elseif ($kingdomId > 0) {
            $where .= ' AND m.kingdom_id IN (' . implode(',', array_map('intval', $this->kingdomFamily($kingdomId))) . ')';
        }
        return $this->credits($where, $limit);
    }

    /** One credit with its names, or null. */
    public function credit(int $bonusCreditId): ?array
    {
        if (!valid_id($bonusCreditId)) {
            return null;
        }
        $rows = $this->credits('b.bonus_credit_id = ' . (int) $bonusCreditId, 1);
        return $rows[0] ?? null;
    }

    private function credits(string $where, int $limit): array
    {
        $out = [];
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT b.*, c.name AS class_name, m.persona,
                    r.name AS reason_name, r.scope_type, r.scope_id, r.source_type, r.source_id,
                    CASE r.scope_type WHEN \'kingdom\' THEN k.name ELSE \'Amtgard\' END AS scope_name,
                    g.persona AS granted_by_persona, rv.persona AS revoked_by_persona
             FROM ' . DB_PREFIX . 'bonus_credit b
             JOIN ' . DB_PREFIX . 'bonus_credit_reason r ON r.reason_id = b.reason_id
             LEFT JOIN ' . DB_PREFIX . 'class c ON c.class_id = b.class_id
             LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = b.mundane_id
             LEFT JOIN ' . DB_PREFIX . 'kingdom k ON r.scope_type = \'kingdom\' AND k.kingdom_id = r.scope_id
             LEFT JOIN ' . DB_PREFIX . 'mundane g ON g.mundane_id = b.granted_by
             LEFT JOIN ' . DB_PREFIX . 'mundane rv ON rv.mundane_id = b.revoked_by
             WHERE ' . $where . '
             ORDER BY b.credit_date DESC, b.bonus_credit_id DESC
             LIMIT ' . max(1, (int) $limit)
        );
        while ($r && $r->Next()) {
            $f = $r->CurrentFieldSet();
            $out[] = [
                'BonusCreditId'    => (int) $f['bonus_credit_id'],
                'MundaneId'        => (int) $f['mundane_id'],
                'Persona'          => (string) ($f['persona'] ?? ''),
                'ClassId'          => (int) $f['class_id'],
                'ClassName'        => (string) ($f['class_name'] ?? ''),
                'Credits'          => (float) $f['credits'],
                'Date'             => (string) $f['credit_date'],
                'ReasonId'         => (int) $f['reason_id'],
                'ReasonName'       => (string) $f['reason_name'],
                'ScopeType'        => (string) $f['scope_type'],
                'ScopeId'          => (int) $f['scope_id'],
                'ScopeName'        => (string) ($f['scope_name'] ?? ''),
                'SourceType'       => (string) $f['source_type'],
                'SourceId'         => (int) $f['source_id'],
                'Note'             => (string) $f['note'],
                'GrantedBy'        => (int) $f['granted_by'],
                'GrantedByPersona' => (string) ($f['granted_by_persona'] ?? ''),
                'GrantedAt'        => (string) $f['granted_at'],
                'RevokedBy'        => $f['revoked_by'] === null ? null : (int) $f['revoked_by'],
                'RevokedByPersona' => (string) ($f['revoked_by_persona'] ?? ''),
                'RevokedAt'        => $f['revoked_at'] === null ? null : (string) $f['revoked_at'],
                'RevokeNote'       => (string) $f['revoke_note'],
            ];
        }
        return $out;
    }

    private function reasonFromRow(array $f): array
    {
        return [
            'ReasonId'       => (int) $f['reason_id'],
            'ScopeType'      => (string) $f['scope_type'],
            'ScopeId'        => (int) $f['scope_id'],
            'Name'           => (string) $f['name'],
            'Description'    => (string) $f['description'],
            'DefaultCredits' => (float) $f['default_credits'],
            'Repeatable'     => (int) $f['repeatable'] === 1,
            'SourceType'     => (string) $f['source_type'],
            'SourceId'       => (int) $f['source_id'],
            'ScopeName'      => (string) ($f['scope_name'] ?? ($f['scope_type'] === 'ork' ? 'Amtgard' : '')),
            'Granted'        => (int) ($f['granted'] ?? 0),
            'Adoptions'      => (int) ($f['adoptions'] ?? 0),
            'CreatedBy'      => (int) $f['created_by'],
            'CreatedAt'      => (string) $f['created_at'],
            'ArchivedAt'     => ($f['archived_at'] === null || $f['archived_at'] === '') ? null : (string) $f['archived_at'],
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** Quarter-credit steps between 0.25 and MAX_CREDITS, or null. */
    public static function normalizeCredits($raw): ?float
    {
        if (!is_numeric($raw)) {
            return null;
        }
        $v = round((float) $raw, 2);
        if ($v < 0.25 || $v > self::MAX_CREDITS || abs($v * 4 - round($v * 4)) > 0.0001) {
            return null;
        }
        return $v;
    }

    /** Y-m-d, a real calendar date, not in the future (ORK wall clock), or null. */
    public static function normalizeDate(string $raw): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        if ($raw > date('Y-m-d') || $raw < self::EARLIEST_DATE) {
            return null;
        }
        return $raw;
    }

    private function playerRow(int $mundaneId): ?array
    {
        if (!valid_id($mundaneId)) {
            return null;
        }
        $this->db->Clear();
        $r = $this->db->DataSet('SELECT mundane_id, park_id, kingdom_id, persona FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $mundaneId);
        if ($r && $r->Next()) {
            return ['mundane_id' => (int) $r->mundane_id, 'park_id' => (int) $r->park_id, 'kingdom_id' => (int) $r->kingdom_id, 'persona' => trim((string) $r->persona)];
        }
        return null;
    }

    private function orgExists(string $scopeType, int $scopeId): bool
    {
        if ($scopeType === 'ork') {
            return true;
        }
        if ($scopeType !== 'kingdom') {
            return false;
        }
        $this->db->Clear();
        $r = $this->db->DataSet('SELECT kingdom_id AS id FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ' . (int) $scopeId);
        return (bool) ($r && $r->Next());
    }

    /** The kingdom a park belongs to, or 0. */
    public function parkKingdom(int $parkId): int
    {
        if (!valid_id($parkId)) {
            return 0;
        }
        $this->db->Clear();
        $r = $this->db->DataSet('SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ' . (int) $parkId);
        return ($r && $r->Next()) ? (int) $r->kingdom_id : 0;
    }

    private function classActive(int $classId): bool
    {
        if (!valid_id($classId)) {
            return false;
        }
        $this->db->Clear();
        $r = $this->db->DataSet('SELECT 1 AS ok FROM ' . DB_PREFIX . 'class WHERE class_id = ' . (int) $classId . ' AND active = 1');
        return (bool) ($r && $r->Next());
    }

    private function liveForReason(int $reasonId, int $mundaneId): int
    {
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT COUNT(*) AS n FROM ' . DB_PREFIX . 'bonus_credit
             WHERE reason_id = ' . (int) $reasonId . ' AND mundane_id = ' . (int) $mundaneId . ' AND revoked_at IS NULL'
        );
        return ($r && $r->Next()) ? (int) $r->n : 0;
    }

    private function lastInsertId(): int
    {
        $this->db->Clear();
        $ir = $this->db->DataSet('SELECT LAST_INSERT_ID() AS new_id');
        return ($ir && $ir->Next()) ? (int) $ir->new_id : 0;
    }

    /**
     * The danger audit, as attendance writes it: a credit is a change to a
     * player's record, a reason a change to a kingdom's. by_whom_id on the
     * row comes from the session; the acting uid rides in the parameters as
     * well, as Attendance::ReactivateInactiveMundane does.
     */
    private function audit(string $action, array $params, string $entity, int $entityId, $prior, $post): void
    {
        if (!isset(Ork3::$Lib->dangeraudit)) {
            return;
        }
        try {
            Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::' . $action, $params, $entity, $entityId, $prior, $post);
        } catch (Throwable $e) {
            // The write already happened; a failed audit must not undo it or break the caller.
        }
    }

    /**
     * A grant or revoke must show on the next page load. Two caches hold the
     * class figures: Player.GetPlayerClasses (300s, the per-class totals) and
     * Player.GetPlayerProfileDetails (60s, the profile page's own copy of
     * them, which is what the Class Levels tab renders).
     */
    private function bust(int $mundaneId): void
    {
        $ck = Ork3::$Lib->ghettocache->key(['MundaneId' => $mundaneId]);
        Ork3::$Lib->ghettocache->bust('Player.GetPlayerClasses', $ck);
        Ork3::$Lib->ghettocache->bust('Player.GetPlayerProfileDetails', $ck);
    }

    private function truthy($v): bool
    {
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
    }

    private function esc($v)
    {
        return str_replace(["'", '\\'], ["''", '\\\\'], (string) $v);
    }

    private function fail(string $error): array
    {
        return ['Status' => 1, 'Error' => $error];
    }

    private function denied(string $error): array
    {
        return ['Status' => 3, 'Error' => $error];
    }
}
