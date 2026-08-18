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

        // Kingdom-level officers who may run the tests. The GMR is included because the
        // Corpora makes them THE test administrator ("Shall write and administer the Reeve
        // and Corpora tests"; "All Reeve and Corpora testing is administered and approved
        // by the current GMR") — yet they were the one officer locked out: unlike
        // Monarch/Regent/Prime Minister, GMRs get no kingdom authorization row (0 of 38 in
        // prod data), so the HasAuthority check above is false for every one of them.
        // Scoped to qual-test management only; this grants no other kingdom powers.
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . 'officer
             WHERE mundane_id = ' . (int)$uid . '
               AND kingdom_id = ' . (int)$kingdom_id . '
               AND park_id = 0
               AND role IN (\'Monarch\',\'Regent\',\'Prime Minister\',\'GMR\')
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
        // Display name is the persona; ork_mundane has no `name` column (same bug
        // that broke getMundaneName / add-manager). Park is shown instead of the
        // raw mundane id.
        $rs = $this->db->DataSet(
            'SELECT qm.qual_manager_id, qm.mundane_id, qm.added_at,
                    m.persona, p.name AS park_name
             FROM ' . DB_PREFIX . 'qual_manager qm
             LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = qm.mundane_id
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = m.park_id
             WHERE qm.kingdom_id = ' . (int)$kingdom_id . '
             ORDER BY m.persona ASC'
        );
        $list = [];
        if ($rs) {
            while ($rs->Next()) {
                $list[] = [
                    'QualManagerId' => (int)$rs->qual_manager_id,
                    'MundaneId'     => (int)$rs->mundane_id,
                    'Name'          => $rs->persona ?? '',
                    'Park'          => $rs->park_name ?? '',
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
        // The mundane display name is the persona; ork_mundane has no `name` column
        // (it has persona / given_name / surname). Selecting `name` threw
        // "Unknown column" and made every add-manager fail with "Persona not found".
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT persona FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $mundane_id . ' LIMIT 1'
        );
        if ($r && $r->Next()) {
            return $r->persona;
        }
        return null;
    }

    /**
     * Persona + home-park name for a mundane, or null if not found. Used by the
     * add-manager response so a freshly-added row can show the park (not the id).
     */
    public function getMundaneDisplay($mundane_id)
    {
        $mundane_id = (int)$mundane_id;
        if (!$mundane_id) {
            return null;
        }
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT m.persona, p.name AS park_name
             FROM ' . DB_PREFIX . 'mundane m
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = m.park_id
             WHERE m.mundane_id = ' . $mundane_id . ' LIMIT 1'
        );
        if ($r && $r->Next()) {
            return ['Name' => $r->persona, 'Park' => $r->park_name ?? ''];
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
                // True only when a real qual_config row exists. When false, every value
                // below is an unsaved DEFAULT — the test still runs, but nothing the
                // admin sees has been persisted. Surfaced as a warning in the UI.
                'Configured'    => true,
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
            // No saved row — everything below is a DEFAULT the admin never chose.
            'Configured'    => false,
            'QualConfigId'  => 0,
            'KingdomId'     => (int)$kingdom_id,
            'TestType'      => $test_type,
            'QuestionCount' => 10,
            'PassPercent'   => 70,
            'ValidDays'     => 365,
            'ValidUntil'    => null,
            'MaxRetakes'    => 0,
            // Sharing to the Global Question Library is EXPLICIT opt-in: a kingdom that
            // has never saved a config is NOT opted in. Defaulting this to 1 previously
            // made the checkbox render pre-checked and let unconfigured kingdoms BROWSE
            // the library, while getLibraryQuestions() (which JOINs a real qual_config
            // row with share_questions = 1) still excluded their questions — so they
            // looked opted in but silently contributed nothing. Both paths now agree:
            // no saved row = not participating. Sharing only applies to reeve anyway.
            'ShareQuestions' => 0,
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

        // Set membership drives the LIVE / DRAFT chips on each row. A question in no set
        // is "unused" — written but not part of any version. Never lost, always reusable.
        $pub = $this->getPublishedSet($kingdom_id, $test_type);
        $drf = $this->getDraftSet($kingdom_id, $test_type);
        $pid = $pub ? (int)$pub['SetId'] : 0;
        $did = $drf ? (int)$drf['SetId'] : 0;

        $this->db->Clear();
        $rs = $this->db->DataSet(
            // Where an imported question came from. source_question_id is recorded on every
            // library copy; resolve it to the ORIGINATING kingdom's name so the bank can say
            // "From the Nine Blades" rather than leaving a GMR unable to tell which questions
            // they wrote and which they inherited. LEFT JOINs: a question written locally has
            // no source, and the source kingdom is looked up through the source question.
            'SELECT q.qual_question_id, q.question_text, q.answer_mode, q.status, q.created_at,
                    q.source_question_id,
                    sk.name AS source_kingdom_name,
                    EXISTS(SELECT 1 FROM ' . DB_PREFIX . 'qual_set_question xl
                            WHERE xl.qual_question_id = q.qual_question_id
                              AND xl.qual_question_set_id = ' . $pid . ') AS in_live,
                    EXISTS(SELECT 1 FROM ' . DB_PREFIX . 'qual_set_question xd
                            WHERE xd.qual_question_id = q.qual_question_id
                              AND xd.qual_question_set_id = ' . $did . ') AS in_draft,
                    (SELECT COUNT(*) FROM ' . DB_PREFIX . 'qual_set_question xa
                      WHERE xa.qual_question_id = q.qual_question_id) AS set_count,
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
             LEFT JOIN ' . DB_PREFIX . 'qual_question sq ON sq.qual_question_id = q.source_question_id
             LEFT JOIN ' . DB_PREFIX . 'kingdom sk       ON sk.kingdom_id = sq.kingdom_id
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
                    // Imported from the Global Question Library. SourceKingdom may be '' even when
                    // SourceQuestionId is set — the originating kingdom could have been removed —
                    // so the badge must not assume a name is there.
                    'SourceQuestionId' => (int)($rs->source_question_id ?? 0),
                    'SourceKingdom'    => (string)($rs->source_kingdom_name ?? ''),
                    'InLive'         => (int)$rs->in_live  === 1,
                    'InDraft'        => (int)$rs->in_draft === 1,
                    'Unused'         => (int)$rs->set_count === 0,
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

            // A NEW question joins the set the admin is working in ('SetId' — the draft
            // they're building, or the live set). With no SetId it joins the published
            // set, auto-creating a 'Current' one if the kingdom has none yet, so the
            // "just start adding questions" flow keeps working. EDITING an existing
            // question never changes membership (an edit is a correction and should
            // land wherever that question already lives — including the live test).
            $target_set = (int)($data['SetId'] ?? 0);
            if ($target_set > 0) {
                $s = $this->getSetById($target_set);
                if ($s === null || (int)$s['KingdomId'] !== $kingdom_id || $s['TestType'] !== $test_type
                    || $s['Status'] === 'retired') {
                    $target_set = 0; // not a set we may write to
                }
            }
            if ($target_set <= 0) {
                $target_set = $this->ensureWorkingSet($kingdom_id, $test_type, $created_by);
            }
            if ($target_set > 0) {
                $this->db->Clear();
                $this->db->Execute(
                    'INSERT IGNORE INTO ' . DB_PREFIX . 'qual_set_question
                     (qual_question_set_id, qual_question_id)
                     VALUES (' . $target_set . ', ' . $question_id . ')'
                );
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
     * Can a player actually SIT this test right now, questions-wise?
     *
     * True only when a published version exists AND holds at least QuestionCount active
     * questions. The draw is a LIMIT n that returns NULL when it cannot fill the test, and
     * the start endpoint then refuses outright — so a short version is not a shorter test,
     * it is NO test.
     *
     * This is the check the player-facing UI was missing. It offered "Take Test" off the
     * kingdom's on/off switch alone, so the moment a monarch flipped the switch every player
     * was invited to sit a test that did not exist yet — and got "Not enough active questions
     * available" the instant they tried. The switch says the kingdom PARTICIPATES; this says
     * there is something to take.
     *
     * Callers must still AND this with the kingdom's QualTest*Enabled config: the switch and
     * the bank are independent, and both have to be true.
     */
    public function hasTakeableVersion($kingdom_id, $test_type)
    {
        $published = $this->getPublishedSet($kingdom_id, $test_type);
        if ($published === null) {
            return false;
        }
        $cfg  = $this->getConfig($kingdom_id, $test_type);
        $need = (int)$cfg['QuestionCount'];
        return $need > 0 && (int)$published['MemberCount'] >= $need;
    }

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
    private function _loadQuestionsAndAnswers($kingdom_id, $test_type, $limit, $includeCorrect = false, $set_id = 0)
    {
        $test_type = $this->sanitizeType($test_type);
        $limit     = max(1, (int)$limit);
        $set_id    = (int)$set_id;

        // THE DRAW: a live test asks only what is in the PUBLISHED set and still active.
        //   draw = (member of published set) AND (question.status = 'active')
        // This is what lets an admin build the next version (a draft set) without
        // touching the running test. Before question sets this was simply
        // "any active question", which made every edit/archive/add go live instantly.
        //
        // $set_id targets a SPECIFIC set — used ONLY by the admin preview, so a GMR can see the
        // draft they are building rather than the test it will replace. The player path
        // (getQuestionsForTest) never passes it, so a draft can never be served to a player: the
        // published-only rule below stays the default and is what the live test always gets.
        // The set is still constrained to this kingdom+type, so an id from another kingdom draws
        // nothing rather than leaking their bank.
        $set_clause = $set_id > 0
            ? ' AND s.qual_question_set_id = ' . $set_id
            : ' AND s.status = \'published\'';

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT q.qual_question_id, q.question_text, q.answer_mode
             FROM ' . DB_PREFIX . 'qual_question q
             JOIN ' . DB_PREFIX . 'qual_set_question sq
               ON sq.qual_question_id = q.qual_question_id
             JOIN ' . DB_PREFIX . 'qual_question_set s
               ON s.qual_question_set_id = sq.qual_question_set_id
             WHERE s.kingdom_id = ' . (int)$kingdom_id . '
               AND s.test_type = \'' . $test_type . '\''
               . $set_clause . '
               AND q.status = \'active\'
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
        // A well-formed single question has exactly one correct id, so this is
        // normally a plain equality check. But if malformed data carries more
        // than one is_correct row (a directly-inserted or legacy question the
        // model's validation never saw), honor ANY of them rather than blessing
        // only the first and marking a genuinely-correct pick wrong. No behavior
        // change when there is a single correct id.
        $want = array_map('intval', $correct_ids);
        return $given_id > 0 && in_array($given_id, $want, true);
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
    public function recordResult($player_id, $kingdom_id, $test_type, $score_percent, $valid_days, $valid_until = null, $rules_version = '')
    {
        $player_id     = (int)$player_id;
        $kingdom_id    = (int)$kingdom_id;
        $test_type     = $this->sanitizeType($test_type);
        $score_percent = (int)$score_percent;

        // Stamp the SET as well as the version — see recordAttempt() for why.
        // The SET's label WINS over whatever the caller passed in (the kingdom-config copy).
        // Precedence used to run the other way, so a config value retyped after publishing
        // silently overrode the version label that publishing had made mandatory — and it was
        // THIS stamp, on the player's permanent record, that carried the lie. Which version a
        // player sat is a property of the version, not of a settings field editable later.
        // The caller's value survives only as a fallback for a set with no label (legacy rows).
        $set = $this->getPublishedSet($kingdom_id, $test_type);
        if ($set !== null) {
            if (trim((string)$set['RulesVersion']) !== '') { $rules_version = $set['RulesVersion']; }
            $set_id_sql = (int)$set['SetId'];
            $set_name   = $this->esc((string)$set['Name']);
        } else {
            $set_id_sql = 'NULL';
            $set_name   = '';
        }
        $rv = $this->esc((string)$rules_version);

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
             (player_id, kingdom_id, test_type, score_percent, rules_version,
              qual_question_set_id, set_name, passed_at, expires_at)
             VALUES (' . $player_id . ', ' . $kingdom_id . ', \'' . $test_type . '\', ' . $score_percent . ', \'' . $rv . '\', '
                . $set_id_sql . ', \'' . $set_name . '\', NOW(), ' . $expires_sql . ')
             ON DUPLICATE KEY UPDATE
               score_percent        = VALUES(score_percent),
               rules_version        = VALUES(rules_version),
               qual_question_set_id = VALUES(qual_question_set_id),
               set_name             = VALUES(set_name),
               passed_at            = NOW(),
               expires_at           = VALUES(expires_at)'
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
     * Append an immutable record of ONE test submission (pass OR fail) plus a
     * full snapshot of every question and option the player saw, so the attempt
     * stays reviewable "for all time" even after the questions are later edited
     * or archived.
     *
     * Unlike recordResult (the current-qualification upsert), this is called on
     * EVERY submission and never overwrites: each attempt is its own row.
     *
     * Why snapshot rather than store answer FKs: saveQuestion() DELETEs and
     * re-INSERTs a question's answer rows on every edit (new qual_answer_id each
     * time), so a live join would rot. We copy the exact text at submit time; the
     * *_id columns are kept only as soft references for optional analytics.
     *
     * $submitted: [question_id => int | int[]] — the same shape scoreTest() reads
     *             (scalar answer id for single, list of ids for multi).
     * Returns the new qual_attempt_id, or 0 on failure.
     */
    public function recordAttempt($player_id, $kingdom_id, $test_type, $score_percent, $pass_percent, $passed, $submitted, $rules_version = '')
    {
        $player_id     = (int)$player_id;
        $kingdom_id    = (int)$kingdom_id;
        $test_type     = $this->sanitizeType($test_type);
        $score_percent = (int)$score_percent;
        $pass_percent  = (int)$pass_percent;
        $passed        = $passed ? 1 : 0;

        // Stamp WHICH SET was sat, not just the rules version: a new GMR may publish a
        // fresh bank under an UNCHANGED rules_version, so the version label alone cannot
        // identify the test taken. The set is authoritative; name is a free-text snapshot
        // so it stays truthful if the set is later renamed.
        // The SET's label WINS over whatever the caller passed in (the kingdom-config copy).
        // Precedence used to run the other way, so a config value retyped after publishing
        // silently overrode the version label that publishing had made mandatory — and it was
        // THIS stamp, on the player's permanent record, that carried the lie. Which version a
        // player sat is a property of the version, not of a settings field editable later.
        // The caller's value survives only as a fallback for a set with no label (legacy rows).
        $set = $this->getPublishedSet($kingdom_id, $test_type);
        if ($set !== null) {
            if (trim((string)$set['RulesVersion']) !== '') { $rules_version = $set['RulesVersion']; }
            $set_id_sql = (int)$set['SetId'];
            $set_name   = $this->esc((string)$set['Name']);
        } else {
            $set_id_sql = 'NULL';
            $set_name   = '';
        }
        $rv = $this->esc((string)$rules_version);

        // Question ids in presentation order (array order of the submission), and
        // the set of answer ids the player selected per question.
        $qids     = [];
        $selected = []; // qid => [answer_id => true]
        foreach ($submitted as $qid => $given) {
            $qid = (int)$qid;
            if ($qid <= 0) {
                continue;
            }
            $qids[] = $qid;
            $set = [];
            foreach ((is_array($given) ? $given : [$given]) as $aid) {
                $aid = (int)$aid;
                if ($aid > 0) {
                    $set[$aid] = true;
                }
            }
            $selected[$qid] = $set;
        }

        $this->db->Clear();
        $this->db->Execute('START TRANSACTION');

        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'qual_attempt
             (player_id, kingdom_id, test_type, score_percent, pass_percent, rules_version,
              qual_question_set_id, set_name, passed, taken_at)
             VALUES (' . $player_id . ', ' . $kingdom_id . ', \'' . $test_type . '\', '
                . $score_percent . ', ' . $pass_percent . ', \'' . $rv . '\', '
                . $set_id_sql . ', \'' . $set_name . '\', ' . $passed . ', NOW())'
        );
        $this->db->Clear();
        $ir = $this->db->DataSet('SELECT LAST_INSERT_ID() AS new_id');
        $attempt_id = ($ir && $ir->Next()) ? (int)$ir->new_id : 0;
        if ($attempt_id <= 0) {
            $this->db->Clear();
            $this->db->Execute('ROLLBACK');
            return 0;
        }

        if (!empty($qids)) {
            $ids_str = implode(',', array_map('intval', $qids));

            // Snapshot the question text + mode, and every option's text + correct
            // flag, straight from the live rows AS THEY ARE RIGHT NOW.
            $this->db->Clear();
            $qr = $this->db->DataSet(
                'SELECT qual_question_id, question_text, answer_mode
                 FROM ' . DB_PREFIX . 'qual_question
                 WHERE qual_question_id IN (' . $ids_str . ')'
            );
            $qmeta = [];
            if ($qr) {
                while ($qr->Next()) {
                    $qmeta[(int)$qr->qual_question_id] = [
                        'text' => $qr->question_text,
                        'mode' => ($qr->answer_mode === 'multi') ? 'multi' : 'single',
                    ];
                }
            }

            $this->db->Clear();
            $ar = $this->db->DataSet(
                'SELECT qual_answer_id, qual_question_id, answer_text, is_correct
                 FROM ' . DB_PREFIX . 'qual_answer
                 WHERE qual_question_id IN (' . $ids_str . ')
                 ORDER BY qual_answer_id'
            );
            $options = []; // qid => [ [id,text,is_correct], ... ]
            if ($ar) {
                while ($ar->Next()) {
                    $qid = (int)$ar->qual_question_id;
                    $options[$qid][] = [
                        'id'         => (int)$ar->qual_answer_id,
                        'text'       => $ar->answer_text,
                        'is_correct' => (int)$ar->is_correct === 1 ? 1 : 0,
                    ];
                }
            }

            $rows  = [];
            $order = 0;
            foreach ($qids as $qid) {
                if (!isset($qmeta[$qid]) || empty($options[$qid])) {
                    continue; // can't faithfully snapshot a question with no text/options
                }
                $qtext = $this->esc($qmeta[$qid]['text']);
                $mode  = $qmeta[$qid]['mode'];
                $sel   = $selected[$qid] ?? [];
                foreach ($options[$qid] as $opt) {
                    $was = isset($sel[$opt['id']]) ? 1 : 0;
                    $rows[] = '(' . $attempt_id . ', ' . $qid . ', \'' . $qtext . '\', \'' . $mode . '\', '
                        . $order . ', ' . $opt['id'] . ', \'' . $this->esc($opt['text']) . '\', '
                        . $opt['is_correct'] . ', ' . $was . ')';
                }
                $order++;
            }

            if (!empty($rows)) {
                $this->db->Clear();
                $this->db->Execute(
                    'INSERT INTO ' . DB_PREFIX . 'qual_attempt_answer
                     (qual_attempt_id, qual_question_id, question_text, answer_mode,
                      question_order, qual_answer_id, answer_text, is_correct, was_selected)
                     VALUES ' . implode(', ', $rows)
                );
            }
        }

        $this->db->Clear();
        $this->db->Execute('COMMIT');
        return $attempt_id;
    }

    /**
     * List a player's attempt history (header rows only), newest first.
     * Optionally scoped to one kingdom and/or test type.
     */
    public function getPlayerAttempts($player_id, $kingdom_id = 0, $test_type = null)
    {
        $player_id  = (int)$player_id;
        $kingdom_id = (int)$kingdom_id;
        $where = 'player_id = ' . $player_id;
        if ($kingdom_id > 0) {
            $where .= ' AND kingdom_id = ' . $kingdom_id;
        }
        if ($test_type !== null) {
            $where .= ' AND test_type = \'' . $this->sanitizeType($test_type) . '\'';
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT qual_attempt_id, kingdom_id, test_type, score_percent, pass_percent, rules_version,
                    set_name, passed, taken_at
             FROM ' . DB_PREFIX . 'qual_attempt
             WHERE ' . $where . '
             ORDER BY taken_at DESC, qual_attempt_id DESC
             LIMIT 500'
        );
        $rows = [];
        if ($rs) {
            while ($rs->Next()) {
                $rows[] = [
                    'QualAttemptId' => (int)$rs->qual_attempt_id,
                    'KingdomId'     => (int)$rs->kingdom_id,
                    'TestType'      => $rs->test_type,
                    'ScorePercent'  => (int)$rs->score_percent,
                    'PassPercent'   => (int)$rs->pass_percent,
                    'RulesVersion'  => $rs->rules_version ?? '',
                    'SetName'       => $rs->set_name ?? '',
                    'Passed'        => (int)$rs->passed === 1,
                    'TakenAt'       => $rs->taken_at,
                ];
            }
        }
        return $rows;
    }

    /**
     * Fetch one attempt's header + full snapshot for the review view. Returns
     * null when the attempt does not exist. The caller MUST authorize access
     * (owner or kingdom manager) using the returned PlayerId/KingdomId before
     * rendering — this method does no permission check.
     */
    public function getAttemptDetail($attempt_id)
    {
        $attempt_id = (int)$attempt_id;
        $this->db->Clear();
        $hr = $this->db->DataSet(
            'SELECT qual_attempt_id, player_id, kingdom_id, test_type,
                    score_percent, pass_percent, rules_version, set_name, passed, taken_at
             FROM ' . DB_PREFIX . 'qual_attempt
             WHERE qual_attempt_id = ' . $attempt_id . '
             LIMIT 1'
        );
        if (!$hr || !$hr->Next()) {
            return null;
        }
        $header = [
            'QualAttemptId' => (int)$hr->qual_attempt_id,
            'PlayerId'      => (int)$hr->player_id,
            'KingdomId'     => (int)$hr->kingdom_id,
            'TestType'      => $hr->test_type,
            'ScorePercent'  => (int)$hr->score_percent,
            'PassPercent'   => (int)$hr->pass_percent,
            'RulesVersion'  => $hr->rules_version ?? '',
            'SetName'       => $hr->set_name ?? '',
            'Passed'        => (int)$hr->passed === 1,
            'TakenAt'       => $hr->taken_at,
        ];

        $this->db->Clear();
        $ar = $this->db->DataSet(
            'SELECT qual_question_id, question_text, answer_mode, question_order,
                    answer_text, is_correct, was_selected
             FROM ' . DB_PREFIX . 'qual_attempt_answer
             WHERE qual_attempt_id = ' . $attempt_id . '
             ORDER BY question_order, qual_attempt_answer_id'
        );
        $questions = []; // keyed by question_order to preserve grouping + sequence
        if ($ar) {
            while ($ar->Next()) {
                $ord = (int)$ar->question_order;
                if (!isset($questions[$ord])) {
                    $questions[$ord] = [
                        'QuestionId'   => (int)$ar->qual_question_id,
                        'QuestionText' => $ar->question_text,
                        'AnswerMode'   => $ar->answer_mode,
                        'Correct'      => true, // recomputed below from option flags
                        'Options'      => [],
                    ];
                }
                $questions[$ord]['Options'][] = [
                    'AnswerText'  => $ar->answer_text,
                    'IsCorrect'   => (int)$ar->is_correct === 1,
                    'WasSelected' => (int)$ar->was_selected === 1,
                ];
            }
        }

        // Per-question correctness, recomputed from the snapshot with the SAME
        // all-or-nothing rule scoreTest() uses (selected set === correct set).
        foreach ($questions as &$q) {
            $ok = true;
            foreach ($q['Options'] as $opt) {
                if ($opt['IsCorrect'] !== $opt['WasSelected']) {
                    $ok = false;
                    break;
                }
            }
            $q['Correct'] = $ok;
        }
        unset($q);

        // Flag questions whose LIVE version is now archived, so the review can show
        // "this question is no longer in active rotation" (e.g. retired after a
        // rules change). Questions are never hard-deleted, only archived, so the
        // soft-referenced id still resolves. The snapshot text is unaffected.
        $qids = [];
        foreach ($questions as $q) {
            if (!empty($q['QuestionId'])) { $qids[(int)$q['QuestionId']] = true; }
        }
        $archived = [];
        $in_live  = [];
        if (!empty($qids)) {
            $ids_in = implode(',', array_keys($qids));
            $this->db->Clear();
            $sr = $this->db->DataSet(
                'SELECT qual_question_id, status FROM ' . DB_PREFIX . 'qual_question
                 WHERE qual_question_id IN (' . $ids_in . ')'
            );
            if ($sr) {
                while ($sr->Next()) {
                    $archived[(int)$sr->qual_question_id] = ($sr->status === 'archived');
                }
            }
            // Which of these are still in the kingdom's CURRENT published set? A question
            // can be perfectly good yet no longer part of the live version (dropped in a
            // newer set) — a different thing from being archived (dead), so the review
            // shows a separate, quieter indicator.
            $live = $this->getPublishedSet((int)$header['KingdomId'], $header['TestType']);
            if ($live !== null) {
                $this->db->Clear();
                $lr = $this->db->DataSet(
                    'SELECT qual_question_id FROM ' . DB_PREFIX . 'qual_set_question
                     WHERE qual_question_set_id = ' . (int)$live['SetId'] . '
                       AND qual_question_id IN (' . $ids_in . ')'
                );
                if ($lr) {
                    while ($lr->Next()) {
                        $in_live[(int)$lr->qual_question_id] = true;
                    }
                }
            }
        }
        foreach ($questions as &$q) {
            $qid = (int)($q['QuestionId'] ?? 0);
            $q['Archived'] = $qid > 0 && !empty($archived[$qid]);
            // Not archived, but dropped from the current version of the test.
            $q['NotInLiveSet'] = $qid > 0 && !$q['Archived'] && empty($in_live[$qid]);
        }
        unset($q);

        $header['Questions'] = array_values($questions);
        return $header;
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
    /**
     * @param array|null $stats  Out-param. Why the list is what it is:
     *                           Shared     — questions other kingdoms are sharing, before dedup
     *                           AlreadyHave— of those, how many this kingdom already has
     *                           Available  — what is left to import (== count of the return value)
     *
     * An empty return has two very different causes — nobody has shared anything, or they have
     * and you already imported all of it — and the caller cannot tell them apart from [] alone.
     */
    public function getLibraryQuestions($excluding_kingdom_id, &$stats = null)
    {
        $excluding_kingdom_id = (int)$excluding_kingdom_id;
        $shared_total = 0;
        $already_have = 0;

        // Load the destination kingdom's active questions once so we can dedup in PHP,
        // avoiding a per-row correlated subquery against the un-indexable TEXT column.
        //
        // TWO dedup keys, and the identity one is what actually holds:
        //   source_question_id — this kingdom already imported that exact question. Survives
        //                        the importer rewording it, which TEXT alone does not: an edit
        //                        used to make the question reappear in the library, inviting a
        //                        near-duplicate of one already held.
        //   question_text      — still needed, and not merely for old rows: it also catches a
        //                        question a kingdom happens to have WRITTEN itself, identically,
        //                        without ever importing it.
        // Both look only at ACTIVE questions, so archiving a question deliberately offers it
        // back — that is how a kingdom un-does an import it no longer wants.
        $own_texts   = [];
        $own_sources = [];
        $this->db->Clear();
        $ors = $this->db->DataSet(
            'SELECT question_text, source_question_id FROM ' . DB_PREFIX . 'qual_question
             WHERE kingdom_id = ' . $excluding_kingdom_id . '
               AND test_type = \'reeve\'
               AND status = \'active\''
        );
        if ($ors) {
            while ($ors->Next()) {
                $own_texts[$ors->question_text] = true;
                if ($ors->source_question_id !== null && (int)$ors->source_question_id > 0) {
                    $own_sources[(int)$ors->source_question_id] = true;
                }
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
            // s.rules_version: which edition of the Rules of Play the sharing Kingdom's LIVE test
            // is built on. Everyone plays the same rulebook, but Kingdoms rewrite their tests at
            // different speeds — so a question can be perfectly "live" for its Kingdom and still
            // be written against a superseded ruleset. The set is already joined for the
            // published-only guard; the label costs nothing more and is the only way a browser
            // can see how current a question is.
            'SELECT q.qual_question_id, q.question_text, q.kingdom_id,
                    k.name AS kingdom_name,
                    s.rules_version AS rules_version,
                    (SELECT COUNT(*) FROM ' . DB_PREFIX . 'qual_report r
                     WHERE r.qual_question_id = q.qual_question_id) AS report_count
             FROM ' . DB_PREFIX . 'qual_question q
             JOIN ' . DB_PREFIX . 'qual_config c
               ON c.kingdom_id = q.kingdom_id AND c.test_type = \'reeve\' AND c.share_questions = 1
             -- Share only what a kingdom actually has LIVE. Without this join an
             -- unpublished draft (their next version) would leak to other kingdoms.
             JOIN ' . DB_PREFIX . 'qual_set_question sq
               ON sq.qual_question_id = q.qual_question_id
             JOIN ' . DB_PREFIX . 'qual_question_set s
               ON s.qual_question_set_id = sq.qual_question_set_id
              AND s.kingdom_id = q.kingdom_id AND s.test_type = \'reeve\'
              AND s.status = \'published\'
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
                $shared_total++;
                $qid = (int)$rs->qual_question_id;
                // Already imported this exact question (even if we have since reworded it),
                // or already hold the same text. Either way there is nothing to import.
                if (isset($own_sources[$qid]) || isset($own_texts[$rs->question_text])) {
                    $already_have++;
                    continue;
                }
                $qids[] = $qid;
                $questions[$qid] = [
                    'QualQuestionId' => $qid,
                    'QuestionText'   => $rs->question_text,
                    'KingdomId'      => (int)$rs->kingdom_id,
                    'KingdomName'    => $rs->kingdom_name,
                    'RulesVersion'   => (string)($rs->rules_version ?? ''),
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
        $stats = [
            'Shared'      => $shared_total,
            'AlreadyHave' => $already_have,
            'Available'   => count($questions),
        ];
        return array_values($questions);
    }

    /**
     * Copy a question (and its answers) from another kingdom into $dest_kingdom_id.
     * Returns the new question_id, or 0 on failure.
     */
    public function copyQuestionToKingdom($source_question_id, $dest_kingdom_id, $created_by = 0, $set_id = 0)
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

        // Record WHERE this came from. The library's "already have it" check used to compare
        // question TEXT, which breaks the moment the importing kingdom rewords the question —
        // and then the library cheerfully offers the same question back, producing a near-
        // duplicate. Identity does not change when the wording does.
        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'qual_question
             (kingdom_id, test_type, question_text, status, created_by, source_question_id)
             VALUES (' . $dest_kingdom_id . ', \'reeve\', \'' . $this->esc($question_text) . '\', \'active\', '
                . (int)$created_by . ', ' . $source_question_id . ')'
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

        // A copied question joins the set the admin is working in (same rule as a
        // hand-added one), so pulling from the library while building a draft doesn't
        // silently drop the question into the live test.
        $target_set = (int)$set_id;
        if ($target_set > 0) {
            $s = $this->getSetById($target_set);
            if ($s === null || (int)$s['KingdomId'] !== $dest_kingdom_id || $s['TestType'] !== 'reeve'
                || $s['Status'] === 'retired') {
                $target_set = 0;
            }
        }
        if ($target_set <= 0) {
            $target_set = $this->ensureWorkingSet($dest_kingdom_id, 'reeve', $created_by);
        }
        if ($target_set > 0) {
            $this->db->Clear();
            $this->db->Execute(
                'INSERT IGNORE INTO ' . DB_PREFIX . 'qual_set_question
                 (qual_question_set_id, qual_question_id)
                 VALUES (' . $target_set . ', ' . $new_qid . ')'
            );
        }

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
     * Who reported a question, most recent first — so a test writer can see the
     * individual reporters (and reach out) rather than just the per-reason totals.
     * player_id is already captured on every report; this just surfaces it with the
     * reporter's persona. Returns [ ['ReportId','PlayerId','Persona','Reason','CreatedAt'], ... ].
     */
    public function getReportDetails($question_id)
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT r.qual_report_id, r.player_id, r.reason, r.created_at, m.persona
             FROM ' . DB_PREFIX . 'qual_report r
             LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = r.player_id
             WHERE r.qual_question_id = ' . (int)$question_id . '
             ORDER BY r.created_at DESC, r.qual_report_id DESC'
        );
        $out = [];
        if ($rs) {
            while ($rs->Next()) {
                $out[] = [
                    'ReportId'  => (int)$rs->qual_report_id,
                    'PlayerId'  => (int)$rs->player_id,
                    'Persona'   => $rs->persona,
                    'Reason'    => $rs->reason,
                    'CreatedAt' => $rs->created_at,
                ];
            }
        }
        return $out;
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

        // One row per player = their MOST RECENT attempt (pass OR fail), from the
        // attempt log. Previously this read ork_qual_result, which only holds
        // PASSING results — so players who had only ever failed never appeared.
        // Current-qualification expiry (if any) is joined from ork_qual_result.
        // MAX(qual_attempt_id) picks the latest attempt reliably (autoincrement id
        // is chronological, avoiding taken_at tie ambiguity).
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT a.player_id, a.score_percent, a.pass_percent, a.passed, a.taken_at,
                    m.persona, m.park_id, p.name AS park_name,
                    r.expires_at
             FROM ' . DB_PREFIX . 'qual_attempt a
             JOIN (
                 SELECT MAX(qual_attempt_id) AS latest_id
                 FROM ' . DB_PREFIX . 'qual_attempt
                 WHERE kingdom_id = ' . $kingdom_id . ' AND test_type = \'' . $test_type . '\'
                 GROUP BY player_id
             ) latest ON latest.latest_id = a.qual_attempt_id
             JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = a.player_id
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = m.park_id
             LEFT JOIN ' . DB_PREFIX . 'qual_result r
                    ON r.player_id = a.player_id AND r.kingdom_id = a.kingdom_id AND r.test_type = a.test_type
             ORDER BY a.taken_at DESC
             LIMIT 2000'
        );

        $rows = [];
        if ($rs) {
            while ($rs->Next()) {
                $rows[] = [
                    'PassedAt'     => $rs->taken_at, // latest attempt date (Date column)
                    'MundaneId'    => (int)$rs->player_id,
                    'Persona'      => $rs->persona,
                    'ParkId'       => (int)$rs->park_id,
                    'ParkName'     => $rs->park_name ?: '',
                    'ScorePercent' => (int)$rs->score_percent,
                    'Passed'       => ((int)$rs->passed === 1),
                    // Non-null only when the player currently holds a passing result.
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

        // Pass rate over the past 6 months = passing attempts / ALL attempts, read
        // from the attempt log (every submission). Previously this counted only
        // ork_qual_result rows (passes), so the denominator excluded every failure
        // and the "N attempts" figure was really "N passes".
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) AS passed
             FROM ' . DB_PREFIX . 'qual_attempt
             WHERE kingdom_id = ' . $kingdom_id . '
               AND test_type = \'' . $test_type . '\'
               AND taken_at >= \'' . $six_months_ago . '\''
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
    public function getQuestionsForPreview($kingdom_id, $test_type, $limit, $set_id = 0)
    {
        return $this->_loadQuestionsAndAnswers($kingdom_id, $test_type, $limit, true, $set_id);
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
    public function saveQuestionBatch($kingdom_id, $test_type, $questions_array, $created_by = 0, $set_id = 0)
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
                // Imported questions land in the set being worked on (draft or live).
                'SetId'        => (int)$set_id,
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
    // Question Sets (versioning)
    //
    // Versioning belongs to the SET, not the question: a question is a MEMBER of many
    // sets, so one unchanged between 8.6 and 8.7 simply belongs to both (no duplication,
    // stats/identity preserved). The live test draws from the single published set:
    //     draw = (member of published set) AND (question.status = 'active')
    // question.status stays orthogonal — 'archived' is a global kill switch, while
    // "not in the draft" just means "not part of v2" (still live in v1 until publish).
    // Design: docs/superpowers/plans/2026-07-13-qual-test-question-sets.md
    // -----------------------------------------------------------------------

    /**
     * All sets for a kingdom+test with their ACTIVE member counts (what the draw sees).
     * Ordered published, draft, then most-recently-retired.
     */
    public function getSets($kingdom_id, $test_type)
    {
        $kingdom_id = (int)$kingdom_id;
        $test_type  = $this->sanitizeType($test_type);
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT s.qual_question_set_id, s.name, s.rules_version, s.status,
                    s.created_at, s.published_at,
                    (SELECT COUNT(*)
                       FROM ' . DB_PREFIX . 'qual_set_question sq
                       JOIN ' . DB_PREFIX . 'qual_question q ON q.qual_question_id = sq.qual_question_id
                      WHERE sq.qual_question_set_id = s.qual_question_set_id
                        AND q.status = \'active\') AS member_count,
                    (SELECT COUNT(*)
                       FROM ' . DB_PREFIX . 'qual_set_question sq
                      WHERE sq.qual_question_set_id = s.qual_question_set_id) AS total_count
             FROM ' . DB_PREFIX . 'qual_question_set s
             WHERE s.kingdom_id = ' . $kingdom_id . '
               AND s.test_type = \'' . $test_type . '\'
             ORDER BY FIELD(s.status, \'published\', \'draft\', \'retired\'),
                      s.published_at DESC, s.qual_question_set_id DESC'
        );
        $rows = [];
        if ($rs) {
            while ($rs->Next()) {
                $rows[] = [
                    'SetId'        => (int)$rs->qual_question_set_id,
                    'Name'         => $rs->name,
                    'RulesVersion' => $rs->rules_version,
                    'Status'       => $rs->status,
                    // ACTIVE members — what the draw can reach. The right number for the live
                    // set and the draft, since an archived question is not askable.
                    'MemberCount'  => (int)$rs->member_count,
                    // ALL members, archived included — what the version actually CONTAINED.
                    // The right number for a retired version: reporting only the still-active
                    // ones would shrink history every time a question is archived later.
                    'TotalCount'   => (int)$rs->total_count,
                    'CreatedAt'    => $rs->created_at,
                    'PublishedAt'  => $rs->published_at,
                ];
            }
        }
        return $rows;
    }

    /**
     * One set by id, or null. Includes kingdom/type so callers can authorize.
     */
    public function getSetById($set_id)
    {
        $set_id = (int)$set_id;
        if ($set_id <= 0) {
            return null;
        }
        // member_count must be here, not just in getSets(): callers reach a set through
        // getPublishedSet()/getDraftSet(), which land on this method. Without it, every
        // 'MemberCount' read here defaulted to 0 — so the questions page could show a bank
        // full of Live questions while claiming the current version was empty. Counts ACTIVE
        // members only, matching getSets() and the draw, so it IS the number of questions
        // a test can actually reach.
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT s.qual_question_set_id, s.kingdom_id, s.test_type, s.name, s.rules_version,
                    s.status, s.created_at, s.published_at,
                    (SELECT COUNT(*)
                       FROM ' . DB_PREFIX . 'qual_set_question sq
                       JOIN ' . DB_PREFIX . 'qual_question q ON q.qual_question_id = sq.qual_question_id
                      WHERE sq.qual_question_set_id = s.qual_question_set_id
                        AND q.status = \'active\') AS member_count
             FROM ' . DB_PREFIX . 'qual_question_set s
             WHERE s.qual_question_set_id = ' . $set_id . ' LIMIT 1'
        );
        if (!$rs || !$rs->Next()) {
            return null;
        }
        return [
            'SetId'        => (int)$rs->qual_question_set_id,
            'KingdomId'    => (int)$rs->kingdom_id,
            'TestType'     => $rs->test_type,
            'Name'         => $rs->name,
            'RulesVersion' => $rs->rules_version,
            'Status'       => $rs->status,
            'CreatedAt'    => $rs->created_at,
            'PublishedAt'  => $rs->published_at,
            'MemberCount'  => (int)$rs->member_count,
        ];
    }

    /** The live set for a kingdom+test, or null. */
    public function getPublishedSet($kingdom_id, $test_type)
    {
        return $this->_getSetByStatus($kingdom_id, $test_type, 'published');
    }

    /** The single in-progress draft, or null. */
    public function getDraftSet($kingdom_id, $test_type)
    {
        return $this->_getSetByStatus($kingdom_id, $test_type, 'draft');
    }

    private function _getSetByStatus($kingdom_id, $test_type, $status)
    {
        $kingdom_id = (int)$kingdom_id;
        $test_type  = $this->sanitizeType($test_type);
        $status     = in_array($status, ['draft', 'published', 'retired'], true) ? $status : 'published';
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT qual_question_set_id FROM ' . DB_PREFIX . 'qual_question_set
             WHERE kingdom_id = ' . $kingdom_id . '
               AND test_type = \'' . $test_type . '\'
               AND status = \'' . $status . '\' LIMIT 1'
        );
        return ($rs && $rs->Next()) ? $this->getSetById((int)$rs->qual_question_set_id) : null;
    }

    /**
     * Default name for the next set: "Version 1", "Version 2", ...
     *
     * Names must be ABSOLUTE, never relative. Drafts used to be auto-named "Next version",
     * which was true exactly until it was published — after that the LIVE test was called
     * "Next version", and a second reign would have retired two different versions both
     * bearing that name. A version's name has to still mean something years later, when it
     * is a row in a player's test history.
     */
    private function nextSetName($kingdom_id, $test_type)
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT COUNT(*) AS cnt FROM ' . DB_PREFIX . 'qual_question_set
             WHERE kingdom_id = ' . (int)$kingdom_id . '
               AND test_type = \'' . $this->sanitizeType($test_type) . '\''
        );
        $n = ($rs && $rs->Next()) ? (int)$rs->cnt : 0;
        return 'Version ' . ($n + 1);
    }

    /**
     * Return the set that new questions should join: the draft if one is open, else the
     * published set, else the kingdom's FIRST set — created as a DRAFT.
     *
     * The first version is NOT auto-published. It used to be, and that made v1 the one
     * version that escaped every rule v2 must obey: the moment the kingdom's switch was on,
     * a GMR's first saved question was live to players — with no version label, too few
     * questions to even draw a test, and no chance to review it. Yet publishing the NEXT
     * version demanded all three. A GMR should be able to ask the monarchy to switch the
     * test on, build the bank at their own pace, and go live deliberately, by publishing.
     * So v1 walks the same path as every version after it: draft -> publish.
     */
    public function ensureWorkingSet($kingdom_id, $test_type, $created_by = 0)
    {
        // An open draft is always the working set — that is the whole point of a draft.
        $draft = $this->getDraftSet($kingdom_id, $test_type);
        if ($draft !== null) {
            return (int)$draft['SetId'];
        }
        // No draft: questions go straight into the live set (an edit to the running test).
        $published = $this->getPublishedSet($kingdom_id, $test_type);
        if ($published !== null) {
            return (int)$published['SetId'];
        }
        // Nothing exists yet — this kingdom's first version. Draft, not published.
        $kingdom_id = (int)$kingdom_id;
        $test_type  = $this->sanitizeType($test_type);
        $cfg        = $this->getConfig($kingdom_id, $test_type);
        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'qual_question_set
             (kingdom_id, test_type, name, rules_version, status, created_by, created_at)
             VALUES (' . $kingdom_id . ', \'' . $test_type . '\', \''
                . $this->esc($this->nextSetName($kingdom_id, $test_type)) . '\', \''
                . $this->esc((string)($cfg['RulesVersion'] ?? '')) . '\', \'draft\', '
                . (int)$created_by . ', NOW())'
        );
        $this->db->Clear();
        $ir = $this->db->DataSet('SELECT LAST_INSERT_ID() AS new_id');
        return ($ir && $ir->Next()) ? (int)$ir->new_id : 0;
    }

    /**
     * Every question in one set, with its answers — for reading a version back, including a
     * RETIRED one. Retiring never deletes membership (publishSet only flips status), so a
     * previous version can always be re-read in full.
     *
     * Caveat worth surfacing in the UI: questions are shared BY REFERENCE across versions,
     * so this returns each question's text as it reads TODAY, not as it read when this
     * version was live. An edit since then shows through here. The immutable record of what
     * a player was actually asked lives in qual_attempt_answer, which snapshots the text.
     *
     * Includes archived questions: they were part of this version, and hiding them would
     * misrepresent what the version contained.
     */
    public function getSetQuestions($set_id)
    {
        $set_id = (int)$set_id;
        if ($set_id <= 0) {
            return [];
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT q.qual_question_id, q.question_text, q.answer_mode, q.status
             FROM ' . DB_PREFIX . 'qual_set_question sq
             JOIN ' . DB_PREFIX . 'qual_question q ON q.qual_question_id = sq.qual_question_id
             WHERE sq.qual_question_set_id = ' . $set_id . '
             ORDER BY q.qual_question_id'
        );
        $rows = [];
        $ids  = [];
        if ($rs) {
            while ($rs->Next()) {
                $qid = (int)$rs->qual_question_id;
                $ids[] = $qid;
                $rows[$qid] = [
                    'QualQuestionId' => $qid,
                    'QuestionText'   => $rs->question_text,
                    'AnswerMode'     => $rs->answer_mode,
                    'Archived'       => ($rs->status !== 'active'),
                    'Answers'        => [],
                ];
            }
        }
        if (!$ids) {
            return [];
        }
        $this->db->Clear();
        $ar = $this->db->DataSet(
            'SELECT qual_question_id, answer_text, is_correct
             FROM ' . DB_PREFIX . 'qual_answer
             WHERE qual_question_id IN (' . implode(',', $ids) . ')
             ORDER BY qual_answer_id'
        );
        if ($ar) {
            while ($ar->Next()) {
                $qid = (int)$ar->qual_question_id;
                if (isset($rows[$qid])) {
                    $rows[$qid]['Answers'][] = [
                        'AnswerText' => $ar->answer_text,
                        'IsCorrect'  => ((int)$ar->is_correct === 1),
                    ];
                }
            }
        }
        return array_values($rows);
    }

    /**
     * Create the (single) draft, cloning the published set's membership so unchanged
     * questions carry over with NO duplication. Returns the new set id, or 0 if a draft
     * already exists (the DB's uq_one_draft also enforces this).
     */
    public function createDraft($kingdom_id, $test_type, $name, $rules_version = '', $created_by = 0)
    {
        $kingdom_id = (int)$kingdom_id;
        $test_type  = $this->sanitizeType($test_type);
        if ($this->getDraftSet($kingdom_id, $test_type) !== null) {
            return 0; // one draft at a time
        }
        // No name given: number it. See nextSetName() — names outlive the draft that carried
        // them, so they must not be relative ("Next version") or generic ("Draft").
        $name = trim($name) !== '' ? trim($name) : $this->nextSetName($kingdom_id, $test_type);

        $this->db->Clear();
        $this->db->Execute('START TRANSACTION');

        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'qual_question_set
             (kingdom_id, test_type, name, rules_version, status, created_by, created_at)
             VALUES (' . $kingdom_id . ', \'' . $test_type . '\', \'' . $this->esc($name) . '\', \''
                . $this->esc((string)$rules_version) . '\', \'draft\', ' . (int)$created_by . ', NOW())'
        );
        $this->db->Clear();
        $ir = $this->db->DataSet('SELECT LAST_INSERT_ID() AS new_id');
        $draft_id = ($ir && $ir->Next()) ? (int)$ir->new_id : 0;
        if ($draft_id <= 0) {
            $this->db->Clear();
            $this->db->Execute('ROLLBACK');
            return 0;
        }

        // Clone membership from the live set — the carry-over, with zero new questions.
        $published = $this->getPublishedSet($kingdom_id, $test_type);
        if ($published !== null) {
            $this->db->Clear();
            $this->db->Execute(
                'INSERT IGNORE INTO ' . DB_PREFIX . 'qual_set_question (qual_question_set_id, qual_question_id)
                 SELECT ' . $draft_id . ', sq.qual_question_id
                 FROM ' . DB_PREFIX . 'qual_set_question sq
                 WHERE sq.qual_question_set_id = ' . (int)$published['SetId']
            );
        }

        $this->db->Clear();
        $this->db->Execute('COMMIT');
        return $draft_id;
    }

    /** Rename / re-version a set (used on the draft before publishing). */
    public function updateSet($set_id, $name, $rules_version)
    {
        $set_id = (int)$set_id;
        if ($set_id <= 0) {
            return false;
        }
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'qual_question_set
             SET name = \'' . $this->esc(trim($name)) . '\',
                 rules_version = \'' . $this->esc(trim((string)$rules_version)) . '\'
             WHERE qual_question_set_id = ' . $set_id
        );
        return true;
    }

    /** Add a question to a set (idempotent). */
    public function addQuestionToSet($set_id, $question_id)
    {
        $set_id      = (int)$set_id;
        $question_id = (int)$question_id;
        if ($set_id <= 0 || $question_id <= 0) {
            return false;
        }
        $this->db->Clear();
        $this->db->Execute(
            'INSERT IGNORE INTO ' . DB_PREFIX . 'qual_set_question (qual_question_set_id, qual_question_id)
             VALUES (' . $set_id . ', ' . $question_id . ')'
        );
        return true;
    }

    /**
     * Remove a question from a set. This does NOT archive it: the question stays active
     * and stays live in the published set (if it's a member) until you publish.
     */
    public function removeQuestionFromSet($set_id, $question_id)
    {
        $set_id      = (int)$set_id;
        $question_id = (int)$question_id;
        if ($set_id <= 0 || $question_id <= 0) {
            return false;
        }
        $this->db->Clear();
        $this->db->Execute(
            'DELETE FROM ' . DB_PREFIX . 'qual_set_question
             WHERE qual_question_set_id = ' . $set_id . '
               AND qual_question_id = ' . $question_id
        );
        return true;
    }

    /**
     * Throw away the draft. Questions themselves survive (they may belong to other sets;
     * any that don't simply become unused bank questions, still reusable).
     */
    public function discardDraft($set_id)
    {
        $set = $this->getSetById($set_id);
        if ($set === null || $set['Status'] !== 'draft') {
            return false;
        }
        $set_id = (int)$set_id;
        $this->db->Clear();
        $this->db->Execute('START TRANSACTION');
        $this->db->Clear();
        $this->db->Execute('DELETE FROM ' . DB_PREFIX . 'qual_set_question WHERE qual_question_set_id = ' . $set_id);
        $this->db->Clear();
        $this->db->Execute('DELETE FROM ' . DB_PREFIX . 'qual_question_set WHERE qual_question_set_id = ' . $set_id);
        $this->db->Clear();
        $this->db->Execute('COMMIT');
        return true;
    }

    /**
     * Publish a draft: hard-refuses on any guard failure, then swaps atomically.
     * Returns ['ok' => bool, 'error' => string].
     */
    public function publishSet($set_id)
    {
        $set = $this->getSetById($set_id);
        if ($set === null || $set['Status'] !== 'draft') {
            return ['ok' => false, 'error' => 'That is not a draft set.'];
        }
        $set_id     = (int)$set['SetId'];
        $kingdom_id = (int)$set['KingdomId'];
        $test_type  = $set['TestType'];

        // Guard 1: a version label is required (but it need NOT differ from the previous
        // set — a new GMR may publish a fresh bank under an unchanged ruleset).
        if (trim((string)$set['RulesVersion']) === '') {
            return ['ok' => false, 'error' => 'Set a rules/corpora version before publishing.'];
        }

        // Guard 2: enough active questions to actually draw a test.
        $cfg   = $this->getConfig($kingdom_id, $test_type);
        $need  = (int)$cfg['QuestionCount'];
        $this->db->Clear();
        $cr = $this->db->DataSet(
            'SELECT COUNT(*) AS cnt
             FROM ' . DB_PREFIX . 'qual_set_question sq
             JOIN ' . DB_PREFIX . 'qual_question q ON q.qual_question_id = sq.qual_question_id
             WHERE sq.qual_question_set_id = ' . $set_id . ' AND q.status = \'active\''
        );
        $have = ($cr && $cr->Next()) ? (int)$cr->cnt : 0;
        if ($have < $need) {
            return ['ok' => false, 'error' => 'This version has ' . $have . ' active question'
                . ($have === 1 ? '' : 's') . ' but the test draws ' . $need . '. Add ' . ($need - $have) . ' more.'];
        }

        // Guard 3: every member must be answerable (>= 2 answers, >= 1 correct) — the
        // same invariant the draw path prunes on, checked up front so publishing can
        // never produce a test that silently drops questions.
        $this->db->Clear();
        $br = $this->db->DataSet(
            'SELECT COUNT(*) AS cnt
             FROM ' . DB_PREFIX . 'qual_question q
             JOIN ' . DB_PREFIX . 'qual_set_question sq ON sq.qual_question_id = q.qual_question_id
             WHERE sq.qual_question_set_id = ' . $set_id . ' AND q.status = \'active\'
               AND ((SELECT COUNT(*) FROM ' . DB_PREFIX . 'qual_answer a
                      WHERE a.qual_question_id = q.qual_question_id) < 2
                 OR (SELECT COUNT(*) FROM ' . DB_PREFIX . 'qual_answer a
                      WHERE a.qual_question_id = q.qual_question_id AND a.is_correct = 1) < 1)'
        );
        $bad = ($br && $br->Next()) ? (int)$br->cnt : 0;
        if ($bad > 0) {
            return ['ok' => false, 'error' => $bad . ' question' . ($bad === 1 ? '' : 's')
                . ' in this version ' . ($bad === 1 ? 'is' : 'are') . ' missing answers or a correct answer.'];
        }

        // Atomic swap. uq_one_published makes a concurrent double-publish impossible.
        $this->db->Clear();
        $this->db->Execute('START TRANSACTION');
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'qual_question_set SET status = \'retired\'
             WHERE kingdom_id = ' . $kingdom_id . ' AND test_type = \'' . $test_type . '\'
               AND status = \'published\''
        );
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'qual_question_set
             SET status = \'published\', published_at = NOW()
             WHERE qual_question_set_id = ' . $set_id
        );
        // Keep config's label in sync so the test intro keeps showing the right edition.
        // (Attempt/result stamping reads the SET, which is authoritative.)
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'qual_config
             SET rules_version = \'' . $this->esc((string)$set['RulesVersion']) . '\'
             WHERE kingdom_id = ' . $kingdom_id . ' AND test_type = \'' . $test_type . '\''
        );
        $this->db->Clear();
        $this->db->Execute('COMMIT');

        return ['ok' => true, 'error' => ''];
    }

    /**
     * The kingdom's whole bank for a test: EVERY question ever written, each tagged with
     * which sets it belongs to. A question in no set is simply "unused" — visible and
     * reusable, never lost. This is what replaces a special "orphan" bucket.
     */
    public function getBank($kingdom_id, $test_type)
    {
        $kingdom_id = (int)$kingdom_id;
        $test_type  = $this->sanitizeType($test_type);

        $published = $this->getPublishedSet($kingdom_id, $test_type);
        $draft     = $this->getDraftSet($kingdom_id, $test_type);
        $pid = $published ? (int)$published['SetId'] : 0;
        $did = $draft     ? (int)$draft['SetId']     : 0;

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT q.qual_question_id, q.question_text, q.answer_mode, q.status, q.created_at,
                    (SELECT COUNT(*) FROM ' . DB_PREFIX . 'qual_answer a
                      WHERE a.qual_question_id = q.qual_question_id) AS answer_count,
                    (SELECT COUNT(*) FROM ' . DB_PREFIX . 'qual_answer a
                      WHERE a.qual_question_id = q.qual_question_id AND a.is_correct = 1) AS correct_count,
                    EXISTS(SELECT 1 FROM ' . DB_PREFIX . 'qual_set_question sq
                            WHERE sq.qual_question_id = q.qual_question_id
                              AND sq.qual_question_set_id = ' . $pid . ') AS in_live,
                    EXISTS(SELECT 1 FROM ' . DB_PREFIX . 'qual_set_question sq
                            WHERE sq.qual_question_id = q.qual_question_id
                              AND sq.qual_question_set_id = ' . $did . ') AS in_draft,
                    (SELECT COUNT(*) FROM ' . DB_PREFIX . 'qual_set_question sq
                      WHERE sq.qual_question_id = q.qual_question_id) AS set_count
             FROM ' . DB_PREFIX . 'qual_question q
             WHERE q.kingdom_id = ' . $kingdom_id . '
               AND q.test_type = \'' . $test_type . '\'
             ORDER BY q.qual_question_id DESC'
        );
        $rows = [];
        if ($rs) {
            while ($rs->Next()) {
                $in_live  = (int)$rs->in_live === 1;
                $in_draft = (int)$rs->in_draft === 1;
                $rows[] = [
                    'QualQuestionId' => (int)$rs->qual_question_id,
                    'QuestionText'   => $rs->question_text,
                    'AnswerMode'     => $rs->answer_mode,
                    'Status'         => $rs->status,
                    'AnswerCount'    => (int)$rs->answer_count,
                    'CorrectCount'   => (int)$rs->correct_count,
                    'InLive'         => $in_live,
                    'InDraft'        => $in_draft,
                    // In no set at all: written but not part of any version. Reusable.
                    'Unused'         => ((int)$rs->set_count === 0),
                    'CreatedAt'      => $rs->created_at,
                ];
            }
        }
        return $rows;
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
