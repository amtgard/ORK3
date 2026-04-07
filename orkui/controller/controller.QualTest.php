<?php

class Controller_QualTest extends Controller {

    public function __construct($call = null, $id = null) {
        parent::__construct($call, $id);
    }

    // -----------------------------------------------------------------------
    // manage — admin overview for a kingdom
    // Route: ?Route=QualTest/manage/{kingdom_id}
    // -----------------------------------------------------------------------
    public function manage($kingdom_id = null) {
        $kingdom_id = (int)preg_replace('/[^0-9]/', '', $kingdom_id ?? '');
        $uid        = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

        if (!valid_id($kingdom_id)) {
            $this->data['Error'] = 'Invalid kingdom.';
            return;
        }
        if (!Ork3::$Lib->qualtest->canManage($uid, $kingdom_id)) {
            $this->data['Error'] = 'You do not have permission to manage qualification tests for this kingdom.';
            return;
        }

        global $DB;
        $DB->Clear();
        $kr = $DB->DataSet('SELECT name FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ' . $kingdom_id . ' LIMIT 1');
        $kingdom_name = ($kr && $kr->Next()) ? $kr->name : '';

        $reeve_config   = Ork3::$Lib->qualtest->getConfig($kingdom_id, 'reeve');
        $corpora_config = Ork3::$Lib->qualtest->getConfig($kingdom_id, 'corpora');

        $this->data['KingdomId']     = $kingdom_id;
        $this->data['KingdomName']   = $kingdom_name;
        $this->data['ReeveConfig']   = $reeve_config;
        $this->data['CorporaConfig'] = $corpora_config;
        $this->data['ReeveCount']    = Ork3::$Lib->qualtest->countActiveQuestions($kingdom_id, 'reeve');
        $this->data['CorporaCount']  = Ork3::$Lib->qualtest->countActiveQuestions($kingdom_id, 'corpora');
        $this->data['Managers']      = Ork3::$Lib->qualtest->getManagers($kingdom_id);
        $this->data['Uid']           = $uid;
    }

    // -----------------------------------------------------------------------
    // questions — question list for a kingdom+type
    // Route: ?Route=QualTest/questions/{kingdom_id}/{type}
    // -----------------------------------------------------------------------
    public function questions($action = null) {
        // Router joins all segments: '17/reeve'
        $parts      = explode('/', $action ?? '');
        $kingdom_id = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
        $test_type  = (($parts[1] ?? '') === 'corpora') ? 'corpora' : 'reeve';
        $uid        = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

        if (!valid_id($kingdom_id)) {
            $this->data['Error'] = 'Invalid kingdom.';
            return;
        }
        if (!Ork3::$Lib->qualtest->canManage($uid, $kingdom_id)) {
            $this->data['Error'] = 'You do not have permission to manage qualification tests for this kingdom.';
            return;
        }

        global $DB;
        $DB->Clear();
        $kr = $DB->DataSet('SELECT name FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ' . $kingdom_id . ' LIMIT 1');
        $kingdom_name = ($kr && $kr->Next()) ? $kr->name : '';

        $questions = Ork3::$Lib->qualtest->getAllQuestions($kingdom_id, $test_type);
        $config    = Ork3::$Lib->qualtest->getConfig($kingdom_id, $test_type);

        $this->data['KingdomId']   = $kingdom_id;
        $this->data['KingdomName'] = $kingdom_name;
        $this->data['TestType']    = $test_type;
        $this->data['Questions']   = $questions;
        $this->data['Config']      = $config;
        $this->data['Uid']         = $uid;
    }

    // -----------------------------------------------------------------------
    // question — create or edit a question
    // Route: ?Route=QualTest/question/create/{kingdom_id}/{type}
    //        ?Route=QualTest/question/edit/{question_id}
    // -----------------------------------------------------------------------
    public function question($action = null) {
        // Router joins all segments: 'create/17/reeve' or 'edit/123'
        $parts  = explode('/', $action ?? '');
        $action = $parts[0] ?? null;
        $param1 = $parts[1] ?? null;
        $param2 = $parts[2] ?? null;

        $uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

        if ($action === 'edit') {
            $question_id = (int)preg_replace('/[^0-9]/', '', $param1 ?? '');
            if (!valid_id($question_id)) {
                $this->data['Error'] = 'Invalid question.';
                return;
            }
            $q = Ork3::$Lib->qualtest->getQuestion($question_id);
            if (!$q) {
                $this->data['Error'] = 'Question not found.';
                return;
            }
            if (!Ork3::$Lib->qualtest->canManage($uid, $q['KingdomId'])) {
                $this->data['Error'] = 'You do not have permission to edit this question.';
                return;
            }
            $this->data['Question']  = $q;
            $this->data['KingdomId'] = $q['KingdomId'];
            $this->data['TestType']  = $q['TestType'];
        } else {
            // create
            $kingdom_id = (int)preg_replace('/[^0-9]/', '', $param1 ?? '');
            $test_type  = ($param2 === 'corpora') ? 'corpora' : 'reeve';
            if (!valid_id($kingdom_id) || !Ork3::$Lib->qualtest->canManage($uid, $kingdom_id)) {
                $this->data['Error'] = 'Invalid kingdom or insufficient permissions.';
                return;
            }
            $this->data['Question']  = null;
            $this->data['KingdomId'] = $kingdom_id;
            $this->data['TestType']  = $test_type;
        }

        global $DB;
        $DB->Clear();
        $kr = $DB->DataSet('SELECT name FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ' . (int)$this->data['KingdomId'] . ' LIMIT 1');
        $this->data['KingdomName'] = ($kr && $kr->Next()) ? $kr->name : '';
        $this->data['Action'] = $action ?? 'create';
        $this->data['Uid']    = $uid;
    }

	// -----------------------------------------------------------------------
	// take — standalone test-taking page for a player
	// Route: ?Route=QualTest/take/{kingdom_id}/{type}
	// -----------------------------------------------------------------------
	public function take($action = null) {
		// Router joins all segments: '17/reeve'
		$parts      = explode('/', $action ?? '');
		$kingdom_id = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
		$test_type  = (($parts[1] ?? '') === 'corpora') ? 'corpora' : 'reeve';
		$uid        = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

		if (!valid_id($kingdom_id)) {
			$this->data['Error'] = 'Invalid kingdom.';
			return;
		}

		// Must be logged in to take a test
		if (!$uid) {
			$this->data['Error'] = 'You must be logged in to take a test.';
			return;
		}

		// Check test is enabled for this kingdom
		$kn_configs  = Common::get_configs($kingdom_id, CFG_KINGDOM);
		$config_key  = ($test_type === 'corpora') ? 'QualTestCorporaEnabled' : 'QualTestReeveEnabled';
		$is_enabled  = isset($kn_configs[$config_key]) ? (bool)(int)$kn_configs[$config_key]['Value'] : false;
		if (!$is_enabled) {
			$this->data['Error'] = 'This test is not currently enabled.';
			return;
		}

		global $DB;
		$DB->Clear();
		$kr           = $DB->DataSet('SELECT name FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ' . $kingdom_id . ' LIMIT 1');
		$kingdom_name = ($kr && $kr->Next()) ? $kr->name : '';

		// Fetch player results and config
		$player_results = Ork3::$Lib->qualtest->getPlayerResults($uid, $kingdom_id);
		$player_result  = $player_results[$test_type] ?? null;
		$config         = Ork3::$Lib->qualtest->getConfig($kingdom_id, $test_type);

		// Treat retake-only records (no actual passing result) as "not taken"
		if ($player_result && empty($player_result['QualResultId'])) {
			$player_result = null;
		}

		// Check retake limit
		$retake_blocked = false;
		$retake_count   = Ork3::$Lib->qualtest->getRetakeCount($uid, $kingdom_id, $test_type);
		if (($config['MaxRetakes'] ?? 0) > 0) {
			$retake_blocked = $retake_count >= $config['MaxRetakes'];
		}

		$test_label = ($test_type === 'corpora') ? 'Corpora Test' : "Reeve's Test";

		$this->template = 'QualTest_take.tpl';

		$this->data['KingdomId']      = $kingdom_id;
		$this->data['KingdomName']    = $kingdom_name;
		$this->data['TestType']       = $test_type;
		$this->data['TestLabel']      = $test_label;
		$this->data['Config']         = $config;
		$this->data['PlayerResult']   = $player_result;
		$this->data['RetakeBlocked']  = $retake_blocked;
		$this->data['RetakeCount']    = $retake_count;
		$this->data['Uid']            = $uid;
	}

}
