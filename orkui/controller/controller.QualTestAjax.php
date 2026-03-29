<?php

class Controller_QualTestAjax extends Controller {

    public function __construct($call = null, $id = null) {
        parent::__construct($call, $id);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function jsonOut($data) {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    private function requireLogin() {
        if (!isset($this->session->user_id)) {
            $this->jsonOut(['status' => 5, 'error' => 'Not logged in.']);
        }
        return (int)$this->session->user_id;
    }

    private function requireAdmin($kingdom_id) {
        $uid = $this->requireLogin();
        if (!Ork3::$Lib->qualtest->canManage($uid, $kingdom_id)) {
            $this->jsonOut(['status' => 3, 'error' => 'Not authorized.']);
        }
        return $uid;
    }

    private function esc($v) {
        return str_replace(["'", '\\'], ["''", '\\\\'], $v);
    }

    private function requireTestEnabled($kingdom_id, $test_type) {
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
    public function saveconfig($p = null) {
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $this->requireAdmin($kingdom_id);

        $test_type      = $_POST['TestType']      ?? 'reeve';
        $question_count = (int)($_POST['QuestionCount'] ?? 10);
        $pass_percent   = (int)($_POST['PassPercent']   ?? 70);
        $valid_days     = (int)($_POST['ValidDays']     ?? 365);
        $valid_until    = trim($_POST['ValidUntil']     ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid_until)) $valid_until = null;
        $max_retakes     = max(0, (int)($_POST['MaxRetakes']     ?? 0));
        $share_questions = empty($_POST['ShareQuestions']) ? 0 : 1;
        $instructions    = $_POST['Instructions'] ?? null;

        Ork3::$Lib->qualtest->saveConfig($kingdom_id, $test_type, $question_count, $pass_percent, $valid_days, $valid_until, $max_retakes, $share_questions, $instructions);

        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // savequestion
    // POST: KingdomId, TestType, QuestionId (0=new), QuestionText,
    //       AnswerText[] (array), IsCorrect (single index of correct answer)
    // -----------------------------------------------------------------------
    public function savequestion($p = null) {
        $kingdom_id  = (int)($_POST['KingdomId']  ?? 0);
        $question_id = (int)($_POST['QuestionId'] ?? 0);
        $this->requireAdmin($kingdom_id);

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
        $correct_index = (int)($_POST['IsCorrect'] ?? -1);

        if (!$question_text) $this->jsonOut(['status' => 1, 'error' => 'Question text is required.']);
        if (count($answer_texts) < 2) $this->jsonOut(['status' => 1, 'error' => 'At least 2 answers required.']);
        if ($correct_index < 0 || $correct_index >= count($answer_texts))
            $this->jsonOut(['status' => 1, 'error' => 'A correct answer must be selected.']);

        $answers = [];
        foreach ($answer_texts as $i => $text) {
            $text = trim($text);
            if (!$text) continue;
            $answers[] = [
                'AnswerText' => $text,
                'IsCorrect'  => ($i === $correct_index) ? 1 : 0,
            ];
        }

        $saved_id = Ork3::$Lib->qualtest->saveQuestion($question_id, [
            'KingdomId'    => $kingdom_id,
            'TestType'     => $test_type,
            'QuestionText' => $question_text,
            'Answers'      => $answers,
        ]);

        if (!$saved_id) $this->jsonOut(['status' => 1, 'error' => 'Failed to save question.']);

        $this->jsonOut(['status' => 0, 'question_id' => $saved_id]);
    }

    // -----------------------------------------------------------------------
    // setstatus
    // POST: KingdomId, QuestionId, Status (active|archived)
    // -----------------------------------------------------------------------
    public function setstatus($p = null) {
        $kingdom_id  = (int)($_POST['KingdomId']  ?? 0);
        $question_id = (int)($_POST['QuestionId'] ?? 0);
        $status      = ($_POST['Status'] ?? '') === 'archived' ? 'archived' : 'active';

        $this->requireAdmin($kingdom_id);

        if (!valid_id($question_id)) $this->jsonOut(['status' => 1, 'error' => 'Invalid question.']);

        // Verify ownership
        $q = Ork3::$Lib->qualtest->getQuestion($question_id);
        if (!$q || (int)$q['KingdomId'] !== $kingdom_id)
            $this->jsonOut(['status' => 1, 'error' => 'Question not found.']);

        Ork3::$Lib->qualtest->setQuestionStatus($question_id, $status);

        $this->jsonOut(['status' => 0, 'new_status' => $status]);
    }

    // -----------------------------------------------------------------------
    // resetstats
    // POST: KingdomId, QuestionId
    // Clears success-rate counters for a single question (admin only)
    // -----------------------------------------------------------------------
    public function resetstats($p = null) {
        $uid         = $this->requireLogin();
        $kingdom_id  = (int)($_POST['KingdomId']  ?? 0);
        $question_id = (int)($_POST['QuestionId'] ?? 0);

        if (!valid_id($kingdom_id) || !valid_id($question_id))
            $this->jsonOut(['status' => 1, 'error' => 'Invalid request.']);
        if (!Ork3::$Lib->qualtest->canManage($uid, $kingdom_id))
            $this->jsonOut(['status' => 1, 'error' => 'Insufficient permissions.']);

        Ork3::$Lib->qualtest->resetQuestionStats($question_id);
        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // reportquestion
    // POST: QuestionId, Reason — requires login only (any player can report)
    // -----------------------------------------------------------------------
    public function reportquestion($p = null) {
        $uid         = $this->requireLogin();
        $question_id = (int)($_POST['QuestionId'] ?? 0);
        $reason      = $_POST['Reason'] ?? '';

        if (!valid_id($question_id))
            $this->jsonOut(['status' => 1, 'error' => 'Invalid question.']);

        $valid_reasons = ['wording', 'correct', 'outdated', 'other'];
        if (!in_array($reason, $valid_reasons, true))
            $this->jsonOut(['status' => 1, 'error' => 'Invalid reason.']);

        // Verify the question exists
        $q = Ork3::$Lib->qualtest->getQuestion($question_id);
        if (!$q) $this->jsonOut(['status' => 1, 'error' => 'Question not found.']);

        Ork3::$Lib->qualtest->reportQuestion($question_id, $uid, $reason);

        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // getreports
    // POST: KingdomId, QuestionId — admin only
    // -----------------------------------------------------------------------
    public function getreports($p = null) {
        $kingdom_id  = (int)($_POST['KingdomId']  ?? 0);
        $question_id = (int)($_POST['QuestionId'] ?? 0);
        $this->requireAdmin($kingdom_id);

        if (!valid_id($question_id))
            $this->jsonOut(['status' => 1, 'error' => 'Invalid question.']);

        $counts = Ork3::$Lib->qualtest->getReportCounts($question_id);
        $this->jsonOut(['status' => 0, 'counts' => $counts]);
    }

    // -----------------------------------------------------------------------
    // clearreports
    // POST: KingdomId, QuestionId — admin only
    // -----------------------------------------------------------------------
    public function clearreports($p = null) {
        $kingdom_id  = (int)($_POST['KingdomId']  ?? 0);
        $question_id = (int)($_POST['QuestionId'] ?? 0);
        $this->requireAdmin($kingdom_id);

        if (!valid_id($question_id))
            $this->jsonOut(['status' => 1, 'error' => 'Invalid question.']);

        Ork3::$Lib->qualtest->clearReports($question_id);
        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // getlibrary
    // POST: KingdomId — returns opted-in questions from other kingdoms
    // -----------------------------------------------------------------------
    public function getlibrary($p = null) {
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
    public function copyfromlibrary($p = null) {
        $kingdom_id  = (int)($_POST['KingdomId']  ?? 0);
        $question_id = (int)($_POST['QuestionId'] ?? 0);
        $uid = $this->requireAdmin($kingdom_id);

        if (!valid_id($question_id)) $this->jsonOut(['status' => 1, 'error' => 'Invalid question.']);

        // Verify source kingdom is opted in and source question is reeve+active
        $src = Ork3::$Lib->qualtest->getQuestion($question_id);
        if (!$src || $src['TestType'] !== 'reeve' || $src['Status'] !== 'active')
            $this->jsonOut(['status' => 1, 'error' => 'Source question not available.']);
        if ((int)$src['KingdomId'] === $kingdom_id)
            $this->jsonOut(['status' => 1, 'error' => 'That question already belongs to your kingdom.']);

        $src_config = Ork3::$Lib->qualtest->getConfig((int)$src['KingdomId'], 'reeve');
        if (!$src_config['ShareQuestions'])
            $this->jsonOut(['status' => 1, 'error' => 'Source kingdom is not sharing questions.']);

        $new_id = Ork3::$Lib->qualtest->copyQuestionToKingdom($question_id, $kingdom_id, $uid);
        if (!$new_id) $this->jsonOut(['status' => 1, 'error' => 'Failed to copy question.']);

        $this->jsonOut(['status' => 0, 'new_question_id' => $new_id]);
    }

    // -----------------------------------------------------------------------
    // resetretakes
    // POST: KingdomId, TestType — resets all players for this kingdom+type
    // -----------------------------------------------------------------------
    public function resetretakes($p = null) {
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
    public function resetplayerretakes($p = null) {
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $this->requireAdmin($kingdom_id);
        $player_id = (int)($_POST['PlayerId'] ?? 0);
        $test_type = $_POST['TestType'] ?? 'reeve';
        if (!valid_id($player_id)) $this->jsonOut(['status' => 1, 'error' => 'Invalid player.']);
        Ork3::$Lib->qualtest->resetPlayerRetakes($player_id, $kingdom_id, $test_type);
        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // addmanager
    // POST: KingdomId, MundaneId
    // -----------------------------------------------------------------------
    public function addmanager($p = null) {
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $this->requireAdmin($kingdom_id);

        $mundane_id = (int)($_POST['MundaneId'] ?? 0);
        if (!$mundane_id) $this->jsonOut(['status' => 1, 'error' => 'Invalid persona ID.']);

        // Verify the mundane exists
        global $DB;
        $DB->Clear();
        $mr = $DB->DataSet('SELECT mundane_id, name FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $mundane_id . ' LIMIT 1');
        if (!$mr || !$mr->Next()) $this->jsonOut(['status' => 1, 'error' => 'Persona not found.']);

        $name = $mr->name;
        Ork3::$Lib->qualtest->addManager($kingdom_id, $mundane_id);

        $this->jsonOut(['status' => 0, 'mundane_id' => $mundane_id, 'name' => $name]);
    }

    // -----------------------------------------------------------------------
    // removemanager
    // POST: KingdomId, MundaneId
    // -----------------------------------------------------------------------
    public function removemanager($p = null) {
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $this->requireAdmin($kingdom_id);

        $mundane_id = (int)($_POST['MundaneId'] ?? 0);
        if (!$mundane_id) $this->jsonOut(['status' => 1, 'error' => 'Invalid persona ID.']);

        Ork3::$Lib->qualtest->removeManager($kingdom_id, $mundane_id);

        $this->jsonOut(['status' => 0]);
    }

    // -----------------------------------------------------------------------
    // checkanswer
    // POST: KingdomId, TestType, QuestionId, AnswerId
    // Returns whether the submitted answer is correct and reveals the correct answer ID
    // -----------------------------------------------------------------------
    public function checkanswer($p = null) {
        $this->requireLogin();
        $kingdom_id  = (int)($_POST['KingdomId']  ?? 0);
        $test_type   = $_POST['TestType']   ?? 'reeve';
        $question_id = (int)($_POST['QuestionId'] ?? 0);
        $answer_id   = (int)($_POST['AnswerId']   ?? 0);

        if (!valid_id($kingdom_id) || !valid_id($question_id))
            $this->jsonOut(['status' => 1, 'error' => 'Invalid request.']);

        $correct_map = Ork3::$Lib->qualtest->getCorrectAnswers([$question_id], $kingdom_id, $test_type);
        if (!isset($correct_map[$question_id]))
            $this->jsonOut(['status' => 1, 'error' => 'Question not found.']);

        $correct_answer_id = $correct_map[$question_id];
        $this->jsonOut([
            'status'            => 0,
            'is_correct'        => ($answer_id === $correct_answer_id),
            'correct_answer_id' => $correct_answer_id,
        ]);
    }

    // -----------------------------------------------------------------------
    // gettest
    // POST: KingdomId, TestType
    // Returns randomized questions without correct-answer flags
    // -----------------------------------------------------------------------
    public function gettest($p = null) {
        $uid        = $this->requireLogin();
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $test_type  = $_POST['TestType'] ?? 'reeve';

        if (!valid_id($kingdom_id)) $this->jsonOut(['status' => 1, 'error' => 'Invalid kingdom.']);

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
            'question_count'=> count($questions),
            'instructions'  => $config['Instructions'] ?? null,
        ]);
    }

    // -----------------------------------------------------------------------
    // submittest
    // POST: KingdomId, TestType, Answers (JSON object: {question_id: answer_id, ...})
    // Server-side scoring; records result if passing
    // -----------------------------------------------------------------------
    public function submittest($p = null) {
        $uid        = $this->requireLogin();
        $kingdom_id = (int)($_POST['KingdomId'] ?? 0);
        $test_type  = $_POST['TestType'] ?? 'reeve';

        if (!valid_id($kingdom_id)) $this->jsonOut(['status' => 1, 'error' => 'Invalid kingdom.']);

        $this->requireTestEnabled($kingdom_id, $test_type);

        $raw_answers = json_decode($_POST['Answers'] ?? '{}', true);
        if (!is_array($raw_answers) || empty($raw_answers))
            $this->jsonOut(['status' => 1, 'error' => 'No answers submitted.']);

        // Sanitize submitted answer map
        $submitted = [];
        foreach ($raw_answers as $qid => $aid) {
            $submitted[(int)$qid] = (int)$aid;
        }

        $config      = Ork3::$Lib->qualtest->getConfig($kingdom_id, $test_type);
        $correct_map = Ork3::$Lib->qualtest->getCorrectAnswers(array_keys($submitted), $kingdom_id, $test_type);

        // Verify the question IDs belong to this kingdom+type to prevent spoofing
        if (count($correct_map) !== count($submitted))
            $this->jsonOut(['status' => 1, 'error' => 'Invalid question set.']);

        $result  = Ork3::$Lib->qualtest->scoreTest($correct_map, $submitted);
        Ork3::$Lib->qualtest->recordQuestionStats($correct_map, $submitted);
        Ork3::$Lib->qualtest->incrementRetakeCount($uid, $kingdom_id, $test_type);
        $passed  = $result['score_percent'] >= $config['PassPercent'];
        $expires = null;

        if ($passed) {
            $expires = Ork3::$Lib->qualtest->recordResult(
                $uid, $kingdom_id, $test_type,
                $result['score_percent'], $config['ValidDays'], $config['ValidUntil'] ?? null
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
}
