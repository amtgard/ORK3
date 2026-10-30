<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A Legend is no longer sent in-app notifications. Skipping means no new
 * ork_notification row for the Legend; everyone else on the same event still gets theirs.
 */
final class LegendNotificationTest extends TestCase
{
    private LegendFixture $fixture;
    private int $park = 0;
    private int $kingdom = 0;
    /** @var list<int> */
    private array $people = [];
    /** @var list<int> */
    private array $recIds = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        Legend::clearMemo();
        $this->fixture = LegendFixture::create();
        $this->park    = $this->fixture->firstParkId();
        $this->kingdom = $this->fixture->kingdomIdForPark($this->park);
    }

    protected function tearDown(): void
    {
        if (!isset($this->fixture)) {
            return;
        }
        $pdo = $this->fixture->pdo();
        if ($this->people) {
            $pdo->exec('DELETE FROM ' . DB_PREFIX . 'notification WHERE mundane_id IN (' . implode(',', $this->people) . ')');
        }
        foreach ($this->recIds as $id) {
            $pdo->exec('DELETE FROM ' . DB_PREFIX . 'recommendation_seconds WHERE recommendations_id = ' . $id);
            $pdo->exec('DELETE FROM ' . DB_PREFIX . 'recommendations WHERE recommendations_id = ' . $id);
        }
        $this->fixture->cleanup();
    }

    private function player(string $suffix, int $legendYear = 0): int
    {
        $id = $this->fixture->addPlayer($this->park, $suffix, $legendYear)['mundane_id'];
        $this->people[] = $id;

        return $id;
    }

    private function noticeCount(int $mundaneId, string $type): int
    {
        return $this->fixture->count('notification', 'mundane_id = ' . $mundaneId . " AND type = '" . $type . "'");
    }

    private function recommendation(int $recipient, int $recommender): int
    {
        $ka = $this->fixture->pdo()->query(
            'SELECT kingdomaward_id, award_id FROM ' . DB_PREFIX . 'kingdomaward WHERE kingdom_id = ' . $this->kingdom . ' LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!$ka) {
            $this->markTestSkipped('No kingdomaward in the sandbox kingdom.');
        }
        $this->fixture->pdo()->prepare(
            'INSERT INTO ' . DB_PREFIX . 'recommendations (mundane_id, kingdomaward_id, award_id, rank, recommended_by_id, date_recommended, mask_giver, reason)
             VALUES (?, ?, ?, 1, ?, CURDATE(), 0, ?)'
        )->execute([$recipient, $ka['kingdomaward_id'], $ka['award_id'], $recommender, 'Legend notification test']);
        $id = (int) $this->fixture->pdo()->lastInsertId();
        $this->recIds[] = $id;

        return $id;
    }

    public function test_recommender_notice_skips_legend(): void
    {
        $recipient = $this->player('recipient');
        $granter   = $this->player('granter');
        $legend    = $this->player('legendrec', 2001);
        $seconder  = $this->player('seconder');
        $rec = $this->recommendation($recipient, $legend);
        $this->fixture->pdo()->prepare(
            'INSERT INTO ' . DB_PREFIX . 'recommendation_seconds (recommendations_id, supporter_mundane_id, notes) VALUES (?, ?, ?)'
        )->execute([$rec, $seconder, 'agreed']);

        (new Notification())->notifyRecommendationGranted($rec, $granter);

        $this->assertSame(0, $this->noticeCount($legend, 'rec_granted'), 'the Legend recommender gets no row');
        $this->assertSame(1, $this->noticeCount($seconder, 'second_granted'), 'the living seconder still does');
    }

    public function test_pass_down_notice_skips_legend(): void
    {
        $recipient = $this->player('pdrecipient');
        $passer    = $this->player('passer');
        $legend    = $this->player('legendmonarch', 2001);
        $regent    = $this->player('regent');
        $this->fixture->addSeat($this->kingdom, $this->park, 'Monarch', $legend, false);
        $this->fixture->addSeat($this->kingdom, $this->park, 'Regent', $regent, false);
        $rec = $this->recommendation($recipient, $passer);

        (new Notification())->notifyRecommendationPassedDown($rec, $passer);

        $this->assertSame(0, $this->noticeCount($legend, 'recs_passed_down'));
        $this->assertSame(1, $this->noticeCount($regent, 'recs_passed_down'));
    }

    public function test_unrecorded_court_notice_skips_legend(): void
    {
        $legend = $this->player('legendrecorder', 2001);
        $living = $this->player('livingrecorder');
        $this->fixture->addCourt($this->kingdom, 0, 'published', $legend);
        $this->fixture->addCourt($this->kingdom, 0, 'published', $living);

        (new Court())->notifyUnrecordedCourts($this->kingdom, 0);

        $this->assertSame(0, $this->noticeCount($legend, 'court_awaiting_record'));
        $this->assertSame(1, $this->noticeCount($living, 'court_awaiting_record'));
    }
}
