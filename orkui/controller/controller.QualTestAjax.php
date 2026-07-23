<?php

class Controller_QualTestAjax extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);
        $this->load_model('QualTest');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function jsonOut($data)
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    private function requireLogin()
    {
        if (!isset($this->session->user_id)) {
            $this->jsonOut(['status' => 5, 'error' => 'Not logged in.']);
        }
        return (int)$this->session->user_id;
    }

    // -----------------------------------------------------------------------
    // help — render a docs/*.md guide to HTML for the in-app help modal.
    // POST: Doc (whitelisted key)
    //
    // The Markdown file stays the single source of truth: it is the same document a developer
    // reads in the repo, so the in-app help cannot quietly drift from it. Rendered server-side
    // with the Parsedown already vendored in system/lib.
    // -----------------------------------------------------------------------
    public function help($p = null)
    {
        $this->requireLogin();

        // WHITELIST, not a path. Never interpolate user input into a filename — a 'Doc' of
        // "../../config.dev.php" would otherwise read out the database password.
        $docs = [
            'qualtests' => 'qualification-tests-guide.md',
        ];
        $key = (string)($_POST['Doc'] ?? '');
        if (!isset($docs[$key])) {
            $this->jsonOut(['status' => 1, 'error' => 'Unknown help topic.']);
        }

        $path = DIR_BASENAME . 'docs/' . $docs[$key];
        if (!is_readable($path)) {
            $this->jsonOut(['status' => 1, 'error' => 'Help document is missing.']);
        }

        require_once DIR_SYSTEM . 'lib/Parsedown.php';
        $pd = new Parsedown();
        $pd->setSafeMode(true);       // the file is ours, but never render raw HTML from a file
        $pd->setBreaksEnabled(false); // the guide hard-wraps at 100 cols; honour paragraphs, not newlines

        $this->jsonOut([
            'status' => 0,
            'html'   => $pd->text(file_get_contents($path)),
        ]);
    }

    private function requireAdmin($kingdom_id)
    {
        $uid = $this->requireLogin();
        if (!$this->QualTest->can_manage($uid, $kingdom_id)) {
            $this->jsonOut(['status' => 3, 'error' => 'Not authorized.']);
        }
        return $uid;
    }

    private function requireTestEnabled($kingdom_id, $test_type)
    {
        $key = ($test_type === 'corpora') ? 'QualTestCorporaEnabled' : 'QualTestReeveEnabled';
        $configs = Common::get_configs($kingdom_id, CFG_KINGDOM);
        $enabled = isset($configs[$key]) ? (bool)(int)$configs[$key]['Value'] : false;
        if (!$enabled) {
            $this->jsonOut(['status' => 1, 'error' => 'This test type is not enabled for this kingdom.']);
        }
    }

    // -----------------------------------------------------------------------
    // saveconfig
    // POST: KingdomId, TestType, QuestionCount, PassPercent, ValidDays
    // -----------------------------------------------------------------------
    public function saveconfig($p = null)
    {
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $this->requireAdmin($kingdom_id);

        $test_type      = $_POST['TestType']      ?? 'reeve';
        $question_count = (int)($_POST['QuestionCount'] ?? 10);
        $pass_percent   = (int)($_POST['PassPercent']   ?? 70);
        $valid_days     = (int)($_POST['ValidDays']     ?? 365);
        $valid_until    = trim($_POST['ValidUntil']     ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid_until)) {
            $valid_until = null;
        }
        $max_retakes     = max(0, (int)($_POST['MaxRetakes']     ?? 0));
        $share_questions = empty($_POST['ShareQuestions']) ? 0 : 1;
        $instructions    = $_POST['Instructions'] ?? null;
        $show_correct    = empty($_POST['ShowCorrectOnIncorrect']) ? 0 : 1;

        // The version label is a property of the VERSION, not of these settings — it is required
        // before a version can be published, and publishSet() writes it down here afterwards.
        // Settings no longer offers a field for it, so this must fall back to the STORED value:
        // saveConfig() writes the column unconditionally, and defaulting to '' would wipe the
        // label off the live test every time someone saved an unrelated setting.
        // (The old "required for the Reeve's Test" check moved to publishSet(), where it now
        // applies to both tests — you cannot publish any version without a label.)
        $existing        = $this->QualTest->config($kingdom_id, $test_type);
        $rules_version   = array_key_exists('RulesVersion', $_POST)
                             ? trim($_POST['RulesVersion'])
                             : (string)($existing['RulesVersion'] ?? '');

        $this->QualTest->save_config($kingdom_id, $test_type, $question_count, $pass_percent, $valid_days, $valid_until, $max_retakes, $share_questions, $instructions, $rules_version, $show_correct);

        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // savequestion
    // POST: KingdomId, TestType, QuestionId (0=new), QuestionText,
    //       AnswerText[] (array), AnswerMode ('single'|'multi'),
    //       IsCorrect (single-mode: index of correct answer)
    //       IsCorrect[] (multi-mode: array of correct-answer indices)
    // -----------------------------------------------------------------------
    public function savequestion($p = null)
    {
        $kingdom_id  = (int)($_POST['KingdomId']  ?? 0);
        $question_id = (int)($_POST['QuestionId'] ?? 0);
        $uid = $this->requireAdmin($kingdom_id);

        // If editing, verify the question belongs to this kingdom
        if ($question_id > 0) {
            $existing = $this->QualTest->question($question_id);
            if (!$existing || (int)$existing['KingdomId'] !== $kingdom_id) {
                $this->jsonOut(['status' => 1, 'error' => 'Question not found.']);
            }
        }

        $test_type     = $_POST['TestType']     ?? 'reeve';
        $question_text = trim($_POST['QuestionText'] ?? '');
        $answer_texts  = $_POST['AnswerText']   ?? [];
        $answer_mode   = (($_POST['AnswerMode'] ?? 'single') === 'multi') ? 'multi' : 'single';

        // IsCorrect is a single scalar for single-mode, an array of indices
        // for multi-mode. Normalize to a set of correct indices either way.
        $raw_correct   = $_POST['IsCorrect'] ?? null;
        $correct_set   = [];
        if (is_array($raw_correct)) {
            foreach ($raw_correct as $idx) {
                $i = (int)$idx;
                if ($i >= 0) {
                    $correct_set[$i] = true;
                }
            }
        } elseif ($raw_correct !== null && $raw_correct !== '') {
            $i = (int)$raw_correct;
            if ($i >= 0) {
                $correct_set[$i] = true;
            }
        }

        if (!$question_text) {
            $this->jsonOut(['status' => 1, 'error' => 'Question text is required.']);
        }
        if (count($answer_texts) < 2) {
            $this->jsonOut(['status' => 1, 'error' => 'At least 2 answers required.']);
        }
        if (empty($correct_set)) {
            $this->jsonOut(['status' => 1, 'error' => 'A correct answer must be selected.']);
        }
        if ($answer_mode === 'single' && count($correct_set) > 1) {
            $this->jsonOut(['status' => 1, 'error' => 'Single-answer questions can have only one correct answer. Switch the mode to Multiple, or unselect extras.']);
        }

        $answers = [];
        foreach ($answer_texts as $i => $text) {
            $text = trim($text);
            if (!$text) {
                continue;
            }
            $answers[] = [
                'AnswerText' => $text,
                'IsCorrect'  => isset($correct_set[$i]) ? 1 : 0,
            ];
        }

        // New questions join the set the admin is working in (draft or live). Editing an
        // existing question never changes membership.
        $saved_id = $this->QualTest->save_question($question_id, [
            'SetId'        => (int)($_POST['SetId'] ?? 0),
            'KingdomId'    => $kingdom_id,
            'TestType'     => $test_type,
            'QuestionText' => $question_text,
            'AnswerMode'   => $answer_mode,
            'Answers'      => $answers,
            'CreatedBy'    => $uid,
        ]);

        if (!$saved_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Failed to save question.']);
        }

        $this->jsonOut(['status' => 0, 'question_id' => $saved_id]);
    }

    // -----------------------------------------------------------------------
    // setstatus
    // POST: KingdomId, QuestionId, Status (active|archived)
    // -----------------------------------------------------------------------
    public function setstatus($p = null)
    {
        $kingdom_id  = (int)($_POST['KingdomId']  ?? 0);
        $question_id = (int)($_POST['QuestionId'] ?? 0);
        $status      = ($_POST['Status'] ?? '') === 'archived' ? 'archived' : 'active';

        $this->requireAdmin($kingdom_id);

        if (!valid_id($question_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid question.']);
        }

        // Verify ownership
        $q = $this->QualTest->question($question_id);
        if (!$q || (int)$q['KingdomId'] !== $kingdom_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Question not found.']);
        }

        $this->QualTest->set_question_status($question_id, $status);

        $this->jsonOut(['status' => 0, 'new_status' => $status]);
    }

    // -----------------------------------------------------------------------
    // resetstats
    // POST: KingdomId, QuestionId
    // Clears success-rate counters for a single question (admin only)
    // -----------------------------------------------------------------------
    public function resetstats($p = null)
    {
        $uid         = $this->requireLogin();
        $kingdom_id  = (int)($_POST['KingdomId']  ?? 0);
        $question_id = (int)($_POST['QuestionId'] ?? 0);

        if (!valid_id($kingdom_id) || !valid_id($question_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid request.']);
        }
        if (!$this->QualTest->can_manage($uid, $kingdom_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Insufficient permissions.']);
        }

        // Verify ownership — question must belong to this kingdom (IDOR guard)
        $q = $this->QualTest->question($question_id);
        if (!$q || (int)$q['KingdomId'] !== $kingdom_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Question not found.']);
        }

        $this->QualTest->reset_question_stats($question_id);
        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // reportquestion
    // POST: QuestionId, Reason — requires login only (any player can report)
    // -----------------------------------------------------------------------
    public function reportquestion($p = null)
    {
        $uid         = $this->requireLogin();
        $question_id = (int)($_POST['QuestionId'] ?? 0);
        $reason      = $_POST['Reason'] ?? '';

        if (!valid_id($question_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid question.']);
        }

        $valid_reasons = ['wording', 'correct', 'outdated', 'other'];
        if (!in_array($reason, $valid_reasons, true)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid reason.']);
        }

        // Verify the question exists
        $q = $this->QualTest->question($question_id);
        if (!$q) {
            $this->jsonOut(['status' => 1, 'error' => 'Question not found.']);
        }

        $this->QualTest->report_question($question_id, $uid, $reason);

        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // getreports
    // POST: KingdomId, QuestionId — admin only
    // -----------------------------------------------------------------------
    public function getreports($p = null)
    {
        $kingdom_id  = (int)($_POST['KingdomId']  ?? 0);
        $question_id = (int)($_POST['QuestionId'] ?? 0);
        $this->requireAdmin($kingdom_id);

        if (!valid_id($question_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid question.']);
        }

        $counts = $this->QualTest->report_counts($question_id);
        $this->jsonOut(['status' => 0, 'counts' => $counts]);
    }

    // -----------------------------------------------------------------------
    // clearreports
    // POST: KingdomId, QuestionId — admin only
    // -----------------------------------------------------------------------
    public function clearreports($p = null)
    {
        $kingdom_id  = (int)($_POST['KingdomId']  ?? 0);
        $question_id = (int)($_POST['QuestionId'] ?? 0);
        $this->requireAdmin($kingdom_id);

        if (!valid_id($question_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid question.']);
        }

        $this->QualTest->clear_reports($question_id);
        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // getlibrary
    // POST: KingdomId — returns opted-in questions from other kingdoms
    // -----------------------------------------------------------------------
    public function getlibrary($p = null)
    {
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $this->requireAdmin($kingdom_id);

        // Verify this kingdom is opted in
        $config = $this->QualTest->config($kingdom_id, 'reeve');
        if (!$config['ShareQuestions']) {
            $this->jsonOut(['status' => 1, 'error' => 'Your kingdom is not opted in to the Global Question Library.']);
        }

        // Pass the stats through: an empty list means "nobody has shared anything" OR "you have
        // already imported everything shared", and the UI must not report the first when it is
        // the second. Only the model knows which, because it does the dedup.
        $stats = null;
        $questions = $this->QualTest->library_questions($kingdom_id, $stats);

        // The version the browsing Kingdom is BUILDING (the draft, or the live set if there is no
        // draft). Every Kingdom plays the same rulebook, but they rewrite their tests at different
        // speeds — so the useful comparison is not "is this question valid" but "is this Kingdom's
        // test as current as mine". Sending it lets the UI flag questions written against a
        // different edition, without the model having to guess what "current" means.
        $_draft   = $this->QualTest->draft_set($kingdom_id, 'reeve');
        $_pub     = $this->QualTest->published_set($kingdom_id, 'reeve');
        $_working = $_draft ?: $_pub;
        $my_version = $_working ? trim((string)$_working['RulesVersion']) : '';

        $this->jsonOut([
            'status'     => 0,
            'questions'  => $questions,
            'stats'      => $stats,
            'my_version' => $my_version,
        ]);
    }

    // -----------------------------------------------------------------------
    // copyfromlibrary
    // POST: KingdomId, QuestionId
    // -----------------------------------------------------------------------
    public function copyfromlibrary($p = null)
    {
        $kingdom_id  = (int)($_POST['KingdomId']  ?? 0);
        $question_id = (int)($_POST['QuestionId'] ?? 0);
        $uid = $this->requireAdmin($kingdom_id);

        if (!valid_id($question_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid question.']);
        }

        // Verify source kingdom is opted in and source question is reeve+active
        $src = $this->QualTest->question($question_id);
        if (!$src || $src['TestType'] !== 'reeve' || $src['Status'] !== 'active') {
            $this->jsonOut(['status' => 1, 'error' => 'Source question not available.']);
        }
        if ((int)$src['KingdomId'] === $kingdom_id) {
            $this->jsonOut(['status' => 1, 'error' => 'That question already belongs to your kingdom.']);
        }

        $src_config = $this->QualTest->config((int)$src['KingdomId'], 'reeve');
        if (!$src_config['ShareQuestions']) {
            $this->jsonOut(['status' => 1, 'error' => 'Source kingdom is not sharing questions.']);
        }

        $new_id = $this->QualTest->copy_question_to_kingdom(
            $question_id,
            $kingdom_id,
            $uid,
            (int)($_POST['SetId'] ?? 0)
        );
        if (!$new_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Failed to copy question.']);
        }

        $this->jsonOut(['status' => 0, 'new_question_id' => $new_id]);
    }

    // -----------------------------------------------------------------------
    // resetretakes
    // POST: KingdomId, TestType — resets all players for this kingdom+type
    // -----------------------------------------------------------------------
    public function resetretakes($p = null)
    {
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $this->requireAdmin($kingdom_id);
        $test_type = $_POST['TestType'] ?? 'reeve';
        $this->QualTest->reset_all_retakes($kingdom_id, $test_type);
        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // resetplayerretakes
    // POST: KingdomId, PlayerId, TestType — resets one player
    // -----------------------------------------------------------------------
    public function resetplayerretakes($p = null)
    {
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $this->requireAdmin($kingdom_id);
        $player_id = (int)($_POST['PlayerId'] ?? 0);
        $test_type = $_POST['TestType'] ?? 'reeve';
        if (!valid_id($player_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid player.']);
        }
        // IDOR guard: confirm the target player belongs to this kingdom
        // before allowing a cross-kingdom admin to reset their retakes.
        $this->load_model('Player');
        $player_info = $this->Player->player_info($player_id);
        if (!$player_info || (int)$player_info['KingdomId'] !== $kingdom_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid player.']);
        }
        $this->QualTest->reset_player_retakes($player_id, $kingdom_id, $test_type);
        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // addmanager
    // POST: KingdomId, MundaneId
    // -----------------------------------------------------------------------
    public function addmanager($p = null)
    {
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $this->requireAdmin($kingdom_id);

        $mundane_id = (int)($_POST['MundaneId'] ?? 0);
        if (!$mundane_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid persona ID.']);
        }

        // Verify the mundane exists; fetch persona + park for the response row.
        $info = $this->QualTest->mundane_display($mundane_id);
        if ($info === null) {
            $this->jsonOut(['status' => 1, 'error' => 'Persona not found.']);
        }

        $this->QualTest->add_manager($kingdom_id, $mundane_id);

        $this->jsonOut(['status' => 0, 'mundane_id' => $mundane_id, 'name' => $info['Name'], 'park' => $info['Park']]);
    }

    // -----------------------------------------------------------------------
    // removemanager
    // POST: KingdomId, MundaneId
    // -----------------------------------------------------------------------
    public function removemanager($p = null)
    {
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $this->requireAdmin($kingdom_id);

        $mundane_id = (int)($_POST['MundaneId'] ?? 0);
        if (!$mundane_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid persona ID.']);
        }

        $this->QualTest->remove_manager($kingdom_id, $mundane_id);

        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // checkanswer
    // POST: KingdomId, TestType, QuestionId, AnswerIds[] (multi) OR AnswerId (single/legacy)
    // Returns whether the submitted answer is correct and (per config) reveals
    // the full set of correct answer IDs.
    // -----------------------------------------------------------------------
    public function checkanswer($p = null)
    {
        $this->requireLogin();
        $kingdom_id  = (int)($_POST['KingdomId']  ?? 0);
        $test_type   = $_POST['TestType']   ?? 'reeve';
        $question_id = (int)($_POST['QuestionId'] ?? 0);

        // AnswerIds is the new array shape (used by both single and multi UI);
        // AnswerId (scalar) stays supported so legacy clients don't break.
        if (isset($_POST['AnswerIds']) && is_array($_POST['AnswerIds'])) {
            $answer_ids = array_values(array_unique(array_map('intval', $_POST['AnswerIds'])));
        } elseif (isset($_POST['AnswerId'])) {
            $answer_ids = [(int)$_POST['AnswerId']];
        } else {
            $answer_ids = [];
        }

        if (!valid_id($kingdom_id) || !valid_id($question_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid request.']);
        }

        $correct_map = $this->QualTest->correct_answers([$question_id], $kingdom_id, $test_type);
        if (!isset($correct_map[$question_id])) {
            $this->jsonOut(['status' => 1, 'error' => 'Question not found.']);
        }

        // Score via the same predicate that scoreTest() uses so a per-question
        // "correct" verdict never disagrees with the aggregate result.
        $score   = $this->QualTest->score_test([$question_id => $correct_map[$question_id]], [$question_id => $answer_ids]);
        $is_correct = ($score['correct'] === 1);

        // Only reveal correct answers when the player got it right, or when the
        // kingdom has opted into showing correct answers on incorrect submissions.
        $cfg    = $this->QualTest->config($kingdom_id, $test_type);
        $reveal = $is_correct || !empty($cfg['ShowCorrectOnIncorrect']);

        $out = [
            'status'      => 0,
            'is_correct'  => $is_correct,
            'answer_mode' => $correct_map[$question_id]['Mode'] ?? 'single',
        ];
        if ($reveal) {
            // Full set every time — multi questions rely on the array; single
            // clients can just take [0].
            $out['correct_answer_ids'] = $correct_map[$question_id]['AnswerIds'];
            // Back-compat: keep the scalar around for the legacy take page JS
            // that reads correct_answer_id. It's the first correct id, which
            // for single is THE answer and for multi is at least AN answer.
            $out['correct_answer_id']  = (int)$correct_map[$question_id]['AnswerIds'][0];
        }
        $this->jsonOut($out);
    }

    // -----------------------------------------------------------------------
    // gettest
    // POST: KingdomId, TestType
    // Returns randomized questions without correct-answer flags
    // -----------------------------------------------------------------------
    public function gettest($p = null)
    {
        $uid        = $this->requireLogin();
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $test_type  = $_POST['TestType'] ?? 'reeve';

        if (!valid_id($kingdom_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid kingdom.']);
        }

        $this->requireTestEnabled($kingdom_id, $test_type);

        $config = $this->QualTest->config($kingdom_id, $test_type);

        // Retake limit check
        if ($config['MaxRetakes'] > 0) {
            $taken = $this->QualTest->retake_count($uid, $kingdom_id, $test_type);
            if ($taken >= $config['MaxRetakes']) {
                $this->jsonOut(['status' => 2, 'retake_blocked' => true,
                    'error' => 'You may not retake this test again. Please reach out to your local monarchy for further instructions.']);
            }
        }

        $questions = $this->QualTest->questions_for_test($kingdom_id, $test_type, $config['QuestionCount']);

        if ($questions === null) {
            $this->jsonOut(['status' => 1, 'error' => 'Not enough active questions available for this test.']);
        }

        $this->jsonOut([
            'status'        => 0,
            'questions'     => $questions,
            'pass_percent'  => $config['PassPercent'],
            'question_count' => count($questions),
            'instructions'  => $config['Instructions'] ?? null,
        ]);
    }

    // -----------------------------------------------------------------------
    // submittest
    // POST: KingdomId, TestType, Answers (JSON object: {question_id: answer_id, ...})
    // Server-side scoring; records result if passing
    // -----------------------------------------------------------------------
    public function submittest($p = null)
    {
        $uid        = $this->requireLogin();
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $test_type  = $_POST['TestType'] ?? 'reeve';

        if (!valid_id($kingdom_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid kingdom.']);
        }

        $this->requireTestEnabled($kingdom_id, $test_type);

        $raw_answers = json_decode($_POST['Answers'] ?? '{}', true);
        if (!is_array($raw_answers) || empty($raw_answers)) {
            $this->jsonOut(['status' => 1, 'error' => 'No answers submitted.']);
        }

        // Sanitize submitted answer map. Multi-correct questions arrive as an
        // array of ids; single-correct come as a scalar (or a one-element array
        // from the new client). Scoring downstream handles both shapes.
        $submitted = [];
        foreach ($raw_answers as $qid => $aid) {
            $qid = (int)$qid;
            if (is_array($aid)) {
                $submitted[$qid] = array_values(array_unique(array_map('intval', $aid)));
            } else {
                $submitted[$qid] = (int)$aid;
            }
        }

        $config      = $this->QualTest->config($kingdom_id, $test_type);

        $correct_map = $this->QualTest->correct_answers(array_keys($submitted), $kingdom_id, $test_type);

        // Verify the question IDs belong to this kingdom+type to prevent spoofing
        if (count($correct_map) !== count($submitted)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid question set.']);
        }

        // Atomically consume a retake slot — prevents TOCTOU races and ensures
        // tampered/stale question sets (caught above) never burn a legitimate slot.
        if (!$this->QualTest->try_consume_retake($uid, $kingdom_id, $test_type, (int)$config['MaxRetakes'])) {
            $this->jsonOut(['status' => 1, 'error' => 'You may not retake this test again. Please reach out to your local monarchy for further instructions.']);
        }

        $result  = $this->QualTest->score_test($correct_map, $submitted);
        $this->QualTest->record_question_stats($correct_map, $submitted);
        $passed  = $result['score_percent'] >= $config['PassPercent'];
        $expires = null;

        // Durable, reviewable-for-all-time record of THIS attempt (pass or fail),
        // with a full snapshot of the questions/options as seen. Distinct from the
        // pass-only recordResult() upsert below.
        $attempt_id = $this->QualTest->record_attempt(
            $uid,
            $kingdom_id,
            $test_type,
            $result['score_percent'],
            (int)$config['PassPercent'],
            $passed,
            $submitted,
            $config['RulesVersion'] ?? ''
        );

        if ($passed) {
            $expires = $this->QualTest->record_result(
                $uid,
                $kingdom_id,
                $test_type,
                $result['score_percent'],
                $config['ValidDays'],
                $config['ValidUntil'] ?? null,
                $config['RulesVersion'] ?? ''
            );
            $this->QualTest->sync_mundane_qual($uid, $test_type, $expires);
        }

        $this->jsonOut([
            'status'        => 0,
            'passed'        => $passed,
            'score_percent' => $result['score_percent'],
            'correct'       => $result['correct'],
            'total'         => $result['total'],
            'pass_percent'  => $config['PassPercent'],
            'expires_at'    => $expires,
            'attempt_id'    => $attempt_id,
        ]);
    }
    // -----------------------------------------------------------------------
    // bulkstatus
    // POST: KingdomId, QuestionIds (JSON array or CSV), Status (active|archived)
    // -----------------------------------------------------------------------
    public function bulkstatus($p = null)
    {
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $this->requireAdmin($kingdom_id);

        $status = ($_POST['Status'] ?? '') === 'archived' ? 'archived' : 'active';

        $raw = $_POST['QuestionIds'] ?? '';
        if (is_string($raw) && substr($raw, 0, 1) === '[') {
            $raw_ids = json_decode($raw, true);
        } else {
            $raw_ids = explode(',', $raw);
        }
        if (!is_array($raw_ids) || empty($raw_ids)) {
            $this->jsonOut(['status' => 1, 'error' => 'No question IDs provided.']);
        }

        $question_ids = array_values(array_filter(array_map('intval', $raw_ids)));
        if (empty($question_ids)) {
            $this->jsonOut(['status' => 1, 'error' => 'No valid question IDs provided.']);
        }


        $result = $this->QualTest->set_question_status_batch($kingdom_id, $question_ids, $status);

        if ($result === false) {
            $this->jsonOut(['status' => 1, 'error' => 'One or more questions do not belong to this kingdom.']);
        }

        $this->jsonOut(['status' => 0, 'updated' => $result]);
    }

    // -----------------------------------------------------------------------
    // duplicatequestion
    // POST: KingdomId, QuestionId
    // -----------------------------------------------------------------------
    public function duplicatequestion($p = null)
    {
        $kingdom_id  = (int)($_POST['KingdomId']  ?? 0);
        $question_id = (int)($_POST['QuestionId'] ?? 0);
        $this->requireAdmin($kingdom_id);

        if (!valid_id($question_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid question.']);
        }

        $q = $this->QualTest->question($question_id);
        if (!$q || (int)$q['KingdomId'] !== $kingdom_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Question not found.']);
        }

        $new_id = $this->QualTest->duplicate_question($question_id, $kingdom_id);
        if (!$new_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Failed to duplicate question.']);
        }

        $this->jsonOut(['status' => 0, 'new_question_id' => $new_id]);
    }

    // -----------------------------------------------------------------------
    // previewtest
    // POST: KingdomId, TestType
    // -----------------------------------------------------------------------
    public function previewtest($p = null)
    {
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        if (!valid_id($kingdom_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid kingdom.']);
        }

        $this->requireAdmin($kingdom_id);

        $test_type = $_POST['TestType'] ?? 'reeve';
        $config    = $this->QualTest->config($kingdom_id, $test_type);

        // Preview the set the GMR is WORKING IN — the draft when one is open, otherwise the live
        // set. It used to always draw from the published set, so while you were building the next
        // version Preview showed you the test it was about to replace, and on a first version it
        // failed outright ("not enough questions") even with a full draft sitting there. Everything
        // else on the questions page targets the draft; Preview now agrees with it.
        // The UI offers one button per version that exists (Preview Draft / Preview Live Test), so
        // it says WHICH set it wants. Fall back to the working set when it doesn't.
        $working = null;
        $want    = (int)($_POST['SetId'] ?? 0);
        if ($want > 0) {
            $s = $this->QualTest->set_by_id($want);
            // Must be THIS kingdom's set, for THIS test. Otherwise a hand-crafted SetId would
            // preview another kingdom's bank — answers and all.
            if ($s === null || (int)$s['KingdomId'] !== $kingdom_id || $s['TestType'] !== $test_type) {
                $this->jsonOut(['status' => 1, 'error' => 'That version does not belong to this test.']);
            }
            $working = $s;
        } else {
            $draft   = $this->QualTest->draft_set($kingdom_id, $test_type);
            $pub     = $this->QualTest->published_set($kingdom_id, $test_type);
            $working = $draft ?: $pub;
        }

        if ($working === null) {
            $this->jsonOut(['status' => 1, 'error' => 'There are no questions to preview yet. Add some first.']);
        }

        $need      = (int)$config['QuestionCount'];
        $questions = $this->QualTest->questions_for_preview(
            $kingdom_id,
            $test_type,
            $need,
            (int)$working['SetId']
        );

        if ($questions === null) {
            // Say which version is short and by how much, rather than a bare "not enough".
            $have = (int)$working['MemberCount'];
            $this->jsonOut(['status' => 1, 'error' =>
                '"' . $working['Name'] . '" has ' . $have . ' active question' . ($have === 1 ? '' : 's')
                . ' but the test draws ' . $need . '. Add ' . max(0, $need - $have) . ' more to preview it.']);
        }

        $this->jsonOut([
            'status'         => 0,
            'questions'      => $questions,
            'pass_percent'   => $config['PassPercent'],
            'question_count' => count($questions),
            // Which version this preview came from — the modal must never leave that ambiguous.
            'set_name'       => $working['Name'],
            'set_status'     => $working['Status'],   // 'draft' | 'published'
            'rules_version'  => (string)($working['RulesVersion'] ?? ''),
        ]);
    }

    // -----------------------------------------------------------------------
    // bulkimport
    // POST: KingdomId, TestType, Questions (JSON string)
    // -----------------------------------------------------------------------
    public function bulkimport($p = null)
    {
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $uid = $this->requireAdmin($kingdom_id);

        $test_type = $_POST['TestType'] ?? 'reeve';
        $raw       = $_POST['Questions'] ?? '';
        $questions = json_decode($raw, true);

        if (!is_array($questions) || empty($questions)) {
            $this->jsonOut(['status' => 1, 'error' => 'No valid questions provided.']);
        }

        if (count($questions) > 200) {
            $this->jsonOut(['status' => 1, 'error' => 'Maximum 200 questions per batch.']);
        }

        $result = $this->QualTest->save_question_batch(
            $kingdom_id,
            $test_type,
            $questions,
            $uid,
            (int)($_POST['SetId'] ?? 0)
        );

        $this->jsonOut([
            'status'   => 0,
            'imported' => $result['imported'],
            'errors'   => $result['errors'],
        ]);
    }

    // -----------------------------------------------------------------------
    // Question Sets (versioning)
    //
    // A draft set lets an admin build the next version of the test WITHOUT touching the
    // running one: the live test draws only from the published set. See
    // docs/superpowers/plans/2026-07-13-qual-test-question-sets.md
    // -----------------------------------------------------------------------

    /** Load a set and authorize the caller against ITS kingdom. Exits on failure. */
    private function requireSet($set_id)
    {
        $set = $this->QualTest->set_by_id((int)$set_id);
        if ($set === null) {
            $this->jsonOut(['status' => 1, 'error' => 'Version not found.']);
        }
        $this->requireAdmin((int)$set['KingdomId']);
        return $set;
    }

    // POST: KingdomId, TestType, Name, RulesVersion
    public function createdraft($p = null)
    {
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $uid        = $this->requireAdmin($kingdom_id);
        $test_type  = ($_POST['TestType'] ?? 'reeve') === 'corpora' ? 'corpora' : 'reeve';

        // An empty name is fine — the model numbers it ("Version 3"). Naming it is the GMR's
        // to do afterwards, and they can rename it any time before it goes live.
        $name = trim($_POST['Name'] ?? '');
        // A draft is the SUCCESSOR to the current version — it has no meaning without one.
        // Allowed through, createDraft() would find nothing to clone and leave the kingdom with
        // a draft and no published set: every new question would land in the draft and the live
        // test would stay empty until publish. The button is hidden in this state; this stops a
        // stale page from posting anyway.
        $published = $this->QualTest->published_set($kingdom_id, $test_type);
        if ($published === null) {
            $this->jsonOut(['status' => 1, 'error' => 'There is no current version yet. Add your first question — the current version is created automatically — then start the next one.']);
        }
        // Clones the live set's membership, so carried-over questions are NOT duplicated.
        $id = $this->QualTest->create_draft(
            $kingdom_id,
            $test_type,
            $name,
            trim($_POST['RulesVersion'] ?? ''),
            $uid
        );
        if ($id <= 0) {
            $this->jsonOut(['status' => 1, 'error' => 'A draft version already exists for this test.']);
        }
        $this->jsonOut(['status' => 0, 'set_id' => $id]);
    }

    // POST: SetId, Name, RulesVersion
    public function updateset($p = null)
    {
        $set = $this->requireSet($_POST['SetId'] ?? 0);
        if ($set['Status'] === 'retired') {
            $this->jsonOut(['status' => 1, 'error' => 'Previous versions cannot be edited.']);
        }
        // Both fields fall back to the CURRENT value, never to ''. updateSet() writes both
        // columns unconditionally, so defaulting RulesVersion to '' would have let a
        // name-only save silently blank the version label — the one field publishing requires.
        $name = array_key_exists('Name', $_POST) ? trim($_POST['Name']) : $set['Name'];
        $ver  = array_key_exists('RulesVersion', $_POST) ? trim($_POST['RulesVersion']) : (string)$set['RulesVersion'];
        if ($name === '') {
            $this->jsonOut(['status' => 1, 'error' => 'A version needs a name.']);
        }
        $this->QualTest->update_set((int)$set['SetId'], $name, $ver);
        $this->jsonOut(['status' => 0, 'name' => $name]);
    }

    // POST: SetId  — hard-refuses if the draft can't make a valid test.
    public function publishset($p = null)
    {
        $set = $this->requireSet($_POST['SetId'] ?? 0);
        $res = $this->QualTest->publish_set((int)$set['SetId']);
        if (!$res['ok']) {
            $this->jsonOut(['status' => 1, 'error' => $res['error']]);
        }
        $this->jsonOut(['status' => 0]);
    }

    // POST: SetId — throws away the draft. Questions survive (they may be in other sets).
    public function discarddraft($p = null)
    {
        $set = $this->requireSet($_POST['SetId'] ?? 0);
        if ($set['Status'] !== 'draft') {
            $this->jsonOut(['status' => 1, 'error' => 'Only a draft can be discarded.']);
        }
        $this->QualTest->discard_draft((int)$set['SetId']);
        $this->jsonOut(['status' => 0]);
    }

    // POST: SetId, QuestionId, In (1 = add, 0 = remove)
    // Removing does NOT archive the question: it stays live in the published set.
    // Read one version back, including a retired one — this is how a manager inspects what a
    // previous version of the test actually contained. Read-only by construction: it returns
    // data and touches nothing. requireSet() enforces canManage() on the set's own kingdom, so
    // this cannot be used to read another kingdom's bank.
    public function setquestions($p = null)
    {
        $set = $this->requireSet($_POST['SetId'] ?? 0);
        $this->jsonOut([
            'status'    => 0,
            'set'       => [
                'SetId'        => (int)$set['SetId'],
                'Name'         => $set['Name'],
                'RulesVersion' => $set['RulesVersion'],
                'Status'       => $set['Status'],
                'PublishedAt'  => $set['PublishedAt'],
            ],
            'questions' => $this->QualTest->set_questions((int)$set['SetId']),
        ]);
    }

    public function setmembership($p = null)
    {
        $set = $this->requireSet($_POST['SetId'] ?? 0);
        if ($set['Status'] === 'retired') {
            $this->jsonOut(['status' => 1, 'error' => 'Previous versions cannot be edited.']);
        }
        $qid = (int)($_POST['QuestionId'] ?? 0);
        $q   = $this->QualTest->question($qid);
        if (!$q || (int)$q['KingdomId'] !== (int)$set['KingdomId']) {
            $this->jsonOut(['status' => 1, 'error' => 'Question not found.']);
        }
        if (!empty($_POST['In'])) {
            $this->QualTest->add_question_to_set((int)$set['SetId'], $qid);
        } else {
            $this->QualTest->remove_question_from_set((int)$set['SetId'], $qid);
        }
        $this->jsonOut(['status' => 0, 'in' => !empty($_POST['In'])]);
    }

    // -----------------------------------------------------------------------
    // attempts
    // POST: PlayerId (optional; defaults to self), KingdomId (optional), TestType (optional)
    // Lists a player's attempt history. Viewing someone ELSE's history requires
    // being a manager of the kingdom it is scoped to.
    // -----------------------------------------------------------------------
    public function attempts($p = null)
    {
        $uid       = $this->requireLogin();
        $player_id = (int)($_POST['PlayerId'] ?? 0);
        if ($player_id <= 0) {
            $player_id = $uid;
        }
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $test_type  = isset($_POST['TestType']) ? ($_POST['TestType'] === 'corpora' ? 'corpora' : 'reeve') : null;

        // Viewing another player's history is a manager action and MUST be scoped
        // to a kingdom the caller manages (no cross-kingdom fishing).
        if ($player_id !== $uid) {
            if ($kingdom_id <= 0 || !$this->QualTest->can_manage($uid, $kingdom_id)) {
                $this->jsonOut(['status' => 3, 'error' => 'Not authorized.']);
            }
        }

        $this->jsonOut([
            'status'   => 0,
            'attempts' => $this->QualTest->player_attempts($player_id, $kingdom_id, $test_type),
        ]);
    }

    // -----------------------------------------------------------------------
    // attemptdetail
    // POST: AttemptId
    // Returns the full snapshot for one attempt (the "Review Your Answers" data).
    // Authorized for the attempt's owner OR a manager of its kingdom.
    // -----------------------------------------------------------------------
    public function attemptdetail($p = null)
    {
        $uid        = $this->requireLogin();
        $attempt_id = (int)($_POST['AttemptId'] ?? 0);
        if ($attempt_id <= 0) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid attempt.']);
        }

        $detail = $this->QualTest->attempt_detail($attempt_id);
        if ($detail === null) {
            $this->jsonOut(['status' => 1, 'error' => 'Attempt not found.']);
        }

        $isManager = $this->QualTest->can_manage($uid, (int)$detail['KingdomId']);
        if ((int)$detail['PlayerId'] !== $uid && !$isManager) {
            $this->jsonOut(['status' => 3, 'error' => 'Not authorized.']);
        }

        // Respect the "Display correct answer on incorrect" setting for a player's
        // OWN review: when it's OFF, don't reveal correct answers on questions they
        // got wrong (otherwise a player could harvest the key by failing and then
        // reviewing). Managers always see the full detail.
        if (!$isManager) {
            $cfg = $this->QualTest->config((int)$detail['KingdomId'], $detail['TestType']);
            if (empty($cfg['ShowCorrectOnIncorrect'])) {
                foreach ($detail['Questions'] as &$q) {
                    if (empty($q['Correct'])) {
                        foreach ($q['Options'] as &$o) {
                            $o['IsCorrect'] = false; // hide the key; keep WasSelected + per-question Correct
                        }
                        unset($o);
                    }
                }
                unset($q);
            }
        }

        $this->jsonOut(['status' => 0, 'attempt' => $detail]);
    }

}
