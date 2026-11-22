<?php

/**
 * SurveyCredit — the bonus credit for completing a survey
 * (docs/superpowers/specs/2026-09-10-survey-sharing-and-credits-design.md §3).
 *
 * A survey credit is a BONUS credit (class.BonusCredit.php): it counts toward
 * the player's class levels and nowhere else. No attendance row, no voting
 * effect, no event. Each survey gets ONE bonus-credit category, made by the
 * system the first time any org switches credit on ("Survey Bonus - {title}",
 * source_type 'survey', source_id = survey_id; ork_survey.bonus_category_id).
 * A kingdom or park survey's category belongs to the owning kingdom; an
 * Amtgard-wide survey's is ork-scope, and a kingdom switching credit on adopts
 * it for its players.
 *
 * A config (ork_survey_credit) is one org's switch. A park's covers its
 * snapshotted members, a kingdom's its kingdom and principalities, and the
 * survey OWNER's everyone who answered (visitors too, if their own kingdom
 * accepts outside credits). Switching off stamps disabled_at: responses
 * submitted before it are still owed, later ones are not. The ledger
 * (ork_survey_credit_grant) holds at most one credit per player per survey and
 * keeps its row when the credit is revoked, so nobody is credited twice. Only
 * non-test `full` responses whose data gate showed a credit line are ever
 * credited (D1): a dated credit beside a day-stamped partial or anonymous
 * response would re-identify it.
 *
 * All survey-credit SQL lives here; the credit rows themselves are written by
 * BonusCredit::grantFromSource(), which trusts this class to have authorized
 * the grant. Reachable through Ork3::$Lib like every lib class, but never
 * listed in orkservice/Json/index.php, so none of this is on the JSON surface.
 */
class SurveyCredit
{
    /** ork_class row for Color: the credit class for a player with no attendance yet. */
    public const COLOR_CLASS_ID = 6;

    /** ork_bonus_credit_category.source_type for a survey's category. */
    public const SOURCE_TYPE = 'survey';

    public const CATEGORY_PREFIX = 'Survey Bonus - ';

    /** ork_bonus_credit_category.name is varchar(100). */
    public const CATEGORY_NAME_MAX = 100;

    /**
     * A web request (enable, the panel's reconcile) posts at most this many
     * credits, and stops early after SYNC_SECONDS; the rest are left owed for
     * the next panel open or the hourly bin/survey-credit-sweep.php.
     */
    public const SYNC_GRANT_CAP = 200;
    public const SYNC_SECONDS = 10.0;

    /** SYNC_GRANT_CAP, overridable by tests. */
    private int $syncCap = self::SYNC_GRANT_CAP;

    private $db;

    /** @var array<int,int>|null kingdom_id => parent_kingdom_id, non-zero parents only */
    private ?array $parentMemo = null;

    /** @var array<string,array{0:int,1:int}> "type:id" => [kingdom_id, parent_kingdom_id] */
    private array $orgMemo = [];
    /** @var array<string,string> "type:id" => org name */
    private array $nameMemo = [];

    public function __construct()
    {
        global $DB;
        $this->db = $DB;
    }

    // -----------------------------------------------------------------------
    // Pure
    // -----------------------------------------------------------------------

    /**
     * The survey's category name, "Survey Bonus - {title}", cut to the
     * column's 100 characters. With $surveyId > 0 the name carries a " (#id)"
     * suffix: the fallback when another category of the same kingdom already
     * holds the plain name (a manual one, or last year's survey of the same
     * title). The suffix is kept whole; the title gives way.
     */
    public static function categoryName(string $title, int $surveyId = 0): string
    {
        $title  = trim(preg_replace('/\s+/', ' ', $title));
        $suffix = $surveyId > 0 ? ' (#' . $surveyId . ')' : '';
        $room   = self::CATEGORY_NAME_MAX - mb_strlen(self::CATEGORY_PREFIX) - mb_strlen($suffix);
        if (mb_strlen($title) > $room) {
            $title = rtrim(mb_substr($title, 0, $room - 1)) . '…';
        }
        return self::CATEGORY_PREFIX . $title . $suffix;
    }

    /** ork_bonus_credit.note for a survey credit. */
    public static function noteFor(int $surveyId): string
    {
        return 'Survey #' . $surveyId;
    }

    /** The player's last class, or Color when they have no attendance. */
    public static function classFor(int $lastClassId): int
    {
        return $lastClassId > 0 ? $lastClassId : self::COLOR_CLASS_ID;
    }

    /**
     * Does the survey reach this org? The same answer decides who may switch
     * credit on (§3.2) and, one level down, who may see shared results (§2).
     * $grantorKingdom is the org's kingdom (a park's own kingdom); $grantorParent
     * is that kingdom's parent, 0 for none.
     */
    public static function grantorReaches(array $surveyRow, string $type, int $id, int $grantorKingdom, int $grantorParent): bool
    {
        if ($id <= 0 || $grantorKingdom <= 0 || !in_array($type, ['kingdom', 'park'], true)) {
            return false;
        }
        $scopeId = (int) ($surveyRow['scope_id'] ?? 0);
        switch ((string) ($surveyRow['scope_type'] ?? '')) {
            case 'park':
                return $type === 'park' && $id === $scopeId;
            case 'kingdom':
                return $grantorKingdom === $scopeId || ($grantorParent > 0 && $grantorParent === $scopeId);
            case 'ork':
                $list = SurveyResponse::kingdomIdList($surveyRow['audience_kingdom_ids'] ?? null);
                return $list === null
                    || in_array($grantorKingdom, $list, true)
                    || ($grantorParent > 0 && in_array($grantorParent, $list, true));
        }
        return false;
    }

    /**
     * Is this config live for a response submitted at $submittedAt? A switch
     * that was off by then covers nothing; one switched off later (or never)
     * still owes the response its credit.
     */
    public static function configLiveFor(array $config, string $submittedAt): bool
    {
        $off = (string) ($config['disabled_at'] ?? '');
        return $off === '' || $off === '0000-00-00 00:00:00' || $submittedAt < $off;
    }

    /**
     * Which config credits this respondent (§3.2)? The earliest enabled_at
     * wins, ties to the lower credit_id. A park config covers its snapshotted
     * park; a kingdom config its kingdom and that kingdom's principalities;
     * the OWNER's config everyone who answered (a kingdom or park survey's
     * visitors; their own kingdom's accept-outside rule is applied at grant
     * time). A config switched off before the response was submitted is
     * skipped.
     *
     * @param list<array<string,mixed>> $configs    ork_survey_credit rows
     * @param array<string,mixed>       $respondent ['park_id'=>?int, 'kingdom_id'=>?int, 'submitted_at'=>string]
     * @param array<int,int>            $parentOf   kingdom_id => parent_kingdom_id
     * @return ?int credit_id
     */
    public static function coverage(array $configs, array $respondent, array $surveyRow, array $parentOf): ?int
    {
        usort($configs, static function (array $a, array $b): int {
            return [(string) $a['enabled_at'], (int) $a['credit_id']] <=> [(string) $b['enabled_at'], (int) $b['credit_id']];
        });

        $park      = (int) ($respondent['park_id'] ?? 0);
        $kingdom   = (int) ($respondent['kingdom_id'] ?? 0);
        $parent    = $kingdom > 0 ? (int) ($parentOf[$kingdom] ?? 0) : 0;
        $at        = (string) ($respondent['submitted_at'] ?? '9999-12-31 23:59:59');
        $ownerType = (string) ($surveyRow['scope_type'] ?? '');
        $ownerId   = (int) ($surveyRow['scope_id'] ?? 0);

        foreach ($configs as $c) {
            if (!self::configLiveFor($c, $at)) {
                continue;
            }
            $type = (string) $c['grantor_type'];
            $gid  = (int) $c['grantor_id'];
            if ($type === $ownerType && $gid === $ownerId) {
                return (int) $c['credit_id'];
            }
            $mine = ($type === 'park' && $park > 0 && $park === $gid)
                || ($type === 'kingdom' && $kingdom > 0 && ($kingdom === $gid || ($parent > 0 && $parent === $gid)));
            if ($mine) {
                return (int) $c['credit_id'];
            }
        }
        return null;
    }

    /**
     * SQL for a home park that counts only while the park is Active, given
     * $column and the ork_park row LEFT JOINed on it as `p`. A retired park is
     * treated as no home park, as orgKingdom() treats it as no org.
     */
    private static function activeParkSql(string $column): string
    {
        return 'CASE WHEN p.active = \'Active\' THEN ' . $column . ' ELSE NULL END';
    }

    /**
     * 1 while the ork_mundane row LEFT JOINed as `m` is banned (penalty_box) or
     * currently suspended (no end date, or an end date not yet passed), else 0.
     * A held player's credit stays owed and posts once the sanction lifts. A
     * missing mundane row is not held (the grant itself decides).
     */
    private static function heldSql(): string
    {
        return 'COALESCE(m.penalty_box <> 0 OR (m.suspended = 1 AND (m.suspended_until IS NULL'
            . ' OR m.suspended_until = \'0000-00-00\' OR m.suspended_until >= CURDATE())), 0)';
    }

    // -----------------------------------------------------------------------
    // Org lookups
    // -----------------------------------------------------------------------

    /** @return array<int,int> kingdom_id => parent_kingdom_id for every kingdom with a parent */
    public function parentMap(): array
    {
        if ($this->parentMemo === null) {
            $this->parentMemo = [];
            foreach ($this->fetchAll('SELECT kingdom_id, parent_kingdom_id FROM ' . DB_PREFIX . 'kingdom
                                      WHERE parent_kingdom_id > 0') as $r) {
                $this->parentMemo[(int) $r['kingdom_id']] = (int) $r['parent_kingdom_id'];
            }
        }
        return $this->parentMemo;
    }

    /** [kingdom_id, parent_kingdom_id] of an ACTIVE park or kingdom, or [0, 0]. */
    public function orgKingdom(string $type, int $id): array
    {
        $key = $type . ':' . $id;
        if (isset($this->orgMemo[$key])) {
            return $this->orgMemo[$key];
        }
        $row = null;
        if ($type === 'park' && $id > 0) {
            $row = $this->fetchRow('SELECT p.kingdom_id, COALESCE(k.parent_kingdom_id, 0) AS parent_kingdom_id
                                      FROM ' . DB_PREFIX . 'park p
                                      JOIN ' . DB_PREFIX . 'kingdom k ON k.kingdom_id = p.kingdom_id
                                     WHERE p.park_id = ' . $id . ' AND p.active = \'Active\'');
        } elseif ($type === 'kingdom' && $id > 0) {
            $row = $this->fetchRow('SELECT kingdom_id, parent_kingdom_id FROM ' . DB_PREFIX . 'kingdom
                                     WHERE kingdom_id = ' . $id . ' AND active = \'Active\'');
        }
        return $this->orgMemo[$key] = $row ? [(int) $row['kingdom_id'], (int) $row['parent_kingdom_id']] : [0, 0];
    }

    /** May this org switch credit on for (and, one level down, receive shared results of) this survey? */
    public function validGrantor(array $surveyRow, string $type, int $id): bool
    {
        [$kingdom, $parent] = $this->orgKingdom($type, $id);
        return self::grantorReaches($surveyRow, $type, $id, $kingdom, $parent);
    }

    /**
     * The scope of a survey's category: the owning kingdom for a kingdom or
     * park survey, Amtgard for an Amtgard-wide one. ['kingdom', 0] when a park
     * survey's park is no longer active.
     *
     * @return array{0:string,1:int}
     */
    public function categoryScopeFor(array $surveyRow): array
    {
        switch ((string) ($surveyRow['scope_type'] ?? '')) {
            case 'ork':
                return ['ork', 0];
            case 'kingdom':
                return ['kingdom', (int) $surveyRow['scope_id']];
            case 'park':
                return ['kingdom', $this->orgKingdom('park', (int) $surveyRow['scope_id'])[0]];
        }
        return ['kingdom', 0];
    }

    /**
     * Every config of these surveys in ONE query (the list page), grouped by
     * survey and earliest first, the order coverage() and precedence rely on.
     *
     * @return array<int,list<array<string,mixed>>> survey_id => configs
     */
    public function configsFor(array $surveyIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $surveyIds)));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($this->fetchAll('SELECT * FROM ' . DB_PREFIX . 'survey_credit
                                  WHERE survey_id IN (' . implode(',', $ids) . ')
                                  ORDER BY enabled_at ASC, credit_id ASC') as $c) {
            $out[(int) $c['survey_id']][] = $c;
        }
        return $out;
    }

    /**
     * A list row's credit state for $grantor (spec §1 CreditChip): `on` when
     * the grantor's own switch is on, else `covered_by` names the earlier live
     * config that already covers the grantor's players, else both empty.
     *
     * @param list<array<string,mixed>> $configs this survey's, earliest first (configsFor())
     * @return array{on: bool, covered_by: ?string}
     */
    public function rowState(array $surveyRow, array $configs, array $grantor): array
    {
        foreach ($configs as $c) {
            if ($c['grantor_type'] === $grantor['type'] && (int) $c['grantor_id'] === (int) $grantor['id'] && self::isOn($c)) {
                return ['on' => true, 'covered_by' => null];
            }
        }
        $cov = $this->coveredBy($configs, $grantor, $surveyRow);
        return ['on' => false, 'covered_by' => $cov['name'] ?? null];
    }

    /** Is this switch currently on (never switched off, or switched on again)? */
    public static function isOn(array $config): bool
    {
        $off = (string) ($config['disabled_at'] ?? '');
        return $off === '' || $off === '0000-00-00 00:00:00';
    }

    // -----------------------------------------------------------------------
    // Configs
    // -----------------------------------------------------------------------

    /** @return list<array<string,mixed>> every config of a survey, on or off, earliest first */
    public function configs(int $surveyId): array
    {
        return $this->fetchAll('SELECT * FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . (int) $surveyId
            . ' ORDER BY enabled_at ASC, credit_id ASC');
    }

    /** Is any org's switch on for this survey (the data-gate lock, the builder's preview chip)? */
    public function hasConfigs(int $surveyId): bool
    {
        return $this->fetchRow('SELECT 1 AS ok FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . (int) $surveyId
            . ' AND disabled_at IS NULL LIMIT 1') !== null;
    }

    /** @return list<int> surveys with at least one config, on or off (the cron sweep's work list: an off switch may still owe earlier responses) */
    public function surveysWithConfigs(): array
    {
        return array_map('intval', array_column(
            $this->fetchAll('SELECT DISTINCT survey_id FROM ' . DB_PREFIX . 'survey_credit ORDER BY survey_id'),
            'survey_id'
        ));
    }

    // -----------------------------------------------------------------------
    // Panel
    // -----------------------------------------------------------------------

    /**
     * Everything the Bonus credit panel shows (§3.6). A manager of the survey
     * sees every config; anyone acting for $grantor sees the configs related
     * to their org (their own, their kingdom chain, parks under their kingdom,
     * and the owner's). Other kingdoms' configs never show, because their
     * credit counts would leak how many of that kingdom answered.
     */
    public function status(int $uid, int $surveyId, ?array $grantor): array
    {
        $survey = $this->survey()->getRow($surveyId);
        if ($survey === null) {
            return $this->fail('Survey not found.');
        }
        $manage = $this->survey()->canManage($uid, $survey);
        $acting = $this->canActFor($uid, $survey, $grantor);
        if (!$this->mayView($survey, $manage, $acting)) {
            return $this->denied('You cannot manage bonus credits for this survey.');
        }
        if (!$acting) {
            $grantor = null;
        }

        $configs  = $this->configs($surveyId);
        $parentOf = $this->parentMap();
        $counts   = [];
        foreach ($this->fetchAll('SELECT credit_id, COUNT(*) AS n FROM ' . DB_PREFIX . 'survey_credit_grant
                                  WHERE survey_id = ' . (int) $surveyId . ' GROUP BY credit_id') as $r) {
            $counts[(int) $r['credit_id']] = (int) $r['n'];
        }

        $visibleId = $this->visibleIds($configs, $survey, $manage, $grantor);
        $visible   = [];
        foreach ($configs as $c) {
            if (isset($visibleId[(int) $c['credit_id']])) {
                $visible[] = $this->configOut($c, $counts[(int) $c['credit_id']] ?? 0);
            }
        }

        // Each owed list is read once and shared: both the preview and the
        // pending count used to re-read it.
        $owed = null;
        $mine = null;
        if ($grantor !== null) {
            $own = $this->ownConfig($surveyId, $grantor);
            $on  = $own !== null && self::isOn($own);
            $problem = $on ? '' : $this->enableProblem($survey, $grantor);
            $unnoticed = [];
            if (!$on) {
                $owed      = $this->owedResponses($surveyId);
                $unnoticed = $this->owedResponses($surveyId, false);
            }
            $mine = [
                'grantor_type'   => $grantor['type'],
                'grantor_id'     => (int) $grantor['id'],
                'name'           => $this->orgName($grantor['type'], (int) $grantor['id']),
                'config_id'      => $own !== null ? (int) $own['credit_id'] : null,
                'enabled'        => $on,
                'can_enable'     => !$on && $problem === '',
                'can_disable'    => $on,
                'blocked_reason' => $problem,
                'covered_by'     => $on ? null : $this->coveredBy($configs, $grantor, $survey),
                'preview'        => $on ? null : $this->preview($survey, $configs, $grantor, $parentOf, $owed, $unnoticed),
            ];
        }

        return $this->ok(['Credit' => [
            'survey_title'  => (string) $survey['title'],
            'survey_status' => (string) $survey['status'],
            'gate_enabled'  => !empty($survey['data_gate_enabled']),
            'category'      => $this->categoryOut($survey),
            'category_name' => self::categoryName((string) $survey['title']),
            'configs'       => $visible,
            'mine'          => $mine,
            // Owed credits under the configs shown, never a hidden one's count.
            'pending'       => $this->pendingCount($survey, $configs, $parentOf, $visibleId, $owed),
            // Owed to banned or suspended players: posts once the sanction lifts.
            'held'          => $this->pendingCount($survey, $configs, $parentOf, $visibleId, null, true),
        ]]);
    }

    /**
     * Switch credit on for $grantor, creating the survey's category if this is
     * the first org to do so, then backfill. Switching a switched-off config
     * back on clears its off stamps (its original enabled_at keeps its place
     * in the precedence order).
     */
    public function enable(int $uid, int $surveyId, ?array $grantor): array
    {
        $survey = $this->survey()->getRow($surveyId);
        if ($survey === null) {
            return $this->fail('Survey not found.');
        }
        // §5: an org the survey does not reach is a bad request (1); a real
        // grantor the caller cannot act for is not authorized (3).
        if (!$this->isGrantor($survey, $grantor)) {
            return $this->fail('That organization cannot give credits for this survey.');
        }
        if (!$this->survey()->canCreate($uid, (string) $grantor['type'], (int) $grantor['id'])) {
            return $this->denied('You cannot turn on credits for this survey.');
        }
        $sid = (int) $survey['survey_id'];
        $row = $this->ownConfig($sid, $grantor);
        $already = $row !== null && self::isOn($row);
        if (!$already) {
            $problem = $this->enableProblem($survey, $grantor);
            if ($problem !== '') {
                return $this->fail($problem);
            }
            $cat = $this->ensureCategory($uid, $survey);
            if ((int) ($cat['Status'] ?? 1) !== 0) {
                return $this->fail((string) ($cat['Error'] ?? 'Could not set up the credit.'));
            }
            $categoryId = (int) $cat['CategoryId'];
            // An Amtgard-wide survey's credit reaches a kingdom only once that
            // kingdom adopted it: switching on IS the adoption.
            if ((string) $survey['scope_type'] === 'ork' && $grantor['type'] === 'kingdom'
                && !$this->bonus()->adoptForSource($uid, $categoryId, (int) $grantor['id'])) {
                return $this->fail('Could not adopt the credit for this Kingdom.');
            }
            $now = date('Y-m-d H:i:s');
            if ($row !== null) {
                if (!$this->exec('UPDATE ' . DB_PREFIX . 'survey_credit SET disabled_by = NULL, disabled_at = NULL
                                  WHERE credit_id = ' . (int) $row['credit_id'])) {
                    return $this->fail('Could not turn credits on.');
                }
            } elseif (!$this->exec('INSERT INTO ' . DB_PREFIX . 'survey_credit
                              (survey_id, grantor_type, grantor_id, enabled_by, enabled_at)
                              VALUES (' . $sid . ', \'' . $grantor['type'] . '\', ' . (int) $grantor['id'] . ', ' . $uid . ', \'' . $now . '\')')) {
                // A concurrent identical enable won the unique key: same answer as a retry.
                $row = $this->ownConfig($sid, $grantor);
                if ($row === null || !self::isOn($row)) {
                    return $this->fail('Credits are already on for this organization.');
                }
                $already = true;
            }
            // Read back by the unique key: LAST_INSERT_ID() is not a duplicate signal.
            $row = $this->ownConfig($sid, $grantor);
        }
        $creditId = $row ? (int) $row['credit_id'] : 0;

        // The backfill posts owed credits (bounded: the rest are Remaining, for
        // the sweep), but the caller reads counts only for the configs its
        // panel shows (status()): another kingdom's numbers would leak how
        // many of that kingdom answered.
        $visible = $this->visibleIds($this->configs($sid), $survey, $this->survey()->canManage($uid, $survey), $grantor);
        [$res, $byCredit, $remaining] = $this->reconcileRun($sid, $visible, true);
        if ($already) {
            return $this->ok(['CreditId' => $creditId, 'Already' => true, 'Remaining' => $remaining] + $res);
        }

        $log = $this->survey();
        $log->setActor($uid);
        $log->logActivity($sid, 'credit', [
            'action' => 'enable', 'credit_id' => $creditId, 'grantor_type' => $grantor['type'], 'grantor_id' => (int) $grantor['id'],
            'category_id' => (int) ($this->survey()->getRow($sid)['bonus_category_id'] ?? 0), 'backfilled' => $byCredit[$creditId] ?? 0,
        ]);

        return $this->ok(['CreditId' => $creditId, 'Already' => false, 'Remaining' => $remaining] + $res);
    }

    /**
     * Switch $grantor's credit off. Responses submitted before now are still
     * owed (and post later if they have not yet); later ones earn nothing from
     * this org. Credits already given stay; officers revoke them one at a time.
     */
    public function disable(int $uid, int $surveyId, ?array $grantor): array
    {
        $survey = $this->survey()->getRow($surveyId);
        if ($survey === null) {
            return $this->fail('Survey not found.');
        }
        if (!$this->isGrantor($survey, $grantor)) {
            return $this->fail('That organization cannot give credits for this survey.');
        }
        if (!$this->survey()->canCreate($uid, (string) $grantor['type'], (int) $grantor['id'])) {
            return $this->denied('You cannot turn off credits for this survey.');
        }
        $sid = (int) $survey['survey_id'];
        $row = $this->ownConfig($sid, $grantor);
        if ($row === null) {
            return $this->fail('Credits are not on for this organization.');
        }
        if (self::isOn($row)) {
            if (!$this->exec('UPDATE ' . DB_PREFIX . 'survey_credit SET disabled_by = ' . $uid . ', disabled_at = \'' . date('Y-m-d H:i:s') . '\'
                              WHERE credit_id = ' . (int) $row['credit_id'] . ' AND disabled_at IS NULL')) {
                return $this->fail('Could not turn credits off.');
            }
            $log = $this->survey();
            $log->setActor($uid);
            $log->logActivity($sid, 'credit', [
                'action' => 'disable', 'credit_id' => (int) $row['credit_id'], 'grantor_type' => $grantor['type'], 'grantor_id' => (int) $grantor['id'],
            ]);
        }
        return $this->ok(['CreditId' => (int) $row['credit_id']]);
    }

    /** $grantor's own config on this survey (on or off), or null. */
    private function ownConfig(int $surveyId, array $grantor): ?array
    {
        return $this->fetchRow('SELECT * FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . (int) $surveyId
            . ' AND grantor_type = \'' . $this->esc($grantor['type']) . '\' AND grantor_id = ' . (int) $grantor['id']);
    }

    /** reconcile() for a panel viewer: a manager, or someone acting for $grantor. */
    public function reconcileAs(int $uid, int $surveyId, ?array $grantor): array
    {
        $survey = $this->survey()->getRow($surveyId);
        if ($survey === null) {
            return $this->fail('Survey not found.');
        }
        $manage = $this->survey()->canManage($uid, $survey);
        $acting = $this->canActFor($uid, $survey, $grantor);
        if (!$this->mayView($survey, $manage, $acting)) {
            return $this->denied('You cannot manage bonus credits for this survey.');
        }
        // Posts what is owed, bounded (Remaining is left for the sweep);
        // reports only what the caller's panel shows.
        $visible = $this->visibleIds($this->configs($surveyId), $survey, $manage, $acting ? $grantor : null);
        [$res, , $remaining] = $this->reconcileRun($surveyId, $visible, true);
        return $this->ok($res + ['Remaining' => $remaining]);
    }

    // -----------------------------------------------------------------------
    // Engine
    // -----------------------------------------------------------------------

    /**
     * Post every owed credit (§3.5). Idempotent: the ledger holds one row per
     * player per survey, so re-running changes nothing.
     *
     * @return array{Granted:int, Skipped:int, Pending:int}
     */
    public function reconcile(int $surveyId): array
    {
        return $this->reconcileRun($surveyId, null)[0];
    }

    /**
     * reconcile(), counting only the owed credits whose winning config is in
     * $countIds (credit_id => true; null counts every config). Every owed
     * credit is still posted. Skipped counts respondents a config covers whom
     * the Kingdom-level rules keep out (their kingdom declines outside credits
     * or has not adopted an Amtgard-wide one); they stay owed and are retried.
     *
     * $bounded (a web request) attempts at most syncCap grants and stops after
     * SYNC_SECONDS; the owed credits it did not reach stay owed and are
     * counted (for $countIds) as the third element.
     *
     * @return array{0: array{Granted:int, Skipped:int, Pending:int}, 1: array<int,int>, 2: int}
     */
    private function reconcileRun(int $surveyId, ?array $countIds, bool $bounded = false): array
    {
        $out      = ['Granted' => 0, 'Skipped' => 0, 'Pending' => 0];
        $byCredit = [];
        $survey   = $this->survey()->getRow($surveyId);
        if ($survey === null) {
            return [$out, $byCredit, 0];
        }
        $configs = $this->configs($surveyId);
        if (!$configs || (int) ($survey['bonus_category_id'] ?? 0) <= 0) {
            return [$out, $byCredit, 0];
        }
        $byId     = array_column($configs, null, 'credit_id');
        $parentOf = $this->parentMap();

        $todo = [];   // [response, credit_id] in response order
        foreach ($this->owedResponses($surveyId) as $r) {
            $cid = self::coverage($configs, $r, $survey, $parentOf);
            if ($cid !== null) {
                $todo[] = [$r, $cid];
            }
        }

        $started = microtime(true);
        $done    = 0;
        $work    = $bounded ? array_slice($todo, 0, $this->syncCap) : $todo;
        foreach (array_chunk($work, max(1, $this->syncCap)) as $chunk) {
            // Last class for the whole chunk in one query, not one per grant.
            $classes = $this->lastClasses(array_map(static fn (array $t): int => (int) $t[0]['mundane_id'], $chunk));
            foreach ($chunk as [$r, $cid]) {
                if ($bounded && microtime(true) - $started >= self::SYNC_SECONDS) {
                    break 2;
                }
                $done++;
                $count = $countIds === null || isset($countIds[$cid]);
                $res   = $this->grant($survey, $byId[$cid], $r, $classes[(int) $r['mundane_id']] ?? 0);
                if ($res === 'granted') {
                    $byCredit[$cid] = ($byCredit[$cid] ?? 0) + 1;
                    $out['Granted'] += $count ? 1 : 0;
                } elseif ($res === 'pending') {
                    $out['Pending'] += $count ? 1 : 0;
                } elseif ($res === 'skipped') {
                    $out['Skipped'] += $count ? 1 : 0;
                }
            }
        }
        $remaining = 0;
        foreach (array_slice($todo, $done) as [, $cid]) {
            $remaining += ($countIds === null || isset($countIds[$cid])) ? 1 : 0;
        }
        return [$out, $byCredit, $remaining];
    }

    /**
     * Each player's last class (the class of their latest attendance, 0 when
     * that row has none or they have no attendance), for many players in ONE
     * query. Same answer as Attendance::GetPlayerLastClass().
     *
     * @param list<int> $mundaneIds
     * @return array<int,int> mundane_id => class_id
     */
    private function lastClasses(array $mundaneIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $mundaneIds))));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($this->fetchAll('SELECT mundane_id, class_id FROM (
                                    SELECT mundane_id, class_id, ROW_NUMBER() OVER (
                                           PARTITION BY mundane_id ORDER BY date DESC, attendance_id DESC) AS rn
                                      FROM ' . DB_PREFIX . 'attendance WHERE mundane_id IN (' . implode(',', $ids) . ')
                                  ) t WHERE rn = 1') as $row) {
            $out[(int) $row['mundane_id']] = max(0, (int) $row['class_id']);
        }
        return $out;
    }

    /** The live grant after a submit commits (§3.5). Never throws for a missing config. */
    public function grantFor(int $surveyId, int $uid): string
    {
        $survey = $this->survey()->getRow($surveyId);
        $configs = $survey ? $this->configs($surveyId) : [];
        if (!$configs || (int) ($survey['bonus_category_id'] ?? 0) <= 0) {
            return 'none';
        }
        if ($this->isGranted($surveyId, $uid)) {
            return 'granted';
        }
        // Only a non-test Any ORK Data response whose data gate showed a credit
        // line is ever credited (D1), and never while the player is banned or
        // suspended: it stays owed, and a sweep posts it once the sanction lifts.
        $r = $this->fetchRow('SELECT r.response_id, r.mundane_id, ' . self::activeParkSql('r.park_id') . ' AS park_id, r.kingdom_id, r.submitted_at
                                FROM ' . DB_PREFIX . 'survey_response r
                                LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = r.park_id
                                LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = r.mundane_id
                               WHERE r.survey_id = ' . (int) $surveyId . ' AND r.mundane_id = ' . (int) $uid . '
                                 AND r.consent = \'full\' AND r.is_test = 0 AND r.credit_notice = 1
                                 AND ' . self::heldSql() . ' = 0 LIMIT 1');
        if ($r === null) {
            return 'none';
        }
        $cid = self::coverage($configs, $r, $survey, $this->parentMap());
        if ($cid === null) {
            return 'none';
        }
        $res = $this->grant($survey, array_column($configs, null, 'credit_id')[$cid], $r);
        return in_array($res, ['granted', 'already'], true) ? 'granted' : ($res === 'pending' ? 'pending' : 'none');
    }

    /** Would this player be credited if they chose Any ORK Data? (the runner's credit line) */
    public function creditAvailableFor(array $surveyRow, int $uid): bool
    {
        return $this->creditAvailableMap([$surveyRow], $uid)[(int) $surveyRow['survey_id']] ?? false;
    }

    /**
     * creditAvailableFor() for a whole list (My Amtgard's Available Surveys):
     * one config query and one player lookup however many surveys, none when no
     * survey has its data gate on.
     *
     * @param list<array<string,mixed>> $surveyRows ork_survey rows
     * @return array<int,bool> survey_id => available
     */
    public function creditAvailableMap(array $surveyRows, int $uid): array
    {
        $out   = [];
        $gated = [];
        foreach ($surveyRows as $s) {
            $sid       = (int) ($s['survey_id'] ?? 0);
            $out[$sid] = false;
            if ($sid > 0 && !empty($s['data_gate_enabled'])) {
                $gated[$sid] = $s;
            }
        }
        if (!$gated || $uid <= 0) {
            return $out;
        }
        // A survey already credited to this player promises nothing more (a retake
        // after cleared results cannot earn a second grant), so its configs drop out
        // here; so do switched-off ones, which owe nothing to a response made now.
        $bySurvey = [];
        foreach ($this->fetchAll('SELECT c.* FROM ' . DB_PREFIX . 'survey_credit c
                                  WHERE c.survey_id IN (' . implode(',', array_keys($gated)) . ')
                                    AND c.disabled_at IS NULL
                                    AND NOT EXISTS (SELECT 1 FROM ' . DB_PREFIX . 'survey_credit_grant g
                                                     WHERE g.survey_id = c.survey_id AND g.mundane_id = ' . (int) $uid . ')
                                  ORDER BY c.enabled_at ASC, c.credit_id ASC') as $c) {
            $bySurvey[(int) $c['survey_id']][] = $c;
        }
        if (!$bySurvey) {
            return $out;
        }
        $p = $this->fetchRow('SELECT ' . self::activeParkSql('m.park_id') . ' AS park_id, m.kingdom_id
                                FROM ' . DB_PREFIX . 'mundane m
                                LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = m.park_id
                               WHERE m.mundane_id = ' . (int) $uid);
        if ($p === null) {
            return $out;
        }
        $p['submitted_at'] = date('Y-m-d H:i:s');
        $parentOf = $this->parentMap();
        foreach ($bySurvey as $sid => $configs) {
            $out[$sid] = self::coverage($configs, $p, $gated[$sid], $parentOf) !== null;
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Survey lifecycle hooks (called by Survey)
    // -----------------------------------------------------------------------

    /** The title changed: the survey's category follows it. */
    public function onTitleChanged(int $surveyId): void
    {
        $survey = $this->survey()->getRow($surveyId);
        if ($survey === null || (int) ($survey['bonus_category_id'] ?? 0) <= 0) {
            return;
        }
        $title = (string) $survey['title'];
        $this->bonus()->renameSourceCategories(self::SOURCE_TYPE, $surveyId, self::categoryName($title), self::categoryName($title, $surveyId));
    }

    /** The survey was archived: its category is archived with it (credits already given stay). */
    public function onArchived(int $uid, int $surveyId): void
    {
        $this->bonus()->archiveSourceCategories($uid, self::SOURCE_TYPE, $surveyId);
    }

    /**
     * A draft survey was deleted (after its rows, outside the transaction): a
     * category that never granted goes too; one that did is archived instead.
     */
    public function onDeleted(int $uid, int $surveyId): void
    {
        $this->bonus()->deleteSourceCategories($uid, self::SOURCE_TYPE, $surveyId);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function survey(): Survey
    {
        return new Survey();
    }

    private function bonus(): BonusCredit
    {
        return new BonusCredit();
    }

    /**
     * The survey's category, created on first use and remembered on the row.
     * Name: "Survey Bonus - {title}", or with a " (#id)" suffix when a category
     * of that name already exists for the kingdom.
     */
    private function ensureCategory(int $uid, array $survey): array
    {
        $sid = (int) $survey['survey_id'];
        if ((int) ($survey['bonus_category_id'] ?? 0) > 0) {
            $have = $this->bonus()->category((int) $survey['bonus_category_id']);
            if ($have !== null) {
                return ['Status' => 0, 'CategoryId' => (int) $have['CategoryId']];
            }
        }
        [$scopeType, $scopeId] = $this->categoryScopeFor($survey);
        if ($scopeType === 'kingdom' && $scopeId <= 0) {
            return $this->fail('This survey\'s park is not active, so its credit has no Kingdom to belong to.');
        }
        $title = (string) $survey['title'];
        $r = $this->bonus()->ensureSourceCategory(
            $uid,
            $scopeType,
            $scopeId,
            self::SOURCE_TYPE,
            $sid,
            self::categoryName($title),
            self::categoryName($title, $sid),
            'Bonus credit for completing the survey “' . $title . '”. Given automatically to respondents who chose Any ORK Data.'
        );
        if ((int) ($r['Status'] ?? 1) !== 0) {
            return $r;
        }
        $this->exec('UPDATE ' . DB_PREFIX . 'survey SET bonus_category_id = ' . (int) $r['CategoryId'] . ' WHERE survey_id = ' . $sid
            . ' AND (bonus_category_id IS NULL OR bonus_category_id = 0)');
        return $r;
    }

    /** @return array{category_id:int,name:string,scope_type:string,scope_name:string,archived:bool}|null */
    private function categoryOut(array $survey): ?array
    {
        $id = (int) ($survey['bonus_category_id'] ?? 0);
        $c  = $id > 0 ? $this->bonus()->category($id) : null;
        if ($c === null) {
            return null;
        }
        return [
            'category_id' => (int) $c['CategoryId'],
            'name'        => (string) $c['Name'],
            'scope_type'  => (string) $c['ScopeType'],
            'scope_name'  => (string) $c['ScopeName'],
            'archived'    => $c['ArchivedAt'] !== null,
        ];
    }

    /** Is $grantor a well-formed org this survey reaches (§3.2), whoever is asking? */
    private function isGrantor(array $survey, ?array $grantor): bool
    {
        return $grantor !== null
            && in_array($grantor['type'] ?? '', ['kingdom', 'park'], true)
            && $this->validGrantor($survey, (string) $grantor['type'], (int) ($grantor['id'] ?? 0));
    }

    private function canActFor(int $uid, array $survey, ?array $grantor): bool
    {
        return $this->isGrantor($survey, $grantor)
            && $this->survey()->canCreate($uid, (string) $grantor['type'], (int) $grantor['id']);
    }

    /**
     * May this caller read the panel (status, reconcile)? A manager always; an
     * officer acting for a grantor below the owner only while the survey is
     * open or closed. Other orgs never see drafts and archived is hidden (D5),
     * the rule listForScope() and resultsAccess() apply.
     */
    private function mayView(array $survey, bool $manage, bool $acting): bool
    {
        return $manage || ($acting && in_array((string) $survey['status'], ['open', 'closed'], true));
    }

    /**
     * credit_id => true for the configs a caller's panel shows: every config
     * for a manager, else the ones related() to the org they act for.
     */
    private function visibleIds(array $configs, array $survey, bool $manage, ?array $grantor): array
    {
        $ids = [];
        foreach ($configs as $c) {
            if ($manage || $this->related($c, $grantor, $survey)) {
                $ids[(int) $c['credit_id']] = true;
            }
        }
        return $ids;
    }

    /** '' when $grantor may switch credit on now, else the reason shown in the panel. */
    private function enableProblem(array $survey, array $grantor): string
    {
        if (empty($survey['data_gate_enabled'])) {
            return 'Turn on the data gate (Privacy) so respondents can choose Any ORK Data; credits are only given to them.';
        }
        // §3.2: other grantors need an open or closed survey; the owner may
        // also set up a draft. Nobody backfills credits for an archived one.
        $status  = (string) $survey['status'];
        $isOwner = (string) $survey['scope_type'] === $grantor['type'] && (int) $survey['scope_id'] === (int) $grantor['id'];
        if ($status === 'archived') {
            return 'Credits cannot be turned on for an archived survey.';
        }
        if (!$isOwner && $status !== 'open' && $status !== 'closed') {
            return 'Credits can be turned on once this survey is open.';
        }
        // An Amtgard-wide survey's credit is adopted per kingdom. A park may
        // switch it on for its members only once its kingdom has adopted it
        // (by switching the credit on itself, or under Bonus Credits).
        if ((string) $survey['scope_type'] === 'ork' && $grantor['type'] === 'park') {
            $catId = (int) ($survey['bonus_category_id'] ?? 0);
            [$kingdom] = $this->orgKingdom('park', (int) $grantor['id']);
            if ($catId <= 0 || $kingdom <= 0 || !$this->bonus()->isAdoptedIn($catId, $this->bonus()->kingdomAncestry($kingdom))) {
                return 'Your Kingdom has not turned on this survey\'s credit yet. A Kingdom officer can, from the Kingdom\'s survey list; then your park can too.';
            }
        }
        $own = $this->fetchRow('SELECT disabled_at FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . (int) $survey['survey_id']
            . ' AND grantor_type = \'' . $grantor['type'] . '\' AND grantor_id = ' . (int) $grantor['id']);
        return $own !== null && self::isOn($own) ? 'Credits are already on for this organization.' : '';
    }

    /** Is config $c shown to someone acting for $grantor? */
    private function related(array $c, ?array $grantor, array $survey): bool
    {
        if ((string) $c['grantor_type'] === (string) $survey['scope_type'] && (int) $c['grantor_id'] === (int) $survey['scope_id']) {
            return true;   // the owner's config
        }
        if ($grantor === null) {
            return false;
        }
        $type = (string) $c['grantor_type'];
        $id   = (int) $c['grantor_id'];
        if ($type === $grantor['type'] && $id === (int) $grantor['id']) {
            return true;
        }
        [$gk, $gp] = $this->orgKingdom($grantor['type'], (int) $grantor['id']);
        if ($type === 'kingdom' && ($id === $gk || ($gp > 0 && $id === $gp))) {
            return true;   // a kingdom above the viewer
        }
        if ($type === 'park' && $grantor['type'] === 'kingdom') {
            [$pk, $pp] = $this->orgKingdom('park', $id);
            return $pk === (int) $grantor['id'] || $pp === (int) $grantor['id'];   // a park below the viewer
        }
        return false;
    }

    /** scopeName(), memoized: the list page asks for the same few orgs on every row. */
    private function orgName(string $type, int $id): string
    {
        return $this->nameMemo[$type . ':' . $id] ??= $this->survey()->scopeName($type, $id);
    }

    /** An earlier LIVE config that already covers ALL of $grantor's players, as {credit_id, name}. */
    private function coveredBy(array $configs, array $grantor, array $survey): ?array
    {
        [$gk, $gp] = $this->orgKingdom($grantor['type'], (int) $grantor['id']);
        foreach ($configs as $c) {   // earliest first
            if (!self::isOn($c)) {
                continue;
            }
            $type  = (string) $c['grantor_type'];
            $id    = (int) $c['grantor_id'];
            $owner = $type === (string) $survey['scope_type'] && $id === (int) $survey['scope_id'];
            $above = $type === 'kingdom' && ($grantor['type'] === 'park' ? ($id === $gk || ($gp > 0 && $id === $gp)) : ($gp > 0 && $id === $gp));
            if ($owner || $above) {
                return ['credit_id' => (int) $c['credit_id'], 'name' => $this->orgName($type, $id)];
            }
        }
        return null;
    }

    /**
     * Dry run: how many owed responses a new config for $grantor would credit
     * now, and how many of its players chose Any ORK Data without being shown
     * a credit line, who therefore get none (no_notice, D1).
     */
    private function preview(array $survey, array $configs, array $grantor, array $parentOf, array $owed, array $unnoticed): array
    {
        $hyp = ['credit_id' => PHP_INT_MAX, 'grantor_type' => $grantor['type'], 'grantor_id' => (int) $grantor['id'],
                'enabled_at' => '9999-12-31 23:59:59', 'disabled_at' => null];
        $n = 0;
        $noNotice = 0;
        foreach ($unnoticed as $r) {
            $noNotice += self::coverage(array_merge($configs, [$hyp]), $r, $survey, $parentOf) === PHP_INT_MAX ? 1 : 0;
        }
        foreach ($owed as $r) {
            $n += self::coverage(array_merge($configs, [$hyp]), $r, $survey, $parentOf) === PHP_INT_MAX ? 1 : 0;
        }
        return ['eligible_now' => $n, 'no_notice' => $noNotice];
    }

    /**
     * Owed credits whose winning config is in $countIds (credit_id => true).
     * Coverage still runs over EVERY config, so precedence is the real one: a
     * player owed under a hidden earlier config is not counted against a later
     * visible one. $held counts the ones held for a banned or suspended player.
     * $owed is the caller's already-read owedResponses() list (null reads it).
     */
    private function pendingCount(array $survey, array $configs, array $parentOf, array $countIds, ?array $owed = null, bool $held = false): int
    {
        if (!$configs || !$countIds) {
            return 0;
        }
        $n = 0;
        foreach ($owed ?? $this->owedResponses((int) $survey['survey_id'], true, $held) as $r) {
            $id = self::coverage($configs, $r, $survey, $parentOf);
            $n += ($id !== null && isset($countIds[$id])) ? 1 : 0;
        }
        return $n;
    }

    /**
     * Non-test Any ORK Data responses whose player has no credit for this
     * survey yet (park_id: Active parks only). Only those whose data gate
     * showed a credit line are owed (D1): a backfill never puts a dated credit
     * on someone who chose Any ORK Data without being told. $noticed = false
     * lists the others instead (the panel's no_notice count). A banned or
     * currently suspended player's response is held, not owed (heldSql());
     * $held = true lists those instead (the panel's held count).
     */
    private function owedResponses(int $surveyId, bool $noticed = true, bool $held = false): array
    {
        return $this->fetchAll(
            'SELECT r.response_id, r.mundane_id, ' . self::activeParkSql('r.park_id') . ' AS park_id, r.kingdom_id, r.submitted_at
               FROM ' . DB_PREFIX . 'survey_response r
               LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = r.park_id
               LEFT JOIN ' . DB_PREFIX . 'survey_credit_grant g ON g.survey_id = r.survey_id AND g.mundane_id = r.mundane_id
               LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = r.mundane_id
              WHERE r.survey_id = ' . (int) $surveyId . ' AND r.is_test = 0 AND r.consent = \'full\'
                AND r.credit_notice = ' . ($noticed ? 1 : 0) . '
                AND ' . self::heldSql() . ' = ' . ($held ? 1 : 0) . '
                AND r.mundane_id IS NOT NULL AND g.mundane_id IS NULL
              ORDER BY r.response_id'
        );
    }

    private function isGranted(int $surveyId, int $uid): bool
    {
        return $this->fetchRow('SELECT 1 AS ok FROM ' . DB_PREFIX . 'survey_credit_grant
                                WHERE survey_id = ' . (int) $surveyId . ' AND mundane_id = ' . (int) $uid) !== null;
    }

    /**
     * One credit: bonus credit + ledger row in ONE transaction, rolled back on
     * either failure. Callers pass only non-test `full` responses
     * (owedResponses(), grantFor()). $lastClass is the player's last class when
     * the caller fetched it for a batch (lastClasses()); null looks it up.
     *
     * A player who already holds the category's credit by an officer's hand
     * gets a ledger row pointing at that credit instead of a second one.
     *
     * @return 'granted'|'already'|'pending'|'skipped'
     */
    private function grant(array $survey, array $config, array $response, ?int $lastClass = null): string
    {
        $sid   = (int) $survey['survey_id'];
        $uid   = (int) $response['mundane_id'];
        $catId = (int) ($survey['bonus_category_id'] ?? 0);
        if ($catId <= 0) {
            return 'pending';
        }
        $class = self::classFor($lastClass ?? (int) Ork3::$Lib->attendance->GetPlayerLastClass(['MundaneId' => $uid]));

        if (!$this->exec('START TRANSACTION')) {
            $this->logFailure($sid, $uid, 'begin', '');
            return 'pending';
        }
        $r = $this->bonus()->grantFromSource([
            'CategoryId' => $catId, 'MundaneId' => $uid, 'ClassId' => $class,
            'Date' => substr((string) $response['submitted_at'], 0, 10), 'GrantedBy' => (int) $config['enabled_by'],
            'Note' => self::noteFor($sid),
        ]);
        $bonusId = (int) ($r['BonusCreditId'] ?? 0);
        if ((int) $r['Status'] !== 0) {
            $reason = (string) ($r['Reason'] ?? '');
            if ($reason === 'already') {
                // Handed out by an officer already: record it so it is never doubled.
                $held = $this->fetchRow('SELECT bonus_credit_id FROM ' . DB_PREFIX . 'bonus_credit
                                         WHERE category_id = ' . $catId . ' AND mundane_id = ' . $uid . ' AND revoked_at IS NULL
                                         ORDER BY bonus_credit_id LIMIT 1');
                $bonusId = $held ? (int) $held['bonus_credit_id'] : 0;
            }
            if ($bonusId <= 0) {
                $this->exec('ROLLBACK');
                if (in_array($reason, ['outside', 'unadopted'], true)) {
                    return 'skipped';
                }
                $this->logFailure($sid, $uid, 'bonus:' . $reason, (string) ($r['Error'] ?? ''));
                return 'pending';
            }
        }
        if (!$this->exec('INSERT INTO ' . DB_PREFIX . 'survey_credit_grant (survey_id, mundane_id, credit_id, bonus_credit_id)
                          VALUES (' . $sid . ', ' . $uid . ', ' . (int) $config['credit_id'] . ', ' . $bonusId . ')')) {
            $this->exec('ROLLBACK');
            return $this->isGranted($sid, $uid) ? 'already' : 'pending';   // a concurrent grant won
        }
        if (!$this->exec('COMMIT')) {
            $this->exec('ROLLBACK');
            $this->logFailure($sid, $uid, 'commit', '');
            return 'pending';
        }
        // After COMMIT, never inside it: a read between an inside bust and the
        // commit would re-cache the pre-credit levels.
        $ck = Ork3::$Lib->ghettocache->key(['MundaneId' => $uid]);
        Ork3::$Lib->ghettocache->bust('Player.GetPlayerClasses', $ck);
        Ork3::$Lib->ghettocache->bust('Player.GetPlayerProfileDetails', $ck);
        return (int) $r['Status'] === 0 ? 'granted' : 'already';
    }

    private function configOut(array $c, int $granted): array
    {
        return [
            'credit_id'    => (int) $c['credit_id'],
            'grantor_type' => (string) $c['grantor_type'],
            'grantor_id'   => (int) $c['grantor_id'],
            'grantor_name' => $this->orgName((string) $c['grantor_type'], (int) $c['grantor_id']),
            'enabled_at'   => (string) $c['enabled_at'],
            'disabled_at'  => self::isOn($c) ? null : (string) $c['disabled_at'],
            'enabled'      => self::isOn($c),
            'granted'      => $granted,
        ];
    }

    /** One structured line; quoted literals in DB messages are redacted like SurveyResponse::rollback(). */
    private function logFailure(int $surveyId, int $uid, string $stage, string $error): void
    {
        error_log('[survey-credit] grant failed ' . json_encode([
            'survey_id' => $surveyId, 'uid' => $uid, 'stage' => $stage,
            'db_error'  => preg_replace("/'[^']*'/", "'?'", $error),
        ]));
    }

    // -----------------------------------------------------------------------
    // SQL helpers (same contract as class.Survey.php)
    // -----------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function fetchAll(string $sql): array
    {
        $this->db->Clear();
        $rs  = $this->db->DataSet($sql);
        $out = [];
        if ($rs) {
            while ($rs->Next()) {
                $out[] = $rs->CurrentFieldSet();
            }
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private function fetchRow(string $sql): ?array
    {
        $this->db->Clear();
        $rs = $this->db->DataSet($sql);
        if ($rs && $rs->Next()) {
            return $rs->CurrentFieldSet();
        }
        return null;
    }

    /**
     * Run one write. Returns false when the statement really failed, so a
     * transactional block can ROLLBACK instead of committing a half-mutation
     * (Execute() alone reports nothing — PDO runs in ERRMODE_WARNING).
     *
     * START TRANSACTION / COMMIT / ROLLBACK go through YapoMysql's depth-counted
     * BeginTrans/CommitTrans/RollbackTrans: a raw second START TRANSACTION
     * silently commits the open one in MariaDB, a nested BeginTrans does not.
     */
    private function exec(string $sql): bool
    {
        $this->db->Clear();
        if (method_exists($this->db, 'BeginTrans')) {
            switch ($sql) {
                case 'START TRANSACTION':
                    // BeginTrans() always returns true; InTrans() catches a failed PDO begin.
                    // On failure unwind the depth BeginTrans() raised, so callers fail closed cleanly.
                    if ($this->db->BeginTrans() && $this->db->InTrans()) {
                        return true;
                    }
                    $this->db->RollbackTrans();
                    return false;
                case 'COMMIT':
                    return (bool) $this->db->CommitTrans();
                case 'ROLLBACK':
                    // Depth 0 (after a failed COMMIT): end whatever the server still holds.
                    return $this->db->RollbackTrans() || !$this->db->InTrans() || (bool) $this->db->ExecuteChecked('ROLLBACK');
            }
        }
        if (method_exists($this->db, 'ExecuteChecked')) {
            return (bool) $this->db->ExecuteChecked($sql);
        }
        $this->db->Execute($sql);
        return true;
    }

    /** @param array<string, mixed> $payload */
    private function ok(array $payload = []): array
    {
        return ['Status' => 0, 'Error' => ''] + $payload;
    }

    private function fail(string $error): array
    {
        return ['Status' => 1, 'Error' => $error];
    }

    private function denied(string $error): array
    {
        return ['Status' => 3, 'Error' => $error];
    }

    private function esc($v)
    {
        return str_replace(["'", '\\'], ["''", '\\\\'], (string) $v);
    }
}
