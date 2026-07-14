<?php

class Controller_QualTest extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);
    }

    // -----------------------------------------------------------------------
    // manage — admin overview for a kingdom
    // Route: ?Route=QualTest/manage/{kingdom_id}
    // -----------------------------------------------------------------------
    public function manage($kingdom_id = null)
    {
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

        $kingdom_name = Ork3::$Lib->qualtest->getKingdomName($kingdom_id);

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

        // The rules/corpora version belongs to the published VERSION, not to these settings —
        // settings shows it read-only. Two editable copies of one fact drift, and the config
        // copy used to be the one that won on a player's permanent attempt record.
        $this->data['ReeveLiveSet']   = Ork3::$Lib->qualtest->getPublishedSet($kingdom_id, 'reeve');
        $this->data['CorporaLiveSet'] = Ork3::$Lib->qualtest->getPublishedSet($kingdom_id, 'corpora');
    }

    // -----------------------------------------------------------------------
    // questions — question list for a kingdom+type
    // Route: ?Route=QualTest/questions/{kingdom_id}/{type}
    // -----------------------------------------------------------------------
    public function questions($action = null)
    {
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

        $kingdom_name = Ork3::$Lib->qualtest->getKingdomName($kingdom_id);

        $questions = Ork3::$Lib->qualtest->getAllQuestions($kingdom_id, $test_type);
        $config    = Ork3::$Lib->qualtest->getConfig($kingdom_id, $test_type);

        // Question-set versioning. The live test draws only from the PUBLISHED set, so a
        // draft can be built without touching it. New questions (add / bulk import /
        // library) land in whichever set the admin is working in — the draft when one
        // exists, otherwise the live set.
        $published = Ork3::$Lib->qualtest->getPublishedSet($kingdom_id, $test_type);
        $draft     = Ork3::$Lib->qualtest->getDraftSet($kingdom_id, $test_type);
        $target    = $draft ? (int)$draft['SetId'] : ($published ? (int)$published['SetId'] : 0);

        $this->data['KingdomId']    = $kingdom_id;
        $this->data['KingdomName']  = $kingdom_name;
        $this->data['TestType']     = $test_type;
        $this->data['Questions']    = $questions;
        $this->data['Config']       = $config;
        $this->data['Uid']          = $uid;
        $this->data['Sets']         = Ork3::$Lib->qualtest->getSets($kingdom_id, $test_type);
        $this->data['PublishedSet'] = $published;
        $this->data['DraftSet']     = $draft;
        $this->data['TargetSetId']  = $target;

        // A published set is NOT actually takeable unless the kingdom has switched the
        // test on (QualTest*Enabled — kingdom config, gated by kingdom authority). A GMR
        // can manage every part of the test but CANNOT flip that switch, so "Published"
        // would otherwise be a lie: the UI has to say the test is still off.
        $_key   = ($test_type === 'corpora') ? 'QualTestCorporaEnabled' : 'QualTestReeveEnabled';
        $_knCfg = Common::get_configs($kingdom_id, CFG_KINGDOM);
        $this->data['TestEnabled'] = isset($_knCfg[$_key]) ? (bool)(int)$_knCfg[$_key]['Value'] : false;
    }

    // -----------------------------------------------------------------------
    // question — create or edit a question
    // Route: ?Route=QualTest/question/create/{kingdom_id}/{type}
    //        ?Route=QualTest/question/edit/{question_id}
    // -----------------------------------------------------------------------
    public function question($action = null)
    {
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

        // Set context. A NEW question joins the set being worked on (the draft when one
        // exists). EDITING never changes membership — but if the question is in the LIVE
        // set the edit changes the running test immediately, so the form warns about it.
        $_kid  = (int)$this->data['KingdomId'];
        $_type = $this->data['TestType'];
        $_pub  = Ork3::$Lib->qualtest->getPublishedSet($_kid, $_type);
        $_drf  = Ork3::$Lib->qualtest->getDraftSet($_kid, $_type);
        $this->data['TargetSetId'] = $_drf ? (int)$_drf['SetId'] : ($_pub ? (int)$_pub['SetId'] : 0);
        $this->data['TargetSetName'] = $_drf ? $_drf['Name'] : ($_pub ? $_pub['Name'] : '');
        $this->data['TargetIsDraft'] = (bool)$_drf;

        $this->data['EditingLiveQuestion'] = false;
        if (($action ?? '') === 'edit' && $_pub && !empty($this->data['Question'])) {
            $bank = Ork3::$Lib->qualtest->getAllQuestions($_kid, $_type);
            foreach ($bank as $bq) {
                if ((int)$bq['QualQuestionId'] === (int)$this->data['Question']['QualQuestionId']) {
                    $this->data['EditingLiveQuestion'] = !empty($bq['InLive']);
                    break;
                }
            }
        }

        $this->data['KingdomName'] = Ork3::$Lib->qualtest->getKingdomName($_kid);
        $this->data['Action'] = $action ?? 'create';
        $this->data['Uid']    = $uid;
    }

    // -----------------------------------------------------------------------
    // take — standalone test-taking page for a player
    // Route: ?Route=QualTest/take/{kingdom_id}/{type}
    // -----------------------------------------------------------------------
    public function take($action = null)
    {
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

        $kingdom_name = Ork3::$Lib->qualtest->getKingdomName($kingdom_id);

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

        // The "Based on ..." footer must name the version the player is ACTUALLY sitting, which
        // is the published set — not the kingdom-config copy, which publishSet() only mirrors
        // and which is stale for any kingdom that never saved its settings.
        $_liveSet = Ork3::$Lib->qualtest->getPublishedSet($kingdom_id, $test_type);
        if ($_liveSet && trim((string)$_liveSet['RulesVersion']) !== '') {
            $config['RulesVersion'] = $_liveSet['RulesVersion'];
        }

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
        // Durable attempt history for this player+test (pass and fail), newest
        // first — powers the "Your Test History" review list on the take page.
        $this->data['PlayerAttempts'] = Ork3::$Lib->qualtest->getPlayerAttempts($uid, $kingdom_id, $test_type);
    }

}
