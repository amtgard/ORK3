<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Monarchy Only recommendations in the Court Planner: the reason must not leak
 * into a court line's public citation, and the pending-recs list must honour
 * the caller's token.
 */
final class CourtMonarchyOnlyTest extends TestCase
{
    private CourtFixture $fx;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->fx = CourtFixture::create();
        unset($_SESSION['is_authorized_mundane_id']);
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
    }

    private function addFromRec(int $level): array
    {
        $k     = $this->fx->firstKingdomId();
        $p     = $this->fx->firstParkId($k);
        $ka    = $this->fx->firstKingdomAwardId($k);
        $recip = $this->fx->createPlayer('mo-court', $k, $p)['mundane_id'];
        $rec   = $this->fx->createRecommendation($recip, (int)$ka['kingdomaward_id'], (int)$ka['award_id'], ['monarchy_only' => $level, 'reason' => 'secret reason']);
        $court = $this->fx->createCourt(['kingdom_id' => $k]);

        $res = (new \Court())->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '');
        return $this->fx->fetchAward((int)$res['CourtAwardId']);
    }

    public function testAddAwardFromHiddenRecMarksCitationCleared(): void
    {
        $this->assertSame(1, (int)$this->addFromRec(2)['public_comment_cleared']);
    }

    public function testAddAwardFromPublicRecInheritsAsBefore(): void
    {
        $this->assertSame(0, (int)$this->addFromRec(0)['public_comment_cleared']);
    }

    public function testPendingRecsHonourToken(): void
    {
        $k     = $this->fx->firstKingdomId();
        $p     = $this->fx->firstParkId($k);
        $ka    = $this->fx->firstKingdomAwardId($k);
        $recip = $this->fx->createPlayer('mo-pend', $k, $p)['mundane_id'];
        $rec   = $this->fx->createRecommendation($recip, (int)$ka['kingdomaward_id'], (int)$ka['award_id'], ['monarchy_only' => 2, 'reason' => 'secret reason']);

        [$parkOff, , $parkTok] = array_values($this->fx->createPlayerWithToken('mo-pk', $k, $p));
        $this->fx->insertScopedAuth($parkOff, $p, 0, 'edit');
        [$kOff, , $kTok] = array_values($this->fx->createPlayerWithToken('mo-k', $k, $p));
        $this->fx->insertScopedAuth($kOff, 0, $k, 'edit');

        $ids = static fn (array $rows): array => array_map('intval', array_column($rows, 'RecommendationsId'));
        $court = new \Court();

        $this->assertNotContains($rec, $ids($court->getPendingRecommendations($k, $p, $parkOff, 0, $parkTok)));
        $this->assertContains($rec, $ids($court->getPendingRecommendations($k, $p, $kOff, 0, $kTok)));
    }
}
