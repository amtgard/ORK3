<?php

declare(strict_types=1);

namespace Tests\Integration;

use CourtFixture;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Release Feature Utilization — the 3.5.6 Crown block (Court Planner and the
 * Recommendations Manager workflow).
 *
 * The sandbox carries its own courts and recommendations, so every count is
 * asserted as a DELTA around rows this test creates, never as an absolute.
 */
final class ReleaseUtilizationCrownTest extends TestCase
{
    private CourtFixture $fixture;
    private \Report $report;
    private int $kingdomId = 0;
    private int $playerId = 0;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Sandbox database not reachable.');
        }

        $this->fixture   = CourtFixture::create();
        $this->report    = new \Report();
        $this->kingdomId = $this->fixture->firstKingdomId();
        $this->playerId  = $this->fixture->createPlayer('rfu', $this->kingdomId)['mundane_id'];
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    /** The Crown release block, or fail if the report does not carry one. */
    private function crown(): array
    {
        foreach ($this->report->ReleaseFeatureUtilization()['releases'] as $release) {
            if ($release['version'] === '3.5.6') {
                return $release;
            }
        }
        $this->fail('The report has no 3.5.6 release block.');
    }

    /** One KPI's value, addressed by feature key and KPI label. */
    private function kpi(string $featureKey, string $label): float
    {
        foreach ($this->crown()['features'] as $feature) {
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

    private function makeRecommendation(array $columns = []): int
    {
        $ka = $this->fixture->firstKingdomAwardId($this->kingdomId);
        if (!$ka) {
            $this->markTestSkipped('No kingdomaward in the sandbox kingdom.');
        }
        $id = $this->fixture->createRecommendation(
            $this->playerId,
            (int) $ka['kingdomaward_id'],
            (int) $ka['award_id'],
            ['dismissed_by' => $columns['dismissed_by'] ?? null]
        );
        unset($columns['dismissed_by']);
        foreach ($columns as $column => $value) {
            $st = $this->fixture->pdo()->prepare(
                'UPDATE ' . DB_PREFIX . "recommendations SET `$column` = ? WHERE recommendations_id = ?"
            );
            $st->execute([$value, $id]);
        }

        return $id;
    }

    public function testCrownIsTheNewestReleaseAndCoversCourtAndRecommendations(): void
    {
        $releases = $this->report->ReleaseFeatureUtilization()['releases'];

        $this->assertSame('3.5.6', $releases[0]['version']);
        $this->assertSame('Crown', $releases[0]['name']);
        $this->assertSame(
            ['court_planner', 'court_run', 'court_artisans', 'recs_workflow', 'rec_notifications'],
            array_column($releases[0]['features'], 'key')
        );
    }

    public function testCourtPlannerCountsCourtsAndTheAwardsPlacedOnThem(): void
    {
        $courts = $this->kpi('court_planner', 'Courts planned');
        $awards = $this->kpi('court_planner', 'Awards placed on a court');

        $kingdomCourt = $this->fixture->createCourt(['kingdom_id' => $this->kingdomId, 'status' => 'draft']);
        $this->fixture->createCourt([
            'kingdom_id' => $this->kingdomId,
            'park_id'    => $this->fixture->firstParkId($this->kingdomId),
            'status'     => 'draft',
        ]);
        $this->fixture->createAward($kingdomCourt, $this->playerId, ['rank' => 1]);
        $this->fixture->createAward($kingdomCourt, $this->playerId, ['rank' => 2]);
        $this->fixture->createAward($kingdomCourt, $this->playerId, ['rank' => 3]);

        $this->assertSame($courts + 2, $this->kpi('court_planner', 'Courts planned'));
        $this->assertSame($awards + 3, $this->kpi('court_planner', 'Awards placed on a court'));
    }

    public function testRunningCourtSeparatesCompletedGrantedAndSkipped(): void
    {
        $completed = $this->kpi('court_run', 'Courts completed');
        $granted   = $this->kpi('court_run', 'Awards granted through a court');
        $skipped   = $this->kpi('court_run', 'Awards skipped at court');
        $printed   = $this->kpi('court_run', 'Court packets printed');

        $done = $this->fixture->createCourt(['kingdom_id' => $this->kingdomId, 'status' => 'complete']);
        $open = $this->fixture->createCourt(['kingdom_id' => $this->kingdomId, 'status' => 'published']);
        $this->fixture->createAward($done, $this->playerId, ['rank' => 1, 'status' => 'given']);
        $this->fixture->createAward($done, $this->playerId, ['rank' => 2, 'status' => 'given']);
        $this->fixture->createAward($done, $this->playerId, ['rank' => 3, 'status' => 'cancelled']);
        // Staged is "granted at court" but not yet on the permanent record.
        $this->fixture->createAward($open, $this->playerId, ['rank' => 4, 'status' => 'staged']);
        $this->fixture->pdo()->exec(
            'UPDATE ' . DB_PREFIX . 'court SET last_printed_at = NOW() WHERE court_id = ' . $open
        );

        $this->assertSame($completed + 1, $this->kpi('court_run', 'Courts completed'));
        $this->assertSame($granted + 2, $this->kpi('court_run', 'Awards granted through a court'));
        $this->assertSame($skipped + 1, $this->kpi('court_run', 'Awards skipped at court'));
        $this->assertSame($printed + 1, $this->kpi('court_run', 'Court packets printed'));
    }

    public function testArtisanCreditsCountEachKindOfMaker(): void
    {
        $scrollTracked = $this->kpi('court_artisans', 'Awards with scroll tracking');
        $scrollMakers  = $this->kpi('court_artisans', 'Awards with a scroll maker credited');
        $regaliaMakers = $this->kpi('court_artisans', 'Awards with a regalia maker credited');

        $maker = $this->fixture->createPlayer('maker', $this->kingdomId)['mundane_id'];
        $court = $this->fixture->createCourt(['kingdom_id' => $this->kingdomId, 'status' => 'draft']);
        $line  = $this->fixture->createAward($court, $this->playerId, ['rank' => 1, 'scroll_maker_id' => $maker]);
        $this->fixture->createAward($court, $this->playerId, ['rank' => 2, 'regalia_maker_id' => $maker]);
        $this->fixture->pdo()->exec(
            'UPDATE ' . DB_PREFIX . 'court_award SET scroll_status = 1 WHERE court_award_id = ' . $line
        );

        $this->assertSame($scrollTracked + 1, $this->kpi('court_artisans', 'Awards with scroll tracking'));
        $this->assertSame($scrollMakers + 1, $this->kpi('court_artisans', 'Awards with a scroll maker credited'));
        $this->assertSame($regaliaMakers + 1, $this->kpi('court_artisans', 'Awards with a regalia maker credited'));
    }

    public function testRecommendationWorkflowCountsOnlyOpenPassedDownAndSnoozedRecs(): void
    {
        $passed  = $this->kpi('recs_workflow', 'Recommendations passed to a local park');
        $snoozed = $this->kpi('recs_workflow', 'Recommendations snoozed');

        $this->makeRecommendation(['passed_to_local' => 1]);
        // snoozed_by_id alone is the mark of a snooze: a scope with a vacant throne
        // stores no snoozed_monarch_id, and the officer still snoozed it.
        $this->makeRecommendation(['snoozed_by_id' => $this->playerId]);
        $this->makeRecommendation();
        // Dismissed: no longer an open recommendation, so neither flag counts.
        $this->makeRecommendation(['passed_to_local' => 1, 'dismissed_by' => $this->playerId]);
        $this->makeRecommendation(['snoozed_by_id' => $this->playerId, 'dismissed_by' => $this->playerId]);

        $this->assertSame($passed + 1, $this->kpi('recs_workflow', 'Recommendations passed to a local park'));
        $this->assertSame($snoozed + 1, $this->kpi('recs_workflow', 'Recommendations snoozed'));
    }

    public function testRecommendationsOnACourtPlanIgnoreSkippedLines(): void
    {
        $onCourt = $this->kpi('recs_workflow', 'Recommendations on a court plan');

        $live    = $this->makeRecommendation();
        $skipped = $this->makeRecommendation();
        $court   = $this->fixture->createCourt(['kingdom_id' => $this->kingdomId, 'status' => 'draft']);
        $a       = $this->fixture->createAward($court, $this->playerId, ['rank' => 1]);
        $b       = $this->fixture->createAward($court, $this->playerId, ['rank' => 2, 'status' => 'cancelled']);
        $link    = $this->fixture->pdo()->prepare(
            'UPDATE ' . DB_PREFIX . 'court_award SET recommendations_id = ? WHERE court_award_id = ?'
        );
        $link->execute([$live, $a]);
        $link->execute([$skipped, $b]);

        $this->assertSame($onCourt + 1, $this->kpi('recs_workflow', 'Recommendations on a court plan'));
    }

    public function testGrantNotificationsCountRecommendersAndSecondersSeparately(): void
    {
        $recommenders = $this->kpi('rec_notifications', 'Recommenders told their recommendation was granted');
        $seconders    = $this->kpi('rec_notifications', 'Seconders told the recommendation was granted');

        $notifications = new \Notification();
        $notifications->Add($this->playerId, 'rec_granted', 'fixture', null);
        $notifications->Add($this->playerId, 'rec_granted', 'fixture', null);
        $notifications->Add($this->playerId, 'second_granted', 'fixture', null);

        try {
            $this->assertSame(
                $recommenders + 2,
                $this->kpi('rec_notifications', 'Recommenders told their recommendation was granted')
            );
            $this->assertSame(
                $seconders + 1,
                $this->kpi('rec_notifications', 'Seconders told the recommendation was granted')
            );
        } finally {
            $this->fixture->pdo()->exec(
                'DELETE FROM ' . DB_PREFIX . 'notification WHERE mundane_id = ' . $this->playerId
            );
        }
    }
}
