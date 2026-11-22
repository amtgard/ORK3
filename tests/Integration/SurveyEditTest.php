<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Survey "Update My Answers" (design 2026-11-22-survey-update-answers-design.md).
 *
 * A respondent who submitted while `allow_edit` was on may resubmit until the
 * survey closes. The response row is updated in place; consent, response_count
 * and bonus credit are untouched; the edit link (edit_key / edit_uuid /
 * edit_answers_json) is wiped at close or when the toggle goes off, and no
 * manager-facing read ever selects it.
 */
final class SurveyEditTest extends TestCase
{
    use SurveyOrgFixture;

    private int $k = 0;
    private int $park = 0;
    private int $owner = 0;

    protected function setUp(): void
    {
        $this->setUpFixture();
        $this->k = $this->kingdom('edit');
        $this->park = $this->park($this->k, 'edit');
        $this->owner = $this->player('editowner', $this->park, $this->k);
        $this->officer($this->owner, AUTH_KINGDOM, $this->k);
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
    }

    // ------------------------------------------------------------------
    // First submit
    // ------------------------------------------------------------------

    public function testEditLinksUseTheDedicatedSecretOnly(): void
    {
        $this->assertTrue(SurveyResponse::editingConfigured(), 'config.test.php sets SURVEY_EDIT_SECRET');
        $this->assertSame(
            hash_hmac('sha256', 'survey-edit:7:9', (string) constant('SURVEY_EDIT_SECRET')),
            SurveyResponse::editKey(7, 9),
            'keyed by SURVEY_EDIT_SECRET itself, not by anything derived from DB_PASSWORD'
        );
        [$sid] = $this->editSurvey(true);
        $this->assertTrue((new Survey())->get($sid)['EditAvailable']);
    }

    public function testFirstSubmitStoresEditKeyOnlyWhenAllowEdit(): void
    {
        [$off, $offQ, $offA] = $this->editSurvey(false);
        $p1 = $this->player('off', $this->park, $this->k);
        $r = $this->submit($off, $p1, [$offQ => $offA], 'full');
        $this->assertNull($r['EditUuid']);
        $this->assertFalse($r['Edited']);
        $row = $this->responseRow($off);
        $this->assertNull($row['edit_key']);
        $this->assertNull($row['edit_uuid']);
        $this->assertNull($row['edit_answers_json']);

        [$on, $onQ, $onA] = $this->editSurvey(true);
        $p2 = $this->player('on', $this->park, $this->k);
        $r = $this->submit($on, $p2, [$onQ => $onA], 'anonymous');
        $row = $this->responseRow($on);
        $this->assertSame(SurveyResponse::editKey($on, $p2), $row['edit_key']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $row['edit_uuid']);
        $this->assertSame($row['edit_uuid'], $r['EditUuid']);
        $this->assertSame([(string) $onQ => $onA], json_decode((string) $row['edit_answers_json'], true));
        $this->assertNotSame(SurveyResponse::editKey($on, $p1), $row['edit_key'], 'the key is per player');
        $this->assertNotSame(SurveyResponse::editKey($off, $p2), $row['edit_key'], 'and per survey');

        // A builder's test response never carries an edit link.
        $t = (new SurveyResponse())->submit($on, $this->owner, [$onQ => $onA], 'full', 5, true);
        $this->assertSame(0, $t['Status'], (string) ($t['Error'] ?? ''));
        $this->assertNull($t['EditUuid']);
        $this->assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_response
                                                       WHERE survey_id = ' . $on . ' AND is_test = 1 AND edit_key IS NOT NULL'));
    }

    // ------------------------------------------------------------------
    // Editing
    // ------------------------------------------------------------------

    public function testEditReplacesAnswersInPlace(): void
    {
        [$sid, $q, $a, $b] = $this->editSurvey(true);
        $uid = $this->player('editor', $this->park, $this->k);
        $first = $this->submit($sid, $uid, [$q => $a], 'full');
        $responseId = (int) $first['ResponseId'];
        // Push the first submit into the past so an update is visible.
        $this->pdo->exec('UPDATE ' . DB_PREFIX . 'survey_response SET submitted_at = \'' . date('Y-m-d H:i:s', time() - 7200) . '\'
                          WHERE response_id = ' . $responseId);
        $before = $this->responseRow($sid);

        $sr = new SurveyResponse();
        $e = $sr->eligibility($this->row($sid), $uid);
        $this->assertSame(['eligible' => true, 'reason' => 'ok', 'editing' => true], $e);

        $def = $sr->definitionForRespondent($sid, $uid, false);
        $this->assertTrue($def['Eligible']);
        $this->assertSame(['uuid' => $first['EditUuid'], 'answers' => [$q => $a]], $def['Edit']);
        $this->assertNull($def['Draft']);
        $this->assertSame(1, $def['Survey']['allow_edit']);

        // An edit in progress autosaves like a first attempt.
        $this->assertTrue($sr->draftSave($sid, $uid, [$q => $b], 0, true)['Saved']);
        $this->assertSame([$q => $b], $sr->definitionForRespondent($sid, $uid, false)['Draft']['answers']);
        // An emptied answer travels as null so a reload does not refill it.
        $this->assertTrue($sr->draftSave($sid, $uid, [$q => null], 0, true)['Saved']);
        $this->assertSame([$q => null], $sr->definitionForRespondent($sid, $uid, false)['Draft']['answers']);
        $this->assertTrue($sr->draftSave($sid, $uid, [$q => $b], 0, true)['Saved']);

        // The consent sent with an edit is ignored: it stays what it was.
        $edit = $this->submit($sid, $uid, [$q => $b], 'anonymous');
        $this->assertTrue($edit['Edited']);
        $this->assertSame($responseId, (int) $edit['ResponseId']);
        $this->assertSame($first['EditUuid'], $edit['EditUuid']);
        $this->assertSame('full', $edit['Consent']);
        $this->assertSame('none', $edit['Credit']);

        $after = $this->responseRow($sid);
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_response WHERE survey_id = ' . $sid));
        $this->assertSame(1, (int) $this->row($sid)['response_count']);
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_participation WHERE survey_id = ' . $sid));
        $this->assertSame('full', $after['consent']);
        $this->assertSame($before['mundane_id'], $after['mundane_id']);
        $this->assertSame($before['duration_seconds'], $after['duration_seconds']);
        $this->assertSame($before['submitted_at'], $after['submitted_at'], 'submitted_at keeps the first submit: bonus credit is judged and dated from it');
        $this->assertSame([$b], $this->answerOptions($responseId));
        $this->assertSame([(string) $q => $b], json_decode((string) $after['edit_answers_json'], true));
        $this->assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_draft WHERE survey_id = ' . $sid), 'draft deleted on resubmit');
        $this->assertSame(['uuid' => $first['EditUuid'], 'answers' => [$q => $b]], $sr->definitionForRespondent($sid, $uid, false)['Edit']);
    }

    public function testEditRefusedWhenTheSurveyClosedUnderneathIt(): void
    {
        [$sid, $q, $a, $b] = $this->editSurvey(true);
        $uid = $this->player('closer', $this->park, $this->k);
        $first = $this->submit($sid, $uid, [$q => $a], 'full');
        $responseId = (int) $first['ResponseId'];
        $staleOpenRow = $this->row($sid);

        // Survey::setStatus commits the status BEFORE it sweeps: model the
        // window where the close has landed but the edit link still exists.
        $this->pdo->exec('UPDATE ' . DB_PREFIX . 'survey SET status = \'closed\' WHERE survey_id = ' . $sid);

        $sr = new SurveyResponse();
        $rows = [['question_id' => $q, 'option_id' => $b]];
        $m = new \ReflectionMethod(SurveyResponse::class, 'submitEdit');
        $m->setAccessible(true);
        $r = $m->invoke($sr, $staleOpenRow, $uid, [$q => $b], $rows);

        $this->assertSame(1, $r['Status']);
        $this->assertSame('closed', $r['Reason']);
        $this->assertSame([$a], $this->answerOptions($responseId), 'answers untouched after close');
    }

    public function testStaleFirstAttemptDraftAfterSubmitIsDropped(): void
    {
        $sid = $this->openSurvey($this->owner, 'kingdom', $this->k);
        $this->assertSame(0, $this->survey()->update($sid, ['AllowEdit' => 1])['Status']);
        // A second question, so a partial draft can leave one out.
        $page = (int) $this->scalar('SELECT page_id FROM ' . DB_PREFIX . 'survey_page WHERE survey_id = ' . $sid . ' ORDER BY page_id LIMIT 1');
        $this->pdo->exec('INSERT INTO ' . DB_PREFIX . 'survey_question (survey_id, page_id, sort_order, type, prompt, required, created_at, updated_at)
                          VALUES (' . $sid . ', ' . $page . ', 9, \'short_text\', \'Anything else?\', 0, NOW(), NOW())');
        $q2 = (int) $this->pdo->lastInsertId();
        $q = (int) $this->scalar('SELECT question_id FROM ' . DB_PREFIX . 'survey_question WHERE survey_id = ' . $sid . ' ORDER BY question_id LIMIT 1');
        $a = (int) $this->scalar('SELECT option_id FROM ' . DB_PREFIX . 'survey_option WHERE question_id = ' . $q . ' ORDER BY sort_order, option_id LIMIT 1');
        $uid = $this->player('stale', $this->park, $this->k);
        $this->submit($sid, $uid, [$q => $a, $q2 => 'hello'], 'full');

        $sr = new SurveyResponse();
        // The first attempt's autosave lands after its own submit: no flag, partial answers.
        $stale = $sr->draftSave($sid, $uid, [$q => $a], 0);
        $this->assertSame(0, $stale['Status']);
        $this->assertFalse($stale['Saved']);
        $def = $sr->definitionForRespondent($sid, $uid, false);
        $this->assertNull($def['Draft']);
        $this->assertSame([$q => $a, $q2 => 'hello'], $def['Edit']['answers']);
        $this->assertFalse($def['Survey']['credit_available'], 'an edit never offers a credit');

        // The runner in edit mode sends the flag: that draft is kept.
        $this->assertTrue($sr->draftSave($sid, $uid, [$q2 => 'changed'], 0, true)['Saved']);
        $def = $sr->definitionForRespondent($sid, $uid, false);
        $this->assertSame([$q2 => 'changed'], $def['Draft']['answers']);
        $this->assertSame([$q => $a, $q2 => 'hello'], $def['Edit']['answers'], 'the sent answers stay intact under a draft');
    }

    public function testAnonymousEditKeepsScrubbedFields(): void
    {
        [$sid, $q, $a, $b] = $this->editSurvey(true);
        $uid = $this->player('anon', $this->park, $this->k);
        $first = $this->submit($sid, $uid, [$q => $a], 'anonymous');
        $yesterday = date('Y-m-d', time() - 86400) . ' 00:00:00';
        $this->pdo->exec('UPDATE ' . DB_PREFIX . 'survey_response SET submitted_at = \'' . $yesterday . '\'
                          WHERE response_id = ' . (int) $first['ResponseId']);
        $before = $this->responseRow($sid);
        $this->assertNull($before['mundane_id']);

        $edit = $this->submit($sid, $uid, [$q => $b], 'full');
        $this->assertTrue($edit['Edited']);
        $this->assertSame('anonymous', $edit['Consent']);

        $after = $this->responseRow($sid);
        foreach (['consent', 'mundane_id', 'kingdom_id', 'park_id', 'tenure_months', 'started_at', 'submitted_at', 'duration_seconds', 'credit_notice'] as $col) {
            $this->assertSame($before[$col], $after[$col], $col . ' unchanged by an anonymous edit');
        }
        $this->assertSame($yesterday, $after['submitted_at']);
        $this->assertSame([$b], $this->answerOptions((int) $first['ResponseId']));
    }

    public function testResubmitTwiceKeepsOneResponse(): void
    {
        [$sid, $q, $a, $b] = $this->editSurvey(true);
        $uid = $this->player('twice', $this->park, $this->k);
        $first = $this->submit($sid, $uid, [$q => $a], 'partial');
        $this->submit($sid, $uid, [$q => $b], 'partial');
        $third = $this->submit($sid, $uid, [$q => $a], 'partial');
        $this->assertTrue($third['Edited']);

        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_response WHERE survey_id = ' . $sid));
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_participation WHERE survey_id = ' . $sid));
        $this->assertSame(1, (int) $this->row($sid)['response_count']);
        $this->assertSame([$a], $this->answerOptions((int) $first['ResponseId']));
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_answer WHERE response_id = ' . (int) $first['ResponseId']));
    }

    public function testInvalidEditLeavesTheStoredAnswersAlone(): void
    {
        [$sid, $q, $a] = $this->editSurvey(true);
        $uid = $this->player('invalid', $this->park, $this->k);
        $first = $this->submit($sid, $uid, [$q => $a], 'full');
        $bad = (new SurveyResponse())->submit($sid, $uid, [$q => 999999999], 'full', 5, false);
        $this->assertSame(1, $bad['Status']);
        $this->assertSame([$a], $this->answerOptions((int) $first['ResponseId']));
    }

    // ------------------------------------------------------------------
    // When editing is not (or no longer) possible
    // ------------------------------------------------------------------

    public function testEditRefusedAfterCloseAtAndKeysSwept(): void
    {
        [$sid, $q, $a, $b] = $this->editSurvey(true);
        $uid = $this->player('late', $this->park, $this->k);
        $first = $this->submit($sid, $uid, [$q => $a], 'full');
        $this->assertNotNull($this->responseRow($sid)['edit_key']);

        // Closed by date: status is still 'open'.
        $this->pdo->exec('UPDATE ' . DB_PREFIX . 'survey SET close_at = \'' . date('Y-m-d H:i:s', time() - 60) . '\' WHERE survey_id = ' . $sid);

        $sr = new SurveyResponse();
        $this->assertNull($sr->editableResponse($this->row($sid), $uid));
        $refused = $sr->submit($sid, $uid, [$q => $b], 'full', 5, false);
        $this->assertSame(1, $refused['Status']);
        $this->assertSame('closed', $refused['Reason']);
        $this->assertSame([$a], $this->answerOptions((int) $first['ResponseId']));
        $this->assertEditColumnsWiped($sid);
    }

    public function testRespondentReadSweepsADateClosedSurvey(): void
    {
        [$sid, $q, $a] = $this->editSurvey(true);
        $uid = $this->player('read', $this->park, $this->k);
        $this->submit($sid, $uid, [$q => $a], 'full');
        $this->pdo->exec('UPDATE ' . DB_PREFIX . 'survey SET close_at = \'' . date('Y-m-d H:i:s', time() - 60) . '\' WHERE survey_id = ' . $sid);

        $def = (new SurveyResponse())->definitionForRespondent($sid, $uid, false);
        $this->assertFalse($def['Eligible']);
        $this->assertSame('closed', $def['Reason']);
        $this->assertNull($def['Edit']);
        $this->assertEditColumnsWiped($sid);
    }

    public function testResultsReadSweepsADateClosedSurvey(): void
    {
        [$sid, $q, $a] = $this->editSurvey(true);
        $this->submit($sid, $this->player('res', $this->park, $this->k), [$q => $a], 'full');
        $this->pdo->exec('UPDATE ' . DB_PREFIX . 'survey SET close_at = \'' . date('Y-m-d H:i:s', time() - 60) . '\' WHERE survey_id = ' . $sid);

        (new SurveyReport())->resultsFor($sid, [], ['level' => 'manage']);
        $this->assertEditColumnsWiped($sid);
    }

    public function testLegacyResponseWithoutKeyIsCompleted(): void
    {
        [$sid, $q, $a, $b] = $this->editSurvey(false);
        $uid = $this->player('legacy', $this->park, $this->k);
        $first = $this->submit($sid, $uid, [$q => $a], 'full');
        $this->assertSame(0, $this->survey()->update($sid, ['AllowEdit' => 1])['Status']);
        $this->assertSame(1, (int) $this->row($sid)['allow_edit']);

        $sr = new SurveyResponse();
        $this->assertSame('completed', $sr->eligibility($this->row($sid), $uid)['reason']);
        $this->assertNull($sr->editableResponse($this->row($sid), $uid));
        $def = $sr->definitionForRespondent($sid, $uid, false);
        $this->assertFalse($def['Eligible']);
        $this->assertNull($def['Edit']);
        $again = $sr->submit($sid, $uid, [$q => $b], 'full', 5, false);
        $this->assertSame('completed', $again['Reason']);
        $this->assertSame([$a], $this->answerOptions((int) $first['ResponseId']));
    }

    public function testAllowEditOffWipesKeys(): void
    {
        [$sid, $q, $a] = $this->editSurvey(true);
        $uid = $this->player('toggle', $this->park, $this->k);
        $other = $this->editSurvey(true);
        $this->submit($sid, $uid, [$q => $a], 'full');
        $this->submit($other[0], $uid, [$other[1] => $other[2]], 'full');

        $this->assertSame(0, $this->survey()->update($sid, ['AllowEdit' => 0])['Status']);
        $this->assertEditColumnsWiped($sid);
        $this->assertNotNull($this->responseRow($other[0])['edit_key'], 'another survey keeps its links');
        $this->assertSame('completed', (new SurveyResponse())->eligibility($this->row($sid), $uid)['reason']);

        // Switching it back on does not resurrect the link.
        $this->assertSame(0, $this->survey()->update($sid, ['AllowEdit' => 1])['Status']);
        $this->assertSame('completed', (new SurveyResponse())->eligibility($this->row($sid), $uid)['reason']);
    }

    public function testCloseStatusWipesKeys(): void
    {
        [$sid, $q, $a] = $this->editSurvey(true);
        $uid = $this->player('closer', $this->park, $this->k);
        $this->submit($sid, $uid, [$q => $a], 'full');

        $this->assertSame(0, $this->survey()->setStatus($sid, 'closed')['Status']);
        $this->assertEditColumnsWiped($sid);

        $this->assertSame(0, $this->survey()->setStatus($sid, 'open')['Status']);
        $this->assertSame('completed', (new SurveyResponse())->eligibility($this->row($sid), $uid)['reason']);
    }

    public function testSweepWithoutASurveyIdCoversEveryClosedSurvey(): void
    {
        [$closed, $q, $a] = $this->editSurvey(true);
        [$live, $lq, $la] = $this->editSurvey(true);
        $uid = $this->player('global', $this->park, $this->k);
        $this->submit($closed, $uid, [$q => $a], 'full');
        $this->submit($live, $uid, [$lq => $la], 'full');
        $this->pdo->exec('UPDATE ' . DB_PREFIX . 'survey SET close_at = \'' . date('Y-m-d H:i:s', time() - 60) . '\' WHERE survey_id = ' . $closed);

        (new SurveyResponse())->sweepEditKeys();
        $this->assertEditColumnsWiped($closed);
        $this->assertNotNull($this->responseRow($live)['edit_key']);
    }

    public function testAvailableSurveysReadSweepsDateClosedSurveys(): void
    {
        [$closed, $q, $a] = $this->editSurvey(true);
        [$live, $lq, $la] = $this->editSurvey(true);
        $uid = $this->player('avail', $this->park, $this->k);
        $this->submit($closed, $uid, [$q => $a], 'full');
        $this->submit($live, $uid, [$lq => $la], 'full');
        $this->pdo->exec('UPDATE ' . DB_PREFIX . 'survey SET close_at = \'' . date('Y-m-d H:i:s', time() - 60) . '\' WHERE survey_id = ' . $closed);

        // Anyone's Available Surveys read sweeps every closed survey, not only theirs.
        (new SurveyResponse())->availableAndBannerFor($this->player('bystander', $this->park, $this->k));
        $this->assertEditColumnsWiped($closed);
        $this->assertNotNull($this->responseRow($live)['edit_key']);
    }

    public function testCronSweepsEditKeys(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/survey-credit-sweep.php');
        $this->assertMatchesRegularExpression('/new SurveyResponse\(\)\)->sweepEditKeys\(\)/', $src);
    }

    public function testForeignUuidIgnored(): void
    {
        [$sid, $q, $a, $b] = $this->editSurvey(true);
        $alice = $this->player('alice', $this->park, $this->k);
        $bob = $this->player('bob', $this->park, $this->k);
        $ra = $this->submit($sid, $alice, [$q => $a], 'full');

        $sr = new SurveyResponse();
        $def = $sr->definitionForRespondent($sid, $bob, false);
        $this->assertTrue($def['Eligible']);
        $this->assertNull($def['Edit']);
        $this->assertStringNotContainsString((string) $ra['EditUuid'], (string) json_encode($def));
        $this->assertSame(['eligible' => true, 'reason' => 'ok'], $sr->eligibility($this->row($sid), $bob), 'a first attempt is not an edit');

        $rb = $this->submit($sid, $bob, [$q => $b], 'full');
        $this->assertFalse($rb['Edited']);
        $this->assertNotSame($ra['EditUuid'], $rb['EditUuid']);
        $this->assertSame(['uuid' => $rb['EditUuid'], 'answers' => [$q => $b]], $sr->definitionForRespondent($sid, $bob, false)['Edit']);
        $this->assertSame(['uuid' => $ra['EditUuid'], 'answers' => [$q => $a]], $sr->definitionForRespondent($sid, $alice, false)['Edit']);
        $this->assertSame([$a], $this->answerOptions((int) $ra['ResponseId']), "Bob's submit never touches Alice's response");
    }

    public function testEditableSurveyIsListedAsEditableButNotBannered(): void
    {
        [$sid, $q, $a] = $this->editSurvey(true);
        [$plain, $pq, $pa] = $this->editSurvey(false);
        $uid = $this->player('cards', $this->park, $this->k);
        $this->assertSame(false, $this->widgetFor($uid, $sid)['editable']);

        $this->submit($sid, $uid, [$q => $a], 'full');
        $this->submit($plain, $uid, [$pq => $pa], 'full');

        $row = $this->widgetFor($uid, $sid);
        $this->assertNotNull($row, 'an editable response keeps the survey listed');
        $this->assertTrue($row['editable']);
        $this->assertNull($this->widgetFor($uid, $plain), 'a completed, non-editable survey drops off');

        $both = (new SurveyResponse())->availableAndBannerFor($uid);
        $this->assertTrue($both['banner'] === null || (int) $both['banner']['survey_id'] !== $sid, 'an answered survey is never promoted again');
        $this->assertNull((new SurveyResponse())->bannerFor($uid));
    }

    // ------------------------------------------------------------------
    // Privacy and credit
    // ------------------------------------------------------------------

    public function testResultsAndExportNeverSelectEditColumns(): void
    {
        foreach (['class.SurveyReport.php', 'class.SurveyCredit.php', 'class.Survey.php'] as $file) {
            $src = (string) file_get_contents(DIR_LIB . 'ork3/' . $file);
            $this->assertDoesNotMatchRegularExpression('/edit_key|edit_uuid|edit_answers_json/', $src, $file . ' must not read the edit link');
        }
        $report = (string) file_get_contents(DIR_LIB . 'ork3/class.SurveyReport.php');
        $this->assertDoesNotMatchRegularExpression('/\br\.\*/', $report);
        $this->assertDoesNotMatchRegularExpression("/SELECT\\s+\\*\\s+FROM\\s+'\\s*\\.\\s*DB_PREFIX\\s*\\.\\s*'survey_response/", $report);
    }

    public function testNoSecondCreditOnEdit(): void
    {
        [$sid, $q, $a, $b] = $this->editSurvey(true);
        $this->assertSame(0, (new SurveyCredit())->enable($this->owner, $sid, ['type' => 'kingdom', 'id' => $this->k])['Status']);
        $uid = $this->player('credit', $this->park, $this->k);

        $first = (new SurveyResponse())->submit($sid, $uid, [$q => $a], 'full', 30, false, true);
        $this->assertSame(0, $first['Status'], (string) ($first['Error'] ?? ''));
        $this->assertSame('granted', $first['Credit']);

        $edit = (new SurveyResponse())->submit($sid, $uid, [$q => $b], 'full', 30, false, true);
        $this->assertSame(0, $edit['Status'], (string) ($edit['Error'] ?? ''));
        $this->assertTrue($edit['Edited']);
        $this->assertSame('none', $edit['Credit']);
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_credit_grant WHERE survey_id = ' . $sid));
        $this->assertSame('1', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'bonus_credit WHERE mundane_id = ' . $uid));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * An open kingdom survey with one optional single-choice question, with
     * allow_edit set through Survey::update().
     *
     * @return array{0:int,1:int,2:int,3:int} survey id, question id, option A, option B
     */
    private function editSurvey(bool $allowEdit): array
    {
        $sid = $this->openSurvey($this->owner, 'kingdom', $this->k);
        $this->assertSame(0, $this->survey()->update($sid, ['AllowEdit' => $allowEdit ? 1 : 0])['Status']);
        $this->assertSame($allowEdit ? 1 : 0, (int) $this->row($sid)['allow_edit']);
        $q = (int) $this->scalar('SELECT question_id FROM ' . DB_PREFIX . 'survey_question WHERE survey_id = ' . $sid . ' LIMIT 1');
        $opts = $this->pdo->query('SELECT option_id FROM ' . DB_PREFIX . 'survey_option WHERE question_id = ' . $q . ' ORDER BY sort_order, option_id')
            ->fetchAll(PDO::FETCH_COLUMN, 0);
        $this->assertGreaterThanOrEqual(2, count($opts));
        return [$sid, $q, (int) $opts[0], (int) $opts[1]];
    }

    private function survey(): Survey
    {
        $s = new Survey();
        $s->setActor($this->owner);
        return $s;
    }

    /** @param array<int, mixed> $answers */
    private function submit(int $sid, int $uid, array $answers, string $consent): array
    {
        $r = (new SurveyResponse())->submit($sid, $uid, $answers, $consent, 30, false);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        return $r;
    }

    /** The survey's single non-test response row. */
    private function responseRow(int $sid): array
    {
        $rows = $this->pdo->query('SELECT * FROM ' . DB_PREFIX . 'survey_response WHERE survey_id = ' . $sid . ' AND is_test = 0')
            ->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        return $rows[0];
    }

    /** @return list<int> */
    private function answerOptions(int $responseId): array
    {
        return array_map('intval', $this->pdo->query('SELECT option_id FROM ' . DB_PREFIX . 'survey_answer
                                                      WHERE response_id = ' . $responseId . ' ORDER BY answer_id')
            ->fetchAll(PDO::FETCH_COLUMN, 0));
    }

    private function assertEditColumnsWiped(int $sid): void
    {
        $this->assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_response
                                                       WHERE survey_id = ' . $sid . '
                                                         AND (edit_key IS NOT NULL OR edit_uuid IS NOT NULL OR edit_answers_json IS NOT NULL)'));
    }

    private function widgetFor(int $uid, int $sid): ?array
    {
        foreach ((new SurveyResponse())->availableFor($uid) as $row) {
            if ((int) $row['survey_id'] === $sid) {
                return $row;
            }
        }
        return null;
    }
}
