<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A recommendation's "already has" test (Report::PlayerAwardRecommendations and its
 * SQL twin in PlayerAwardRecommendationsPage) must not treat two unrelated awards as
 * the same because they share an award_id. 0 (no catalog row), Custom Award and
 * Custom Title are shared by hundreds of kingdom awards: a Crystal Groves player
 * holding the kingdom title Justicar had a Walker In The Middle recommendation
 * hidden from their profile as "already has" in October 2026.
 *
 * Kingdom-original ladders that are one award under several kingdoms' names
 * (Award::pseudoLadderEquivalenceGroups) must still match each other: the archery
 * ladder is Hunter, Marksman, Sharpshooter or Archer depending on the kingdom.
 *
 * The sandbox carries none of the curated production ladder ids, so the archery
 * tests create kingdom awards under them, as PlayerAwardsKingdomLadderTest does.
 */
final class RecommendationAlreadyHasSharedAwardIdTest extends TestCase
{
    private const HUNTER_KA_ID        = 7249;
    private const MARKSMAN_KA_ID      = 7084;
    private const MASTER_HUNTER_KA_ID = 7250;
    private const CUSTOM_AWARD_ID     = 94;

    private ReportsFixture $fixture;
    private CourtFixture $recs;
    private int $kingdomId = 0;
    private int $parkId = 0;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->fixture   = ReportsFixture::create();
        $this->recs      = CourtFixture::create();
        $this->kingdomId = $this->fixture->firstKingdomId();
        $this->parkId    = $this->fixture->parkIdInKingdom($this->kingdomId);
    }

    protected function tearDown(): void
    {
        // Recommendations first: they reference the fixture's players.
        if (isset($this->recs)) {
            $this->recs->cleanup();
        }
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    private function kingdomTitle(string $name): int
    {
        $kaId = $this->fixture->insertKingdomOnlyAward($this->kingdomId, $name);
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . 'kingdomaward SET is_title = 1 WHERE kingdomaward_id = ' . $kaId);

        return $kaId;
    }

    /** @return array<string, mixed> the recommendation's row from the player's list */
    private function recRow(int $mundaneId, int $recId): array
    {
        $r = (new Report())->PlayerAwardRecommendations([
            'KingdomId' => 0, 'ParkId' => 0, 'PlayerId' => $mundaneId, 'SkipCache' => 1,
        ]);
        foreach ($r['AwardRecommendations'] ?? [] as $rec) {
            if ((int) $rec['RecommendationsId'] === $recId) {
                return $rec;
            }
        }
        $this->fail("recommendation {$recId} is missing from the player's list");
    }

    /** @return list<int> recommendation ids in the Manager's Open eligibility for this kingdom */
    private function openRecIds(): array
    {
        $r = (new Report())->PlayerAwardRecommendationsPage([
            'RequestedBy' => 0, 'KingdomId' => $this->kingdomId, 'ParkId' => 0,
            'Eligibility' => 'open', 'Limit' => 100000, 'Offset' => 0,
        ]);
        $ids = [];
        foreach ($r['Groups'] ?? [] as $g) {
            foreach ((array) ($g['MemberRecIds'] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    public function testAnotherKingdomTitleOnTheSharedIdIsNotAlreadyHeld(): void
    {
        $justicar = $this->kingdomTitle('Justicar');
        $walker   = $this->kingdomTitle('Walker In The Middle');
        $player   = $this->fixture->createPlayer($this->parkId, 'walker');
        $this->fixture->insertLadderAward($player['mundane_id'], $this->parkId, $this->kingdomId, $justicar, 0, 0);
        $recId = $this->recs->createRecommendation($player['mundane_id'], $walker, 0, ['rank' => 0]);

        $rec = $this->recRow($player['mundane_id'], $recId);
        $this->assertFalse($rec['AlreadyHas'], 'holding Justicar is not holding Walker In The Middle');
        $this->assertSame(0, $rec['HeldRank']);
        $this->assertContains($recId, $this->openRecIds(), 'the Manager lists it as open too');
    }

    public function testHoldingTheSameKingdomTitleIsStillAlreadyHeld(): void
    {
        $walker = $this->kingdomTitle('Walker In The Middle');
        $player = $this->fixture->createPlayer($this->parkId, 'walker-held');
        $this->fixture->insertLadderAward($player['mundane_id'], $this->parkId, $this->kingdomId, $walker, 0, 0);
        $recId = $this->recs->createRecommendation($player['mundane_id'], $walker, 0, ['rank' => 0]);

        $this->assertTrue($this->recRow($player['mundane_id'], $recId)['AlreadyHas']);
        $this->assertNotContains($recId, $this->openRecIds());
    }

    public function testArcheryLadderMatchesAcrossKingdomNames(): void
    {
        $hunter   = $this->fixture->insertKingdomAwardWithId(self::HUNTER_KA_ID, $this->kingdomId, 0, 'Order of the Hunter');
        $marksman = $this->fixture->insertKingdomAwardWithId(self::MARKSMAN_KA_ID, $this->kingdomId, self::CUSTOM_AWARD_ID, 'Order of the Marksman');
        $player   = $this->fixture->createPlayer($this->parkId, 'archer');
        $this->fixture->insertLadderAward($player['mundane_id'], $this->parkId, $this->kingdomId, $marksman, self::CUSTOM_AWARD_ID, 5);

        $atHeld = $this->recs->createRecommendation($player['mundane_id'], $hunter, 0, ['rank' => 5]);
        $above  = $this->recs->createRecommendation($player['mundane_id'], $hunter, 0, ['rank' => 6]);

        $held = $this->recRow($player['mundane_id'], $atHeld);
        $this->assertTrue($held['AlreadyHas'], 'Marksman 5 is Hunter 5');
        $this->assertSame(5, (int) $held['CurrentRank']);

        $next = $this->recRow($player['mundane_id'], $above);
        $this->assertFalse($next['AlreadyHas']);
        $this->assertSame(5, $next['HeldRank'], 'the grant modal shades the Marksman ranks already earned');

        $open = $this->openRecIds();
        $this->assertNotContains($atHeld, $open);
        $this->assertContains($above, $open);
    }

    public function testMasterHunterCoversTheArcheryLadder(): void
    {
        $hunter = $this->fixture->insertKingdomAwardWithId(self::HUNTER_KA_ID, $this->kingdomId, 0, 'Order of the Hunter');
        $master = $this->fixture->insertKingdomAwardWithId(self::MASTER_HUNTER_KA_ID, $this->kingdomId, 0, 'Master Hunter');
        $player = $this->fixture->createPlayer($this->parkId, 'master-hunter');
        $this->fixture->insertLadderAward($player['mundane_id'], $this->parkId, $this->kingdomId, $master, 0, 0);
        $recId = $this->recs->createRecommendation($player['mundane_id'], $hunter, 0, ['rank' => 8]);

        $this->assertTrue($this->recRow($player['mundane_id'], $recId)['AlreadyHas']);
        $this->assertNotContains($recId, $this->openRecIds());
    }

    public function testUnrelatedKingdomLadderOnTheSharedIdIsNotAlreadyHeld(): void
    {
        $hunter = $this->fixture->insertKingdomAwardWithId(self::HUNTER_KA_ID, $this->kingdomId, 0, 'Order of the Hunter');
        $juror  = $this->fixture->insertKingdomOnlyAward($this->kingdomId, 'Order of the Juror');
        $player = $this->fixture->createPlayer($this->parkId, 'juror');
        $this->fixture->insertLadderAward($player['mundane_id'], $this->parkId, $this->kingdomId, $juror, 0, 9);
        $recId = $this->recs->createRecommendation($player['mundane_id'], $hunter, 0, ['rank' => 1]);

        $rec = $this->recRow($player['mundane_id'], $recId);
        $this->assertFalse($rec['AlreadyHas'], 'Juror 9 says nothing about the Hunter ladder');
        $this->assertSame(0, $rec['HeldRank']);
    }
}
