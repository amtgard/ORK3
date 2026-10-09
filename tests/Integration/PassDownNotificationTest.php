<?php

declare(strict_types=1);

namespace Tests\Integration;

use CourtFixture;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * When a kingdom passes a recommendation down to the recipient's park, the park's
 * officers are told once — however many recommendations come down with it.
 */
final class PassDownNotificationTest extends TestCase
{
    private const KINGDOM_TEXT = 'Your Kingdom has authorized you to give out one or more higher level awards. '
        . 'Check your Park Recommendations tab for details.';
    private const PRINCIPALITY_TEXT = 'Your Principality has authorized you to give out one or more higher level awards. '
        . 'Check your Park Recommendations tab for details.';

    private CourtFixture $fixture;
    private \Notification $notifications;
    private int $kingdomId = 0;
    private int $parkId = 0;
    /** @var list<int> everyone this test may have notified */
    private array $people = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Sandbox database not reachable.');
        }

        $this->fixture       = CourtFixture::create();
        $this->notifications = new \Notification();
        $this->kingdomId     = $this->fixture->firstKingdomId();
        $this->parkId        = $this->fixture->firstParkId($this->kingdomId);
    }

    protected function tearDown(): void
    {
        if (!isset($this->fixture)) {
            return;
        }
        if ($this->people) {
            $this->fixture->pdo()->exec(
                'DELETE FROM ' . DB_PREFIX . 'notification WHERE mundane_id IN (' . implode(',', $this->people) . ')'
            );
        }
        $this->fixture->cleanup();
    }

    private function player(string $tag, ?int $kingdomId = null, ?int $parkId = null): int
    {
        $id = $this->fixture->createPlayer($tag, $kingdomId ?? $this->kingdomId, $parkId ?? $this->parkId)['mundane_id'];
        $this->people[] = $id;

        return $id;
    }

    private function officer(string $role, ?int $kingdomId = null, ?int $parkId = null): int
    {
        $id = $this->player(strtolower(str_replace(' ', '', $role)), $kingdomId, $parkId);
        $this->fixture->insertOfficer($id, $kingdomId ?? $this->kingdomId, $parkId ?? $this->parkId, $role);

        return $id;
    }

    /** A recommendation for a member of the given park. */
    private function recommendationFor(int $recipientId, ?int $kingdomId = null): int
    {
        $ka = $this->fixture->firstKingdomAwardId($kingdomId ?? $this->kingdomId);
        if (!$ka) {
            $this->markTestSkipped('No kingdomaward in the sandbox kingdom.');
        }

        return $this->fixture->createRecommendation($recipientId, (int) $ka['kingdomaward_id'], (int) $ka['award_id']);
    }

    /** @return list<array{type: string, message: string, link: ?string}> */
    private function noticesFor(int $mundaneId): array
    {
        $st = $this->fixture->pdo()->prepare(
            'SELECT type, message, link FROM ' . DB_PREFIX . 'notification
              WHERE mundane_id = ? ORDER BY notification_id'
        );
        $st->execute([$mundaneId]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testEachOfficerOfTheRecipientsParkIsToldWithALinkToTheParkRecommendationsTab(): void
    {
        $monarch = $this->officer('Monarch');
        $regent  = $this->officer('Regent');
        $pm      = $this->officer('Prime Minister');
        $rec     = $this->recommendationFor($this->player('recipient'));

        $this->notifications->notifyRecommendationPassedDown($rec, $this->player('kingdomofficer'));

        foreach ([$monarch, $regent, $pm] as $officer) {
            $notices = $this->noticesFor($officer);
            $this->assertCount(1, $notices);
            $this->assertSame('recs_passed_down', $notices[0]['type']);
            $this->assertSame(self::KINGDOM_TEXT, $notices[0]['message']);
            $this->assertStringEndsWith(
                'Park/index/' . $this->parkId . '&tab=recommendations',
                (string) $notices[0]['link']
            );
        }
    }

    public function testOfficersOfOtherParksAndTheChampionAreNotTold(): void
    {
        $champion = $this->officer('Champion');
        $rec      = $this->recommendationFor($this->player('recipient'));
        // A member of the same park who holds no office.
        $member   = $this->player('member');

        $this->notifications->notifyRecommendationPassedDown($rec, $this->player('kingdomofficer'));

        $this->assertSame([], $this->noticesFor($champion));
        $this->assertSame([], $this->noticesFor($member));
    }

    public function testASecondPassDownDoesNotRepeatAnUnreadNotice(): void
    {
        $monarch = $this->officer('Monarch');
        $actor   = $this->player('kingdomofficer');
        $first   = $this->recommendationFor($this->player('one'));
        $second  = $this->recommendationFor($this->player('two'));

        $this->notifications->notifyRecommendationPassedDown($first, $actor);
        $this->notifications->notifyRecommendationPassedDown($second, $actor);

        $this->assertCount(1, $this->noticesFor($monarch), '"One or more" — a batch of pass-downs is one notice.');
    }

    public function testAPassDownAfterTheOfficerHasReadTheLastNoticeTellsThemAgain(): void
    {
        $monarch = $this->officer('Monarch');
        $actor   = $this->player('kingdomofficer');

        $this->notifications->notifyRecommendationPassedDown($this->recommendationFor($this->player('one')), $actor);
        $this->notifications->MarkAllRead($monarch);
        $this->notifications->notifyRecommendationPassedDown($this->recommendationFor($this->player('two')), $actor);

        $this->assertCount(2, $this->noticesFor($monarch));
    }

    public function testTheOfficerWhoPassedItDownIsNotToldAboutTheirOwnAction(): void
    {
        $monarch = $this->officer('Monarch');
        $regent  = $this->officer('Regent');
        $rec     = $this->recommendationFor($this->player('recipient'));

        $this->notifications->notifyRecommendationPassedDown($rec, $monarch);

        $this->assertSame([], $this->noticesFor($monarch));
        $this->assertCount(1, $this->noticesFor($regent));
    }

    public function testAParkInAPrincipalityIsToldItsPrincipalityAuthorizedIt(): void
    {
        $row = $this->fixture->pdo()->query(
            'SELECT k.kingdom_id, MIN(p.park_id) AS park_id
               FROM ' . DB_PREFIX . 'kingdom k
               JOIN ' . DB_PREFIX . 'park p ON p.kingdom_id = k.kingdom_id
              WHERE k.parent_kingdom_id > 0
              GROUP BY k.kingdom_id ORDER BY k.kingdom_id LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->markTestSkipped('No principality with a park in the sandbox.');
        }
        $principality = (int) $row['kingdom_id'];
        $park         = (int) $row['park_id'];

        $monarch = $this->officer('Monarch', $principality, $park);
        $rec     = $this->recommendationFor($this->player('recipient', $principality, $park), $principality);

        $this->notifications->notifyRecommendationPassedDown($rec, $this->player('kingdomofficer'));

        $notices = $this->noticesFor($monarch);
        $this->assertCount(1, $notices);
        $this->assertSame(self::PRINCIPALITY_TEXT, $notices[0]['message']);
    }
}
