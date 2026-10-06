<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * End-to-end integration coverage for the survey module (plan Task 7).
 *
 * Exercises the three domain classes against the ork_test sandbox in the order a
 * real survey lives: create -> build -> open (structure lock) -> submit under the
 * three consent tiers -> report -> clone. Everything this class writes is tracked
 * and removed in tearDown; it never touches rows it did not create.
 */
final class SurveyTest extends TestCase
{
    use SurveyOrgFixture;

    private const MARKER = 'T07SURVEY';

    private Survey $survey;

    private SurveyResponse $response;

    private SurveyReport $report;

    /** @var list<int> */
    private array $surveyIds = [];

    private int $kingdomId = 0;

    private int $parkId = 0;

    private int $otherKingdomId = 0;

    /** @var array<string, int> */
    private array $players = [];

    private int $officerId = 0;

    private int $editorId = 0;

    private int $outsiderId = 0;

    protected function setUp(): void
    {
        $this->setUpFixture();

        $this->survey = new Survey();
        $this->response = new SurveyResponse();
        $this->report = new SurveyReport();

        // Own the whole org tree so the fixture never depends on seeded data and
        // the "other kingdom" is genuinely unreachable through the authority walk
        // (both are root kingdoms: parent_kingdom_id = 0).
        $this->kingdomId = $this->kingdom('home');
        $this->otherKingdomId = $this->kingdom('away');
        $this->parkId = $this->park($this->kingdomId, 'home');

        $this->officerId = $this->player('officer', $this->parkId, $this->kingdomId);
        $this->officer($this->officerId, AUTH_KINGDOM, $this->kingdomId, AUTH_CREATE);

        $this->editorId = $this->player('editor', $this->parkId, $this->kingdomId);
        $this->officer($this->editorId, AUTH_KINGDOM, $this->kingdomId, AUTH_EDIT);

        $this->outsiderId = $this->player('outsider', $this->parkId, $this->kingdomId);
        $this->officer($this->outsiderId, AUTH_KINGDOM, $this->otherKingdomId, AUTH_CREATE);

        foreach (['p1', 'p2', 'p3'] as $key) {
            $this->players[$key] = $this->player($key, $this->parkId, $this->kingdomId);
        }
    }

    protected function tearDown(): void
    {
        // Surveys go first through deleteSurvey(), which also removes their
        // image rows and files; the fixture then removes the org tree.
        foreach ($this->surveyIds as $id) {
            $this->deleteSurvey($id);
        }
        $this->surveyIds = [];

        // Authorizations the code under test granted to fixture players.
        foreach ($this->fx['mundane'] as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'authorization WHERE mundane_id = ' . (int) $id);
        }

        $this->tearDownFixture();
    }

    // ------------------------------------------------------------------
    // Case 1 — creation authority
    // ------------------------------------------------------------------

    public function testCreateByKingdomOfficerSucceeds(): void
    {
        $r = $this->survey->create($this->officerId, 'kingdom', $this->kingdomId, self::MARKER . ' Create');
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertGreaterThan(0, (int) $r['SurveyId']);
        $this->surveyIds[] = (int) $r['SurveyId'];

        // Page 1 is created with the survey.
        $pages = $this->pdo->query(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_page WHERE survey_id = ' . (int) $r['SurveyId']
        )->fetchColumn();
        $this->assertSame(1, (int) $pages);

        $row = $this->survey->getRow((int) $r['SurveyId']);
        $this->assertNotNull($row);
        $this->assertSame('draft', (string) $row['status']);
        $this->assertTrue($this->survey->canManage($this->officerId, $row));
    }

    public function testEditOnlyOfficerCannotCreate(): void
    {
        $this->assertFalse($this->survey->canCreate($this->editorId, 'kingdom', $this->kingdomId));

        $r = $this->survey->create($this->editorId, 'kingdom', $this->kingdomId, self::MARKER . ' Denied');
        $this->assertSame(3, $r['Status']);
        if (isset($r['SurveyId'])) {
            $this->surveyIds[] = (int) $r['SurveyId'];
            $this->fail('An EDIT-only officer must not be able to create a survey.');
        }
    }

    // ------------------------------------------------------------------
    // Case 2 — building and the structure lock
    // ------------------------------------------------------------------

    public function testBuildOpenAndStructureLock(): void
    {
        $ctx = $this->buildSurvey();
        $surveyId = $ctx['survey_id'];

        // Two pages, seven questions, the single question carrying an "other" option.
        $built = $this->survey->get($surveyId);
        $this->assertSame(0, $built['Status']);
        $this->assertCount(2, $built['Pages']);
        $this->assertCount(7, $built['Questions']);
        $this->assertFalse($built['Locked']);

        $open = $this->survey->setStatus($surveyId, 'open');
        $this->assertSame(0, $open['Status'], (string) ($open['Error'] ?? ''));
        $this->assertSame('open', (string) $open['Survey']['status']);
        $this->assertTrue($this->survey->isStructureLocked($open['Survey']));

        // Structure is locked once opened...
        $locked = $this->survey->questionAdd($surveyId, $ctx['page1'], 'short_text', null);
        $this->assertSame(1, $locked['Status']);
        $this->assertSame(Survey::LOCKED_ERROR, $locked['Error']);

        // ...but copy stays editable.
        $copy = $this->survey->questionUpdate($ctx['q_single'], ['Prompt' => self::MARKER . ' reworded prompt']);
        $this->assertSame(0, $copy['Status'], (string) ($copy['Error'] ?? ''));
        $this->assertSame(self::MARKER . ' reworded prompt', (string) $copy['Question']['prompt']);
    }

    public function testOpeningASurveyWithNoQuestionsFails(): void
    {
        $r = $this->survey->create($this->officerId, 'kingdom', $this->kingdomId, self::MARKER . ' Empty');
        $this->assertSame(0, $r['Status']);
        $surveyId = (int) $r['SurveyId'];
        $this->surveyIds[] = $surveyId;

        $open = $this->survey->setStatus($surveyId, 'open');
        $this->assertSame(1, $open['Status']);
        $this->assertNotSame('', (string) $open['Error']);
        $this->assertSame('draft', (string) $this->survey->getRow($surveyId)['status']);
    }

    /**
     * setStatus() follows the transition map: nothing returns to draft once
     * opened, and archived leaves only through the builder's Reopen (-> open).
     */
    public function testStatusTransitionsFollowTheLifecycle(): void
    {
        $ctx = $this->buildSurvey();
        $sid = $ctx['survey_id'];
        $status = fn () => (string) $this->survey->getRow($sid)['status'];

        $this->assertSame(1, $this->survey->setStatus($sid, 'closed')['Status'], 'draft -> closed');
        $this->assertSame(0, $this->survey->setStatus($sid, 'open')['Status']);
        $openedAt = (string) $this->survey->getRow($sid)['opened_at'];
        $this->submitThree($ctx);

        $back = $this->survey->setStatus($sid, 'draft');
        $this->assertSame(1, $back['Status']);
        $this->assertSame('This survey has been opened, so it cannot go back to draft.', $back['Error']);
        $this->assertSame('open', $status());
        $this->assertSame($openedAt, (string) $this->survey->getRow($sid)['opened_at']);

        $this->assertSame(1, $this->survey->setStatus($sid, 'open')['Status'], 'open -> open');
        $this->assertSame(0, $this->survey->setStatus($sid, 'closed')['Status']);
        $this->assertSame(1, $this->survey->setStatus($sid, 'draft')['Status'], 'closed -> draft');
        $this->assertSame(0, $this->survey->setStatus($sid, 'open')['Status'], 'closed -> open');
        $this->assertSame(0, $this->survey->setStatus($sid, 'archived')['Status'], 'open -> archived');

        foreach (['draft', 'closed', 'archived'] as $to) {
            $this->assertSame(1, $this->survey->setStatus($sid, $to)['Status'], 'archived -> ' . $to);
            $this->assertSame('archived', $status());
        }
        $this->assertSame(0, $this->survey->setStatus($sid, 'open')['Status'], 'archived -> open (Reopen)');
        $this->assertSame('open', $status());
    }

    /** Row stamps come from PHP's clock, the same one setStatus() stamps with. */
    public function testCreatedAtUsesThePhpClock(): void
    {
        $before = date('Y-m-d H:i:s');
        $r = $this->survey->create($this->officerId, 'kingdom', $this->kingdomId, self::MARKER . ' Clock');
        $after = date('Y-m-d H:i:s');
        $this->assertSame(0, $r['Status']);
        $this->surveyIds[] = (int) $r['SurveyId'];

        $row = $this->survey->getRow((int) $r['SurveyId']);
        $this->assertGreaterThanOrEqual($before, (string) $row['created_at']);
        $this->assertLessThanOrEqual($after, (string) $row['created_at']);
        $this->assertGreaterThanOrEqual($before, (string) $row['updated_at']);
        $this->assertLessThanOrEqual($after, (string) $row['updated_at']);
    }

    /**
     * questionUpdate(['Type' => …]) is what the builder's footer type picker calls
     * (plan Task 10 Step 2). The prompt survives, options survive where the new
     * type owns their role, and settings reset to the new type's defaults.
     */
    public function testRetypeKeepsThePromptAndReusesOptionsTheNewTypeOwns(): void
    {
        $ctx = $this->buildSurvey();
        $questionId = $ctx['q_single'];
        $before = $this->optionIds($questionId, 'choice');
        $this->assertCount(3, $before);

        // single -> multi: same role, so the option rows keep their ids (and any
        // answers already attached to them).
        $toMulti = $this->survey->questionUpdate($questionId, ['Type' => 'multi']);
        $this->assertSame(0, $toMulti['Status'], (string) ($toMulti['Error'] ?? ''));
        $this->assertSame('multi', (string) $toMulti['Question']['type']);
        $this->assertSame(self::MARKER . ' Which weapon style?', (string) $toMulti['Question']['prompt']);
        $this->assertSame($before, $this->optionIds($questionId, 'choice'));
        $this->assertSame(false, $toMulti['Question']['settings']['randomize']);
        $this->assertSame(0, (int) $toMulti['Question']['settings']['min_select']);
        $this->assertSame(0, (int) $toMulti['Question']['settings']['max_select']);

        // multi -> yesno: exactly two choices, relabelled, and the "other" flag
        // (which only single/multi/dropdown support) is cleared.
        $toYesNo = $this->survey->questionUpdate($questionId, ['Type' => 'yesno']);
        $this->assertSame(0, $toYesNo['Status'], (string) ($toYesNo['Error'] ?? ''));
        $labels = [];
        $others = [];
        foreach ($toYesNo['Question']['Options'] as $o) {
            $labels[] = (string) $o['label'];
            $others[] = (int) $o['is_other'];
        }
        $this->assertSame(['Yes', 'No'], $labels);
        $this->assertSame([0, 0], $others);

        // yesno -> matrix: 'choice' is not a matrix role, so those rows go and the
        // starter grid is seeded in their place.
        $toMatrix = $this->survey->questionUpdate($questionId, ['Type' => 'matrix']);
        $this->assertSame(0, $toMatrix['Status'], (string) ($toMatrix['Error'] ?? ''));
        $this->assertSame([], $this->optionIds($questionId, 'choice'));
        $this->assertCount(2, $this->optionIds($questionId, 'row'));
        $this->assertCount(3, $this->optionIds($questionId, 'column'));

        // matrix -> short_text: no options at all.
        $toText = $this->survey->questionUpdate($questionId, ['Type' => 'short_text']);
        $this->assertSame(0, $toText['Status'], (string) ($toText['Error'] ?? ''));
        $this->assertSame([], $this->optionIds($questionId, 'row'));
        $this->assertSame([], $this->optionIds($questionId, 'column'));
        $this->assertSame(200, (int) $toText['Question']['settings']['max_length']);
    }

    public function testRetypeAwayFromAChoiceTypeReleasesConditionsPointingAtIt(): void
    {
        $ctx = $this->buildSurvey();

        // The paragraph on page 2 is shown only when the page-1 single answers opt_a.
        $cond = $this->survey->questionUpdate($ctx['q_paragraph'], [
            'ShowIfQuestionId' => $ctx['q_single'],
            'ShowIfOptionId'   => $ctx['opt_a'],
        ]);
        $this->assertSame(0, $cond['Status'], (string) ($cond['Error'] ?? ''));
        $this->assertSame($ctx['q_single'], (int) $cond['Question']['show_if_question_id']);

        // A rating cannot be a show-if source, so the condition must let go.
        $retype = $this->survey->questionUpdate($ctx['q_single'], ['Type' => 'rating']);
        $this->assertSame(0, $retype['Status'], (string) ($retype['Error'] ?? ''));

        $row = $this->pdo->query(
            'SELECT show_if_question_id, show_if_option_id FROM ' . DB_PREFIX . 'survey_question
              WHERE question_id = ' . $ctx['q_paragraph']
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($row['show_if_question_id']);
        $this->assertNull($row['show_if_option_id']);
    }

    public function testRetypeIsRefusedOnALockedSurveyAndForAnUnknownType(): void
    {
        $ctx = $this->buildSurvey();

        $bogus = $this->survey->questionUpdate($ctx['q_single'], ['Type' => 'telepathy']);
        $this->assertSame(1, $bogus['Status']);
        $this->assertSame('That is not a question type.', $bogus['Error']);
        $this->assertSame('single', (string) $this->survey->get($ctx['survey_id'])['Questions'][0]['type']);

        $this->assertSame(0, $this->survey->setStatus($ctx['survey_id'], 'open')['Status']);

        $locked = $this->survey->questionUpdate($ctx['q_single'], ['Type' => 'dropdown']);
        $this->assertSame(1, $locked['Status']);
        $this->assertSame(Survey::LOCKED_ERROR, $locked['Error']);

        // Wording still saves on the same call path.
        $copy = $this->survey->questionUpdate($ctx['q_single'], ['Prompt' => self::MARKER . ' still editable']);
        $this->assertSame(0, $copy['Status'], (string) ($copy['Error'] ?? ''));
    }

    // ------------------------------------------------------------------
    // Pairwise (pairwise spec §1, §3)
    // ------------------------------------------------------------------

    /** @return array{survey_id:int, page:int, q:int, ids:list<int>} a draft survey with one pairwise question of $n options */
    private function buildPairwise(int $n, bool $required): array
    {
        $r = $this->survey->create($this->officerId, 'kingdom', $this->kingdomId, self::MARKER . ' Pairwise');
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $surveyId = (int) $r['SurveyId'];
        $this->surveyIds[] = $surveyId;
        $page = (int) $this->pdo->query(
            'SELECT page_id FROM ' . DB_PREFIX . 'survey_page WHERE survey_id = ' . $surveyId . ' ORDER BY sort_order LIMIT 1'
        )->fetchColumn();

        $q = $this->addQuestion($surveyId, $page, 'pairwise', 'Which is better?');
        $this->assertCount(3, $this->optionIds($q, 'choice'), 'a new pairwise question seeds three options');
        if ($required) {
            $this->assertSame(0, $this->survey->questionUpdate($q, ['Required' => 1])['Status']);
        }
        $labels = [];
        for ($i = 1; $i <= $n; $i++) {
            $labels[] = ['label' => 'Item ' . $i];
        }
        $set = $this->survey->optionSet($q, 'choice', $labels);
        $this->assertSame(0, $set['Status'], (string) ($set['Error'] ?? ''));

        return ['survey_id' => $surveyId, 'page' => $page, 'q' => $q, 'ids' => $this->optionIds($q, 'choice')];
    }

    /** @return list<array{a:int,b:int,w:int}> the first $count pairs of $ids, left winning */
    private function pairwiseAnswer(array $ids, int $count): array
    {
        $out = [];
        foreach ($ids as $i => $a) {
            foreach (array_slice($ids, $i + 1) as $b) {
                if (count($out) >= $count) {
                    return $out;
                }
                $out[] = ['a' => $a, 'b' => $b, 'w' => $a];
            }
        }
        return $out;
    }

    public function testPairwiseOptionSetRefusesDuplicateLabelsAndOther(): void
    {
        $ctx = $this->buildPairwise(3, false);

        $dup = $this->survey->optionSet($ctx['q'], 'choice', [['label' => 'Hawk'], ['label' => 'Owl'], ['label' => ' hawk ']]);
        $this->assertSame(1, $dup['Status']);
        $this->assertSame('“hawk” is listed twice.', $dup['Error']);

        $other = $this->survey->optionSet($ctx['q'], 'choice', [['label' => 'A'], ['label' => 'B'], ['label' => 'C', 'is_other' => 1]]);
        $this->assertSame(1, $other['Status']);
        $this->assertSame('A pairwise question cannot have an "other" option.', $other['Error']);

        $two = $this->survey->optionSet($ctx['q'], 'choice', [['label' => 'A'], ['label' => 'B']]);
        $this->assertSame(1, $two['Status'], 'pairwise needs at least three options');
    }

    public function testPairwiseRequiredGateOnSubmitAndStoredRows(): void
    {
        // 9 options = 36 matchups: gate 11.
        $ctx = $this->buildPairwise(9, true);
        $this->assertSame(0, $this->survey->setStatus($ctx['survey_id'], 'open')['Status']);

        $below = (new SurveyResponse())->submit(
            $ctx['survey_id'],
            $this->players['p1'],
            [$ctx['q'] => $this->pairwiseAnswer($ctx['ids'], 10)],
            'full',
            60,
            false
        );
        $this->assertSame(1, $below['Status']);
        $this->assertSame('Please complete at least 11 matchups to continue.', $below['Errors'][$ctx['q']] ?? null);

        $answer = $this->pairwiseAnswer($ctx['ids'], 11);
        $answer[1]['w'] = 0;                      // one tie
        $answer[2]['w'] = $answer[2]['b'];        // one right-side win
        $ok = (new SurveyResponse())->submit(
            $ctx['survey_id'],
            $this->players['p1'],
            [$ctx['q'] => $answer],
            'full',
            60,
            false
        );
        $this->assertSame(0, $ok['Status'], (string) ($ok['Error'] ?? ''));

        $rows = $this->pdo->query(
            'SELECT a.option_id, a.row_option_id, a.value_num FROM ' . DB_PREFIX . 'survey_answer a
               JOIN ' . DB_PREFIX . 'survey_response r ON r.response_id = a.response_id
              WHERE r.survey_id = ' . $ctx['survey_id'] . ' ORDER BY a.answer_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(11, $rows);
        $this->assertSame(
            [(int) $answer[0]['a'], (int) $answer[0]['b'], 1.0],
            [(int) $rows[0]['option_id'], (int) $rows[0]['row_option_id'], (float) $rows[0]['value_num']]
        );
        $this->assertSame(0.5, (float) $rows[1]['value_num']);
        $this->assertSame(0.0, (float) $rows[2]['value_num']);
    }

    public function testPairwiseDefinitionCarriesThePlan(): void
    {
        $ctx = $this->buildPairwise(12, false);
        $def = (new SurveyResponse())->definitionForRespondent($ctx['survey_id'], $this->officerId, true);
        $this->assertSame(0, $def['Status'], (string) ($def['Error'] ?? ''));
        $q = null;
        foreach ($def['Pages'] as $page) {
            foreach ($page['questions'] as $cand) {
                if ((int) $cand['question_id'] === $ctx['q']) {
                    $q = $cand;
                }
            }
        }
        $this->assertNotNull($q);
        $this->assertSame(SurveyTypes::pairwisePlan(12), $q['pairwise']);
        $this->assertSame(20, $q['pairwise']['gate']);
    }

    public function testRetypeSingleWithOtherToPairwiseClearsOther(): void
    {
        $ctx = $this->buildSurvey();   // q_single carries an "other" option
        $r = $this->survey->questionUpdate($ctx['q_single'], ['Type' => 'pairwise']);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $others = array_map(static fn ($o) => (int) $o['is_other'], $r['Question']['Options']);
        $this->assertCount(3, $others);
        $this->assertSame([0, 0, 0], $others);
    }

    public function testRecentAttendanceMonthsRefusesNonNumericInput(): void
    {
        $ctx = $this->buildSurvey();
        $stored = fn (): int => (int) $this->pdo->query(
            'SELECT audience_recent_months FROM ' . DB_PREFIX . 'survey WHERE survey_id = ' . $ctx['survey_id']
        )->fetchColumn();

        $this->assertSame(0, $this->survey->update($ctx['survey_id'], ['AudienceRecentMonths' => '6'])['Status']);
        $this->assertSame(6, $stored());

        // Non-numeric must not be cast to 0 (which would switch the rule off).
        foreach (['abc', '121', '-1', '1.5'] as $bad) {
            $r = $this->survey->update($ctx['survey_id'], ['AudienceRecentMonths' => $bad]);
            $this->assertSame(1, $r['Status'], 'Accepted ' . $bad);
            $this->assertSame('Recent attendance must be between 0 and 120 months.', $r['Error']);
        }
        $this->assertSame(6, $stored());

        // A cleared box switches the rule off.
        $this->assertSame(0, $this->survey->update($ctx['survey_id'], ['AudienceRecentMonths' => ''])['Status']);
        $this->assertSame(0, $stored());
    }

    // ------------------------------------------------------------------
    // Case 3 — submissions and the consent data gate
    // ------------------------------------------------------------------

    public function testThreeSubmissionsStoreConsentScrubbedColumns(): void
    {
        $ctx = $this->buildSurvey();
        $this->assertSame(0, $this->survey->setStatus($ctx['survey_id'], 'open')['Status']);
        $this->submitThree($ctx);

        $rows = [];
        $stmt = $this->pdo->query(
            'SELECT consent, mundane_id, kingdom_id, tenure_months, started_at, submitted_at, duration_seconds
               FROM ' . DB_PREFIX . 'survey_response
              WHERE survey_id = ' . $ctx['survey_id'] . ' ORDER BY response_id ASC'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[(string) $r['consent']] = $r;
        }
        $this->assertCount(3, $rows);

        // full — everything retained, exact timestamp.
        $full = $rows['full'];
        $this->assertSame($this->players['p1'], (int) $full['mundane_id']);
        $this->assertSame($this->kingdomId, (int) $full['kingdom_id']);
        $this->assertNotNull($full['tenure_months']);
        $this->assertNotNull($full['started_at']);
        $this->assertSame(120, (int) $full['duration_seconds']);
        $this->assertLessThanOrEqual(
            120,
            abs(time() - (int) strtotime((string) $full['submitted_at'])),
            'A full-consent response keeps the exact submission time.'
        );

        // partial — kingdom and a years-played BAND floor only, no duration,
        // timestamp truncated to the day.
        $partial = $rows['partial'];
        $this->assertNull($partial['mundane_id']);
        $this->assertSame($this->kingdomId, (int) $partial['kingdom_id']);
        $this->assertNotNull($partial['tenure_months']);
        $this->assertContains(
            (int) $partial['tenure_months'],
            array_column(SurveyResponse::TENURE_BANDS, 0),
            'A partial-consent response stores a tenure band floor, never exact months.'
        );
        $this->assertSame(
            SurveyResponse::tenureBandFloor((new SurveyResponse())->tenureMonths($this->players['p2'])),
            (int) $partial['tenure_months']
        );
        $this->assertNull($partial['started_at']);
        $this->assertNull($partial['duration_seconds']);
        $this->assertSame(date('Y-m-d') . ' 00:00:00', (string) $partial['submitted_at']);

        // anonymous — nothing about the player at all.
        $anon = $rows['anonymous'];
        $this->assertNull($anon['mundane_id']);
        $this->assertNull($anon['kingdom_id']);
        $this->assertNull($anon['tenure_months']);
        $this->assertNull($anon['started_at']);
        $this->assertNull($anon['duration_seconds']);
        $this->assertSame(date('Y-m-d') . ' 00:00:00', (string) $anon['submitted_at']);
    }

    public function testParticipationTableLocksOutASecondSubmission(): void
    {
        $ctx = $this->buildSurvey();
        $this->assertSame(0, $this->survey->setStatus($ctx['survey_id'], 'open')['Status']);
        $this->submitThree($ctx);

        $count = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_participation WHERE survey_id = ' . $ctx['survey_id']
        )->fetchColumn();
        $this->assertSame(3, $count);

        // The lock table must never be joinable back to a response: id pair only.
        $columns = $this->pdo->query('SHOW COLUMNS FROM ' . DB_PREFIX . 'survey_participation')
            ->fetchAll(PDO::FETCH_COLUMN, 0);
        $this->assertSame(['survey_id', 'mundane_id'], array_map('strval', $columns));

        $surveyRow = $this->survey->getRow($ctx['survey_id']);
        $eligibility = (new SurveyResponse())->eligibility($surveyRow, $this->players['p1']);
        $this->assertFalse($eligibility['eligible']);
        $this->assertSame('completed', $eligibility['reason']);

        $again = (new SurveyResponse())->submit(
            $ctx['survey_id'],
            $this->players['p1'],
            [$ctx['q_single'] => $ctx['opt_a']],
            'full',
            30,
            false
        );
        $this->assertSame(1, $again['Status']);
        $this->assertSame('completed', $again['Reason']);
        $this->assertSame(
            3,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_response WHERE survey_id = ' . $ctx['survey_id']
            )->fetchColumn()
        );
    }

    public function testClearResultsDeletesEveryResponseButKeepsPostedCredits(): void
    {
        $ctx = $this->buildSurvey();
        $sid = $ctx['survey_id'];
        $this->fx['survey'][] = $sid;   // the fixture also sweeps starts, activity, credits and grants
        $p = DB_PREFIX;
        $this->assertSame(0, $this->survey->setStatus($sid, 'open')['Status']);
        $openedAt = (string) $this->survey->getRow($sid)['opened_at'];

        $this->submitThree($ctx);
        $test = (new SurveyResponse())->submit($sid, $this->officerId, [$ctx['q_single'] => $ctx['opt_a']], 'anonymous', 10, true);
        $this->assertSame(0, $test['Status'], (string) ($test['Error'] ?? ''));
        $draft = $this->response->draftSave($sid, $this->editorId, [$ctx['q_single'] => $ctx['opt_b']], 0);
        $this->assertSame(0, $draft['Status'], (string) ($draft['Error'] ?? ''));
        $this->pdo->exec("INSERT IGNORE INTO {$p}survey_start (survey_id, mundane_id) VALUES ({$sid}, " . $this->editorId . ')');

        // One credit already posted to p1: a config, its attendance row and the ledger row.
        $this->pdo->exec("INSERT INTO {$p}survey_credit (survey_id, grantor_type, grantor_id, mode, enabled_by, enabled_at)
                          VALUES ({$sid}, 'park', " . $this->parkId . ", 'home_park', " . $this->officerId . ', NOW())');
        $creditId = (int) $this->pdo->lastInsertId();
        $st = $this->pdo->prepare("INSERT INTO {$p}attendance
            (mundane_id, class_id, date, date_year, date_month, date_week3, date_week6, park_id, kingdom_id,
             event_id, event_calendardetail_id, credits, persona, flavor, note, by_whom_id, entry_method, entered_at)
            VALUES (?, 6, CURDATE(), YEAR(CURDATE()), MONTH(CURDATE()), 1, 1, ?, ?, 0, 0, 1, '', '', ?, ?, 'survey', NOW())");
        $st->execute([$this->players['p1'], $this->parkId, $this->kingdomId, 'Survey #' . $sid, $this->officerId]);
        $attendanceId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO {$p}survey_credit_grant (survey_id, mundane_id, credit_id, attendance_id)
                          VALUES ({$sid}, " . $this->players['p1'] . ", {$creditId}, {$attendanceId})");

        $count = fn (string $table): int => (int) $this->pdo->query("SELECT COUNT(*) FROM {$p}{$table} WHERE survey_id = {$sid}")->fetchColumn();
        $this->assertSame(4, $count('survey_response'));

        // Non-managers are refused and nothing moves: another kingdom's officer, an
        // edit-only officer of this kingdom, and a respondent.
        foreach ([$this->outsiderId, $this->editorId, $this->players['p1']] as $uid) {
            $this->assertSame(3, $this->survey->clearResults($sid, $uid)['Status']);
            $this->assertSame(3, $this->survey->clearResults($sid, $uid, true)['Status']);
        }
        $this->assertSame(4, $count('survey_response'));

        // The dry run (the modal's count) counts test and real, and deletes nothing.
        $dry = $this->survey->clearResults($sid, $this->officerId, true);
        $this->assertSame(0, $dry['Status']);
        $this->assertSame(4, $dry['Count']);
        $this->assertSame(4, $count('survey_response'));

        $r = $this->survey->clearResults($sid, $this->officerId);
        $this->assertSame(0, $r['Status'], (string) $r['Error']);
        $this->assertSame(4, $r['Cleared']);

        foreach (['survey_response', 'survey_participation', 'survey_start', 'survey_draft'] as $table) {
            $this->assertSame(0, $count($table), $table . ' must be empty after Clear Results');
        }
        $this->assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM {$p}survey_answer a LEFT JOIN {$p}survey_response r ON r.response_id = a.response_id WHERE r.response_id IS NULL"
        )->fetchColumn(), 'no orphaned answers');
        $row = $this->survey->getRow($sid);
        $this->assertSame(0, (int) $row['response_count']);
        $this->assertSame($openedAt, (string) $row['opened_at'], 'the structure lock stays');
        $this->assertSame('open', (string) $row['status']);

        // Posted credits stay, so a retake cannot earn a second one.
        $this->assertSame(1, $count('survey_credit'));
        $this->assertSame(1, $count('survey_credit_grant'));
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM {$p}attendance WHERE attendance_id = {$attendanceId}")->fetchColumn());

        // Audit row with the number cleared, and a report that no longer counts anyone.
        $act = $this->pdo->query("SELECT detail FROM {$p}survey_activity WHERE survey_id = {$sid} AND action = 'clear_results'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(1, $act);
        $this->assertSame(['responses' => 4], json_decode((string) $act[0], true));
        $this->assertSame(0, (int) $this->report->summary($sid, ['include_test' => true])['responses']);

        // Everyone may take it again.
        $again = (new SurveyResponse())->eligibility($this->survey->getRow($sid), $this->players['p1']);
        $this->assertTrue($again['eligible']);
    }

    public function testTestSubmitLeavesTheManagersRealDraftAlone(): void
    {
        $ctx = $this->buildSurvey();
        $this->assertSame(0, $this->survey->setStatus($ctx['survey_id'], 'open')['Status']);

        $saved = $this->response->draftSave($ctx['survey_id'], $this->officerId, [$ctx['q_single'] => $ctx['opt_a']], 1);
        $this->assertSame(0, $saved['Status'], (string) ($saved['Error'] ?? ''));
        $this->pdo->exec(
            'UPDATE ' . DB_PREFIX . 'survey_draft SET started_at = \'2020-01-01 00:00:00\''
            . ' WHERE survey_id = ' . $ctx['survey_id'] . ' AND mundane_id = ' . $this->officerId
        );

        $r = (new SurveyResponse())->submit(
            $ctx['survey_id'],
            $this->officerId,
            [$ctx['q_single'] => $ctx['opt_a']],
            'anonymous',
            10,
            true
        );
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));

        $draft = $this->response->draftLoad($ctx['survey_id'], $this->officerId);
        $this->assertNotNull($draft, 'A test submit must not delete the real draft.');
        $this->assertSame(1, $draft['page_index']);
        $this->assertSame(strtotime('2020-01-01 00:00:00'), $draft['started_ts']);

        $started = (string) $this->pdo->query(
            'SELECT started_at FROM ' . DB_PREFIX . 'survey_response WHERE survey_id = ' . $ctx['survey_id']
            . ' AND is_test = 1 LIMIT 1'
        )->fetchColumn();
        $this->assertNotSame('2020-01-01 00:00:00', $started, 'A test submit stamps its own start.');
    }

    public function testStartedAtAndDurationDescribeTheSameInterval(): void
    {
        $ctx = $this->buildSurvey();
        $sid = $ctx['survey_id'];
        $this->assertSame(0, $this->survey->setStatus($sid, 'open')['Status']);

        $setDraftStart = function (int $uid, string $stamp) use ($sid, $ctx): void {
            $saved = $this->response->draftSave($sid, $uid, [$ctx['q_single'] => $ctx['opt_a']], 0);
            $this->assertSame(0, $saved['Status'], (string) ($saved['Error'] ?? ''));
            $this->pdo->exec(
                'UPDATE ' . DB_PREFIX . 'survey_draft SET started_at = \'' . $stamp . '\''
                . ' WHERE survey_id = ' . $sid . ' AND mundane_id = ' . $uid
            );
        };
        $submit = function (int $uid, int $duration) use ($sid, $ctx): array {
            // Opening the runner records the (timestamp-free) survey_start row.
            $this->response->definitionForRespondent($sid, $uid, false);
            $r = (new SurveyResponse())->submit($sid, $uid, [$ctx['q_single'] => $ctx['opt_a']], 'full', $duration, false);
            $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
            $row = $this->pdo->query(
                'SELECT started_at, submitted_at, duration_seconds FROM ' . DB_PREFIX . 'survey_response
                  WHERE response_id = ' . (int) $r['ResponseId']
            )->fetch(PDO::FETCH_ASSOC);
            $span = strtotime((string) $row['submitted_at']) - strtotime((string) $row['started_at']);
            $this->assertGreaterThanOrEqual(0, $span, 'started_at is never after submitted_at.');
            if (null !== $row['duration_seconds']) {
                $this->assertLessThanOrEqual($span, (int) $row['duration_seconds'], 'duration never exceeds the stored span.');
            }
            return $row + ['span' => $span];
        };

        // No draft: the start is the client timer's start, not the submit instant.
        $noDraft = $submit($this->players['p1'], 45);
        $this->assertSame(45, (int) $noDraft['duration_seconds']);
        $this->assertSame(45, $noDraft['span']);

        // Draft saved 18s ago but the page loaded 45s ago: the earlier start wins,
        // and the client duration is kept (the median reads duration_seconds).
        $setDraftStart($this->players['p2'], date('Y-m-d H:i:s', time() - 18));
        $recent = $submit($this->players['p2'], 45);
        $this->assertSame(45, (int) $recent['duration_seconds']);
        $this->assertGreaterThanOrEqual(45, $recent['span']);

        // Draft older than the client timer: the draft's start is kept verbatim.
        $old = date('Y-m-d H:i:s', time() - 600);
        $setDraftStart($this->players['p3'], $old);
        $resumed = $submit($this->players['p3'], 45);
        $this->assertSame($old, (string) $resumed['started_at']);
        $this->assertSame(45, (int) $resumed['duration_seconds']);

        // Clamp: a draft stamped in the future (bad clock) with no client timer
        // never yields a start after the submission or a negative duration.
        $late = $this->player('late', $this->parkId, $this->kingdomId);
        $setDraftStart($late, date('Y-m-d H:i:s', time() + 3600));
        $future = $submit($late, 0);
        $this->assertSame(0, $future['span']);
        $this->assertNull($future['duration_seconds']);
    }

    public function testManagerLabelNamesTheWholeManagementChain(): void
    {
        $child = $this->kingdom('child', $this->kingdomId);
        $park  = $this->park($child, 'childpark');
        $names = [];
        foreach ([['park', $park], ['kingdom', $child], ['kingdom', $this->kingdomId]] as [$t, $id]) {
            $names[$t . $id] = (string) $this->pdo->query(
                'SELECT name FROM ' . DB_PREFIX . $t . ' WHERE ' . $t . '_id = ' . (int) $id
            )->fetchColumn();
        }

        $this->assertSame(
            'The ' . $names['park' . $park] . ' officers, the ' . $names['kingdom' . $child]
            . ' officers, the ' . $names['kingdom' . $this->kingdomId] . ' officers, and ORK administrators',
            $this->response->managerLabel('park', $park)
        );
        $this->assertSame(
            'The ' . $names['kingdom' . $this->kingdomId] . ' officers and ORK administrators',
            $this->response->managerLabel('kingdom', $this->kingdomId)
        );
        $this->assertSame('The ORK administrators', $this->response->managerLabel('ork', 0));
    }

    // ------------------------------------------------------------------
    // Case 4 — reporting
    // ------------------------------------------------------------------

    public function testSummaryAggregateRowsAndCsv(): void
    {
        $ctx = $this->buildSurvey();
        $this->assertSame(0, $this->survey->setStatus($ctx['survey_id'], 'open')['Status']);
        $this->submitThree($ctx);
        $surveyId = $ctx['survey_id'];

        $summary = $this->report->summary($surveyId, []);
        $this->assertSame(3, $summary['responses']);
        $this->assertSame(
            ['full' => 1, 'partial' => 1, 'anonymous' => 1],
            $summary['consent_breakdown']
        );
        $this->assertSame(0, $summary['excluded_anonymous']);

        // A kingdom filter can never match an anonymous row; the page must say so.
        // It also leaves out the partial rows of a kingdom with fewer than
        // MIN_CELL of them (review #4), so only the full row remains.
        $filtered = $this->report->summary($surveyId, ['kingdom_ids' => [$this->kingdomId]]);
        $this->assertSame(1, $filtered['responses']);
        $this->assertSame(1, $filtered['excluded_anonymous']);
        $this->assertTrue($filtered['partial_cell_rule']);
        $this->assertTrue($filtered['suppressed']);

        // Aggregation of the single-choice question mirrors what was submitted.
        $agg = $this->report->aggregate($surveyId, []);
        $single = null;
        foreach ($agg['questions'] as $q) {
            if ((int) $q['question_id'] === $ctx['q_single']) {
                $single = $q;
            }
        }
        $this->assertNotNull($single);
        $this->assertSame('single', $single['type']);
        $this->assertSame(3, $single['n']);
        $counts = [];
        foreach ($single['agg']['counts'] as $c) {
            $counts[(int) $c['option_id']] = (int) $c['count'];
        }
        $this->assertSame(2, $counts[$ctx['opt_a']]);
        $this->assertSame(0, $counts[$ctx['opt_b']]);
        $this->assertSame(1, $counts[$ctx['opt_other']]);
        $this->assertSame([self::MARKER . ' write-in'], $single['agg']['other_texts']);

        // Row-level data: persona only ever appears on a full-consent row.
        $rows = $this->report->rows($surveyId, [], 0, 100);
        $this->assertSame(3, $rows['total']);
        $this->assertCount(3, $rows['rows']);
        $byConsent = [];
        foreach ($rows['rows'] as $r) {
            $byConsent[$r['consent']] = $r;
        }
        $this->assertNotNull($byConsent['full']['persona']);
        $this->assertSame($this->players['p1'], (int) $byConsent['full']['mundane_id']);
        $this->assertFalse($byConsent['full']['masked']);
        $this->assertNull($byConsent['partial']['persona']);
        $this->assertNull($byConsent['partial']['mundane_id']);
        // One partial row is a cell below MIN_CELL: kingdom and band withheld.
        $this->assertNull($byConsent['partial']['kingdom']);
        $this->assertNull($byConsent['partial']['tenure_label']);
        $this->assertTrue($byConsent['partial']['masked']);
        $this->assertNull($byConsent['anonymous']['persona']);
        $this->assertNull($byConsent['anonymous']['kingdom']);
        $this->assertNotSame('', (string) $byConsent['full']['answers'][$ctx['q_paragraph']]);

        // CSV: BOM + header + one line per response, CRLF terminated.
        $csv = $this->report->csv($surveyId, []);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = array_values(array_filter(explode("\r\n", $csv), static fn ($l) => $l !== ''));
        $this->assertCount(4, $lines);
        $this->assertStringContainsString('Consent', $lines[0]);
    }

    public function testLegacyImageNamesUpgradeRenamesFileAndMarkdown(): void
    {
        $ctx = $this->buildSurvey();
        $surveyId = $ctx['survey_id'];
        $p = DB_PREFIX;

        // An image stored before the token column: sequential, guessable name.
        $this->pdo->exec(
            "INSERT INTO {$p}survey_image (survey_id, ext, token, width, height, created_by, created_at)
             VALUES ({$surveyId}, 'png', '', 1, 1, {$this->officerId}, NOW())"
        );
        $imageId = (int) $this->pdo->lastInsertId();
        $legacy = sprintf('%06d.png', $imageId);
        if (!is_dir(DIR_SURVEY_IMAGE)) {
            mkdir(DIR_SURVEY_IMAGE, 0775, true);
        }
        file_put_contents(DIR_SURVEY_IMAGE . $legacy, 'x');

        // Markdown names it by URL; a longer id that merely ends in the same
        // digits must be left alone.
        $md = '![map](' . HTTP_SURVEY_IMAGE . $legacy . ') and 1' . $legacy;
        $this->pdo->prepare("UPDATE {$p}survey SET welcome_md = ? WHERE survey_id = ?")->execute([$md, $surveyId]);

        $result = $this->survey->upgradeLegacyImageNames();
        $this->assertSame(0, $result['failed']);
        $this->assertGreaterThanOrEqual(1, $result['upgraded']);

        $token = (string) $this->pdo->query("SELECT token FROM {$p}survey_image WHERE image_id = {$imageId}")->fetchColumn();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $token);
        $renamed = sprintf('%06d-%s.png', $imageId, $token);
        $this->assertFileDoesNotExist(DIR_SURVEY_IMAGE . $legacy);
        $this->assertFileExists(DIR_SURVEY_IMAGE . $renamed);

        $welcome = (string) $this->pdo->query("SELECT welcome_md FROM {$p}survey WHERE survey_id = {$surveyId}")->fetchColumn();
        $this->assertSame('![map](' . HTTP_SURVEY_IMAGE . $renamed . ') and 1' . $legacy, $welcome);

        // Idempotent: a second run leaves the upgraded row alone.
        $this->survey->upgradeLegacyImageNames();
        $this->assertSame(
            $token,
            (string) $this->pdo->query("SELECT token FROM {$p}survey_image WHERE image_id = {$imageId}")->fetchColumn()
        );
        $this->assertFileExists(DIR_SURVEY_IMAGE . $renamed);
    }

    // ------------------------------------------------------------------
    // Case 5 — cross-kingdom authority and cloning
    // ------------------------------------------------------------------

    public function testAnotherKingdomsOfficerCannotManage(): void
    {
        $ctx = $this->buildSurvey();
        $row = $this->survey->getRow($ctx['survey_id']);

        $this->assertTrue($this->survey->canManage($this->officerId, $row));
        $this->assertFalse($this->survey->canManage($this->outsiderId, $row));
        $this->assertFalse($this->survey->canManage($this->players['p1'], $row));
    }

    public function testCloneProducesAFreshDraft(): void
    {
        $ctx = $this->buildSurvey();
        $this->assertSame(0, $this->survey->setStatus($ctx['survey_id'], 'open')['Status']);
        $this->submitThree($ctx);

        $clone = $this->survey->cloneSurvey($ctx['survey_id'], $this->officerId);
        $this->assertSame(0, $clone['Status'], (string) ($clone['Error'] ?? ''));
        $newId = (int) $clone['SurveyId'];
        $this->assertGreaterThan(0, $newId);
        $this->assertNotSame($ctx['survey_id'], $newId);
        $this->surveyIds[] = $newId;

        $source = $this->survey->get($ctx['survey_id']);
        $copy = $this->survey->get($newId);
        $this->assertSame(0, $copy['Status']);
        $this->assertSame('draft', (string) $copy['Survey']['status']);
        $this->assertSame(0, (int) $copy['Survey']['response_count']);
        $this->assertSame(3, (int) $source['Survey']['response_count']);
        $this->assertCount(count($source['Questions']), $copy['Questions']);
        $this->assertCount(count($source['Pages']), $copy['Pages']);
        $this->assertNotSame((string) $source['Survey']['slug'], (string) $copy['Survey']['slug']);
        $this->assertFalse($copy['Locked']);
        $this->assertSame(
            0,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ' . DB_PREFIX . 'survey_response WHERE survey_id = ' . $newId
            )->fetchColumn()
        );
    }

    public function testCloneResetsTheScheduleAndOwnsItsImagesAfterTheSourceIsDeleted(): void
    {
        $p = DB_PREFIX;
        $r = $this->survey->create($this->officerId, 'kingdom', $this->kingdomId, self::MARKER . ' Clone Source');
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $sourceId = (int) $r['SurveyId'];
        $this->surveyIds[] = $sourceId;

        // One image, named by URL in the source's welcome copy.
        $token = '0123456789abcdef';
        $this->pdo->exec(
            "INSERT INTO {$p}survey_image (survey_id, ext, token, width, height, created_by, created_at)
             VALUES ({$sourceId}, 'png', '{$token}', 1, 1, {$this->officerId}, NOW())"
        );
        $imageId = (int) $this->pdo->lastInsertId();
        $srcName = sprintf('%06d-%s.png', $imageId, $token);
        file_put_contents(DIR_SURVEY_IMAGE . $srcName, 'x');
        $this->pdo->prepare(
            "UPDATE {$p}survey SET welcome_md = ?, open_at = '2026-01-01 00:00:00', close_at = '2026-01-31 00:00:00',
                    show_banner = 1 WHERE survey_id = ?"
        )->execute(['![map](' . HTTP_SURVEY_IMAGE . $srcName . ')', $sourceId]);

        $clone = $this->survey->cloneSurvey($sourceId, $this->officerId);
        $this->assertSame(0, $clone['Status'], (string) ($clone['Error'] ?? ''));
        $cloneId = (int) $clone['SurveyId'];
        $this->surveyIds[] = $cloneId;

        // #11: the copy starts unscheduled, with no banner.
        $row = $this->survey->getRow($cloneId);
        $this->assertNull($row['open_at']);
        $this->assertNull($row['close_at']);
        $this->assertNull($row['audience_event_calendardetail_id']);
        $this->assertSame(0, (int) $row['show_banner']);

        // #12: the copy's markdown names the copy's own file.
        $img = $this->pdo->query("SELECT image_id, token FROM {$p}survey_image WHERE survey_id = {$cloneId}")->fetch(PDO::FETCH_ASSOC);
        $cloneName = sprintf('%06d-%s.png', (int) $img['image_id'], (string) $img['token']);
        $this->assertSame('![map](' . HTTP_SURVEY_IMAGE . $cloneName . ')', (string) $row['welcome_md']);
        $this->assertFileExists(DIR_SURVEY_IMAGE . $cloneName);

        // A survey that still names the source's file by URL (an old verbatim clone).
        $r = $this->survey->create($this->officerId, 'kingdom', $this->kingdomId, self::MARKER . ' Old Clone');
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $oldCloneId = (int) $r['SurveyId'];
        $this->surveyIds[] = $oldCloneId;
        $this->pdo->prepare("UPDATE {$p}survey SET thanks_md = ? WHERE survey_id = ?")
            ->execute(['![map](' . HTTP_SURVEY_IMAGE . $srcName . ')', $oldCloneId]);

        try {
            $del = $this->survey->delete($sourceId);
            $this->assertSame(0, $del['Status'], (string) ($del['Error'] ?? ''));
            $this->assertFileExists(DIR_SURVEY_IMAGE . $cloneName);
            $this->assertFileExists(DIR_SURVEY_IMAGE . $srcName, 'a file another survey still names must survive the delete');
        } finally {
            @unlink(DIR_SURVEY_IMAGE . $srcName);
        }
    }

    // ------------------------------------------------------------------
    // Fixture helpers
    // ------------------------------------------------------------------

    /**
     * A two-page draft survey owned by the kingdom officer with one question of
     * every shape the reporting layer has to handle.
     *
     * @return array<string, int>
     */
    private function buildSurvey(): array
    {
        $r = $this->survey->create($this->officerId, 'kingdom', $this->kingdomId, self::MARKER . ' Full Survey');
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $surveyId = (int) $r['SurveyId'];
        $this->surveyIds[] = $surveyId;

        $page1 = (int) $this->pdo->query(
            'SELECT page_id FROM ' . DB_PREFIX . 'survey_page WHERE survey_id = ' . $surveyId
            . ' ORDER BY sort_order, page_id LIMIT 1'
        )->fetchColumn();

        $addPage = $this->survey->pageAdd($surveyId);
        $this->assertSame(0, $addPage['Status'], (string) ($addPage['Error'] ?? ''));
        $page2 = (int) $addPage['Page']['page_id'];

        $ids = ['survey_id' => $surveyId, 'page1' => $page1, 'page2' => $page2];

        $ids['q_single'] = $this->addQuestion($surveyId, $page1, 'single', 'Which weapon style?');
        $ids['q_multi'] = $this->addQuestion($surveyId, $page1, 'multi', 'Which events did you attend?');
        $ids['q_rating'] = $this->addQuestion($surveyId, $page1, 'rating', 'How was the event?');
        $ids['q_matrix'] = $this->addQuestion($surveyId, $page2, 'matrix', 'Rate each area');
        $ids['q_ranking'] = $this->addQuestion($surveyId, $page2, 'ranking', 'Rank these');
        $ids['q_paragraph'] = $this->addQuestion($surveyId, $page2, 'paragraph', 'Anything else?');
        $ids['q_section'] = $this->addQuestion($surveyId, $page2, 'section', 'Closing thoughts');

        // Replace the seeded choices so the single question carries an "other".
        $opts = $this->survey->optionSet($ids['q_single'], 'choice', [
            ['label' => 'Sword and board'],
            ['label' => 'Florentine'],
            ['label' => 'Something else', 'is_other' => 1],
        ]);
        $this->assertSame(0, $opts['Status'], (string) ($opts['Error'] ?? ''));
        $this->assertCount(3, $opts['Options']);
        $ids['opt_a'] = (int) $opts['Options'][0]['option_id'];
        $ids['opt_b'] = (int) $opts['Options'][1]['option_id'];
        $ids['opt_other'] = (int) $opts['Options'][2]['option_id'];
        $this->assertSame(1, (int) $opts['Options'][2]['is_other']);

        $multi = $this->optionIds($ids['q_multi'], 'choice');
        $ids['multi_a'] = $multi[0];
        $ids['multi_b'] = $multi[1];

        $matrixRows = $this->optionIds($ids['q_matrix'], 'row');
        $matrixCols = $this->optionIds($ids['q_matrix'], 'column');
        $ids['matrix_row_a'] = $matrixRows[0];
        $ids['matrix_row_b'] = $matrixRows[1];
        $ids['matrix_col_a'] = $matrixCols[0];
        $ids['matrix_col_b'] = $matrixCols[1];

        $rank = $this->optionIds($ids['q_ranking'], 'choice');
        $ids['rank_a'] = $rank[0];
        $ids['rank_b'] = $rank[1];

        return $ids;
    }

    /** One submission per consent tier, from three different players. */
    private function submitThree(array $ctx): void
    {
        $full = (new SurveyResponse())->submit(
            $ctx['survey_id'],
            $this->players['p1'],
            [
                $ctx['q_single'] => $ctx['opt_a'],
                $ctx['q_multi'] => [$ctx['multi_a'], $ctx['multi_b']],
                $ctx['q_rating'] => 4,
                $ctx['q_matrix'] => [
                    $ctx['matrix_row_a'] => $ctx['matrix_col_a'],
                    $ctx['matrix_row_b'] => $ctx['matrix_col_b'],
                ],
                $ctx['q_ranking'] => [$ctx['rank_b'], $ctx['rank_a']],
                $ctx['q_paragraph'] => self::MARKER . ' a full-consent comment',
            ],
            'full',
            120,
            false
        );
        $this->assertSame(0, $full['Status'], (string) ($full['Error'] ?? ''));
        $this->assertSame('full', $full['Consent']);

        $partial = (new SurveyResponse())->submit(
            $ctx['survey_id'],
            $this->players['p2'],
            [
                $ctx['q_single'] => $ctx['opt_a'],
                $ctx['q_rating'] => 2,
            ],
            'partial',
            90,
            false
        );
        $this->assertSame(0, $partial['Status'], (string) ($partial['Error'] ?? ''));
        $this->assertSame('partial', $partial['Consent']);

        $anonymous = (new SurveyResponse())->submit(
            $ctx['survey_id'],
            $this->players['p3'],
            [
                $ctx['q_single'] => ['option_id' => $ctx['opt_other'], 'other' => self::MARKER . ' write-in'],
            ],
            'anonymous',
            60,
            false
        );
        $this->assertSame(0, $anonymous['Status'], (string) ($anonymous['Error'] ?? ''));
        $this->assertSame('anonymous', $anonymous['Consent']);
    }

    private function addQuestion(int $surveyId, int $pageId, string $type, string $prompt): int
    {
        $q = $this->survey->questionAdd($surveyId, $pageId, $type, null);
        $this->assertSame(0, $q['Status'], (string) ($q['Error'] ?? ''));
        $questionId = (int) $q['Question']['question_id'];

        $u = $this->survey->questionUpdate($questionId, ['Prompt' => self::MARKER . ' ' . $prompt]);
        $this->assertSame(0, $u['Status'], (string) ($u['Error'] ?? ''));

        return $questionId;
    }

    /** @return list<int> */
    private function optionIds(int $questionId, string $role): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT option_id FROM ' . DB_PREFIX . 'survey_option
              WHERE question_id = ? AND role = ? ORDER BY sort_order, option_id'
        );
        $stmt->execute([$questionId, $role]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
    }

    private function deleteSurvey(int $surveyId): void
    {
        $surveyId = (int) $surveyId;
        $p = DB_PREFIX;

        $this->pdo->exec(
            "DELETE a FROM {$p}survey_answer a
               JOIN {$p}survey_response r ON r.response_id = a.response_id
              WHERE r.survey_id = {$surveyId}"
        );
        $this->pdo->exec("DELETE FROM {$p}survey_response WHERE survey_id = {$surveyId}");
        $this->pdo->exec("DELETE FROM {$p}survey_participation WHERE survey_id = {$surveyId}");
        $this->pdo->exec("DELETE FROM {$p}survey_draft WHERE survey_id = {$surveyId}");
        $this->pdo->exec("DELETE FROM {$p}survey_dismissal WHERE survey_id = {$surveyId}");
        $this->pdo->exec(
            "DELETE o FROM {$p}survey_option o
               JOIN {$p}survey_question q ON q.question_id = o.question_id
              WHERE q.survey_id = {$surveyId}"
        );
        $this->pdo->exec("DELETE FROM {$p}survey_question WHERE survey_id = {$surveyId}");
        $this->pdo->exec("DELETE FROM {$p}survey_page WHERE survey_id = {$surveyId}");

        $stmt = $this->pdo->query("SELECT image_id, ext, token FROM {$p}survey_image WHERE survey_id = {$surveyId}");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $img) {
            $token = (string) $img['token'];
            $file = defined('DIR_SURVEY_IMAGE')
                ? DIR_SURVEY_IMAGE . sprintf('%06d', (int) $img['image_id'])
                    . ($token !== '' ? '-' . $token : '') . '.' . (string) $img['ext']
                : '';
            if ($file !== '' && is_file($file)) {
                @unlink($file);
            }
            $this->pdo->exec("DELETE FROM {$p}survey_image WHERE image_id = " . (int) $img['image_id']);
        }

        $this->pdo->exec("DELETE FROM {$p}survey WHERE survey_id = {$surveyId}");
    }
}
