<?php

class QualTest
{
    private $db;

    public function __construct()
    {
        global $DB;
        $this->db = $DB;
    }

    // -----------------------------------------------------------------------
    // Auth
    // -----------------------------------------------------------------------

    /**
     * Returns true if $uid may manage qualification tests for this kingdom.
     * Grants access to: kingdom editors and officers with role Monarch/Regent/Prime Minister.
     */
    public function canManage($uid, $kingdom_id)
    {
        if ($uid <= 0 || !valid_id($kingdom_id)) {
            return false;
        }

        if (Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT)) {
            return true;
        }

        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . 'officer
             WHERE mundane_id = ' . (int)$uid . '
               AND kingdom_id = ' . (int)$kingdom_id . '
               AND park_id = 0
               AND role IN (\'Monarch\',\'Regent\',\'Prime Minister\')
             LIMIT 1'
        );
        if ($r && $r->Next()) {
            return true;
        }

        // Test manager list
        $this->db->Clear();
        $m = $this->db->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . 'qual_manager
             WHERE kingdom_id = ' . (int)$kingdom_id . '
               AND mundane_id = ' . (int)$uid . '
             LIMIT 1'
        );
        if ($m && $m->Next()) {
            return true;
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Managers
    // -----------------------------------------------------------------------

    /**
     * Return all test managers for a kingdom.
     * Returns array of ['MundaneId' => int, 'Name' => string, 'AddedAt' => string]
     */
    public function getManagers($kingdom_id)
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT qm.qual_manager_id, qm.mundane_id, qm.added_at,
                    m.name
             FROM ' . DB_PREFIX . 'qual_manager qm
             LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = qm.mundane_id
             WHERE qm.kingdom_id = ' . (int)$kingdom_id . '
             ORDER BY m.name ASC'
        );
        $list = [];
        if ($rs) {
            while ($rs->Next()) {
                $list[] = [
                    'QualManagerId' => (int)$rs->qual_manager_id,
                    'MundaneId'     => (int)$rs->mundane_id,
                    'Name'          => $rs->name ?? '',
                    'AddedAt'       => $rs->added_at,
                ];
            }
        }
        return $list;
    }

    /**
     * Return a kingdom's display name, or '' if not found.
     */
    public function getKingdomName($kingdom_id)
    {
        $kingdom_id = (int)$kingdom_id;
        if (!$kingdom_id) {
            return '';
        }
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT name FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ' . $kingdom_id . ' LIMIT 1'
        );
        if ($r && $r->Next()) {
            return $r->name;
        }
        return '';
    }

    /**
     * Return a mundane's name, or null if not found (so callers can distinguish
     * "not found" from "found").
     */
    public function getMundaneName($mundane_id)
    {
        $mundane_id = (int)$mundane_id;
        if (!$mundane_id) {
            return null;
        }
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT name FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $mundane_id . ' LIMIT 1'
        );
        if ($r && $r->Next()) {
            return $r->name;
        }
        return null;
    }

    /**
     * Add a test manager for a kingdom. Silently ignores duplicates.
     */
    public function addManager($kingdom_id, $mundane_id)
    {
        $kingdom_id = (int)$kingdom_id;
        $mundane_id = (int)$mundane_id;
        if (!$kingdom_id || !$mundane_id) {
            return false;
        }
        $this->db->Clear();
        $this->db->Execute(
            'INSERT IGNORE INTO ' . DB_PREFIX . 'qual_manager
             (kingdom_id, mundane_id)
             VALUES (' . $kingdom_id . ', ' . $mundane_id . ')'
        );
        return true;
    }

    /**
     * Remove a test manager from a kingdom.
     */
    public function removeManager($kingdom_id, $mundane_id)
    {
        $kingdom_id = (int)$kingdom_id;
        $mundane_id = (int)$mundane_id;
        $this->db->Clear();
        $this->db->Execute(
            'DELETE FROM ' . DB_PREFIX . 'qual_manager
             WHERE kingdom_id = ' . $kingdom_id . '
               AND mundane_id = ' . $mundane_id
        );
        return true;
    }

    // -----------------------------------------------------------------------
    // Config
    // -----------------------------------------------------------------------

    /**
     * Fetch test config for a kingdom+type. Returns defaults if not set.
     */
    public function getConfig($kingdom_id, $test_type)
    {
        $test_type = $this->sanitizeType($test_type);
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT * FROM ' . DB_PREFIX . 'qual_config
             WHERE kingdom_id = ' . (int)$kingdom_id . '
               AND test_type = \'' . $test_type . '\'
             LIMIT 1'
        );
        if ($rs && $rs->Next()) {
            return [
                'QualConfigId'  => (int)$rs->qual_config_id,
                'KingdomId'     => (int)$rs->kingdom_id,
                'TestType'      => $rs->test_type,
                'QuestionCount' => (int)$rs->question_count,
                'PassPercent'   => (int)$rs->pass_percent,
                'ValidDays'     => (int)$rs->valid_days,
                'ValidUntil'    => $rs->valid_until ?? null,
                'MaxRetakes'    => (int)$rs->max_retakes,
                'ShareQuestions' => (int)$rs->share_questions,
                'Instructions'  => $rs->instructions ?? null,
                'RulesVersion'  => $rs->rules_version ?? '',
                'ShowCorrectOnIncorrect' => (int)$rs->show_correct_on_incorrect,
            ];
        }
        return [
            'QualConfigId'  => 0,
            'KingdomId'     => (int)$kingdom_id,
            'TestType'      => $test_type,
            'QuestionCount' => 10,
            'PassPercent'   => 70,
            'ValidDays'     => 365,
            'ValidUntil'    => null,
            'MaxRetakes'    => 0,
            // Default the Reeve's-test sharing opt-in to yes (checkbox pre-checked for
            // unconfigured kingdoms). Sharing only applies to reeve; corpora stays 0.
            'ShareQuestions' => ($test_type === 'reeve') ? 1 : 0,
            'Instructions'  => null,
            'RulesVersion'  => '',
            'ShowCorrectOnIncorrect' => 0,
        ];
    }

    /**
     * Upsert test config for a kingdom+type.
     */
    public function saveConfig($kingdom_id, $test_type, $question_count, $pass_percent, $valid_days, $valid_until = null, $max_retakes = 0, $share_questions = 0, $instructions = null, $rules_version = null, $show_correct_on_incorrect = 0)
    {
        $test_type      = $this->sanitizeType($test_type);
        $kingdom_id     = (int)$kingdom_id;
        $question_count = max(1, (int)$question_count);
        $pass_percent   = min(100, max(1, (int)$pass_percent));

        // Exactly one expiry mode active: valid_until takes precedence; clears valid_days when set
        $safe_until = null;
        if ($valid_until && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid_until)) {
            $safe_until = $valid_until;
            $valid_days = 365; // stored but ignored when valid_until is set
        } else {
            $valid_days = max(1, (int)$valid_days);
        }
        $until_sql       = $safe_until ? '\'' . $safe_until . '\'' : 'NULL';
        $max_retakes     = max(0, (int)$max_retakes);
        $share_questions = ($test_type === 'reeve' && $share_questions) ? 1 : 0;
        $instructions_sql = ($instructions !== null && trim($instructions) !== '')
            ? "'" . $this->esc(trim($instructions)) . "'"
            : 'NULL';

        // Version attribution ("Based on ...") applies to any test type: Reeve stores
        // just the Rules of Play version number, Corpora stores the full document name
        // and version. Persist whatever was entered for either.
        $rv = ($rules_version !== null && trim($rules_version) !== '')
            ? trim($rules_version)
            : '';
        $rv_sql = ($rv !== '') ? "'" . $this->esc($rv) . "'" : 'NULL';
        $show_correct = $show_correct_on_incorrect ? 1 : 0;

        // Atomic upsert on the unique (kingdom_id, test_type) key — one round-trip,
        // no SELECT-then-write race.
        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'qual_config
             (kingdom_id, test_type, question_count, pass_percent, valid_days, valid_until, max_retakes, share_questions, instructions, rules_version, show_correct_on_incorrect)
             VALUES (' . $kingdom_id . ', \'' . $test_type . '\', ' . $question_count . ', ' . $pass_percent . ', ' . $valid_days . ', ' . $until_sql . ', ' . $max_retakes . ', ' . $share_questions . ', ' . $instructions_sql . ', ' . $rv_sql . ', ' . $show_correct . ')
             ON DUPLICATE KEY UPDATE
               question_count = VALUES(question_count),
               pass_percent   = VALUES(pass_percent),
               valid_days     = VALUES(valid_days),
               valid_until    = VALUES(valid_until),
               max_retakes    = VALUES(max_retakes),
               share_questions= VALUES(share_questions),
               instructions   = VALUES(instructions),
               rules_version  = VALUES(rules_version),
               show_correct_on_incorrect = VALUES(show_correct_on_incorrect)'
        );
        return true;
    }

    // -----------------------------------------------------------------------
    // Questions (admin)
    // -----------------------------------------------------------------------

    /**
     * All questions for a kingdom+type (admin listing, all statuses).
     */
    public function getAllQuestions($kingdom_id, $test_type)
    {
        $test_type = $this->sanitizeType($test_type);
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT q.qual_question_id, q.question_text, q.answer_mode, q.status, q.created_at,
                    COUNT(a.qual_answer_id) AS answer_count,
                    SUM(a.is_correct) AS correct_count,
                    (SELECT ac.answer_text FROM ' . DB_PREFIX . 'qual_answer ac
                     WHERE ac.qual_question_id = q.qual_question_id AND ac.is_correct = 1 LIMIT 1) AS correct_text,
                    COALESCE(s.times_answered, 0) AS times_answered,
                    COALESCE(s.times_correct,  0) AS times_correct,
                    (SELECT COUNT(*) FROM ' . DB_PREFIX . 'qual_report r
                     WHERE r.qual_question_id = q.qual_question_id) AS report_count
             FROM ' . DB_PREFIX . 'qual_question q
             LEFT JOIN ' . DB_PREFIX . 'qual_answer a  ON a.qual_question_id = q.qual_question_id
             LEFT JOIN ' . DB_PREFIX . 'qual_question_stat s ON s.qual_question_id = q.qual_question_id
             WHERE q.kingdom_id = ' . (int)$kingdom_id . '
               AND q.test_type = \'' . $test_type . '\'
             GROUP BY q.qual_question_id
             ORDER BY q.status ASC, q.qual_question_id DESC'
        );
        $list = [];
        if ($rs) {
            while ($rs->Next()) {
                $list[] = [
                    'QualQuestionId' => (int)$rs->qual_question_id,
                    'QuestionText'   => $rs->question_text,
                    'AnswerMode'     => $rs->answer_mode,
                    'Status'         => $rs->status,
                    'CreatedAt'      => $rs->created_at,
                    'AnswerCount'    => (int)$rs->answer_count,
                    'CorrectCount'   => (int)$rs->correct_count,
                    'CorrectText'    => $rs->correct_text ?? '',
                    'TimesAnswered'  => (int)$rs->times_answered,
                    'TimesCorrect'   => (int)$rs->times_correct,
                    'ReportCount'    => (int)$rs->report_count,
                ];
            }
        }
        return $list;
    }

    /**
     * Single question + answers (for admin edit form).
     */
    public function getQuestion($question_id)
    {
        $question_id = (int)$question_id;
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT * FROM ' . DB_PREFIX . 'qual_question
             WHERE qual_question_id = ' . $question_id . ' LIMIT 1'
        );
        if (!$rs || !$rs->Next()) {
            return null;
        }

        $q = [
            'QualQuestionId' => (int)$rs->qual_question_id,
            'KingdomId'      => (int)$rs->kingdom_id,
            'TestType'       => $rs->test_type,
            'QuestionText'   => $rs->question_text,
            'AnswerMode'     => $rs->answer_mode,
            'Status'         => $rs->status,
            'Answers'        => [],
        ];

        $this->db->Clear();
        $ars = $this->db->DataSet(
            'SELECT * FROM ' . DB_PREFIX . 'qual_answer
             WHERE qual_question_id = ' . $question_id . '
             ORDER BY qual_answer_id'
        );
        if ($ars) {
            while ($ars->Next()) {
                $q['Answers'][] = [
                    'QualAnswerId'   => (int)$ars->qual_answer_id,
                    'AnswerText'     => $ars->answer_text,
                    'IsCorrect'      => (bool)(int)$ars->is_correct,
                ];
            }
        }
        return $q;
    }

    /**
     * Create or update a question and its answers.
     * $data: ['KingdomId', 'TestType', 'QuestionText', 'Answers' => [['AnswerText', 'IsCorrect'], ...]]
     * $question_id: 0 for new, >0 to update.
     */
    public function saveQuestion($question_id, $data)
    {
        $question_id   = (int)$question_id;
        $kingdom_id    = (int)($data['KingdomId'] ?? 0);
        $test_type     = $this->sanitizeType($data['TestType'] ?? '');
        $question_text = trim($data['QuestionText'] ?? '');
        $answers       = is_array($data['Answers']) ? $data['Answers'] : [];
        // 'multi' = "select all that apply" (score all-or-nothing).
        // Default 'single' so callers that predate the multi-correct
        // feature keep working unchanged.
        $answer_mode   = (($data['AnswerMode'] ?? 'single') === 'multi') ? 'multi' : 'single';

        if (!$question_text || !$test_type || !valid_id($kingdom_id)) {
            return 0;
        }
        if (count($answers) < 2) {
            return 0;
        }

        $correct_count = 0;
        foreach ($answers as $a) {
            if (!empty($a['IsCorrect'])) {
                $correct_count++;
            }
        }
        if ($correct_count < 1) {
            return 0;
        }
        // Single-mode is an equality check downstream — refuse to persist a
        // question that would silently drop one of its correct answers.
        if ($answer_mode === 'single' && $correct_count > 1) {
            return 0;
        }

        // The ENTIRE write is atomic: for a new question its INSERT, and for any
        // question the answer DELETE + single multi-row INSERT, all commit together.
        // A failure (or a fatal/timeout) rolls back the question row too, so a
        // half-written question with no answers can never persist and pollute the
        // active pool.
        $this->db->Clear();
        $this->db->Execute('START TRANSACTION');

        if ($question_id > 0) {
            $this->db->Clear();
            $this->db->Execute(
                'UPDATE ' . DB_PREFIX . 'qual_question
                 SET question_text = \'' . $this->esc($question_text) . '\',
                     answer_mode  = \'' . $answer_mode . '\'
                 WHERE qual_question_id = ' . $question_id
            );
        } else {
            $created_by = (int)($data['CreatedBy'] ?? 0);
            $this->db->Clear();
            $this->db->Execute(
                'INSERT INTO ' . DB_PREFIX . 'qual_question
                 (kingdom_id, test_type, question_text, answer_mode, status, created_by)
                 VALUES (' . $kingdom_id . ', \'' . $test_type . '\', \'' . $this->esc($question_text) . '\', \'' . $answer_mode . '\', \'active\', ' . $created_by . ')'
            );
            $this->db->Clear();
            $ir = $this->db->DataSet('SELECT LAST_INSERT_ID() AS new_id');
            if ($ir && $ir->Next()) {
                $question_id = (int)$ir->new_id;
            }
            if ($question_id <= 0) {
                $this->db->Clear();
                $this->db->Execute('ROLLBACK');
                return 0;
            }
        }

        $this->db->Clear();
        $this->db->Execute(
            'DELETE FROM ' . DB_PREFIX . 'qual_answer WHERE qual_question_id = ' . $question_id
        );

        // Build the answer rows from non-empty-text answers. A single multi-row
        // INSERT is statement-level all-or-nothing, so no per-row failure check is
        // needed (the Yapo Execute() can't signal one anyway).
        $rows            = [];
        $has_correct_row = false;
        foreach ($answers as $a) {
            $text = trim($a['AnswerText'] ?? '');
            if ($text === '') {
                continue;
            }
            $is_correct = empty($a['IsCorrect']) ? 0 : 1;
            if ($is_correct) {
                $has_correct_row = true;
            }
            $rows[] = '(' . $question_id . ', \'' . $this->esc($text) . '\', ' . $is_correct . ')';
        }

        // A valid question needs >= 2 non-empty answers AND a correct one among
        // them (guards against a correct answer whose text was left blank). If not,
        // abandon the whole write rather than persist a broken question.
        if (count($rows) < 2 || !$has_correct_row) {
            $this->db->Clear();
            $this->db->Execute('ROLLBACK');
            return 0;
        }

        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'qual_answer (qual_question_id, answer_text, is_correct)
             VALUES ' . implode(', ', $rows)
        );

        $this->db->Clear();
        $this->db->Execute('COMMIT');

        return $question_id;
    }

    /**
     * Set question status (active or archived).
     */
    public function setQuestionStatus($question_id, $status)
    {
        $status = ($status === 'archived') ? 'archived' : 'active';
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'qual_question
             SET status = \'' . $status . '\'
             WHERE qual_question_id = ' . (int)$question_id
        );
        return true;
    }

    // -----------------------------------------------------------------------
    // Questions (player — randomized, no correct flags)
    // -----------------------------------------------------------------------

    /**
     * Get $limit random active questions with shuffled answers (no is_correct flag).
     * Returns null if not enough active questions exist.
     */
    public function getQuestionsForTest($kingdom_id, $test_type, $limit)
    {
        return $this->_loadQuestionsAndAnswers($kingdom_id, $test_type, $limit, false);
    }

    /**
     * Shared loader for the player test path and the admin preview path.
     * Returns $limit random active questions with shuffled answers, or null if
     * not enough active questions exist. When $includeCorrect is true the answer
     * rows carry the is_correct flag (admin preview); otherwise it is stripped
     * (player payload).
     */
    private function _loadQuestionsAndAnswers($kingdom_id, $test_type, $limit, $includeCorrect = false)
    {
        $test_type = $this->sanitizeType($test_type);
        $limit     = max(1, (int)$limit);

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT qual_question_id, question_text, answer_mode
             FROM ' . DB_PREFIX . 'qual_question
             WHERE kingdom_id = ' . (int)$kingdom_id . '
               AND test_type = \'' . $test_type . '\'
               AND status = \'active\'
             ORDER BY RAND()
             LIMIT ' . $limit
        );

        $questions = [];
        $qids      = [];
        if ($rs) {
            while ($rs->Next()) {
                $qid = (int)$rs->qual_question_id;
                $qids[] = $qid;
                $questions[$qid] = [
                    'QualQuestionId' => $qid,
                    'QuestionText'   => $rs->question_text,
                    'AnswerMode'     => $rs->answer_mode,
                    'Answers'        => [],
                ];
            }
        }

        if (count($questions) < $limit) {
            return null;
        }

        // Always fetch is_correct internally so we can guarantee every served
        // question has >= 2 answers and at least one correct option; the flag is
        // only EXPOSED to the caller on the admin preview path ($includeCorrect).
        $ids_str = implode(',', $qids);
        $this->db->Clear();
        $ars = $this->db->DataSet(
            'SELECT qual_answer_id, qual_question_id, answer_text, is_correct
             FROM ' . DB_PREFIX . 'qual_answer
             WHERE qual_question_id IN (' . $ids_str . ')
             ORDER BY RAND()'
        );
        $has_correct = [];
        if ($ars) {
            while ($ars->Next()) {
                $qid = (int)$ars->qual_question_id;
                if (isset($questions[$qid])) {
                    $answer = [
                        'QualAnswerId' => (int)$ars->qual_answer_id,
                        'AnswerText'   => $ars->answer_text,
                    ];
                    if ((int)$ars->is_correct === 1) {
                        $has_correct[$qid] = true;
                    }
                    if ($includeCorrect) {
                        $answer['IsCorrect'] = (bool)(int)$ars->is_correct;
                    }
                    $questions[$qid]['Answers'][] = $answer;
                }
            }
        }

        // Drop any question missing answers or a correct option — a corrupted
        // question must never reach a live test (it would render with no choices
        // and soft-lock the quiz, and checkanswer/scoring would have no key).
        foreach ($questions as $qid => $q) {
            if (count($q['Answers']) < 2 || empty($has_correct[$qid])) {
                unset($questions[$qid]);
            }
        }

        // If pruning dropped us below the requested count, treat it as
        // "not enough valid questions" rather than serving a short/broken test.
        if (count($questions) < $limit) {
            return null;
        }

        return array_values($questions);
    }

    /**
     * Get correct-answer info per question for server-side scoring.
     * Returns array: [question_id => ['Mode' => 'single'|'multi', 'AnswerIds' => [int,...]]]
     *
     * Multi-correct questions carry every is_correct=1 row; single-correct
     * questions carry the single row (also as a one-element array so the
     * scoring code doesn't need two branches).
     */
    public function getCorrectAnswers($question_ids, $kingdom_id = 0, $test_type = '')
    {
        if (empty($question_ids)) {
            return [];
        }
        $ids_str    = implode(',', array_map('intval', $question_ids));
        $test_type  = $this->sanitizeType($test_type);
        // JOIN through qual_question to verify kingdom + type ownership AND
        // pull the mode so the caller knows whether to score by equality
        // (single) or set-match (multi).
        $where_kq = $kingdom_id > 0
            ? 'AND q.kingdom_id = ' . (int)$kingdom_id . ' AND q.test_type = \'' . $test_type . '\''
            : '';
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT a.qual_question_id, a.qual_answer_id, q.answer_mode
             FROM ' . DB_PREFIX . 'qual_answer a
             JOIN ' . DB_PREFIX . 'qual_question q ON q.qual_question_id = a.qual_question_id
             WHERE a.qual_question_id IN (' . $ids_str . ')
               AND a.is_correct = 1
               ' . $where_kq
        );
        $map = [];
        if ($rs) {
            while ($rs->Next()) {
                $qid = (int)$rs->qual_question_id;
                if (!isset($map[$qid])) {
                    $map[$qid] = ['Mode' => $rs->answer_mode, 'AnswerIds' => []];
                }
                $map[$qid]['AnswerIds'][] = (int)$rs->qual_answer_id;
            }
        }
        return $map;
    }

    /**
     * Score a submitted test.
     * $correct_map: [question_id => ['Mode' => 'single'|'multi', 'AnswerIds' => [int,...]]]
     * $submitted:   [question_id => int | int[]] — scalar for single, list for multi
     * Returns ['score_percent' => int, 'correct' => int, 'total' => int]
     *
     * Multi is all-or-nothing: the submitted set must exactly equal the
     * correct set (no missing, no extra).
     */
    public function scoreTest($correct_map, $submitted)
    {
        $total   = count($correct_map);
        $correct = 0;
        foreach ($correct_map as $qid => $info) {
            if ($this->_scoreOne($info, $submitted[$qid] ?? null)) {
                $correct++;
            }
        }
        $percent = $total > 0 ? (int)round(($correct / $total) * 100) : 0;
        return ['score_percent' => $percent, 'correct' => $correct, 'total' => $total];
    }

    /**
     * Predicate: did the player answer this ONE question correctly?
     * $info:      ['Mode' => 'single'|'multi', 'AnswerIds' => [int,...]]
     * $given_raw: int | int[] | null (scalar for single; list for multi)
     *
     * Kept private so scoreTest() and recordQuestionStats() share the same
     * definition of "correct" — a per-question stat that disagrees with the
     * aggregate would rot admin question-quality reports.
     */
    private function _scoreOne($info, $given_raw)
    {
        $mode        = $info['Mode'] ?? 'single';
        $correct_ids = $info['AnswerIds'] ?? [];
        if ($mode === 'multi') {
            $given = is_array($given_raw) ? array_values(array_unique(array_map('intval', $given_raw))) : [];
            sort($given);
            $want = array_values(array_unique(array_map('intval', $correct_ids)));
            sort($want);
            return !empty($want) && $given === $want;
        }
        $given_id = is_array($given_raw) ? (int)($given_raw[0] ?? 0) : (int)$given_raw;
        $want_id  = (int)($correct_ids[0] ?? 0);
        return $given_id > 0 && $given_id === $want_id;
    }

    /**
     * Record per-question answer stats (called after every test submission).
     * $correct_map: new shape from getCorrectAnswers() —
     *               [question_id => ['Mode' => 'single'|'multi', 'AnswerIds' => [int,...]]]
     * $submitted:   [question_id => int | int[]]
     *
     * Correctness is determined by re-running the same predicate scoreTest()
     * uses, so a multi-correct question only counts as "correct" when the
     * submitted set exactly matches the correct set.
     */
    public function recordQuestionStats($correct_map, $submitted)
    {
        $rows = [];
        foreach ($correct_map as $qid => $info) {
            $qid         = (int)$qid;
            $was_correct = $this->_scoreOne($info, $submitted[$qid] ?? null) ? 1 : 0;
            $rows[] = '(' . $qid . ', 1, ' . $was_correct . ')';
        }
        if (empty($rows)) {
            return;
        }

        // Single multi-row upsert instead of one round-trip per question.
        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'qual_question_stat
             (qual_question_id, times_answered, times_correct)
             VALUES ' . implode(', ', $rows) . '
             ON DUPLICATE KEY UPDATE
               times_answered = times_answered + VALUES(times_answered),
               times_correct  = times_correct  + VALUES(times_correct)'
        );
    }

    /**
     * Record (or update) a player's passing result.
     */
    public function recordResult($player_id, $kingdom_id, $test_type, $score_percent, $valid_days, $valid_until = null)
    {
        $player_id     = (int)$player_id;
        $kingdom_id    = (int)$kingdom_id;
        $test_type     = $this->sanitizeType($test_type);
        $score_percent = (int)$score_percent;

        // Compute the expiry IN SQL so the stored value shares MySQL's clock with
        // the NOW() it is later compared against (avoids PHP-host vs DB-host skew).
        // valid_until (a fixed date) takes precedence; otherwise roll forward from valid_days.
        if ($valid_until && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid_until)) {
            $expires_sql = '\'' . $valid_until . ' 23:59:59\'';
        } else {
            $valid_days  = max(1, (int)$valid_days);
            $expires_sql = 'DATE_ADD(NOW(), INTERVAL ' . $valid_days . ' DAY)';
        }

        // Atomic upsert on the unique (player_id, kingdom_id, test_type) key — no
        // SELECT-then-write race, and a duplicate submission cannot create a second row.
        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'qual_result
             (player_id, kingdom_id, test_type, score_percent, passed_at, expires_at)
             VALUES (' . $player_id . ', ' . $kingdom_id . ', \'' . $test_type . '\', ' . $score_percent . ', NOW(), ' . $expires_sql . ')
             ON DUPLICATE KEY UPDATE
               score_percent = VALUES(score_percent),
               passed_at     = NOW(),
               expires_at    = VALUES(expires_at)'
        );

        // Read the stored expiry back so the caller (syncMundaneQual + the AJAX
        // response) reports exactly what the DB persisted.
        $this->db->Clear();
        $er = $this->db->DataSet(
            'SELECT expires_at FROM ' . DB_PREFIX . 'qual_result
             WHERE player_id = ' . $player_id . '
               AND kingdom_id = ' . $kingdom_id . '
               AND test_type = \'' . $test_type . '\'
             LIMIT 1'
        );
        return ($er && $er->Next()) ? $er->expires_at : null;
    }

    /**
     * Reset the success-rate counters for a single question.
     */
    public function resetQuestionStats($question_id)
    {
        $this->db->Clear();
        $this->db->Execute(
            'DELETE FROM ' . DB_PREFIX . 'qual_question_stat WHERE qual_question_id = ' . (int)$question_id
        );
        return true;
    }

    /**
     * Write qualification outcome back to ork_mundane so the player sidebar card stays current.
     * Called ONLY on a passing submission — it sets the qualified flag and the
     * expiry date. Stale/expired qualifications are determined at read time by
     * comparing expires_at to NOW() (see getPlayerResults), not by clearing this
     * flag, so there is intentionally no un-qualify path here.
     */
    public function syncMundaneQual($player_id, $test_type, $expires_date)
    {
        $player_id = (int)$player_id;
        $test_type = $this->sanitizeType($test_type);
        if ($test_type === 'reeve') {
            $col_flag  = 'reeve_qualified';
            $col_until = 'reeve_qualified_until';
        } else {
            $col_flag  = 'corpora_qualified';
            $col_until = 'corpora_qualified_until';
        }
        $safe_date = $expires_date ? '\'' . date('Y-m-d', strtotime($expires_date)) . '\'' : 'NULL';
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'mundane
             SET ' . $col_flag  . ' = 1,
                 ' . $col_until . ' = ' . $safe_date . '
             WHERE mundane_id = ' . $player_id
        );
    }

    /**
     * Get all test results for a player in a kingdom.
     * Returns array keyed by test_type.
     */
    public function getPlayerResults($player_id, $kingdom_id)
    {
        $player_id  = (int)$player_id;
        $kingdom_id = (int)$kingdom_id;
        // Compute expiry authoritatively in SQL (NOW() shares the DB session TZ
        // with the stored expires_at) to avoid PHP-local-vs-time() skew.
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT qual_result_id, test_type, score_percent, passed_at, expires_at,
                    (expires_at <= NOW()) AS is_expired
             FROM ' . DB_PREFIX . 'qual_result
             WHERE player_id = ' . $player_id . '
               AND kingdom_id = ' . $kingdom_id
        );
        $results = [];
        if ($rs) {
            while ($rs->Next()) {
                $results[$rs->test_type] = [
                    'QualResultId' => (int)$rs->qual_result_id,
                    'TestType'     => $rs->test_type,
                    'ScorePercent' => (int)$rs->score_percent,
                    'PassedAt'     => $rs->passed_at,
                    'ExpiresAt'    => $rs->expires_at,
                    'Expired'      => (bool)(int)$rs->is_expired,
                    'RetakeCount'  => 0,
                ];
            }
        }
        // Merge in retake counts
        $this->db->Clear();
        $rr = $this->db->DataSet(
            'SELECT test_type, retake_count FROM ' . DB_PREFIX . 'qual_retake
             WHERE player_id = ' . $player_id . '
               AND kingdom_id = ' . $kingdom_id
        );
        if ($rr) {
            while ($rr->Next()) {
                $tt = $rr->test_type;
                if (isset($results[$tt])) {
                    $results[$tt]['RetakeCount'] = (int)$rr->retake_count;
                } else {
                    $results[$tt] = [
                        'QualResultId' => 0,
                        'TestType'     => $tt,
                        'ScorePercent' => 0,
                        'PassedAt'     => null,
                        'ExpiresAt'    => null,
                        'Expired'      => true,
                        'RetakeCount'  => (int)$rr->retake_count,
                    ];
                }
            }
        }
        return $results;
    }

    /**
     * Count active questions for a kingdom+type.
     */
    public function countActiveQuestions($kingdom_id, $test_type)
    {
        $test_type = $this->sanitizeType($test_type);
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT COUNT(*) AS cnt FROM ' . DB_PREFIX . 'qual_question
             WHERE kingdom_id = ' . (int)$kingdom_id . '
               AND test_type = \'' . $test_type . '\'
               AND status = \'active\''
        );
        if ($rs && $rs->Next()) {
            return (int)$rs->cnt;
        }
        return 0;
    }

    // -----------------------------------------------------------------------
    // Global Question Library
    // -----------------------------------------------------------------------

    /**
     * Return all active reeve questions from kingdoms that have opted in,
     * excluding the given kingdom's own questions.
     * Returns array of questions each with KingdomName + Answers. The correct-answer
     * flag is deliberately NOT selected or returned — the shared library must never
     * leak another kingdom's answer key to opted-in admins browsing it.
     */
    public function getLibraryQuestions($excluding_kingdom_id)
    {
        $excluding_kingdom_id = (int)$excluding_kingdom_id;

        // Load the destination kingdom's active question texts once so we can
        // dedup in PHP, avoiding a per-row correlated subquery against the
        // un-indexable TEXT column.
        $own_texts = [];
        $this->db->Clear();
        $ors = $this->db->DataSet(
            'SELECT question_text FROM ' . DB_PREFIX . 'qual_question
             WHERE kingdom_id = ' . $excluding_kingdom_id . '
               AND test_type = \'reeve\'
               AND status = \'active\''
        );
        if ($ors) {
            while ($ors->Next()) {
                $own_texts[$ors->question_text] = true;
            }
        }

        // report_count is index-backed (qual_report.idx_question). We sort by it
        // ascending so the most-reported ("offending") questions sink to the bottom
        // and admins browse the cleanest questions first; ties fall back to the
        // natural kingdom/id ordering. Because ORDER BY runs before LIMIT, the most-
        // reported questions are also the first dropped when the shared pool exceeds
        // the 500-row cap.
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT q.qual_question_id, q.question_text, q.kingdom_id,
                    k.name AS kingdom_name,
                    (SELECT COUNT(*) FROM ' . DB_PREFIX . 'qual_report r
                     WHERE r.qual_question_id = q.qual_question_id) AS report_count
             FROM ' . DB_PREFIX . 'qual_question q
             JOIN ' . DB_PREFIX . 'qual_config c
               ON c.kingdom_id = q.kingdom_id AND c.test_type = \'reeve\' AND c.share_questions = 1
             JOIN ' . DB_PREFIX . 'kingdom k ON k.kingdom_id = q.kingdom_id
             WHERE q.kingdom_id != ' . $excluding_kingdom_id . '
               AND q.test_type = \'reeve\'
               AND q.status = \'active\'
             ORDER BY report_count ASC, k.name ASC, q.qual_question_id ASC
             LIMIT 500'
        );
        $questions = [];
        $qids      = [];
        if ($rs) {
            while ($rs->Next()) {
                // Drop library rows whose text already exists in the destination.
                if (isset($own_texts[$rs->question_text])) {
                    continue;
                }
                $qid = (int)$rs->qual_question_id;
                $qids[] = $qid;
                $questions[$qid] = [
                    'QualQuestionId' => $qid,
                    'QuestionText'   => $rs->question_text,
                    'KingdomId'      => (int)$rs->kingdom_id,
                    'KingdomName'    => $rs->kingdom_name,
                    'ReportCount'    => (int)$rs->report_count,
                    'Answers'        => [],
                ];
            }
        }
        if (!empty($qids)) {
            $ids_str = implode(',', $qids);
            $this->db->Clear();
            $ars = $this->db->DataSet(
                'SELECT qual_question_id, answer_text
                 FROM ' . DB_PREFIX . 'qual_answer
                 WHERE qual_question_id IN (' . $ids_str . ')
                 ORDER BY qual_answer_id'
            );
            if ($ars) {
                while ($ars->Next()) {
                    $qid = (int)$ars->qual_question_id;
                    if (isset($questions[$qid])) {
                        $questions[$qid]['Answers'][] = [
                            'AnswerText' => $ars->answer_text,
                        ];
                    }
                }
            }
        }
        return array_values($questions);
    }

    /**
     * Copy a question (and its answers) from another kingdom into $dest_kingdom_id.
     * Returns the new question_id, or 0 on failure.
     */
    public function copyQuestionToKingdom($source_question_id, $dest_kingdom_id, $created_by = 0)
    {
        $source_question_id = (int)$source_question_id;
        $dest_kingdom_id    = (int)$dest_kingdom_id;

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT q.question_text, a_list.answer_text, a_list.is_correct
             FROM ' . DB_PREFIX . 'qual_question q
             JOIN ' . DB_PREFIX . 'qual_answer a_list ON a_list.qual_question_id = q.qual_question_id
             WHERE q.qual_question_id = ' . $source_question_id . '
               AND q.test_type = \'reeve\'
             ORDER BY a_list.qual_answer_id'
        );
        if (!$rs) {
            return 0;
        }

        $question_text = null;
        $answers       = [];
        while ($rs->Next()) {
            if ($question_text === null) {
                $question_text = $rs->question_text;
            }
            $answers[] = ['text' => $rs->answer_text, 'correct' => (int)$rs->is_correct];
        }
        if (!$question_text || count($answers) < 2) {
            return 0;
        }

        // Question + all answers commit together: a failed answer insert rolls back
        // the question too, so a copy can never leave an answerless orphan.
        $this->db->Clear();
        $this->db->Execute('START TRANSACTION');

        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'qual_question
             (kingdom_id, test_type, question_text, status, created_by)
             VALUES (' . $dest_kingdom_id . ', \'reeve\', \'' . $this->esc($question_text) . '\', \'active\', ' . (int)$created_by . ')'
        );
        $this->db->Clear();
        $ir = $this->db->DataSet('SELECT LAST_INSERT_ID() AS new_id');
        if (!$ir || !$ir->Next() || (int)$ir->new_id <= 0) {
            $this->db->Clear();
            $this->db->Execute('ROLLBACK');
            return 0;
        }
        $new_qid = (int)$ir->new_id;

        // Single multi-row insert for all answers.
        $rows = [];
        foreach ($answers as $a) {
            $rows[] = '(' . $new_qid . ', \'' . $this->esc($a['text']) . '\', ' . (int)$a['correct'] . ')';
        }
        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'qual_answer (qual_question_id, answer_text, is_correct)
             VALUES ' . implode(', ', $rows)
        );

        $this->db->Clear();
        $this->db->Execute('COMMIT');
        return $new_qid;
    }

    // -----------------------------------------------------------------------
    // Retakes
    // -----------------------------------------------------------------------

    /**
     * Increment retake counter for a player (called on every submission).
     */
    public function incrementRetakeCount($player_id, $kingdom_id, $test_type)
    {
        $test_type = $this->sanitizeType($test_type);
        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'qual_retake (player_id, kingdom_id, test_type, retake_count)'
            . ' VALUES (' . (int)$player_id . ', ' . (int)$kingdom_id . ', \'' . $test_type . '\', 1)'
            . ' ON DUPLICATE KEY UPDATE retake_count = retake_count + 1'
        );
    }

    /**
     * Atomically consume one retake/attempt slot for a player+kingdom+type.
     * Returns true if a slot was consumed (player was under the cap, or the cap is
     * unlimited when $max_retakes <= 0), false if the player is already at the cap.
     *
     * Race safety: the DB-side IF(...) guarantees retake_count can never be pushed
     * past $max_retakes even under concurrent submissions, so a player can never
     * accumulate attempts beyond the cap. (The Yapo DB layer does not expose
     * affected-row counts, so the at-cap case is rejected by a cheap pre-read; the
     * authoritative cap is enforced atomically in SQL.)
     */
    public function tryConsumeRetake($player_id, $kingdom_id, $test_type, $max_retakes)
    {
        $player_id   = (int)$player_id;
        $kingdom_id  = (int)$kingdom_id;
        $test_type   = $this->sanitizeType($test_type);
        $max_retakes = (int)$max_retakes;

        // Unlimited: just bump the counter and allow.
        if ($max_retakes <= 0) {
            $this->incrementRetakeCount($player_id, $kingdom_id, $test_type);
            return true;
        }

        // Already at the cap: reject without writing.
        if ($this->getRetakeCount($player_id, $kingdom_id, $test_type) >= $max_retakes) {
            return false;
        }

        // Under the cap: atomic, cap-enforcing increment (never exceeds $max_retakes).
        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'qual_retake (player_id, kingdom_id, test_type, retake_count)'
            . ' VALUES (' . $player_id . ', ' . $kingdom_id . ', \'' . $test_type . '\', 1)'
            . ' ON DUPLICATE KEY UPDATE retake_count = IF(retake_count < ' . $max_retakes . ', retake_count + 1, retake_count)'
        );
        return true;
    }

    /**
     * Get retake count for a single player+kingdom+type.
     */
    public function getRetakeCount($player_id, $kingdom_id, $test_type)
    {
        $test_type = $this->sanitizeType($test_type);
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT retake_count FROM ' . DB_PREFIX . 'qual_retake'
            . ' WHERE player_id = ' . (int)$player_id
            . ' AND kingdom_id = ' . (int)$kingdom_id
            . ' AND test_type = \'' . $test_type . '\''
            . ' LIMIT 1'
        );
        if ($rs && $rs->Next()) {
            return (int)$rs->retake_count;
        }
        return 0;
    }

    /**
     * Reset retake counter for a single player.
     */
    public function resetPlayerRetakes($player_id, $kingdom_id, $test_type)
    {
        $test_type = $this->sanitizeType($test_type);
        $this->db->Clear();
        $this->db->Execute(
            'DELETE FROM ' . DB_PREFIX . 'qual_retake'
            . ' WHERE player_id = ' . (int)$player_id
            . ' AND kingdom_id = ' . (int)$kingdom_id
            . ' AND test_type = \'' . $test_type . '\''
        );
    }

    /**
     * Reset retake counters for all players in a kingdom+type.
     */
    public function resetAllRetakes($kingdom_id, $test_type)
    {
        $test_type = $this->sanitizeType($test_type);
        $this->db->Clear();
        $this->db->Execute(
            'DELETE FROM ' . DB_PREFIX . 'qual_retake'
            . ' WHERE kingdom_id = ' . (int)$kingdom_id
            . ' AND test_type = \'' . $test_type . '\''
        );
    }

    // -----------------------------------------------------------------------
    // Reports
    // -----------------------------------------------------------------------

    /**
     * Record a player's report against a question.
     * $reason: 'wording' | 'correct' | 'outdated' | 'other'
     */
    public function reportQuestion($question_id, $player_id, $reason)
    {
        $valid = ['wording', 'correct', 'outdated', 'other'];
        if (!in_array($reason, $valid, true)) {
            return false;
        }
        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'qual_report
             (qual_question_id, player_id, reason)
             VALUES (' . (int)$question_id . ', ' . (int)$player_id . ', \'' . $reason . '\')'
        );
        return true;
    }

    /**
     * Get report count breakdown for a single question.
     * Returns ['total' => int, 'wording' => int, 'correct' => int, 'outdated' => int, 'other' => int]
     */
    public function getReportCounts($question_id)
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT reason, COUNT(*) AS cnt
             FROM ' . DB_PREFIX . 'qual_report
             WHERE qual_question_id = ' . (int)$question_id . '
             GROUP BY reason'
        );
        $counts = ['total' => 0, 'wording' => 0, 'correct' => 0, 'outdated' => 0, 'other' => 0];
        if ($rs) {
            while ($rs->Next()) {
                $r = $rs->reason;
                if (isset($counts[$r])) {
                    $counts[$r]      = (int)$rs->cnt;
                    $counts['total'] += (int)$rs->cnt;
                }
            }
        }
        return $counts;
    }

    /**
     * Delete all reports for a question (call after archiving or editing to clear the flag).
     */
    public function clearReports($question_id)
    {
        $this->db->Clear();
        $this->db->Execute(
            'DELETE FROM ' . DB_PREFIX . 'qual_report WHERE qual_question_id = ' . (int)$question_id
        );
        return true;
    }

    // -----------------------------------------------------------------------
    // Kingdom Test Result Reports
    // -----------------------------------------------------------------------

    /**
     * Get all test completions for a kingdom+type, ordered most recent first.
     * Returns array of rows with: PassedAt, Persona, ParkName, ParkId, MundaneId, ScorePercent, ExpiresAt, PassPercent, FlagCount
     */
    public function getTestResults($kingdom_id, $test_type)
    {
        $kingdom_id = (int)$kingdom_id;
        $test_type  = $this->sanitizeType($test_type);

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT r.passed_at, r.score_percent, r.expires_at,
                    m.mundane_id, m.persona, m.park_id,
                    p.name AS park_name,
                    cfg.pass_percent
             FROM ' . DB_PREFIX . 'qual_result r
             JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = r.player_id
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = m.park_id
             LEFT JOIN ' . DB_PREFIX . 'qual_config cfg
                    ON cfg.kingdom_id = r.kingdom_id AND cfg.test_type = r.test_type
             WHERE r.kingdom_id = ' . $kingdom_id . '
               AND r.test_type = \'' . $test_type . '\'
             ORDER BY r.passed_at DESC
             LIMIT 2000'
        );

        $rows = [];
        if ($rs) {
            while ($rs->Next()) {
                $rows[] = [
                    'PassedAt'     => $rs->passed_at,
                    'MundaneId'    => (int)$rs->mundane_id,
                    'Persona'      => $rs->persona,
                    'ParkId'       => (int)$rs->park_id,
                    'ParkName'     => $rs->park_name ?: '',
                    'ScorePercent' => (int)$rs->score_percent,
                    'ExpiresAt'    => $rs->expires_at,
                    'PassPercent'  => (int)($rs->pass_percent ?: 70),
                ];
            }
        }
        return $rows;
    }

    /**
     * Get summary stats for a kingdom+type test report header.
     * Returns: ActiveQualified, ActivePlayers, PassRate6Mo, ActiveQuestions, FlaggedQuestions
     */
    public function getTestReportStats($kingdom_id, $test_type)
    {
        $kingdom_id = (int)$kingdom_id;
        $test_type  = $this->sanitizeType($test_type);
        $now = date('Y-m-d H:i:s');
        $six_months_ago = date('Y-m-d H:i:s', strtotime('-6 months'));

        // Active players: signed in within the past 6 months
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT COUNT(DISTINCT a.mundane_id) AS cnt
             FROM ' . DB_PREFIX . 'attendance a
             JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = a.mundane_id
             JOIN ' . DB_PREFIX . 'park pk ON pk.park_id = m.park_id
             WHERE pk.kingdom_id = ' . $kingdom_id . '
               AND a.date >= \'' . $six_months_ago . '\''
        );
        $activePlayers = ($rs && $rs->Next()) ? (int)$rs->cnt : 0;

        // Currently qualified (expires_at > now)
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT COUNT(*) AS cnt
             FROM ' . DB_PREFIX . 'qual_result r
             JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = r.player_id
             JOIN ' . DB_PREFIX . 'park pk ON pk.park_id = m.park_id
             WHERE r.kingdom_id = ' . $kingdom_id . '
               AND r.test_type = \'' . $test_type . '\'
               AND r.expires_at > \'' . $now . '\'
               AND pk.kingdom_id = ' . $kingdom_id
        );
        $activeQualified = ($rs && $rs->Next()) ? (int)$rs->cnt : 0;

        // Pass rate in past 6 months: count of results where score >= pass_percent / total results
        // We need the pass_percent from config
        $config = $this->getConfig($kingdom_id, $test_type);
        $passPercent = (int)$config['PassPercent'];

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN r.score_percent >= ' . $passPercent . ' THEN 1 ELSE 0 END) AS passed
             FROM ' . DB_PREFIX . 'qual_result r
             WHERE r.kingdom_id = ' . $kingdom_id . '
               AND r.test_type = \'' . $test_type . '\'
               AND r.passed_at >= \'' . $six_months_ago . '\''
        );
        $totalAttempts = 0;
        $passedAttempts = 0;
        if ($rs && $rs->Next()) {
            $totalAttempts  = (int)$rs->total;
            $passedAttempts = (int)$rs->passed;
        }

        // Active questions
        $activeQuestions = $this->countActiveQuestions($kingdom_id, $test_type);

        // Flagged questions (questions with at least one report)
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT COUNT(DISTINCT rp.qual_question_id) AS cnt
             FROM ' . DB_PREFIX . 'qual_report rp
             JOIN ' . DB_PREFIX . 'qual_question q ON q.qual_question_id = rp.qual_question_id
             WHERE q.kingdom_id = ' . $kingdom_id . '
               AND q.test_type = \'' . $test_type . '\'
               AND q.status = \'active\''
        );
        $flaggedQuestions = ($rs && $rs->Next()) ? (int)$rs->cnt : 0;

        return [
            'ActiveQualified'  => $activeQualified,
            'ActivePlayers'    => $activePlayers,
            'PassRate6Mo'      => $totalAttempts > 0 ? round(($passedAttempts / $totalAttempts) * 100) : 0,
            'PassRate6MoTotal' => $totalAttempts,
            'ActiveQuestions'  => $activeQuestions,
            'FlaggedQuestions' => $flaggedQuestions,
        ];
    }

    // -----------------------------------------------------------------------
    // Admin Preview
    // -----------------------------------------------------------------------

    /**
     * Like getQuestionsForTest but INCLUDES is_correct flags (admin preview).
     */
    public function getQuestionsForPreview($kingdom_id, $test_type, $limit)
    {
        return $this->_loadQuestionsAndAnswers($kingdom_id, $test_type, $limit, true);
    }

    // -----------------------------------------------------------------------
    // Duplication
    // -----------------------------------------------------------------------

    /**
     * Clone a question + answers within the same kingdom.
     * Always creates the clone as 'active' status.
     */
    public function duplicateQuestion($question_id, $kingdom_id)
    {
        $question_id = (int)$question_id;
        $kingdom_id  = (int)$kingdom_id;

        $q = $this->getQuestion($question_id);
        if (!$q || (int)$q['KingdomId'] !== $kingdom_id) {
            return 0;
        }
        if (count($q['Answers']) < 2) {
            return 0;
        }

        $new_text  = 'Copy of ' . $q['QuestionText'];
        $test_type = $this->sanitizeType($q['TestType']);

        // Question + all answers commit together so a clone can never leave an
        // answerless orphan in the active pool.
        $this->db->Clear();
        $this->db->Execute('START TRANSACTION');

        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'qual_question
             (kingdom_id, test_type, question_text, status, created_by)
             VALUES (' . $kingdom_id . ', \'' . $test_type . '\', \'' . $this->esc($new_text) . '\', \'active\', 0)'
        );
        $this->db->Clear();
        $ir = $this->db->DataSet('SELECT LAST_INSERT_ID() AS new_id');
        if (!$ir || !$ir->Next() || (int)$ir->new_id <= 0) {
            $this->db->Clear();
            $this->db->Execute('ROLLBACK');
            return 0;
        }
        $new_qid = (int)$ir->new_id;

        // Single multi-row insert for all answers.
        $rows = [];
        foreach ($q['Answers'] as $a) {
            $rows[] = '(' . $new_qid . ', \'' . $this->esc($a['AnswerText']) . '\', ' . ((int)(bool)$a['IsCorrect']) . ')';
        }
        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'qual_answer (qual_question_id, answer_text, is_correct)
             VALUES ' . implode(', ', $rows)
        );

        $this->db->Clear();
        $this->db->Execute('COMMIT');
        return $new_qid;
    }

    /**
     * Bulk-update question status for a set of IDs (verified against kingdom).
     * Returns count of updated rows, or false if any IDs don't belong to the kingdom.
     */
    public function setQuestionStatusBatch($kingdom_id, $question_ids, $status)
    {
        $kingdom_id = (int)$kingdom_id;
        $status = ($status === 'archived') ? 'archived' : 'active';
        if (empty($question_ids)) {
            return 0;
        }

        $id_list = implode(',', array_map('intval', $question_ids));

        // Verify ALL question IDs belong to this kingdom
        $this->db->Clear();
        $vr = $this->db->DataSet(
            'SELECT qual_question_id FROM ' . DB_PREFIX . 'qual_question
             WHERE qual_question_id IN (' . $id_list . ')
               AND kingdom_id = ' . $kingdom_id
        );
        $verified_ids = [];
        if ($vr) {
            while ($vr->Next()) {
                $verified_ids[] = (int)$vr->qual_question_id;
            }
        }

        if (count($verified_ids) !== count($question_ids)) {
            return false;
        }

        $safe_list = implode(',', $verified_ids);
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'qual_question
             SET status = \'' . $status . '\'
             WHERE qual_question_id IN (' . $safe_list . ')
               AND kingdom_id = ' . $kingdom_id
        );

        return count($verified_ids);
    }

    // -----------------------------------------------------------------------
    // Batch Operations
    // -----------------------------------------------------------------------

    /**
     * Import multiple questions at once. Max 200 per batch.
     * Returns ['imported' => int, 'errors' => [['index' => int, 'error' => string]]]
     */
    public function saveQuestionBatch($kingdom_id, $test_type, $questions_array, $created_by = 0)
    {
        $kingdom_id = (int)$kingdom_id;
        $test_type  = $this->sanitizeType($test_type);
        $created_by = (int)$created_by;
        $imported   = 0;
        $errors     = [];

        // Hard cap at 200 questions per batch to avoid max_execution_time blowups.
        if (count($questions_array) > 200) {
            return ['imported' => 0, 'errors' => [['index' => 0, 'error' => 'Maximum 200 questions per batch.']]];
        }

        foreach ($questions_array as $i => $q) {
            $text = trim($q['QuestionText'] ?? '');
            if (!$text) {
                $errors[] = ['index' => $i, 'error' => 'Question text is empty.'];
                continue;
            }

            $answers = is_array($q['Answers'] ?? null) ? $q['Answers'] : [];
            $clean_answers = [];
            $has_correct   = false;
            foreach ($answers as $a) {
                $atext = trim($a['AnswerText'] ?? '');
                if (!$atext) {
                    continue;
                }
                $is_correct = !empty($a['IsCorrect']) ? 1 : 0;
                if ($is_correct) {
                    $has_correct = true;
                }
                $clean_answers[] = ['AnswerText' => $atext, 'IsCorrect' => $is_correct];
            }

            if (count($clean_answers) < 2) {
                $errors[] = ['index' => $i, 'error' => 'At least 2 non-empty answers required.'];
                continue;
            }
            if (!$has_correct) {
                $errors[] = ['index' => $i, 'error' => 'No correct answer marked.'];
                continue;
            }

            $saved = $this->saveQuestion(0, [
                'KingdomId'    => $kingdom_id,
                'TestType'     => $test_type,
                'QuestionText' => $text,
                // AnswerMode is set by the bulk-import parser (multi when the
                // block had 2+ *-prefixed answers). Absence defaults to single.
                'AnswerMode'   => ($q['AnswerMode'] ?? 'single'),
                'Answers'      => $clean_answers,
                'CreatedBy'    => $created_by,
            ]);

            if ($saved > 0) {
                $imported++;
            } else {
                $errors[] = ['index' => $i, 'error' => 'Failed to save question.'];
            }
        }

        return ['imported' => $imported, 'errors' => $errors];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function sanitizeType($type)
    {
        return ($type === 'corpora') ? 'corpora' : 'reeve';
    }

    private function esc($v)
    {
        return str_replace(["'", '\\'], ["''", '\\\\'], $v);
    }
}
