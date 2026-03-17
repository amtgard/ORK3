<?php

class QualTest {

    private $db;

    public function __construct() {
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
    public function canManage($uid, $kingdom_id) {
        if ($uid <= 0 || !valid_id($kingdom_id)) return false;

        if (Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT))
            return true;

        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . 'officer
             WHERE mundane_id = ' . (int)$uid . '
               AND kingdom_id = ' . (int)$kingdom_id . '
               AND park_id = 0
               AND role IN (\'Monarch\',\'Regent\',\'Prime Minister\')
             LIMIT 1'
        );
        if ($r && $r->Next()) return true;

        // Test manager list
        $this->db->Clear();
        $m = $this->db->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . 'qual_manager
             WHERE kingdom_id = ' . (int)$kingdom_id . '
               AND mundane_id = ' . (int)$uid . '
             LIMIT 1'
        );
        if ($m && $m->Next()) return true;

        return false;
    }

    // -----------------------------------------------------------------------
    // Managers
    // -----------------------------------------------------------------------

    /**
     * Return all test managers for a kingdom.
     * Returns array of ['MundaneId' => int, 'Name' => string, 'AddedAt' => string]
     */
    public function getManagers($kingdom_id) {
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
     * Add a test manager for a kingdom. Silently ignores duplicates.
     */
    public function addManager($kingdom_id, $mundane_id) {
        $kingdom_id = (int)$kingdom_id;
        $mundane_id = (int)$mundane_id;
        if (!$kingdom_id || !$mundane_id) return false;
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
    public function removeManager($kingdom_id, $mundane_id) {
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
    public function getConfig($kingdom_id, $test_type) {
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
                'ShareQuestions'=> (int)$rs->share_questions,
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
            'ShareQuestions'=> 0,
        ];
    }

    /**
     * Upsert test config for a kingdom+type.
     */
    public function saveConfig($kingdom_id, $test_type, $question_count, $pass_percent, $valid_days, $valid_until = null, $max_retakes = 0, $share_questions = 0) {
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

        $this->db->Clear();
        $exists = $this->db->DataSet(
            'SELECT qual_config_id FROM ' . DB_PREFIX . 'qual_config
             WHERE kingdom_id = ' . $kingdom_id . ' AND test_type = \'' . $test_type . '\' LIMIT 1'
        );
        if ($exists && $exists->Next()) {
            $this->db->Clear();
            $this->db->Execute(
                'UPDATE ' . DB_PREFIX . 'qual_config
                 SET question_count = ' . $question_count . ',
                     pass_percent   = ' . $pass_percent . ',
                     valid_days     = ' . $valid_days . ',
                     valid_until    = ' . $until_sql . ',
                     max_retakes    = ' . $max_retakes . ',
                     share_questions= ' . $share_questions . '
                 WHERE kingdom_id = ' . $kingdom_id . ' AND test_type = \'' . $test_type . '\''
            );
        } else {
            $this->db->Clear();
            $this->db->Execute(
                'INSERT INTO ' . DB_PREFIX . 'qual_config
                 (kingdom_id, test_type, question_count, pass_percent, valid_days, valid_until, max_retakes, share_questions)
                 VALUES (' . $kingdom_id . ', \'' . $test_type . '\', ' . $question_count . ', ' . $pass_percent . ', ' . $valid_days . ', ' . $until_sql . ', ' . $max_retakes . ', ' . $share_questions . ')'
            );
        }
        return true;
    }

    // -----------------------------------------------------------------------
    // Questions (admin)
    // -----------------------------------------------------------------------

    /**
     * All questions for a kingdom+type (admin listing, all statuses).
     */
    public function getAllQuestions($kingdom_id, $test_type) {
        $test_type = $this->sanitizeType($test_type);
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT q.qual_question_id, q.question_text, q.status, q.created_at,
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
    public function getQuestion($question_id) {
        $question_id = (int)$question_id;
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT * FROM ' . DB_PREFIX . 'qual_question
             WHERE qual_question_id = ' . $question_id . ' LIMIT 1'
        );
        if (!$rs || !$rs->Next()) return null;

        $q = [
            'QualQuestionId' => (int)$rs->qual_question_id,
            'KingdomId'      => (int)$rs->kingdom_id,
            'TestType'       => $rs->test_type,
            'QuestionText'   => $rs->question_text,
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
    public function saveQuestion($question_id, $data) {
        $question_id   = (int)$question_id;
        $kingdom_id    = (int)($data['KingdomId'] ?? 0);
        $test_type     = $this->sanitizeType($data['TestType'] ?? '');
        $question_text = trim($data['QuestionText'] ?? '');
        $answers       = is_array($data['Answers']) ? $data['Answers'] : [];

        if (!$question_text || !$test_type || !valid_id($kingdom_id)) return 0;
        if (count($answers) < 2) return 0;

        $has_correct = false;
        foreach ($answers as $a) {
            if (!empty($a['IsCorrect'])) { $has_correct = true; break; }
        }
        if (!$has_correct) return 0;

        if ($question_id > 0) {
            $this->db->Clear();
            $this->db->Execute(
                'UPDATE ' . DB_PREFIX . 'qual_question
                 SET question_text = \'' . $this->esc($question_text) . '\'
                 WHERE qual_question_id = ' . $question_id
            );
        } else {
            $created_by = 0;
            $this->db->Clear();
            $this->db->Execute(
                'INSERT INTO ' . DB_PREFIX . 'qual_question
                 (kingdom_id, test_type, question_text, status, created_by)
                 VALUES (' . $kingdom_id . ', \'' . $test_type . '\', \'' . $this->esc($question_text) . '\', \'active\', ' . $created_by . ')'
            );
            $this->db->Clear();
            $ir = $this->db->DataSet(
                'SELECT qual_question_id FROM ' . DB_PREFIX . 'qual_question
                 WHERE kingdom_id = ' . $kingdom_id . '
                 ORDER BY qual_question_id DESC LIMIT 1'
            );
            if ($ir && $ir->Next()) {
                $question_id = (int)$ir->qual_question_id;
            }
        }

        if ($question_id <= 0) return 0;

        // Replace all answers
        $this->db->Clear();
        $this->db->Execute(
            'DELETE FROM ' . DB_PREFIX . 'qual_answer WHERE qual_question_id = ' . $question_id
        );
        foreach ($answers as $a) {
            $text       = trim($a['AnswerText'] ?? '');
            $is_correct = empty($a['IsCorrect']) ? 0 : 1;
            if (!$text) continue;
            $this->db->Clear();
            $this->db->Execute(
                'INSERT INTO ' . DB_PREFIX . 'qual_answer
                 (qual_question_id, answer_text, is_correct)
                 VALUES (' . $question_id . ', \'' . $this->esc($text) . '\', ' . $is_correct . ')'
            );
        }

        return $question_id;
    }

    /**
     * Set question status (active or archived).
     */
    public function setQuestionStatus($question_id, $status) {
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
    public function getQuestionsForTest($kingdom_id, $test_type, $limit) {
        $test_type = $this->sanitizeType($test_type);
        $limit     = max(1, (int)$limit);

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT qual_question_id, question_text
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
                    'Answers'        => [],
                ];
            }
        }

        if (count($questions) < $limit) return null;

        // Load answers, strip is_correct from public payload
        $ids_str = implode(',', $qids);
        $this->db->Clear();
        $ars = $this->db->DataSet(
            'SELECT qual_answer_id, qual_question_id, answer_text
             FROM ' . DB_PREFIX . 'qual_answer
             WHERE qual_question_id IN (' . $ids_str . ')
             ORDER BY RAND()'
        );
        if ($ars) {
            while ($ars->Next()) {
                $qid = (int)$ars->qual_question_id;
                if (isset($questions[$qid])) {
                    $questions[$qid]['Answers'][] = [
                        'QualAnswerId' => (int)$ars->qual_answer_id,
                        'AnswerText'   => $ars->answer_text,
                    ];
                }
            }
        }

        return array_values($questions);
    }

    /**
     * Get correct answer IDs for a set of question IDs (server-side scoring).
     * Returns array: [question_id => correct_answer_id, ...]
     */
    public function getCorrectAnswers($question_ids, $kingdom_id = 0, $test_type = '') {
        if (empty($question_ids)) return [];
        $ids_str    = implode(',', array_map('intval', $question_ids));
        $test_type  = $this->sanitizeType($test_type);
        // JOIN through qual_question to verify kingdom + type ownership
        $where_kq = $kingdom_id > 0
            ? 'AND q.kingdom_id = ' . (int)$kingdom_id . ' AND q.test_type = \'' . $test_type . '\''
            : '';
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT a.qual_question_id, a.qual_answer_id
             FROM ' . DB_PREFIX . 'qual_answer a
             JOIN ' . DB_PREFIX . 'qual_question q ON q.qual_question_id = a.qual_question_id
             WHERE a.qual_question_id IN (' . $ids_str . ')
               AND a.is_correct = 1
               ' . $where_kq
        );
        $map = [];
        if ($rs) {
            while ($rs->Next()) {
                $map[(int)$rs->qual_question_id] = (int)$rs->qual_answer_id;
            }
        }
        return $map;
    }

    /**
     * Score a submitted test.
     * $correct_map: [question_id => correct_answer_id]
     * $submitted:   [question_id => submitted_answer_id]
     * Returns ['score_percent' => int, 'correct' => int, 'total' => int]
     */
    public function scoreTest($correct_map, $submitted) {
        $total   = count($correct_map);
        $correct = 0;
        foreach ($correct_map as $qid => $correct_aid) {
            $given = isset($submitted[$qid]) ? (int)$submitted[$qid] : 0;
            if ($given === (int)$correct_aid) $correct++;
        }
        $percent = $total > 0 ? (int)round(($correct / $total) * 100) : 0;
        return ['score_percent' => $percent, 'correct' => $correct, 'total' => $total];
    }

    /**
     * Record per-question answer stats (called after every test submission).
     * $correct_map: [question_id => correct_answer_id]
     * $submitted:   [question_id => submitted_answer_id]
     */
    public function recordQuestionStats($correct_map, $submitted) {
        foreach ($correct_map as $qid => $correct_aid) {
            $qid        = (int)$qid;
            $was_correct = (isset($submitted[$qid]) && (int)$submitted[$qid] === (int)$correct_aid) ? 1 : 0;
            $this->db->Clear();
            $this->db->Execute(
                'INSERT INTO ' . DB_PREFIX . 'qual_question_stat
                 (qual_question_id, times_answered, times_correct)
                 VALUES (' . $qid . ', 1, ' . $was_correct . ')
                 ON DUPLICATE KEY UPDATE
                   times_answered = times_answered + 1,
                   times_correct  = times_correct  + ' . $was_correct
            );
        }
    }

    /**
     * Record (or update) a player's passing result.
     */
    public function recordResult($player_id, $kingdom_id, $test_type, $score_percent, $valid_days, $valid_until = null) {
        $player_id     = (int)$player_id;
        $kingdom_id    = (int)$kingdom_id;
        $test_type     = $this->sanitizeType($test_type);
        $score_percent = (int)$score_percent;

        // Use valid_until (fixed date) if provided and valid, otherwise roll forward from valid_days
        if ($valid_until && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid_until)) {
            $expires = date('Y-m-d H:i:s', strtotime($valid_until . ' 23:59:59'));
        } else {
            $valid_days = max(1, (int)$valid_days);
            $expires    = date('Y-m-d H:i:s', strtotime('+' . $valid_days . ' days'));
        }

        $this->db->Clear();
        $exists = $this->db->DataSet(
            'SELECT qual_result_id FROM ' . DB_PREFIX . 'qual_result
             WHERE player_id = ' . $player_id . '
               AND kingdom_id = ' . $kingdom_id . '
               AND test_type = \'' . $test_type . '\'
             LIMIT 1'
        );
        if ($exists && $exists->Next()) {
            $this->db->Clear();
            $this->db->Execute(
                'UPDATE ' . DB_PREFIX . 'qual_result
                 SET score_percent = ' . $score_percent . ',
                     passed_at     = NOW(),
                     expires_at    = \'' . $expires . '\'
                 WHERE player_id = ' . $player_id . '
                   AND kingdom_id = ' . $kingdom_id . '
                   AND test_type = \'' . $test_type . '\''
            );
        } else {
            $this->db->Clear();
            $this->db->Execute(
                'INSERT INTO ' . DB_PREFIX . 'qual_result
                 (player_id, kingdom_id, test_type, score_percent, passed_at, expires_at)
                 VALUES (' . $player_id . ', ' . $kingdom_id . ', \'' . $test_type . '\', ' . $score_percent . ', NOW(), \'' . $expires . '\')'
            );
        }
        return $expires;
    }

    /**
     * Reset the success-rate counters for a single question.
     */
    public function resetQuestionStats($question_id) {
        $this->db->Clear();
        $this->db->Execute(
            'DELETE FROM ' . DB_PREFIX . 'qual_question_stat WHERE qual_question_id = ' . (int)$question_id
        );
        return true;
    }

    /**
     * Write qualification outcome back to ork_mundane so the player sidebar card stays current.
     * Called after every test submission (pass or non-pass after expiry).
     */
    public function syncMundaneQual($player_id, $test_type, $expires_date) {
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
    public function getPlayerResults($player_id, $kingdom_id) {
        $player_id  = (int)$player_id;
        $kingdom_id = (int)$kingdom_id;
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT * FROM ' . DB_PREFIX . 'qual_result
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
                    'Expired'      => strtotime($rs->expires_at) < time(),
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
    public function countActiveQuestions($kingdom_id, $test_type) {
        $test_type = $this->sanitizeType($test_type);
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT COUNT(*) AS cnt FROM ' . DB_PREFIX . 'qual_question
             WHERE kingdom_id = ' . (int)$kingdom_id . '
               AND test_type = \'' . $test_type . '\'
               AND status = \'active\''
        );
        if ($rs && $rs->Next()) return (int)$rs->cnt;
        return 0;
    }

    // -----------------------------------------------------------------------
    // Global Question Library
    // -----------------------------------------------------------------------

    /**
     * Return all active reeve questions from kingdoms that have opted in,
     * excluding the given kingdom's own questions.
     * Returns array of questions each with KingdomName + Answers (no is_correct exposed).
     */
    public function getLibraryQuestions($excluding_kingdom_id) {
        $excluding_kingdom_id = (int)$excluding_kingdom_id;
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT q.qual_question_id, q.question_text, q.kingdom_id,
                    k.name AS kingdom_name
             FROM ' . DB_PREFIX . 'qual_question q
             JOIN ' . DB_PREFIX . 'qual_config c
               ON c.kingdom_id = q.kingdom_id AND c.test_type = \'reeve\' AND c.share_questions = 1
             JOIN ' . DB_PREFIX . 'kingdom k ON k.kingdom_id = q.kingdom_id
             WHERE q.kingdom_id != ' . $excluding_kingdom_id . '
               AND q.test_type = \'reeve\'
               AND q.status = \'active\'
               AND NOT EXISTS (
                   SELECT 1 FROM ' . DB_PREFIX . 'qual_question own
                   WHERE own.kingdom_id = ' . $excluding_kingdom_id . '
                     AND own.question_text = q.question_text
                     AND own.status = \'active\'
               )
             ORDER BY k.name ASC, q.qual_question_id ASC'
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
                    'KingdomId'      => (int)$rs->kingdom_id,
                    'KingdomName'    => $rs->kingdom_name,
                    'Answers'        => [],
                ];
            }
        }
        if (!empty($qids)) {
            $ids_str = implode(',', $qids);
            $this->db->Clear();
            $ars = $this->db->DataSet(
                'SELECT qual_question_id, answer_text, is_correct
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
                            'IsCorrect'  => (bool)(int)$ars->is_correct,
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
    public function copyQuestionToKingdom($source_question_id, $dest_kingdom_id, $created_by = 0) {
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
        if (!$rs) return 0;

        $question_text = null;
        $answers       = [];
        while ($rs->Next()) {
            if ($question_text === null) $question_text = $rs->question_text;
            $answers[] = ['text' => $rs->answer_text, 'correct' => (int)$rs->is_correct];
        }
        if (!$question_text || count($answers) < 2) return 0;

        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'qual_question
             (kingdom_id, test_type, question_text, status, created_by)
             VALUES (' . $dest_kingdom_id . ', \'reeve\', \'' . $this->esc($question_text) . '\', \'active\', ' . (int)$created_by . ')'
        );
        $this->db->Clear();
        $ir = $this->db->DataSet(
            'SELECT qual_question_id FROM ' . DB_PREFIX . 'qual_question
             WHERE kingdom_id = ' . $dest_kingdom_id . '
             ORDER BY qual_question_id DESC LIMIT 1'
        );
        if (!$ir || !$ir->Next()) return 0;
        $new_qid = (int)$ir->qual_question_id;

        foreach ($answers as $a) {
            $this->db->Clear();
            $this->db->Execute(
                'INSERT INTO ' . DB_PREFIX . 'qual_answer
                 (qual_question_id, answer_text, is_correct)
                 VALUES (' . $new_qid . ', \'' . $this->esc($a['text']) . '\', ' . $a['correct'] . ')'
            );
        }
        return $new_qid;
    }

    // -----------------------------------------------------------------------
    // Retakes
    // -----------------------------------------------------------------------

    /**
     * Increment retake counter for a player (called on every submission).
     */
    public function incrementRetakeCount($player_id, $kingdom_id, $test_type) {
        $test_type = $this->sanitizeType($test_type);
        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . 'qual_retake (player_id, kingdom_id, test_type, retake_count)'
            . ' VALUES (' . (int)$player_id . ', ' . (int)$kingdom_id . ', \'' . $test_type . '\', 1)'
            . ' ON DUPLICATE KEY UPDATE retake_count = retake_count + 1'
        );
    }

    /**
     * Get retake count for a single player+kingdom+type.
     */
    public function getRetakeCount($player_id, $kingdom_id, $test_type) {
        $test_type = $this->sanitizeType($test_type);
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT retake_count FROM ' . DB_PREFIX . 'qual_retake'
            . ' WHERE player_id = ' . (int)$player_id
            . ' AND kingdom_id = ' . (int)$kingdom_id
            . ' AND test_type = \'' . $test_type . '\''
            . ' LIMIT 1'
        );
        if ($rs && $rs->Next()) return (int)$rs->retake_count;
        return 0;
    }

    /**
     * Reset retake counter for a single player.
     */
    public function resetPlayerRetakes($player_id, $kingdom_id, $test_type) {
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
    public function resetAllRetakes($kingdom_id, $test_type) {
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
    public function reportQuestion($question_id, $player_id, $reason) {
        $valid = ['wording', 'correct', 'outdated', 'other'];
        if (!in_array($reason, $valid, true)) return false;
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
    public function getReportCounts($question_id) {
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
    public function clearReports($question_id) {
        $this->db->Clear();
        $this->db->Execute(
            'DELETE FROM ' . DB_PREFIX . 'qual_report WHERE qual_question_id = ' . (int)$question_id
        );
        return true;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function sanitizeType($type) {
        return ($type === 'corpora') ? 'corpora' : 'reeve';
    }

    private function esc($v) {
        return str_replace(["'", '\\'], ["''", '\\\\'], $v);
    }
}
