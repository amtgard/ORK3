<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Release Feature Utilization — the 3.5.7 Garber block (Surveys and bonus
 * credit).
 *
 * The sandbox carries its own surveys, so every count is asserted as a DELTA
 * around rows this test creates, never as an absolute. Everything created is
 * removed again in tearDown.
 */
final class ReleaseUtilizationGarberTest extends TestCase
{
    private PDO $pdo;
    private \Report $report;
    private int $surveyId = 0;
    private int $categoryId = 0;
    /** @var int[] */
    private array $bonusIds = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Sandbox database not reachable.');
        }

        $host = getenv('ORK3_TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('ORK3_TEST_DB_PORT') ?: '19307';
        $this->pdo = new PDO(
            "mysql:host={$host};port={$port};dbname=ork_test",
            'ork',
            'secret',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->report = new \Report();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $p = DB_PREFIX;
        if ($this->bonusIds) {
            $in = implode(',', $this->bonusIds);
            $this->pdo->exec("DELETE FROM {$p}survey_credit_grant WHERE bonus_credit_id IN ($in)");
            $this->pdo->exec("DELETE FROM {$p}bonus_credit WHERE bonus_credit_id IN ($in)");
        }
        if ($this->surveyId) {
            foreach (['survey_credit', 'survey_response', 'survey_participation', 'survey_start', 'survey_question', 'survey_dismissal'] as $t) {
                $this->pdo->exec("DELETE FROM {$p}{$t} WHERE survey_id = {$this->surveyId}");
            }
            $this->pdo->exec("DELETE FROM {$p}survey WHERE survey_id = {$this->surveyId}");
        }
        if ($this->categoryId) {
            $this->pdo->exec("DELETE FROM {$p}bonus_credit_category WHERE category_id = {$this->categoryId}");
        }
    }

    private function garber(): array
    {
        foreach ($this->report->ReleaseFeatureUtilization()['releases'] as $release) {
            if ($release['version'] === '3.5.7') {
                return $release;
            }
        }
        $this->fail('The report has no 3.5.7 release block.');
    }

    private function kpi(string $featureKey, string $label): float
    {
        foreach ($this->garber()['features'] as $feature) {
            if ($feature['key'] !== $featureKey) {
                continue;
            }
            foreach ($feature['kpis'] as $kpi) {
                if ($kpi['label'] === $label) {
                    return (float) $kpi['value'];
                }
            }
        }
        $this->fail("No KPI \"$label\" on feature \"$featureKey\".");
    }

    private function makeSurvey(string $status = 'open'): int
    {
        $p = DB_PREFIX;
        $slug = 'rfu' . bin2hex(random_bytes(6));
        $this->pdo->exec(
            "INSERT INTO {$p}survey (scope_type, scope_id, title, slug, status, opened_at, created_by, created_at, updated_at)
             VALUES ('ork', 0, 'RFU test survey', '$slug', '$status', NOW(), 1, NOW(), NOW())"
        );
        return $this->surveyId = (int) $this->pdo->lastInsertId();
    }

    private function respond(string $consent, int $isTest = 0): void
    {
        $p = DB_PREFIX;
        $this->pdo->exec(
            "INSERT INTO {$p}survey_response (survey_id, consent, is_test, submitted_at)
             VALUES ({$this->surveyId}, '$consent', $isTest, NOW())"
        );
    }

    public function testGarberIsTheNewestReleaseAndCoversSurveysAndBonusCredit(): void
    {
        $releases = $this->report->ReleaseFeatureUtilization()['releases'];

        $this->assertSame('3.5.7', $releases[0]['version']);
        $this->assertSame('Garber', $releases[0]['name']);
        $this->assertSame(
            ['surveys_created', 'survey_responses', 'survey_credit', 'bonus_credits', 'survey_discovery'],
            array_column($releases[0]['features'], 'key')
        );
    }

    public function testSurveysCreatedCountsSurveysAndOpenOnes(): void
    {
        $created = $this->kpi('surveys_created', 'Surveys created');
        $open    = $this->kpi('surveys_created', 'Surveys open right now');

        $this->makeSurvey('open');

        $this->assertSame($created + 1, $this->kpi('surveys_created', 'Surveys created'));
        $this->assertSame($open + 1, $this->kpi('surveys_created', 'Surveys open right now'));
    }

    public function testResponsesIgnoreTestSubmissionsAndCountConsentChoices(): void
    {
        $total  = $this->kpi('survey_responses', 'Responses submitted');
        $linked = $this->kpi('survey_responses', 'Responses linked to a profile');

        $this->makeSurvey();
        $this->respond('full');
        $this->respond('anonymous');
        $this->respond('full', 1); // an author's preview run — must not count

        $this->assertSame($total + 2, $this->kpi('survey_responses', 'Responses submitted'));
        $this->assertSame($linked + 1, $this->kpi('survey_responses', 'Responses linked to a profile'));
    }

    public function testResponseTrendChartsAreGapFree(): void
    {
        foreach ($this->garber()['features'] as $feature) {
            if ($feature['key'] !== 'survey_responses') {
                continue;
            }
            $byId = array_column($feature['charts'], null, 'id');
            $this->assertCount(30, $byId['rfu-survey-resp-day']['categories']);
            $this->assertCount(12, $byId['rfu-survey-resp-week']['categories']);
            return;
        }
        $this->fail('No survey_responses feature.');
    }

    public function testBonusCreditsSplitSurveyFromOfficerGrantsAndExcludeRevoked(): void
    {
        $p = DB_PREFIX;
        $awarded  = $this->kpi('bonus_credits', 'Bonus credits awarded');
        $viaSurv  = $this->kpi('bonus_credits', 'Awarded through a survey');
        $manual   = $this->kpi('bonus_credits', 'Granted by an officer');
        $revoked  = $this->kpi('bonus_credits', 'Credits revoked');
        $sGranted = $this->kpi('survey_credit', 'Survey credits granted');

        $surveyId = $this->makeSurvey();
        $this->pdo->exec(
            "INSERT INTO {$p}bonus_credit_category (scope_type, scope_id, name, source_type, source_id, created_by, created_at)
             VALUES ('kingdom', 0, 'RFU survey credit', 'survey', $surveyId, 1, NOW())"
        );
        $this->categoryId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO {$p}survey_credit (survey_id, grantor_type, grantor_id, enabled_by, enabled_at)
             VALUES ($surveyId, 'kingdom', 0, 1, NOW())"
        );
        $creditId = (int) $this->pdo->lastInsertId();

        // Two live survey credits and one revoked one.
        foreach ([[1, null], [2, null], [3, 'NOW()']] as [$player, $revokedAt]) {
            $this->pdo->exec(
                "INSERT INTO {$p}bonus_credit (mundane_id, class_id, credits, credit_date, category_id, granted_by, granted_at, revoked_at)
                 VALUES ($player, 1, 1.00, CURDATE(), {$this->categoryId}, 1, NOW(), " . ($revokedAt ?? 'NULL') . ')'
            );
            $bonusId = (int) $this->pdo->lastInsertId();
            $this->bonusIds[] = $bonusId;
            $this->pdo->exec(
                "INSERT INTO {$p}survey_credit_grant (survey_id, mundane_id, credit_id, bonus_credit_id)
                 VALUES ($surveyId, $player, $creditId, $bonusId)"
            );
        }

        $this->assertSame($awarded + 2, $this->kpi('bonus_credits', 'Bonus credits awarded'));
        $this->assertSame($viaSurv + 2, $this->kpi('bonus_credits', 'Awarded through a survey'));
        $this->assertSame($manual, $this->kpi('bonus_credits', 'Granted by an officer'));
        $this->assertSame($revoked + 1, $this->kpi('bonus_credits', 'Credits revoked'));
        $this->assertSame($sGranted + 2, $this->kpi('survey_credit', 'Survey credits granted'));
    }
}
