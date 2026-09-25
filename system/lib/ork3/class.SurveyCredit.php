<?php

/**
 * SurveyCredit — attendance credits for completing a survey
 * (docs/superpowers/specs/2026-09-10-survey-sharing-and-credits-design.md §3).
 *
 * A config (ork_survey_credit) is one org's PERMANENT promise to credit the
 * players it covers; the ledger (ork_survey_credit_grant) holds at most one
 * credit per player per survey. Only non-test `full` responses are ever
 * credited (D1): a dated public credit beside a day-stamped partial or
 * anonymous response would re-identify it.
 *
 * All credit SQL lives here. Attendance and event rows are written by
 * Attendance::add_system_credit() and EventPlanning::create_system_event(), which
 * trust this class to have authorized the write.
 */
class SurveyCredit
{
    public const MODES = ['home_park', 'event'];

    /** ork_class row for Color: the credit class for a player with no attendance yet. */
    public const COLOR_CLASS_ID = 6;

    public const EVENT_PREFIX = 'Survey Credit - ';

    /** ork_event.name is varchar(100). */
    public const EVENT_NAME_MAX = 100;

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

    /** ork_attendance.note for a survey credit; <= 20 chars for any 10-digit id. */
    public static function noteFor(int $surveyId): string
    {
        return 'Survey #' . $surveyId;
    }

    /** "Survey Credit - {title}", cut to ork_event.name's 100 characters. */
    public static function eventName(string $title): string
    {
        $name = self::EVENT_PREFIX . trim($title);
        if (mb_strlen($name) <= self::EVENT_NAME_MAX) {
            return $name;
        }
        return rtrim(mb_substr($name, 0, self::EVENT_NAME_MAX - 1)) . '…';
    }

    /**
     * 'Y-m-d' the survey started: DATE(opened_at), or DATE(open_at) when later;
     * null if never opened. A zero date ('0000-00-00 …', which sql_mode='' can
     * write for '') parses to a negative timestamp and counts as unset.
     */
    public static function startDate(array $surveyRow): ?string
    {
        $opened = strtotime((string) ($surveyRow['opened_at'] ?? ''));
        if (!$opened || $opened <= 0) {
            return null;
        }
        $scheduled = strtotime((string) ($surveyRow['open_at'] ?? ''));
        return date('Y-m-d', ($scheduled && $scheduled > $opened) ? $scheduled : $opened);
    }

    /** The player's last class, or Color when they have no attendance. */
    public static function classFor(int $lastClassId): int
    {
        return $lastClassId > 0 ? $lastClassId : self::COLOR_CLASS_ID;
    }

    /**
     * Does the survey reach this org? The same answer decides who may grant
     * credits (§3.2) and, one level down, who may see shared results (§2).
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
     * Which config credits this respondent (§3.2)? The earliest enabled_at wins,
     * ties to the lower credit_id. A park config covers its snapshotted park; a
     * kingdom config covers its kingdom and that kingdom's principalities; the
     * OWNER's event config covers everyone (event audiences bring visitors). A
     * home_park config cannot place a respondent with no park: it is skipped
     * (a later config may still cover them) and no_home_park reports why.
     *
     * @param list<array<string,mixed>> $configs    ork_survey_credit rows
     * @param array<string,mixed>       $respondent ['park_id'=>?int, 'kingdom_id'=>?int] from the response
     * @param array<int,int>            $parentOf   kingdom_id => parent_kingdom_id
     * @return array{credit_id:?int, no_home_park:bool}
     */
    public static function coverage(array $configs, array $respondent, array $surveyRow, array $parentOf): array
    {
        usort($configs, static function (array $a, array $b): int {
            return [(string) $a['enabled_at'], (int) $a['credit_id']] <=> [(string) $b['enabled_at'], (int) $b['credit_id']];
        });

        $park      = (int) ($respondent['park_id'] ?? 0);
        $kingdom   = (int) ($respondent['kingdom_id'] ?? 0);
        $parent    = $kingdom > 0 ? (int) ($parentOf[$kingdom] ?? 0) : 0;
        $ownerType = (string) ($surveyRow['scope_type'] ?? '');
        $ownerId   = (int) ($surveyRow['scope_id'] ?? 0);
        $noPark    = false;

        foreach ($configs as $c) {
            $type = (string) $c['grantor_type'];
            $gid  = (int) $c['grantor_id'];
            $mode = (string) $c['mode'];

            if ($mode === 'event' && $type === $ownerType && $gid === $ownerId) {
                return ['credit_id' => (int) $c['credit_id'], 'no_home_park' => false];
            }
            $mine = ($type === 'park' && $park > 0 && $park === $gid)
                || ($type === 'kingdom' && $kingdom > 0 && ($kingdom === $gid || ($parent > 0 && $parent === $gid)));
            if (!$mine) {
                continue;
            }
            if ($mode === 'home_park' && $park <= 0) {
                $noPark = true;
                continue;
            }
            return ['credit_id' => (int) $c['credit_id'], 'no_home_park' => false];
        }
        return ['credit_id' => null, 'no_home_park' => $noPark];
    }

    /**
     * SQL for a home park that counts only while the park is Active, given
     * $column and the ork_park row LEFT JOINed on it as `p`. A retired park is
     * treated as no home park, as orgKingdom() treats it as no org: a credit
     * there would feed the attendance of a park that closed years ago.
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

    /** May this org grant credits on (and, one level down, receive shared results of) this survey? */
    public function validGrantor(array $surveyRow, string $type, int $id): bool
    {
        [$kingdom, $parent] = $this->orgKingdom($type, $id);
        return self::grantorReaches($surveyRow, $type, $id, $kingdom, $parent);
    }

    /**
     * Every config of these surveys in ONE query (the list page), grouped by
     * survey and earliest first, the order coveredBy() and precedence rely on.
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
     * the grantor has its own config, else `covered_by` names the earlier
     * config that already covers the grantor's players (the panel's
     * "already covered" line), else both empty.
     *
     * @param list<array<string,mixed>> $configs this survey's, earliest first (configsFor())
     * @return array{on: bool, covered_by: ?string}
     */
    public function rowState(array $surveyRow, array $configs, array $grantor): array
    {
        foreach ($configs as $c) {
            if ($c['grantor_type'] === $grantor['type'] && (int) $c['grantor_id'] === (int) $grantor['id']) {
                return ['on' => true, 'covered_by' => null];
            }
        }
        $cov = $this->coveredBy($configs, $grantor, $surveyRow);
        return ['on' => false, 'covered_by' => $cov['name'] ?? null];
    }

    // -----------------------------------------------------------------------
    // Configs
    // -----------------------------------------------------------------------

    /** @return list<array<string,mixed>> every config of a survey, earliest first */
    public function configs(int $surveyId): array
    {
        return $this->fetchAll('SELECT * FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . (int) $surveyId
            . ' ORDER BY enabled_at ASC, credit_id ASC');
    }

    public function hasConfigs(int $surveyId): bool
    {
        return $this->fetchRow('SELECT 1 AS ok FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . (int) $surveyId . ' LIMIT 1') !== null;
    }

    /** @return list<int> surveys with at least one config (the cron sweep's work list) */
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
     * Everything the Attendance credit panel shows (§3.6). A manager of the
     * survey sees every config; anyone acting for $grantor sees the configs
     * related to their org (their own, their kingdom chain, parks under their
     * kingdom, and the owner's). Other kingdoms' configs never show, because
     * their credit counts would leak how many of that kingdom answered.
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
            return $this->denied('You cannot manage attendance credits for this survey.');
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
        $shown     = array_values(array_filter($configs, static fn (array $c): bool => isset($visibleId[(int) $c['credit_id']])));
        $events    = $this->eventNames($shown);
        $visible   = [];
        foreach ($shown as $c) {
            $visible[] = $this->configOut($c, $counts[(int) $c['credit_id']] ?? 0, $events[(int) ($c['event_id'] ?? 0)] ?? '');
        }

        // Each owed list is read once and shared: both previews and the
        // pending count used to re-read it (five queries, now at most two).
        $owed = null;
        $mine = null;
        if ($grantor !== null) {
            $own = null;
            foreach ($configs as $c) {
                if ($c['grantor_type'] === $grantor['type'] && (int) $c['grantor_id'] === (int) $grantor['id']) {
                    $own = $c;
                }
            }
            $problem = $own !== null ? '' : $this->enableProblem($survey, $grantor);
            if ($own === null) {
                $owed      = $this->owedResponses($surveyId);
                $unnoticed = $this->owedResponses($surveyId, false);
            }
            $mine = [
                'grantor_type'   => $grantor['type'],
                'grantor_id'     => (int) $grantor['id'],
                'name'           => $this->orgName($grantor['type'], (int) $grantor['id']),
                'config_id'      => $own !== null ? (int) $own['credit_id'] : null,
                'can_enable'     => $own === null && $problem === '',
                'blocked_reason' => $problem,
                'covered_by'     => $own === null ? $this->coveredBy($configs, $grantor, $survey) : null,
                'preview'        => $own === null ? [
                    'home_park' => $this->preview($survey, $configs, $grantor, 'home_park', $parentOf, $owed, $unnoticed),
                    'event'     => $this->preview($survey, $configs, $grantor, 'event', $parentOf, $owed, $unnoticed),
                ] : null,
            ];
        }

        return $this->ok(['Credit' => [
            'survey_title'  => (string) $survey['title'],
            'survey_status' => (string) $survey['status'],
            'event_name'    => self::eventName((string) $survey['title']),
            'start_date'    => self::startDate($survey),
            'gate_enabled'  => !empty($survey['data_gate_enabled']),
            'configs'       => $visible,
            'mine'          => $mine,
            // Owed credits under the configs shown, never a hidden one's count.
            'pending'       => $this->pendingCount($survey, $configs, $parentOf, $visibleId, $owed),
            // Owed to banned or suspended players: posts once the sanction lifts.
            'held'          => $this->pendingCount($survey, $configs, $parentOf, $visibleId, null, true),
        ]]);
    }

    /** Turn credits on for $grantor (§3.1: permanent), then backfill. */
    public function enable(int $uid, int $surveyId, ?array $grantor, string $mode, bool $confirm): array
    {
        $survey = $this->survey()->getRow($surveyId);
        if ($survey === null) {
            return $this->fail('Survey not found.');
        }
        if (!in_array($mode, self::MODES, true)) {
            return $this->fail('Choose where the credit is given.');
        }
        if (!$confirm) {
            return $this->fail('Confirm that you understand this cannot be undone.');
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
        // A retry of an enable that already committed (the first request timed
        // out in its backfill) is a success with the same config's status.
        $row = $this->ownConfig($sid, $grantor);
        $already = $row !== null && (string) $row['mode'] === $mode;
        if (!$already) {
            $problem = $this->enableProblem($survey, $grantor);
            if ($problem !== '') {
                return $this->fail($problem);
            }
            if (!$this->exec('INSERT INTO ' . DB_PREFIX . 'survey_credit
                              (survey_id, grantor_type, grantor_id, mode, enabled_by, enabled_at)
                              VALUES (' . $sid . ', \'' . $grantor['type'] . '\', ' . (int) $grantor['id'] . ', \''
                              . $mode . '\', ' . $uid . ', \'' . date('Y-m-d H:i:s') . '\')')) {
                // A concurrent identical enable won the unique key: same answer as a retry.
                $row = $this->ownConfig($sid, $grantor);
                if ($row === null || (string) $row['mode'] !== $mode) {
                    return $this->fail('Credits are already on for this organization.');
                }
                $already = true;
            } else {
                // Read back by the unique key: LAST_INSERT_ID() is not a duplicate signal.
                $row = $this->ownConfig($sid, $grantor);
            }
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
            'credit_id' => $creditId, 'grantor_type' => $grantor['type'], 'grantor_id' => (int) $grantor['id'],
            'mode' => $mode, 'backfilled' => $byCredit[$creditId] ?? 0,
        ]);

        return $this->ok(['CreditId' => $creditId, 'Already' => false, 'Remaining' => $remaining] + $res);
    }

    /** $grantor's own config on this survey, or null. */
    private function ownConfig(int $surveyId, array $grantor): ?array
    {
        return $this->fetchRow('SELECT credit_id, mode FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . $surveyId
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
            return $this->denied('You cannot manage attendance credits for this survey.');
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
     * player per survey, so re-running changes nothing. Creates missing events
     * first, outside any transaction (create_system_event opens its own).
     *
     * @return array{Granted:int, SkippedNoPark:int, Pending:int}
     */
    public function reconcile(int $surveyId): array
    {
        return $this->reconcileRun($surveyId, null)[0];
    }

    /**
     * reconcile(), counting only the owed credits whose winning config is in
     * $countIds (credit_id => true; null counts every config). Every owed
     * credit is still posted. A no-home-park skip counts when a config in
     * $countIds is the one that could not place the player.
     *
     * $bounded (a web request) attempts at most syncCap grants and stops after
     * SYNC_SECONDS; the owed credits it did not reach stay owed and are
     * counted (for $countIds) as the third element.
     *
     * @return array{0: array{Granted:int, SkippedNoPark:int, Pending:int}, 1: array<int,int>, 2: int}
     *         the counts, credits granted per credit_id (every config), and
     *         owed credits left for later
     */
    private function reconcileRun(int $surveyId, ?array $countIds, bool $bounded = false): array
    {
        $out      = ['Granted' => 0, 'SkippedNoPark' => 0, 'Pending' => 0];
        $byCredit = [];
        $survey   = $this->survey()->getRow($surveyId);
        if ($survey === null) {
            return [$out, $byCredit, 0];
        }
        $configs = $this->withEvents($this->configs($surveyId), $survey);
        if (!$configs) {
            return [$out, $byCredit, 0];
        }
        $byId     = array_column($configs, null, 'credit_id');
        $parentOf = $this->parentMap();
        $counted  = $countIds === null ? $configs
            : array_values(array_filter($configs, static fn (array $c): bool => isset($countIds[(int) $c['credit_id']])));

        $todo = [];   // [response, credit_id] in response order
        foreach ($this->owedResponses($surveyId) as $r) {
            $cov = self::coverage($configs, $r, $survey, $parentOf);
            if ($cov['credit_id'] === null) {
                if ($cov['no_home_park'] && ($countIds === null || self::coverage($counted, $r, $survey, $parentOf)['no_home_park'])) {
                    $out['SkippedNoPark']++;
                }
                continue;
            }
            $todo[] = [$r, (int) $cov['credit_id']];
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
        if (!$configs) {
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
        $configs = $this->withEvents($configs, $survey);
        $cov = self::coverage($configs, $r, $survey, $this->parentMap());
        if ($cov['credit_id'] === null) {
            return 'none';
        }
        $res = $this->grant($survey, array_column($configs, null, 'credit_id')[$cov['credit_id']], $r);
        return $res === 'pending' ? 'pending' : 'granted';
    }

    /** First (and any) transition to open: give event configs their event now that a start date exists. */
    public function onOpened(int $surveyId): void
    {
        $survey = $this->survey()->getRow($surveyId);
        if ($survey !== null) {
            $this->withEvents($this->configs($surveyId), $survey);
        }
    }

    /**
     * open_at changed (Survey::update): the start date may have moved, so each
     * event-mode event follows it while it holds no attendance (ensureEvent()).
     * Without this a credit event made while open_at was weeks out kept that
     * date after open_at was cleared, and dated today's credits in the future.
     */
    public function onStartChanged(int $surveyId): void
    {
        $this->onOpened($surveyId);
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
        // after cleared results cannot earn a second grant), so its configs drop out here.
        $bySurvey = [];
        foreach ($this->fetchAll('SELECT c.* FROM ' . DB_PREFIX . 'survey_credit c
                                  WHERE c.survey_id IN (' . implode(',', array_keys($gated)) . ')
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
        $parentOf = $this->parentMap();
        foreach ($bySurvey as $sid => $configs) {
            $out[$sid] = self::coverage($configs, $p, $gated[$sid], $parentOf)['credit_id'] !== null;
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function survey(): Survey
    {
        return new Survey();
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

    /** '' when $grantor may switch credits on now, else the reason shown in the panel. */
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
        $own = $this->fetchRow('SELECT 1 AS ok FROM ' . DB_PREFIX . 'survey_credit WHERE survey_id = ' . (int) $survey['survey_id']
            . ' AND grantor_type = \'' . $grantor['type'] . '\' AND grantor_id = ' . (int) $grantor['id']);
        return $own !== null ? 'Credits are already on for this organization.' : '';
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

    /** An earlier config that already covers ALL of $grantor's players, as {credit_id, name}. */
    private function coveredBy(array $configs, array $grantor, array $survey): ?array
    {
        [$gk, $gp] = $this->orgKingdom($grantor['type'], (int) $grantor['id']);
        foreach ($configs as $c) {   // earliest first
            $type  = (string) $c['grantor_type'];
            $id    = (int) $c['grantor_id'];
            $owner = $type === (string) $survey['scope_type'] && $id === (int) $survey['scope_id'];
            $above = $type === 'kingdom' && ($grantor['type'] === 'park' ? ($id === $gk || ($gp > 0 && $id === $gp)) : ($gp > 0 && $id === $gp));
            if (($owner && $c['mode'] === 'event') || $above) {
                return ['credit_id' => (int) $c['credit_id'], 'name' => $this->orgName($type, $id)];
            }
        }
        return null;
    }

    /**
     * Dry run: how many owed responses a new config for $grantor would credit
     * now, how many of those it cannot place (no home park), and how many of
     * its players chose Any ORK Data without being shown a credit line, who
     * therefore get none (no_notice, D1).
     */
    private function preview(array $survey, array $configs, array $grantor, string $mode, array $parentOf, array $owed, array $unnoticed): array
    {
        $hyp = ['credit_id' => PHP_INT_MAX, 'grantor_type' => $grantor['type'], 'grantor_id' => (int) $grantor['id'],
                'mode' => $mode, 'enabled_at' => '9999-12-31 23:59:59'];
        $n = 0;
        $noPark = 0;
        $noNotice = 0;
        // Un-told respondents this credit would reach. In home-park mode that
        // includes someone with no Active home park: they get nothing either
        // way, and counting them here keeps the warning's total the same as
        // event mode's rather than dropping them from every count.
        foreach ($unnoticed as $r) {
            $cov = self::coverage(array_merge($configs, [$hyp]), $r, $survey, $parentOf);
            $noNotice += ($cov['credit_id'] === PHP_INT_MAX
                || ($cov['credit_id'] === null && $mode === 'home_park'
                    && self::coverage([$hyp], $r, $survey, $parentOf)['no_home_park'])) ? 1 : 0;
        }
        foreach ($owed as $r) {
            $cov = self::coverage(array_merge($configs, [$hyp]), $r, $survey, $parentOf);
            if ($cov['credit_id'] === PHP_INT_MAX) {
                $n++;
            } elseif ($cov['credit_id'] === null && $mode === 'home_park'
                && self::coverage([$hyp], $r, $survey, $parentOf)['no_home_park']) {
                $noPark++;
            }
        }
        return ['eligible_now' => $n, 'no_home_park' => $noPark, 'no_notice' => $noNotice];
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
            $id = self::coverage($configs, $r, $survey, $parentOf)['credit_id'];
            $n += ($id !== null && isset($countIds[$id])) ? 1 : 0;
        }
        return $n;
    }

    /**
     * Non-test Any ORK Data responses whose player has no credit for this
     * survey yet (park_id: Active parks only). Only those whose data gate
     * showed a credit line are owed (D1): a backfill never puts a public,
     * dated credit on someone who chose Any ORK Data without being told.
     * $noticed = false lists the others instead (the panel's no_notice count).
     * A banned or currently suspended player's response is held, not owed
     * (heldSql()); $held = true lists those instead (the panel's held count).
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

    /** $configs with every event config given its event where the survey has a start date. */
    private function withEvents(array $configs, array $survey): array
    {
        foreach ($configs as $i => $c) {
            if ($c['mode'] === 'event') {
                $configs[$i] = $this->ensureEvent($c, $survey);
            }
        }
        return $configs;
    }

    /**
     * Give an event config its event, or re-date the one it has when the start
     * date moved (open_at edited, a reopened survey restamped) and nobody has
     * attendance on it yet. Never call inside a transaction:
     * create_system_event opens its own.
     *
     * $config may be stale: another request (enable's backfill beside a live
     * submit, the sweep) can create and link an event after this one read it.
     * The link is therefore conditional on the row still holding what was
     * read; the loser deletes its own new event, which holds nothing yet, and
     * uses the winner's, so no orphan "Survey Credit" event is left behind.
     */
    private function ensureEvent(array $config, array $survey): array
    {
        $start    = self::startDate($survey);
        $detailId = (int) ($config['event_calendardetail_id'] ?? 0);
        if ($detailId > 0) {
            $occ = $this->fetchRow('SELECT DATE(event_start) AS d FROM ' . DB_PREFIX . 'event_calendardetail
                                     WHERE event_calendardetail_id = ' . $detailId);
            if ($occ !== null) {
                if ($start !== null && (string) $occ['d'] !== $start) {
                    // Refused (and left alone) once the occurrence holds attendance:
                    // credits already posted keep the date they were given.
                    Ork3::$Lib->eventplanning->redate_system_event($detailId, $start);
                }
                return $config;
            }
        }
        if ($start === null) {
            return $config;
        }
        if (method_exists($this->db, 'InTrans') && $this->db->InTrans()) {
            // create_system_event refuses too; say which caller broke the rule.
            $this->logFailure((int) $survey['survey_id'], 0, 'create_event', 'ensureEvent called inside an open transaction');
            return $config;
        }
        $isPark = $config['grantor_type'] === 'park';
        $title  = (string) $survey['title'];
        $r = Ork3::$Lib->eventplanning->create_system_event([
            'KingdomId'   => $isPark ? 0 : (int) $config['grantor_id'],
            'ParkId'      => $isPark ? (int) $config['grantor_id'] : 0,
            'Name'        => self::eventName($title),
            'Date'        => $start,
            'Description' => 'Attendance credit for completing the survey "' . $title . '". Credits are entered automatically '
                . 'for respondents who chose to link their answers to their ORK profile.',
            'Url'         => (defined('UIR') ? UIR : HTTP_UI_REMOTE . 'index.php?Route=') . 'Survey/s/' . (string) $survey['slug'],
            'UrlName'     => 'Take the survey',
        ]);
        if ((int) ($r['Status'] ?? 1) !== 0) {
            $this->logFailure((int) $survey['survey_id'], 0, 'create_event', (string) ($r['Error'] ?? ''));
            return $config;
        }
        $creditId = (int) $config['credit_id'];
        $this->exec('UPDATE ' . DB_PREFIX . 'survey_credit SET event_id = ' . (int) $r['EventId']
            . ', event_calendardetail_id = ' . (int) $r['DetailId'] . ' WHERE credit_id = ' . $creditId
            . ' AND (event_calendardetail_id IS NULL OR event_calendardetail_id = ' . $detailId . ')');
        $now    = $this->fetchRow('SELECT event_id, event_calendardetail_id FROM ' . DB_PREFIX . 'survey_credit WHERE credit_id = ' . $creditId);
        $linked = $now ? (int) $now['event_calendardetail_id'] : 0;
        if ($linked !== (int) $r['DetailId']) {
            $del = Ork3::$Lib->eventplanning->delete_system_event((int) $r['EventId']);
            if ((int) ($del['Status'] ?? 1) !== 0) {
                // The losing event stays published and unlinked: say so in the log.
                $this->logFailure((int) $survey['survey_id'], 0, 'delete_losing_event', (string) ($del['Error'] ?? ''));
            }
            if ($linked <= 0) {
                $this->logFailure((int) $survey['survey_id'], 0, 'link_event', '');
                return $config;
            }
            $config['event_id']                = (int) $now['event_id'];
            $config['event_calendardetail_id'] = $linked;
            return $config;
        }
        // Event::GetActiveEventsAtScope() (the attendance pages' "currently
        // happening" nudge) skips occurrences a config points at. create_system_event
        // busted that cache before this UPDATE linked the ids, so bust it again.
        // A park event is park-scoped only (its park_id is set).
        Ork3::$Lib->ghettocache->bust('Event.GetActiveEventsAtScope', Ork3::$Lib->ghettocache->key([
            'Scope' => $isPark ? 'park' : 'kingdom', 'ScopeId' => (int) $config['grantor_id'], 'Date' => $start,
        ]));
        $config['event_id']                = (int) $r['EventId'];
        $config['event_calendardetail_id'] = (int) $r['DetailId'];
        return $config;
    }

    /**
     * One credit: attendance row + ledger row in ONE transaction, rolled back on
     * either failure. Callers pass only non-test `full` responses (owedResponses(),
     * grantFor()). Opens no nested transaction: events already exist by now.
     * $lastClass is the player's last class when the caller fetched it for a
     * batch (lastClasses()); null looks it up.
     *
     * @return 'granted'|'already'|'pending'
     */
    private function grant(array $survey, array $config, array $response, ?int $lastClass = null): string
    {
        $sid = (int) $survey['survey_id'];
        $uid = (int) $response['mundane_id'];

        if ($config['mode'] === 'event') {
            $occ = (int) ($config['event_calendardetail_id'] ?? 0) > 0 ? $this->fetchRow(
                'SELECT e.event_id, e.kingdom_id, COALESCE(cd.at_park_id, 0) AS at_park_id, DATE(cd.event_start) AS d
                   FROM ' . DB_PREFIX . 'event_calendardetail cd
                   JOIN ' . DB_PREFIX . 'event e ON e.event_id = cd.event_id
                  WHERE cd.event_calendardetail_id = ' . (int) $config['event_calendardetail_id']
            ) : null;
            if ($occ === null) {
                return 'pending';
            }
            $where = ['Date' => (string) $occ['d'], 'ParkId' => (int) $occ['at_park_id'], 'KingdomId' => (int) $occ['kingdom_id'],
                      'EventId' => (int) $occ['event_id'], 'EventCalendarDetailId' => (int) $config['event_calendardetail_id']];
        } else {
            $park = $this->fetchRow('SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ' . (int) $response['park_id']
                . ' AND active = \'Active\'');
            if ($park === null) {
                return 'pending';
            }
            $where = ['Date' => substr((string) $response['submitted_at'], 0, 10), 'ParkId' => (int) $response['park_id'],
                      'KingdomId' => (int) $park['kingdom_id'], 'EventId' => 0, 'EventCalendarDetailId' => 0];
        }

        $class = self::classFor($lastClass ?? (int) Ork3::$Lib->attendance->GetPlayerLastClass(['MundaneId' => $uid]));

        if (!$this->exec('START TRANSACTION')) {
            $this->logFailure($sid, $uid, 'begin', '');
            return 'pending';
        }
        $att = Ork3::$Lib->attendance->add_system_credit($where + [
            'MundaneId' => $uid, 'ClassId' => $class, 'Credits' => 1, 'Note' => self::noteFor($sid),
            'ByWhomId' => (int) $config['enabled_by'], 'EntryMethod' => 'survey',
        ]);
        if ((int) $att['Status'] !== 0) {
            $this->exec('ROLLBACK');
            // A concurrent grant for this player trips the attendance unique key
            // before the ledger key: that is "already granted", not a failure (§7).
            if ($this->isGranted($sid, $uid)) {
                return 'already';
            }
            $this->logFailure($sid, $uid, 'attendance', (string) $att['Error']);
            return 'pending';
        }
        if (!$this->exec('INSERT INTO ' . DB_PREFIX . 'survey_credit_grant (survey_id, mundane_id, credit_id, attendance_id)
                          VALUES (' . $sid . ', ' . $uid . ', ' . (int) $config['credit_id'] . ', ' . (int) $att['AttendanceId'] . ')')) {
            $this->exec('ROLLBACK');
            return $this->isGranted($sid, $uid) ? 'already' : 'pending';   // a concurrent grant won
        }
        if (!$this->exec('COMMIT')) {
            $this->exec('ROLLBACK');
            $this->logFailure($sid, $uid, 'commit', '');
            return 'pending';
        }
        Ork3::$Lib->attendance->bust_player_attendance_caches($uid);   // after COMMIT, never inside it
        return 'granted';
    }

    /** @return array<int,string> event_id => name for these configs' events, in ONE query. */
    private function eventNames(array $configs): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $c): int => (int) ($c['event_id'] ?? 0), $configs))));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($this->fetchAll('SELECT event_id, name FROM ' . DB_PREFIX . 'event WHERE event_id IN (' . implode(',', $ids) . ')') as $e) {
            $out[(int) $e['event_id']] = (string) $e['name'];
        }
        return $out;
    }

    private function configOut(array $c, int $granted, string $eventLabel): array
    {
        return [
            'credit_id'               => (int) $c['credit_id'],
            'grantor_type'            => (string) $c['grantor_type'],
            'grantor_id'              => (int) $c['grantor_id'],
            'grantor_name'            => $this->orgName((string) $c['grantor_type'], (int) $c['grantor_id']),
            'mode'                    => (string) $c['mode'],
            'event_id'                => (int) ($c['event_id'] ?? 0) ?: null,
            'event_calendardetail_id' => (int) ($c['event_calendardetail_id'] ?? 0) ?: null,
            'event_label'             => $eventLabel,
            'enabled_at'              => (string) $c['enabled_at'],
            'granted'                 => $granted,
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
