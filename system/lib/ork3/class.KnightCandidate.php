<?php

/**
 * Circle of Knights Manager, the candidate pipeline half.
 *
 * Candidacy is one row per (kingdom, player, order) in ork_knight_candidate. The
 * suggested watchlist is computed, never stored. Dismissal is a stage, so a
 * reconcile never re-adds a dismissed player. Candidates are never hard-deleted.
 *
 * reconcile() keeps the board honest with the real award records, idempotently,
 * with history rows written by the system (actor 0, "Automatic: ..."):
 *   1. a candidate whose player now holds the order's knighthood becomes Knighted
 *      (from any stage), remembering the ork_awards row that did it;
 *   2. a Watchlist candidate whose player holds the matching Master moves to
 *      Masterhood;
 *   3. resident players who hold a Master of an order but not its knighthood, and
 *      who have no row yet, are added straight into Masterhood (source
 *      master_auto). It never moves a card backwards and never touches a
 *      Dismissed row.
 * board() runs reconcile() first.
 *
 * Authority comes from KnightCircle::access(): only a Circle admin (GMoK, deputy,
 * ORK admin) changes anything. Viewers see the pipeline per KnightCircle's matrix,
 * and a viewer who is not a Circle admin never sees their own candidacies.
 *
 * Callers reach this through Model_KnightCircle; it must never be added to the
 * class list in orkservice/Json/index.php.
 *
 * Envelope: ['Status' => 0, ...] ok; 1 bad request; 3 not authorized.
 */
class KnightCandidate
{
    public const STAGES = ['watchlist', 'masterhood', 'discussion', 'voting', 'holding', 'elect', 'knighted', 'dismissed'];

    /** The stages the board shows as lanes: the path, then Holding as the side column. Dismissed hides behind a toggle. */
    public const BOARD_STAGES = ['watchlist', 'masterhood', 'discussion', 'voting', 'elect', 'knighted', 'holding'];

    /** from => allowed to (for a person moving a card). The system may also do anything -> knighted and watchlist -> masterhood. */
    public const TRANSITIONS = [
        'watchlist'  => ['masterhood', 'discussion', 'dismissed'],
        'masterhood' => ['watchlist', 'discussion', 'dismissed'],
        'discussion' => ['voting', 'holding', 'watchlist', 'dismissed'],
        'voting'     => ['elect', 'discussion', 'holding'],
        'holding'    => ['discussion', 'voting', 'dismissed'],
        'elect'      => ['voting', 'discussion', 'holding'],
        'knighted'   => [],
        'dismissed'  => ['watchlist'],
    ];

    /** Stages a candidate may be created (or reinstated) into. */
    public const ADD_STAGES = ['watchlist', 'masterhood', 'discussion'];

    public const SOURCES = ['manual', 'suggested', 'master_auto'];

    private const COMMENT_MAX = 500;
    private const NOTE_MAX = 4000;
    private const VOTE_MAX = 9999;

    private $db;

    public function __construct()
    {
        global $DB;
        $this->db = $DB;
    }

    // -----------------------------------------------------------------------
    // Pure rules
    // -----------------------------------------------------------------------

    /**
     * May a card move $from -> $to? Pure. Knighted is entered by the system only
     * (the knighthood being granted); the system may also promote watchlist ->
     * masterhood when the player earns the Master.
     */
    public static function canTransition(string $from, string $to, bool $system = false): bool
    {
        if (!in_array($from, self::STAGES, true) || !in_array($to, self::STAGES, true) || $from === $to) {
            return false;
        }
        if ($system) {
            if ($to === 'knighted') {
                return true;
            }
            if ($from === 'watchlist' && $to === 'masterhood') {
                return true;
            }
        }
        return in_array($to, self::TRANSITIONS[$from], true);
    }

    /**
     * Normalise a vote tally. Pure. Accepts keys For/Against/Abstain (any case) or
     * votes_for/votes_against/votes_abstain; each a whole number 0..9999. null or an
     * empty array means "no tally".
     *
     * @return array{ok: bool, votes: ?array{For:int,Against:int,Abstain:int}, error: string}
     */
    public static function normalizeVotes(?array $votes): array
    {
        if ($votes === null || $votes === []) {
            return ['ok' => true, 'votes' => null, 'error' => ''];
        }
        $lower = [];
        foreach ($votes as $k => $v) {
            $lower[strtolower((string) $k)] = $v;
        }
        $out = [];
        foreach (['For' => ['for', 'votes_for'], 'Against' => ['against', 'votes_against'], 'Abstain' => ['abstain', 'votes_abstain']] as $label => $keys) {
            $raw = 0;
            foreach ($keys as $key) {
                if (array_key_exists($key, $lower)) {
                    $raw = $lower[$key];
                    break;
                }
            }
            if ($raw === '' || $raw === null) {
                $raw = 0;
            }
            if (is_bool($raw) || !is_numeric($raw) || (string) (int) $raw !== (string) (0 + $raw) || (int) $raw < 0 || (int) $raw > self::VOTE_MAX) {
                return ['ok' => false, 'votes' => null, 'error' => 'Votes for, against and abstain must be whole numbers from 0 to ' . self::VOTE_MAX . '.'];
            }
            $out[$label] = (int) $raw;
        }
        return ['ok' => true, 'votes' => $out, 'error' => ''];
    }

    /** Trim and cap free text to $max characters. Pure. */
    public static function clipText(string $text, int $max): string
    {
        $text = trim($text);
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }

    // -----------------------------------------------------------------------
    // Board
    // -----------------------------------------------------------------------

    /**
     * The whole pipeline for a kingdom: lane => cards, plus Dismissed, plus (for
     * Circle admins) the computed suggestions. Reconciles first.
     */
    public function board(int $uid, int $kingdomId): array
    {
        $gate = $this->pipelineGate($uid, $kingdomId);
        if ($gate['error'] !== null) {
            return $gate['error'];
        }
        $access = $gate['access'];
        $config = $gate['config'];

        $rec = $this->reconcile($kingdomId);

        $where = 'c.kingdom_id = ' . (int) $kingdomId;
        if (!$access['CanManage']) {
            $where .= ' AND c.mundane_id <> ' . (int) $uid;
        }
        $cards = $this->cards($where, $this->family($kingdomId, $config));

        $stages = [];
        foreach (array_merge(self::BOARD_STAGES, ['dismissed']) as $s) {
            $stages[$s] = [];
        }
        foreach ($cards as $card) {
            $stages[$card['Stage']][] = $card;
        }

        $out = [
            'Status'      => 0,
            'KingdomId'   => $kingdomId,
            'Stages'      => $stages,
            'BoardStages' => self::BOARD_STAGES,
            'Suggestions' => [],
            'Reconciled'  => ['Knighted' => $rec['Knighted'] ?? 0, 'Promoted' => $rec['Promoted'] ?? 0, 'Added' => $rec['Added'] ?? 0],
            'Access'      => $access,
            'MinRank'     => (int) $config['watchlist_min_rank'],
        ];
        if ($access['CanManage']) {
            $s = $this->suggestions($kingdomId, 0);
            $out['Suggestions'] = $s['Suggestions'] ?? [];
        }
        return $out;
    }

    /**
     * Players who look ready for the Watchlist: residents with a ladder at
     * rank >= $minRank (0 = the kingdom's watchlist_min_rank), not Legends, who do
     * not already hold the order and have no candidate row for it. A ladder that
     * leads to no knighthood (or one we cannot map) comes back with OrderAwardId 0
     * and the GMoK picks the order on promote. Computed, never stored.
     */
    public function suggestions(int $kingdomId, int $minRank = 0): array
    {
        if (!valid_id($kingdomId)) {
            return ['Status' => 1, 'Error' => 'Choose a Kingdom.'];
        }
        $config = $this->circle()->config($kingdomId);
        if ($minRank <= 0) {
            $minRank = (int) $config['watchlist_min_rank'];
        }
        $minRank = max(1, $minRank);
        $fam = implode(',', array_map('intval', $this->family($kingdomId, $config)));

        $rows = [];
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT ma.mundane_id, m.persona, m.park_id, p.name AS park_name, ma.award_id AS ladder_id, a.name AS ladder_name,
                    GREATEST(MAX(ma.rank), COUNT(ma.awards_id)) AS ladder_rank
             FROM ' . DB_PREFIX . 'awards ma
             JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = ma.mundane_id
             JOIN ' . DB_PREFIX . 'award a ON a.award_id = ma.award_id
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = m.park_id
             WHERE a.is_ladder = 1 AND a.award_id <> 31
               AND (ma.revoked = 0 OR ma.revoked IS NULL)
               AND m.active = 1 AND m.is_legend = 0 AND m.kingdom_id IN (' . $fam . ')
             GROUP BY ma.mundane_id, ma.award_id
             HAVING ladder_rank >= ' . (int) $minRank . '
             ORDER BY ladder_rank DESC, m.persona'
        );
        if ($rs) {
            while ($rs->Next()) {
                $rows[] = [
                    'MundaneId' => (int) $rs->mundane_id, 'Persona' => (string) $rs->persona,
                    'ParkId' => (int) $rs->park_id, 'ParkName' => (string) $rs->park_name,
                    'LadderAwardId' => (int) $rs->ladder_id, 'LadderName' => (string) $rs->ladder_name,
                    'Rank' => (int) $rs->ladder_rank,
                ];
            }
        }
        if (!$rows) {
            return ['Status' => 0, 'Suggestions' => [], 'MinRank' => $minRank];
        }

        $ids      = array_values(array_unique(array_column($rows, 'MundaneId')));
        $holds    = $this->holdings($ids);
        $existing = $this->existingRows($kingdomId);

        $best = [];
        foreach ($rows as $r) {
            $mid   = $r['MundaneId'];
            $order = (int) (KnightCircle::LADDER_ORDER[$r['LadderAwardId']] ?? 0);
            if ($order > 0) {
                if (isset($holds[$mid][$order]) || isset($existing[$mid][$order])) {
                    continue;
                }
                $key = $mid . ':' . $order;
            } else {
                // Unmapped ladder: skip anyone who is already a knight or already in the pipeline.
                if (!empty($holds[$mid]) || !empty($existing[$mid])) {
                    continue;
                }
                $key = $mid . ':l' . $r['LadderAwardId'];
            }
            if (isset($best[$key]) && $best[$key]['Rank'] >= $r['Rank']) {
                continue;
            }
            $best[$key] = $r + [
                'OrderAwardId' => $order,
                'OrderName'    => $order > 0 ? KnightCircle::ORDER_NAMES[$order] : '',
            ];
        }
        $list = array_values($best);
        usort($list, function ($a, $b) {
            return [$b['Rank'], $a['Persona'], $a['MundaneId']] <=> [$a['Rank'], $b['Persona'], $b['MundaneId']];
        });
        return ['Status' => 0, 'Suggestions' => $list, 'MinRank' => $minRank];
    }

    // -----------------------------------------------------------------------
    // Reconcile
    // -----------------------------------------------------------------------

    /**
     * Bring the pipeline in line with the award records. Idempotent; no authority
     * check (it only ever reflects facts already recorded).
     *
     * @return array{Status:int, Knighted:int, Promoted:int, Added:int}
     */
    public function reconcile(int $kingdomId): array
    {
        $out = ['Status' => 0, 'Knighted' => 0, 'Promoted' => 0, 'Added' => 0];
        if (!valid_id($kingdomId)) {
            return ['Status' => 1, 'Error' => 'Choose a Kingdom.'] + $out;
        }
        $kid = (int) $kingdomId;
        $now = date('Y-m-d H:i:s');

        // 1. Knighted: the player now holds the order.
        $todo = [];
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT c.candidate_id, c.stage, h.awards_id
             FROM ' . DB_PREFIX . 'knight_candidate c
             JOIN (' . $this->holdingsSql(' AND ma.mundane_id IN (SELECT mundane_id FROM ' . DB_PREFIX . 'knight_candidate WHERE kingdom_id = ' . $kid . ')') . ') h ON h.mundane_id = c.mundane_id AND h.order_id = c.award_id
             WHERE c.kingdom_id = ' . $kid . " AND c.stage <> 'knighted'"
        );
        if ($rs) {
            while ($rs->Next()) {
                $todo[] = [(int) $rs->candidate_id, (string) $rs->stage, (int) $rs->awards_id];
            }
        }
        foreach ($todo as [$cid, $from, $awardsId]) {
            if ($this->setKnighted($cid, $from, $awardsId, 'Automatic: knighthood recorded.', $now)) {
                $out['Knighted']++;
            }
        }

        // 2. Masterhood: a Watchlist candidate holds the matching Master.
        $todo = [];
        foreach ($this->mastersByOrder() as $order => $masterIds) {
            $this->db->Clear();
            $rs = $this->db->DataSet(
                'SELECT c.candidate_id FROM ' . DB_PREFIX . 'knight_candidate c
                 WHERE c.kingdom_id = ' . $kid . " AND c.stage = 'watchlist' AND c.award_id = " . (int) $order . '
                   AND EXISTS (SELECT 1 FROM ' . DB_PREFIX . 'awards mo
                               WHERE mo.mundane_id = c.mundane_id AND mo.award_id IN (' . implode(',', $masterIds) . ')
                                 AND (mo.revoked = 0 OR mo.revoked IS NULL))'
            );
            if ($rs) {
                while ($rs->Next()) {
                    $todo[] = (int) $rs->candidate_id;
                }
            }
        }
        foreach ($todo as $cid) {
            if ($this->setStage($cid, 'watchlist', 'masterhood', 0, 'Automatic: Master recorded.', null, $now)) {
                $out['Promoted']++;
            }
        }

        // 3. Auto-include resident Masters who do not hold the knighthood and have no row.
        $config = $this->circle()->config($kid);
        $fam    = implode(',', array_map('intval', $this->family($kid, $config)));
        $pairs  = [];
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT DISTINCT ma.mundane_id, mm.order_id
             FROM ' . DB_PREFIX . 'awards ma
             JOIN (' . $this->masterMapSql() . ') mm ON mm.master_id = ma.award_id
             JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = ma.mundane_id
             WHERE (ma.revoked = 0 OR ma.revoked IS NULL)
               AND m.active = 1 AND m.is_legend = 0 AND m.kingdom_id IN (' . $fam . ')
               AND NOT EXISTS (SELECT 1 FROM ' . DB_PREFIX . 'knight_candidate c
                               WHERE c.kingdom_id = ' . $kid . ' AND c.mundane_id = ma.mundane_id AND c.award_id = mm.order_id)
               AND NOT EXISTS (SELECT 1 FROM ' . DB_PREFIX . 'awards hm
                               JOIN ' . DB_PREFIX . 'kingdomaward hk ON hk.kingdomaward_id = hm.kingdomaward_id
                               LEFT JOIN ' . DB_PREFIX . 'award ha ON ha.award_id = hm.alias_award_id
                               WHERE hm.mundane_id = ma.mundane_id AND COALESCE(ha.award_id, hk.award_id) = mm.order_id
                                 AND (hm.revoked = 0 OR hm.revoked IS NULL))'
        );
        if ($rs) {
            while ($rs->Next()) {
                $pairs[] = [(int) $rs->mundane_id, (int) $rs->order_id];
            }
        }
        foreach ($pairs as [$mid, $order]) {
            $this->db->Clear();
            $this->db->ExecuteChecked(
                'INSERT IGNORE INTO ' . DB_PREFIX . 'knight_candidate
                   (kingdom_id, mundane_id, award_id, stage, source, sort_order, stage_changed_at, stage_changed_by, created_by, created_at)
                 VALUES (' . $kid . ', ' . (int) $mid . ', ' . (int) $order . ", 'masterhood', 'master_auto', 0, '" . $now . "', 0, 0, '" . $now . "')"
            );
            $cid = $this->findCandidateId($kid, $mid, $order);
            if ($cid > 0 && $this->historyCount($cid) === 0) {
                $this->history($cid, null, 'masterhood', 0, 'Automatic: added as a Master without the knighthood.', null, $now);
                $out['Added']++;
            }
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Put a player on the pipeline for an order. A Dismissed row is reinstated.
     * Optional trailing params: $stage (watchlist|masterhood|discussion) and
     * $source (manual|suggested).
     */
    public function addCandidate(int $uid, int $kingdomId, int $mundaneId, int $orderAwardId, string $comment = '', string $stage = 'watchlist', string $source = 'manual'): array
    {
        if (!valid_id($kingdomId) || !valid_id($mundaneId)) {
            return $this->fail('Choose a Kingdom and a player.');
        }
        $access = $this->circle()->access($uid, $kingdomId);
        if (!$access['CanManage']) {
            return $this->denied('Only the Guardian of the Circle, their deputies or an ORK admin can change the pipeline.');
        }
        if (Legend::isLegend($mundaneId)) {
            return $this->denied(Legend::REFUSED);
        }
        if (!isset(KnightCircle::ORDERS[$orderAwardId])) {
            return $this->fail('Choose which Order the candidate is being considered for.');
        }
        if (!in_array($stage, self::ADD_STAGES, true)) {
            return $this->fail('A candidate can only be added to Watchlist, Masterhood or Under Discussion.');
        }
        if (!in_array($source, ['manual', 'suggested'], true)) {
            $source = 'manual';
        }
        $config = $this->circle()->config($kingdomId);
        $fam    = $this->family($kingdomId, $config);
        $player = $this->activePlayer($mundaneId);
        if ($player === null) {
            return $this->fail('That player does not exist or is not active.');
        }
        if (!in_array($player['KingdomId'], $fam, true)) {
            return $this->fail('That player is not a resident of this Kingdom.');
        }
        if (isset($this->holdings([$mundaneId])[$mundaneId][$orderAwardId])) {
            return $this->fail($player['Persona'] . ' already holds ' . KnightCircle::ORDER_NAMES[$orderAwardId] . '.');
        }
        $comment = self::clipText($comment, self::COMMENT_MAX);
        $now = date('Y-m-d H:i:s');

        $existing = $this->findCandidateId($kingdomId, $mundaneId, $orderAwardId);
        if ($existing > 0) {
            $row = $this->loadRow($existing);
            if ($row === null || $row['Stage'] !== 'dismissed') {
                return $this->fail($player['Persona'] . ' is already a candidate for ' . KnightCircle::ORDER_NAMES[$orderAwardId] . '.');
            }
            $stage = 'watchlist';
            if (!$this->setStage($existing, 'dismissed', $stage, $uid, $comment !== '' ? $comment : 'Reinstated.', null, $now, true)) {
                return $this->fail('Could not reinstate the candidate.');
            }
            $this->audit('addCandidate', ['KingdomId' => $kingdomId, 'MundaneId' => $mundaneId, 'OrderAwardId' => $orderAwardId, 'by_whom_id' => $uid], $existing, ['Stage' => 'dismissed'], ['Stage' => $stage, 'Reinstated' => true]);
            return ['Status' => 0, 'CandidateId' => $existing, 'Stage' => $stage, 'Reinstated' => true];
        }

        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'INSERT INTO ' . DB_PREFIX . 'knight_candidate
               (kingdom_id, mundane_id, award_id, stage, source, sort_order, stage_changed_at, stage_changed_by, created_by, created_at)
             VALUES (' . (int) $kingdomId . ', ' . (int) $mundaneId . ', ' . (int) $orderAwardId . ", '" . $stage . "', '" . $source . "', "
            . $this->nextSort($kingdomId, $stage) . ", '" . $now . "', " . (int) $uid . ', ' . (int) $uid . ", '" . $now . "')"
        );
        $cid = $ok === false ? 0 : $this->findCandidateId($kingdomId, $mundaneId, $orderAwardId);
        if ($cid <= 0) {
            return $this->fail('Could not add the candidate.');
        }
        $this->history($cid, null, $stage, $uid, $comment, null, $now);
        $this->audit('addCandidate', ['KingdomId' => $kingdomId, 'MundaneId' => $mundaneId, 'OrderAwardId' => $orderAwardId, 'by_whom_id' => $uid], $cid, null, ['Stage' => $stage, 'Source' => $source]);
        return ['Status' => 0, 'CandidateId' => $cid, 'Stage' => $stage, 'Reinstated' => false];
    }

    /**
     * Move a card. $votes (For/Against/Abstain) is recorded only when the card is
     * leaving Voting; sent for any other move it is ignored.
     */
    public function moveCandidate(int $uid, int $candidateId, string $toStage, string $comment = '', ?array $votes = null): array
    {
        $row = $this->loadRow($candidateId);
        if ($row === null) {
            return $this->fail('That candidate does not exist.');
        }
        $access = $this->circle()->access($uid, $row['KingdomId']);
        if (!$access['CanManage']) {
            return $this->denied('Only the Guardian of the Circle, their deputies or an ORK admin can change the pipeline.');
        }
        if (Legend::isLegend($row['MundaneId'])) {
            return $this->denied(Legend::REFUSED);
        }
        if (!in_array($toStage, self::STAGES, true)) {
            return $this->fail('Unknown stage.');
        }
        if (!self::canTransition($row['Stage'], $toStage, false)) {
            return $this->fail($toStage === 'knighted'
                ? 'A candidate becomes Knighted when the knighthood is recorded, not by moving the card.'
                : 'A candidate cannot move from ' . $row['Stage'] . ' to ' . $toStage . '.');
        }
        $tally = null;
        if ($row['Stage'] === 'voting') {
            $norm = self::normalizeVotes($votes);
            if (!$norm['ok']) {
                return $this->fail($norm['error']);
            }
            $tally = $norm['votes'];
        }
        $comment = self::clipText($comment, self::COMMENT_MAX);
        if (!$this->setStage($candidateId, $row['Stage'], $toStage, $uid, $comment, $tally, date('Y-m-d H:i:s'), true)) {
            return $this->fail('The candidate was moved by someone else. Refresh the board.');
        }
        $this->audit('moveCandidate', ['CandidateId' => $candidateId, 'To' => $toStage, 'by_whom_id' => $uid], $candidateId, ['Stage' => $row['Stage']], ['Stage' => $toStage, 'Votes' => $tally]);
        return ['Status' => 0, 'CandidateId' => $candidateId, 'From' => $row['Stage'], 'To' => $toStage, 'Votes' => $tally];
    }

    /** One candidate with facts, history and notes. */
    public function candidate(int $uid, int $candidateId): array
    {
        $row = $this->loadRow($candidateId);
        if ($row === null) {
            return $this->fail('That candidate does not exist.');
        }
        $gate = $this->pipelineGate($uid, $row['KingdomId']);
        if ($gate['error'] !== null) {
            return $gate['error'];
        }
        $access = $gate['access'];
        if (!$access['CanManage'] && $row['MundaneId'] === $uid) {
            return $this->denied('You cannot view your own candidacy.');
        }
        $cards = $this->cards('c.candidate_id = ' . (int) $candidateId, $this->family($row['KingdomId'], $gate['config']));
        if (!$cards) {
            return $this->fail('That candidate does not exist.');
        }
        $card = $cards[0];

        $history = [];
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT h.history_id, h.from_stage, h.to_stage, h.actor_id, m.persona AS actor_persona, h.comment,
                    h.votes_for, h.votes_against, h.votes_abstain, h.created_at
             FROM ' . DB_PREFIX . 'knight_candidate_history h
             LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = h.actor_id
             WHERE h.candidate_id = ' . (int) $candidateId . ' ORDER BY h.history_id'
        );
        if ($rs) {
            while ($rs->Next()) {
                $hasVotes = $rs->votes_for !== null || $rs->votes_against !== null || $rs->votes_abstain !== null;
                $history[] = [
                    'HistoryId' => (int) $rs->history_id,
                    'FromStage' => $rs->from_stage === null ? null : (string) $rs->from_stage,
                    'ToStage'   => (string) $rs->to_stage,
                    'ActorId'   => (int) $rs->actor_id,
                    'Actor'     => (int) $rs->actor_id === 0 ? 'System' : (string) $rs->actor_persona,
                    'Comment'   => (string) $rs->comment,
                    'Votes'     => $hasVotes ? ['For' => (int) $rs->votes_for, 'Against' => (int) $rs->votes_against, 'Abstain' => (int) $rs->votes_abstain] : null,
                    'CreatedAt' => (string) $rs->created_at,
                ];
            }
        }

        $notes = [];
        if ($access['CanManage']) {
            $this->db->Clear();
            $rs = $this->db->DataSet(
                'SELECT n.note_id, n.author_id, m.persona AS author_persona, n.body, n.created_at
                 FROM ' . DB_PREFIX . 'knight_candidate_note n
                 LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = n.author_id
                 WHERE n.candidate_id = ' . (int) $candidateId . ' AND n.deleted_at IS NULL ORDER BY n.note_id'
            );
            if ($rs) {
                while ($rs->Next()) {
                    $notes[] = [
                        'NoteId' => (int) $rs->note_id, 'AuthorId' => (int) $rs->author_id,
                        'Author' => (string) $rs->author_persona, 'Body' => (string) $rs->body,
                        'CreatedAt' => (string) $rs->created_at,
                        'CanDelete' => (int) $rs->author_id === $uid || $access['CanManage'],
                    ];
                }
            }
        }

        $allowed = [];
        if ($access['CanManage']) {
            foreach (self::TRANSITIONS[$card['Stage']] as $to) {
                $allowed[] = $to;
            }
        }
        return [
            'Status'      => 0,
            'Candidate'   => $card,
            'History'     => $history,
            'Notes'       => $notes,
            'Transitions' => $allowed,
            'CanManage'   => (bool) $access['CanManage'],
        ];
    }

    public function addNote(int $uid, int $candidateId, string $body): array
    {
        $row = $this->loadRow($candidateId);
        if ($row === null) {
            return $this->fail('That candidate does not exist.');
        }
        if (!$this->circle()->access($uid, $row['KingdomId'])['CanManage']) {
            return $this->denied('Only the Guardian of the Circle, their deputies or an ORK admin can add notes.');
        }
        if (Legend::isLegend($row['MundaneId'])) {
            return $this->denied(Legend::REFUSED);
        }
        $body = self::clipText($body, self::NOTE_MAX);
        if ($body === '') {
            return $this->fail('Write something first.');
        }
        $now = date('Y-m-d H:i:s');
        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'INSERT INTO ' . DB_PREFIX . 'knight_candidate_note (candidate_id, author_id, body, created_at)
             VALUES (' . (int) $candidateId . ', ' . (int) $uid . ", '" . $this->esc($body) . "', '" . $now . "')"
        );
        if ($ok === false) {
            return $this->fail('Could not save the note.');
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT MAX(note_id) AS id FROM ' . DB_PREFIX . 'knight_candidate_note
             WHERE candidate_id = ' . (int) $candidateId . ' AND author_id = ' . (int) $uid
        );
        $noteId = ($rs && $rs->Next()) ? (int) $rs->id : 0;
        $this->audit('addNote', ['CandidateId' => $candidateId, 'by_whom_id' => $uid], $candidateId, null, ['NoteId' => $noteId]);
        return ['Status' => 0, 'NoteId' => $noteId, 'CandidateId' => $candidateId, 'CreatedAt' => $now];
    }

    /** Soft-delete a note: its author, or a Circle admin of the candidate's kingdom. */
    public function deleteNote(int $uid, int $noteId): array
    {
        if (!valid_id($noteId)) {
            return $this->fail('That note does not exist.');
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT n.note_id, n.candidate_id, n.author_id, n.deleted_at, c.kingdom_id
             FROM ' . DB_PREFIX . 'knight_candidate_note n
             JOIN ' . DB_PREFIX . 'knight_candidate c ON c.candidate_id = n.candidate_id
             WHERE n.note_id = ' . (int) $noteId . ' LIMIT 1'
        );
        if (!$rs || !$rs->Next()) {
            return $this->fail('That note does not exist.');
        }
        $candidateId = (int) $rs->candidate_id;
        $authorId    = (int) $rs->author_id;
        $kingdomId   = (int) $rs->kingdom_id;
        $deleted     = $rs->deleted_at !== null && (string) $rs->deleted_at !== '';
        $access = $this->circle()->access($uid, $kingdomId);
        if (!$access['CanManage'] && !($authorId === $uid && $access['CanView'])) {
            return $this->denied('Only the note\'s author or a Circle officer can delete it.');
        }
        if ($deleted) {
            return $this->fail('That note was already deleted.');
        }
        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            "UPDATE " . DB_PREFIX . "knight_candidate_note SET deleted_at = '" . date('Y-m-d H:i:s') . "', deleted_by = " . (int) $uid . '
             WHERE note_id = ' . (int) $noteId . ' AND deleted_at IS NULL'
        );
        if ($ok === false) {
            return $this->fail('Could not delete the note.');
        }
        $this->audit('deleteNote', ['NoteId' => $noteId, 'by_whom_id' => $uid], $candidateId, ['NoteId' => $noteId], null);
        return ['Status' => 0, 'NoteId' => $noteId, 'CandidateId' => $candidateId];
    }

    /**
     * Best-effort hook from Player::AddAward / UpdateAward. A knighthood moves
     * matching candidates to Knighted; a Master moves matching Watchlist
     * candidates to Masterhood. Never creates rows, never throws.
     */
    public function onAwardGranted(int $mundaneId, int $awardId, int $awardsId): void
    {
        try {
            if ($mundaneId <= 0) {
                return;
            }
            $baseId = $awardId;
            $revoked = false;
            if ($awardsId > 0) {
                $this->db->Clear();
                $rs = $this->db->DataSet(
                    'SELECT ma.award_id, ma.revoked, COALESCE(alias.award_id, ka.award_id, ma.award_id) AS order_id
                     FROM ' . DB_PREFIX . 'awards ma
                     LEFT JOIN ' . DB_PREFIX . 'kingdomaward ka ON ka.kingdomaward_id = ma.kingdomaward_id
                     LEFT JOIN ' . DB_PREFIX . 'award alias ON alias.award_id = ma.alias_award_id
                     WHERE ma.awards_id = ' . (int) $awardsId . ' AND ma.mundane_id = ' . (int) $mundaneId . ' LIMIT 1'
                );
                if ($rs && $rs->Next()) {
                    $baseId  = (int) $rs->order_id;
                    $revoked = (int) $rs->revoked === 1;
                    $own     = (int) $rs->award_id;
                    if (!isset(KnightCircle::ORDERS[$baseId]) && $own > 0) {
                        $baseId = $own;
                    }
                }
            }
            if ($revoked || $baseId <= 0) {
                return;
            }
            $now = date('Y-m-d H:i:s');
            $kingdoms = [];
            if (isset(KnightCircle::ORDERS[$baseId])) {
                $this->db->Clear();
                $rs = $this->db->DataSet(
                    'SELECT candidate_id, kingdom_id, stage FROM ' . DB_PREFIX . 'knight_candidate
                     WHERE mundane_id = ' . (int) $mundaneId . ' AND award_id = ' . (int) $baseId . " AND stage <> 'knighted'"
                );
                $rows = [];
                if ($rs) {
                    while ($rs->Next()) {
                        $rows[] = [(int) $rs->candidate_id, (int) $rs->kingdom_id, (string) $rs->stage];
                    }
                }
                foreach ($rows as [$cid, $kid, $from]) {
                    if ($this->setKnighted($cid, $from, $awardsId, 'Automatic: knighthood recorded.', $now)) {
                        $kingdoms[$kid] = true;
                    }
                }
                $this->bustHomeRoster($mundaneId, array_keys($kingdoms));
                return;
            }
            $order = KnightCircle::masterOrderMap()[$baseId] ?? 0;
            if ($order > 0) {
                $this->db->Clear();
                $rs = $this->db->DataSet(
                    'SELECT candidate_id FROM ' . DB_PREFIX . 'knight_candidate
                     WHERE mundane_id = ' . (int) $mundaneId . ' AND award_id = ' . (int) $order . " AND stage = 'watchlist'"
                );
                $ids = [];
                if ($rs) {
                    while ($rs->Next()) {
                        $ids[] = (int) $rs->candidate_id;
                    }
                }
                foreach ($ids as $cid) {
                    $this->setStage($cid, 'watchlist', 'masterhood', 0, 'Automatic: Master recorded.', null, $now);
                }
            }
        } catch (\Throwable $e) {
            // Best effort: the award itself is already saved.
        }
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function circle(): KnightCircle
    {
        return (isset(Ork3::$Lib->knightcircle) && Ork3::$Lib->knightcircle instanceof KnightCircle)
            ? Ork3::$Lib->knightcircle
            : new KnightCircle();
    }

    /** @return int[] */
    private function family(int $kingdomId, array $config): array
    {
        return array_map('intval', $this->circle()->kingdomFamily($kingdomId, (bool) $config['include_principalities']));
    }

    /**
     * Who may see this kingdom's pipeline: Circle admins, appointers, resident
     * knights, and officers when the kingdom lets them.
     *
     * @return array{error: ?array, access: array, config: array}
     */
    private function pipelineGate(int $uid, int $kingdomId): array
    {
        if (!valid_id($kingdomId)) {
            return ['error' => $this->fail('Choose a Kingdom.'), 'access' => [], 'config' => []];
        }
        $access = $this->circle()->access($uid, $kingdomId);
        $config = $this->circle()->config($kingdomId);
        $may = $access['CanManage'] || $access['CanAppoint'] || $access['IsKnight']
            || ($access['IsOfficer'] && !empty($config['officers_see_pipeline']));
        if (!$may) {
            return ['error' => $this->denied('You do not have access to this Kingdom\'s candidate pipeline.'), 'access' => $access, 'config' => $config];
        }
        return ['error' => null, 'access' => $access, 'config' => $config];
    }

    /** SQL for a player's live knighthoods: mundane_id, order_id, awards_id (earliest), since. */
    private function holdingsSql(string $extraWhere = ''): string
    {
        return 'SELECT ma.mundane_id, COALESCE(alias.award_id, ka.award_id) AS order_id, MIN(ma.awards_id) AS awards_id
                FROM ' . DB_PREFIX . 'awards ma
                JOIN ' . DB_PREFIX . 'kingdomaward ka ON ka.kingdomaward_id = ma.kingdomaward_id
                LEFT JOIN ' . DB_PREFIX . 'award alias ON alias.award_id = ma.alias_award_id
                WHERE COALESCE(alias.award_id, ka.award_id) IN (' . implode(',', array_keys(KnightCircle::ORDERS)) . ')
                  AND (ma.revoked = 0 OR ma.revoked IS NULL)' . $extraWhere . '
                GROUP BY ma.mundane_id, COALESCE(alias.award_id, ka.award_id)';
    }

    /** Derived table of master award_id => order_id. */
    private function masterMapSql(): string
    {
        $parts = [];
        foreach (KnightCircle::masterOrderMap() as $masterId => $orderId) {
            $parts[] = 'SELECT ' . (int) $masterId . ' AS master_id, ' . (int) $orderId . ' AS order_id';
        }
        return $parts ? implode(' UNION ALL ', $parts) : 'SELECT 0 AS master_id, 0 AS order_id';
    }

    /** @return array<int,int[]> order id => master award ids */
    private function mastersByOrder(): array
    {
        $by = [];
        foreach (KnightCircle::masterOrderMap() as $masterId => $orderId) {
            $by[(int) $orderId][] = (int) $masterId;
        }
        return $by;
    }

    /** @return array<int,array<int,int>> mundane_id => [order_id => awards_id] for the given players. */
    private function holdings(array $mundaneIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $mundaneIds), fn($i) => $i > 0));
        $out = [];
        if (!$ids) {
            return $out;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT h.mundane_id, h.order_id, h.awards_id FROM (' . $this->holdingsSql(' AND ma.mundane_id IN (' . implode(',', $ids) . ')') . ') h'
        );
        if ($rs) {
            while ($rs->Next()) {
                $out[(int) $rs->mundane_id][(int) $rs->order_id] = (int) $rs->awards_id;
            }
        }
        return $out;
    }

    /** @return array<int,array<int,string>> mundane_id => [order_id => stage] for every row in the kingdom. */
    private function existingRows(int $kingdomId): array
    {
        $out = [];
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT mundane_id, award_id, stage FROM ' . DB_PREFIX . 'knight_candidate WHERE kingdom_id = ' . (int) $kingdomId
        );
        if ($rs) {
            while ($rs->Next()) {
                $out[(int) $rs->mundane_id][(int) $rs->award_id] = (string) $rs->stage;
            }
        }
        return $out;
    }

    /**
     * Board/detail cards for candidate rows matching $where (a fragment over alias c).
     *
     * @return array<int,array<string,mixed>>
     */
    private function cards(string $where, array $family): array
    {
        $cards = [];
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT c.candidate_id, c.kingdom_id, c.mundane_id, c.award_id, c.stage, c.source, c.sort_order, c.stage_changed_at,
                    c.stage_changed_by, c.knighted_awards_id, c.created_at,
                    m.persona, m.park_id, p.name AS park_name, m.kingdom_id AS home_kingdom_id, m.active, m.is_legend,
                    TIMESTAMPDIFF(DAY, c.stage_changed_at, NOW()) AS days_in_stage,
                    (SELECT COUNT(*) FROM ' . DB_PREFIX . 'knight_candidate_note n
                      WHERE n.candidate_id = c.candidate_id AND n.deleted_at IS NULL) AS note_count
             FROM ' . DB_PREFIX . 'knight_candidate c
             JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = c.mundane_id
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = m.park_id
             WHERE ' . $where . '
             ORDER BY c.sort_order, c.stage_changed_at, c.candidate_id'
        );
        if ($rs) {
            while ($rs->Next()) {
                $order = (int) $rs->award_id;
                $cards[] = [
                    'CandidateId'    => (int) $rs->candidate_id,
                    'KingdomId'      => (int) $rs->kingdom_id,
                    'MundaneId'      => (int) $rs->mundane_id,
                    'Persona'        => (string) $rs->persona,
                    'ParkId'         => (int) $rs->park_id,
                    'ParkName'       => (string) $rs->park_name,
                    'HomeKingdomId'  => (int) $rs->home_kingdom_id,
                    'Resident'       => in_array((int) $rs->home_kingdom_id, $family, true),
                    'Active'         => (int) $rs->active === 1,
                    'OrderAwardId'   => $order,
                    'OrderName'      => KnightCircle::ORDER_NAMES[$order] ?? '',
                    'OrderShort'     => KnightCircle::ORDERS[$order] ?? '',
                    'Stage'          => (string) $rs->stage,
                    'Source'         => (string) $rs->source,
                    'SortOrder'      => (int) $rs->sort_order,
                    'StageChangedAt' => (string) $rs->stage_changed_at,
                    'StageChangedBy' => (int) $rs->stage_changed_by,
                    'DaysInStage'    => max(0, (int) $rs->days_in_stage),
                    'NoteCount'      => (int) $rs->note_count,
                    'KnightedAwardsId' => $rs->knighted_awards_id === null ? null : (int) $rs->knighted_awards_id,
                    'KnightRevoked'  => false,
                    'LadderRank'     => 0,
                    'Ladders'        => [],
                    'HoldsMaster'    => false,
                    'CreatedAt'      => (string) $rs->created_at,
                ];
            }
        }
        if (!$cards) {
            return $cards;
        }

        // Ladder ranks and Masters, in two queries for every card.
        $ids   = array_values(array_unique(array_column($cards, 'MundaneId')));
        $in    = implode(',', array_map('intval', $ids));
        $ranks = [];
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT ma.mundane_id, ma.award_id, a.name, GREATEST(MAX(ma.rank), COUNT(ma.awards_id)) AS ladder_rank
             FROM ' . DB_PREFIX . 'awards ma
             JOIN ' . DB_PREFIX . 'award a ON a.award_id = ma.award_id
             WHERE ma.mundane_id IN (' . $in . ') AND ma.award_id IN (' . implode(',', array_keys(KnightCircle::LADDER_ORDER)) . ')
               AND (ma.revoked = 0 OR ma.revoked IS NULL)
             GROUP BY ma.mundane_id, ma.award_id, a.name'
        );
        if ($rs) {
            while ($rs->Next()) {
                $ranks[(int) $rs->mundane_id][(int) $rs->award_id] = ['Name' => (string) $rs->name, 'Rank' => (int) $rs->ladder_rank];
            }
        }
        $masters = [];
        $masterOrder = KnightCircle::masterOrderMap();
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT DISTINCT mundane_id, award_id FROM ' . DB_PREFIX . 'awards
             WHERE mundane_id IN (' . $in . ') AND award_id IN (' . implode(',', array_map('intval', array_keys($masterOrder)) ?: [0]) . ')
               AND (revoked = 0 OR revoked IS NULL)'
        );
        if ($rs) {
            while ($rs->Next()) {
                $masters[(int) $rs->mundane_id][(int) ($masterOrder[(int) $rs->award_id] ?? 0)] = true;
            }
        }
        $holds = $this->holdings($ids);
        foreach ($cards as &$card) {
            $mid = $card['MundaneId'];
            $ord = $card['OrderAwardId'];
            foreach ($ranks[$mid] ?? [] as $ladderId => $info) {
                $card['Ladders'][] = ['AwardId' => $ladderId, 'Name' => $info['Name'], 'Rank' => $info['Rank'], 'OrderAwardId' => (int) (KnightCircle::LADDER_ORDER[$ladderId] ?? 0)];
                if ((int) (KnightCircle::LADDER_ORDER[$ladderId] ?? 0) === $ord) {
                    $card['LadderRank'] = max($card['LadderRank'], $info['Rank']);
                }
            }
            $card['HoldsMaster'] = !empty($masters[$mid][$ord]);
            if ($card['Stage'] === 'knighted') {
                $card['KnightRevoked'] = !isset($holds[$mid][$ord]);
            }
        }
        unset($card);
        return $cards;
    }

    /** @return array{CandidateId:int,KingdomId:int,MundaneId:int,OrderAwardId:int,Stage:string}|null */
    private function loadRow(int $candidateId): ?array
    {
        if (!valid_id($candidateId)) {
            return null;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT candidate_id, kingdom_id, mundane_id, award_id, stage FROM ' . DB_PREFIX . 'knight_candidate
             WHERE candidate_id = ' . (int) $candidateId . ' LIMIT 1'
        );
        if (!$rs || !$rs->Next()) {
            return null;
        }
        return [
            'CandidateId' => (int) $rs->candidate_id, 'KingdomId' => (int) $rs->kingdom_id,
            'MundaneId' => (int) $rs->mundane_id, 'OrderAwardId' => (int) $rs->award_id, 'Stage' => (string) $rs->stage,
        ];
    }

    private function findCandidateId(int $kingdomId, int $mundaneId, int $orderId): int
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT candidate_id FROM ' . DB_PREFIX . 'knight_candidate
             WHERE kingdom_id = ' . (int) $kingdomId . ' AND mundane_id = ' . (int) $mundaneId . ' AND award_id = ' . (int) $orderId . ' LIMIT 1'
        );
        return ($rs && $rs->Next()) ? (int) $rs->candidate_id : 0;
    }

    private function historyCount(int $candidateId): int
    {
        $this->db->Clear();
        $rs = $this->db->DataSet('SELECT COUNT(*) AS n FROM ' . DB_PREFIX . 'knight_candidate_history WHERE candidate_id = ' . (int) $candidateId);
        return ($rs && $rs->Next()) ? (int) $rs->n : 0;
    }

    private function nextSort(int $kingdomId, string $stage): int
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM ' . DB_PREFIX . 'knight_candidate
             WHERE kingdom_id = ' . (int) $kingdomId . " AND stage = '" . $this->esc($stage) . "'"
        );
        return ($rs && $rs->Next()) ? (int) $rs->n : 1;
    }

    /** @return array{MundaneId:int,Persona:string,KingdomId:int}|null */
    private function activePlayer(int $mundaneId): ?array
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT mundane_id, persona, kingdom_id FROM ' . DB_PREFIX . 'mundane
             WHERE mundane_id = ' . (int) $mundaneId . ' AND active = 1 AND is_legend = 0 LIMIT 1'
        );
        return ($rs && $rs->Next())
            ? ['MundaneId' => (int) $rs->mundane_id, 'Persona' => (string) $rs->persona, 'KingdomId' => (int) $rs->kingdom_id]
            : null;
    }

    /**
     * Move a row $from -> $to (guarded on the current stage so two writers cannot
     * both win) and write its history. Returns false if the row was not in $from.
     */
    private function setStage(int $candidateId, string $from, string $to, int $actor, string $comment, ?array $votes, string $now, bool $newSort = false): bool
    {
        $sort = '';
        if ($newSort) {
            $row = $this->loadRow($candidateId);
            if ($row !== null) {
                $sort = ', sort_order = ' . $this->nextSort($row['KingdomId'], $to);
            }
        }
        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'UPDATE ' . DB_PREFIX . "knight_candidate SET stage = '" . $this->esc($to) . "', stage_changed_at = '" . $now . "',
               stage_changed_by = " . (int) $actor . $sort . '
             WHERE candidate_id = ' . (int) $candidateId . " AND stage = '" . $this->esc($from) . "'"
        );
        if ($ok === false || $this->currentStage($candidateId) !== $to) {
            return false;
        }
        $this->history($candidateId, $from, $to, $actor, $comment, $votes, $now);
        return true;
    }

    private function setKnighted(int $candidateId, string $from, int $awardsId, string $comment, string $now): bool
    {
        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'UPDATE ' . DB_PREFIX . "knight_candidate SET stage = 'knighted', stage_changed_at = '" . $now . "', stage_changed_by = 0,
               knighted_awards_id = " . ($awardsId > 0 ? (int) $awardsId : 'NULL') . '
             WHERE candidate_id = ' . (int) $candidateId . " AND stage = '" . $this->esc($from) . "'"
        );
        if ($ok === false || $this->currentStage($candidateId) !== 'knighted') {
            return false;
        }
        $this->history($candidateId, $from, 'knighted', 0, $comment, null, $now);
        return true;
    }

    private function currentStage(int $candidateId): string
    {
        $this->db->Clear();
        $rs = $this->db->DataSet('SELECT stage FROM ' . DB_PREFIX . 'knight_candidate WHERE candidate_id = ' . (int) $candidateId . ' LIMIT 1');
        return ($rs && $rs->Next()) ? (string) $rs->stage : '';
    }

    private function history(int $candidateId, ?string $from, string $to, int $actor, string $comment, ?array $votes, string $now): void
    {
        $this->db->Clear();
        $this->db->ExecuteChecked(
            'INSERT INTO ' . DB_PREFIX . 'knight_candidate_history
               (candidate_id, from_stage, to_stage, actor_id, comment, votes_for, votes_against, votes_abstain, created_at)
             VALUES (' . (int) $candidateId . ', ' . ($from === null ? 'NULL' : "'" . $this->esc($from) . "'") . ", '" . $this->esc($to) . "', "
            . (int) $actor . ", '" . $this->esc(self::clipText($comment, self::COMMENT_MAX)) . "', "
            . ($votes === null ? 'NULL, NULL, NULL' : (int) $votes['For'] . ', ' . (int) $votes['Against'] . ', ' . (int) $votes['Abstain'])
            . ", '" . $now . "')"
        );
    }

    /** A new knighthood changes the roster: drop the cached roster for the player's home kingdom and the candidacy kingdoms. */
    private function bustHomeRoster(int $mundaneId, array $kingdomIds): void
    {
        $this->db->Clear();
        $rs = $this->db->DataSet('SELECT kingdom_id FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $mundaneId . ' LIMIT 1');
        if ($rs && $rs->Next()) {
            $kingdomIds[] = (int) $rs->kingdom_id;
        }
        foreach (array_unique(array_map('intval', $kingdomIds)) as $kid) {
            if ($kid > 0) {
                $this->circle()->bustRoster($kid);
            }
        }
    }

    private function audit(string $action, array $params, int $entityId, $prior, $post): void
    {
        if (!isset(Ork3::$Lib->dangeraudit)) {
            return;
        }
        try {
            Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::' . $action, $params, 'KnightCandidate', $entityId, $prior, $post);
        } catch (Throwable $e) {
            // The write already happened.
        }
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
