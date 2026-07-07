<?php

class Controller_QualTestAjax extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);
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

    private function requireAdmin($kingdom_id)
    {
        $uid = $this->requireLogin();
        if (!Ork3::$Lib->qualtest->canManage($uid, $kingdom_id)) {
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
        $rules_version   = trim($_POST['RulesVersion'] ?? '');
        $show_correct    = empty($_POST['ShowCorrectOnIncorrect']) ? 0 : 1;

        if ($test_type === 'reeve' && $rules_version === '') {
            $this->jsonOut(['status' => 1, 'error' => 'Rules of Play version is required for the Reeve\'s Test.']);
        }

        Ork3::$Lib->qualtest->saveConfig($kingdom_id, $test_type, $question_count, $pass_percent, $valid_days, $valid_until, $max_retakes, $share_questions, $instructions, $rules_version, $show_correct);

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
            $existing = Ork3::$Lib->qualtest->getQuestion($question_id);
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

        $saved_id = Ork3::$Lib->qualtest->saveQuestion($question_id, [
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
        $q = Ork3::$Lib->qualtest->getQuestion($question_id);
        if (!$q || (int)$q['KingdomId'] !== $kingdom_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Question not found.']);
        }

        Ork3::$Lib->qualtest->setQuestionStatus($question_id, $status);

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
        if (!Ork3::$Lib->qualtest->canManage($uid, $kingdom_id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Insufficient permissions.']);
        }

        // Verify ownership — question must belong to this kingdom (IDOR guard)
        $q = Ork3::$Lib->qualtest->getQuestion($question_id);
        if (!$q || (int)$q['KingdomId'] !== $kingdom_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Question not found.']);
        }

        Ork3::$Lib->qualtest->resetQuestionStats($question_id);
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
        $q = Ork3::$Lib->qualtest->getQuestion($question_id);
        if (!$q) {
            $this->jsonOut(['status' => 1, 'error' => 'Question not found.']);
        }

        Ork3::$Lib->qualtest->reportQuestion($question_id, $uid, $reason);

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

        $counts = Ork3::$Lib->qualtest->getReportCounts($question_id);
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

        Ork3::$Lib->qualtest->clearReports($question_id);
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
        $config = Ork3::$Lib->qualtest->getConfig($kingdom_id, 'reeve');
        if (!$config['ShareQuestions']) {
            $this->jsonOut(['status' => 1, 'error' => 'Your kingdom is not opted in to the Global Question Library.']);
        }

        $questions = Ork3::$Lib->qualtest->getLibraryQuestions($kingdom_id);
        $this->jsonOut(['status' => 0, 'questions' => $questions]);
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
        $src = Ork3::$Lib->qualtest->getQuestion($question_id);
        if (!$src || $src['TestType'] !== 'reeve' || $src['Status'] !== 'active') {
            $this->jsonOut(['status' => 1, 'error' => 'Source question not available.']);
        }
        if ((int)$src['KingdomId'] === $kingdom_id) {
            $this->jsonOut(['status' => 1, 'error' => 'That question already belongs to your kingdom.']);
        }

        $src_config = Ork3::$Lib->qualtest->getConfig((int)$src['KingdomId'], 'reeve');
        if (!$src_config['ShareQuestions']) {
            $this->jsonOut(['status' => 1, 'error' => 'Source kingdom is not sharing questions.']);
        }

        $new_id = Ork3::$Lib->qualtest->copyQuestionToKingdom($question_id, $kingdom_id, $uid);
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
        Ork3::$Lib->qualtest->resetAllRetakes($kingdom_id, $test_type);
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
        $player_info = Ork3::$Lib->player->player_info($player_id);
        if (!$player_info || (int)$player_info['KingdomId'] !== $kingdom_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid player.']);
        }
        Ork3::$Lib->qualtest->resetPlayerRetakes($player_id, $kingdom_id, $test_type);
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

        // Verify the mundane exists
        $name = Ork3::$Lib->qualtest->getMundaneName($mundane_id);
        if ($name === null) {
            $this->jsonOut(['status' => 1, 'error' => 'Persona not found.']);
        }

        Ork3::$Lib->qualtest->addManager($kingdom_id, $mundane_id);

        $this->jsonOut(['status' => 0, 'mundane_id' => $mundane_id, 'name' => $name]);
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

        Ork3::$Lib->qualtest->removeManager($kingdom_id, $mundane_id);

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

        $correct_map = Ork3::$Lib->qualtest->getCorrectAnswers([$question_id], $kingdom_id, $test_type);
        if (!isset($correct_map[$question_id])) {
            $this->jsonOut(['status' => 1, 'error' => 'Question not found.']);
        }

        // Score via the same predicate that scoreTest() uses so a per-question
        // "correct" verdict never disagrees with the aggregate result.
        $qtmp    = Ork3::$Lib->qualtest;
        $score   = $qtmp->scoreTest([$question_id => $correct_map[$question_id]], [$question_id => $answer_ids]);
        $is_correct = ($score['correct'] === 1);

        // Only reveal correct answers when the player got it right, or when the
        // kingdom has opted into showing correct answers on incorrect submissions.
        $cfg    = $qtmp->getConfig($kingdom_id, $test_type);
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

        $config = Ork3::$Lib->qualtest->getConfig($kingdom_id, $test_type);

        // Retake limit check
        if ($config['MaxRetakes'] > 0) {
            $taken = Ork3::$Lib->qualtest->getRetakeCount($uid, $kingdom_id, $test_type);
            if ($taken >= $config['MaxRetakes']) {
                $this->jsonOut(['status' => 2, 'retake_blocked' => true,
                    'error' => 'You may not retake this test again. Please reach out to your local monarchy for further instructions.']);
            }
        }

        $questions = Ork3::$Lib->qualtest->getQuestionsForTest($kingdom_id, $test_type, $config['QuestionCount']);

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

        $config      = Ork3::$Lib->qualtest->getConfig($kingdom_id, $test_type);

        $correct_map = Ork3::$Lib->qualtest->getCorrectAnswers(array_keys($submitted), $kingdom_id, $test_type);

        // Verify the question IDs belong to this kingdom+type to prevent spoofing
        if (count($correct_map) !== count($submitted)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid question set.']);
        }

        // Atomically consume a retake slot — prevents TOCTOU races and ensures
        // tampered/stale question sets (caught above) never burn a legitimate slot.
        if (!Ork3::$Lib->qualtest->tryConsumeRetake($uid, $kingdom_id, $test_type, (int)$config['MaxRetakes'])) {
            $this->jsonOut(['status' => 1, 'error' => 'You may not retake this test again. Please reach out to your local monarchy for further instructions.']);
        }

        $result  = Ork3::$Lib->qualtest->scoreTest($correct_map, $submitted);
        Ork3::$Lib->qualtest->recordQuestionStats($correct_map, $submitted);
        $passed  = $result['score_percent'] >= $config['PassPercent'];
        $expires = null;

        if ($passed) {
            $expires = Ork3::$Lib->qualtest->recordResult(
                $uid,
                $kingdom_id,
                $test_type,
                $result['score_percent'],
                $config['ValidDays'],
                $config['ValidUntil'] ?? null
            );
            Ork3::$Lib->qualtest->syncMundaneQual($uid, $test_type, $expires);
        }

        $this->jsonOut([
            'status'        => 0,
            'passed'        => $passed,
            'score_percent' => $result['score_percent'],
            'correct'       => $result['correct'],
            'total'         => $result['total'],
            'pass_percent'  => $config['PassPercent'],
            'expires_at'    => $expires,
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


        $result = Ork3::$Lib->qualtest->setQuestionStatusBatch($kingdom_id, $question_ids, $status);

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

        $q = Ork3::$Lib->qualtest->getQuestion($question_id);
        if (!$q || (int)$q['KingdomId'] !== $kingdom_id) {
            $this->jsonOut(['status' => 1, 'error' => 'Question not found.']);
        }

        $new_id = Ork3::$Lib->qualtest->duplicateQuestion($question_id, $kingdom_id);
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
        $config    = Ork3::$Lib->qualtest->getConfig($kingdom_id, $test_type);
        $questions = Ork3::$Lib->qualtest->getQuestionsForPreview($kingdom_id, $test_type, $config['QuestionCount']);

        if ($questions === null) {
            $this->jsonOut(['status' => 1, 'error' => 'Not enough active questions available for this test.']);
        }

        $this->jsonOut([
            'status'         => 0,
            'questions'      => $questions,
            'pass_percent'   => $config['PassPercent'],
            'question_count' => count($questions),
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

        $result = Ork3::$Lib->qualtest->saveQuestionBatch($kingdom_id, $test_type, $questions, $uid);

        $this->jsonOut([
            'status'   => 0,
            'imported' => $result['imported'],
            'errors'   => $result['errors'],
        ]);
    }

}
