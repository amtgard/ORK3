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
    /** @var list<int> ork_awards rows written by commit tests */
    private array $ledgerIds = [];

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
            if ($this->ledgerIds) {
                $this->fx->pdo()->exec('DELETE FROM ' . DB_PREFIX . 'awards WHERE awards_id IN (' . implode(',', array_map('intval', $this->ledgerIds)) . ')');
            }
            $this->fx->cleanup();
        }
        unset($_SESSION['is_authorized_mundane_id']);
    }

    /** A kingdom officer of $k — the caller every legitimate Court Planner add carries. */
    private function kingdomOfficer(int $k, int $p): array
    {
        $o = $this->fx->createPlayerWithToken('mo-kcaller', $k, $p);
        $this->fx->insertScopedAuth($o['mundane_id'], 0, $k, 'edit');
        return $o;
    }

    private function addFromRec(int $level): array
    {
        $k     = $this->fx->firstKingdomId();
        $p     = $this->fx->firstParkId($k);
        $ka    = $this->fx->firstKingdomAwardId($k);
        $recip = $this->fx->createPlayer('mo-court', $k, $p)['mundane_id'];
        $rec   = $this->fx->createRecommendation($recip, (int)$ka['kingdomaward_id'], (int)$ka['award_id'], ['monarchy_only' => $level, 'reason' => 'secret reason']);
        $court = $this->fx->createCourt(['kingdom_id' => $k]);
        $caller = $this->kingdomOfficer($k, $p)['mundane_id'];

        $res = (new \Court())->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, $caller);
        $this->assertIsArray($res, 'A kingdom officer may add any rec of this kingdom to the court.');
        return $this->fx->fetchAward((int)$res['CourtAwardId']);
    }

    /**
     * Review 2, item 6: the line no longer persists a forced clear for a hidden rec —
     * that flag went stale when the rec was unhidden. Whether the reason is withheld
     * is decided from the rec's CURRENT state wherever it would be used.
     */
    public function testAddAwardFromHiddenRecDoesNotPersistAClear(): void
    {
        $line = $this->addFromRec(2);
        $this->assertSame(0, (int)$line['public_comment_cleared']);
        $this->assertTrue((new \Court())->getAwardForCommit((int)$line['court_award_id'])['RecMonarchyOnly']);
    }

    public function testAddAwardFromPublicRecInheritsAsBefore(): void
    {
        $this->assertSame(0, (int)$this->addFromRec(0)['public_comment_cleared']);
    }

    public function testAddAwardFromOutOfRangeLevelRecIsTreatedAsHidden(): void
    {
        $line = $this->addFromRec(-1);
        $this->assertSame(0, (int)$line['public_comment_cleared']);
        $this->assertTrue((new \Court())->getAwardForCommit((int)$line['court_award_id'])['RecMonarchyOnly'], 'an out-of-range level is hidden (fail closed)');
    }

    /** @return array{0:int,1:int,2:int,3:array,4:int,5:int} court, kingdom, recipient, kingdomaward row, rec, caller */
    private function hiddenRecSetup(): array
    {
        $k     = $this->fx->firstKingdomId();
        $p     = $this->fx->firstParkId($k);
        $ka    = $this->fx->firstKingdomAwardId($k);
        $recip = $this->fx->createPlayer('mo-redo', $k, $p)['mundane_id'];
        $rec   = $this->fx->createRecommendation($recip, (int)$ka['kingdomaward_id'], (int)$ka['award_id'], ['monarchy_only' => 1, 'reason' => 'secret reason']);
        $court = $this->fx->createCourt(['kingdom_id' => $k]);
        $caller = $this->kingdomOfficer($k, $p)['mundane_id'];
        return [$court, $k, $recip, $ka, $rec, $caller];
    }

    public function testRevivedCancelledLineFromHiddenRecStartsFresh(): void
    {
        [$court, $k, $recip, $ka, $rec, $caller] = $this->hiddenRecSetup();
        $c = new \Court();
        $first = $c->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, $caller);
        $id = (int)$first['CourtAwardId'];
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . "court_award SET status = 'cancelled', public_comment_cleared = 1 WHERE court_award_id = " . $id);

        $again = $c->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, $caller);
        $this->assertSame($id, (int)$again['CourtAwardId']);
        $line = $this->fx->fetchAward($id);
        $this->assertSame('planned', $line['status']);
        $this->assertSame(0, (int)$line['public_comment_cleared'], 'a revived line is a fresh line: no carried or forced clear');
        $this->assertTrue($again['RecMonarchyOnly']);
    }

    public function testReusedLineTiedToHiddenRecIsLeftAsIs(): void
    {
        [$court, $k, $recip, $ka, $rec, $caller] = $this->hiddenRecSetup();
        $c = new \Court();
        $id = (int)$c->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, $caller)['CourtAwardId'];

        $again = $c->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, $caller);
        $this->assertSame(0, (int)$this->fx->fetchAward($id)['public_comment_cleared']);
        $this->assertTrue($again['AlreadyOnCourt']);
        $this->assertTrue($again['RecMonarchyOnly']);
    }

    public function testReusedLineTiedToPublicRecIsLeftAlone(): void
    {
        $k     = $this->fx->firstKingdomId();
        $p     = $this->fx->firstParkId($k);
        $ka    = $this->fx->firstKingdomAwardId($k);
        $recip = $this->fx->createPlayer('mo-pub', $k, $p)['mundane_id'];
        $pub   = $this->fx->createRecommendation($recip, (int)$ka['kingdomaward_id'], (int)$ka['award_id'], ['monarchy_only' => 0, 'reason' => 'fine']);
        $hid   = $this->fx->createRecommendation($recip, (int)$ka['kingdomaward_id'], (int)$ka['award_id'], ['monarchy_only' => 2, 'reason' => 'secret']);
        $court = $this->fx->createCourt(['kingdom_id' => $k]);
        $caller = $this->kingdomOfficer($k, $p)['mundane_id'];
        $c = new \Court();
        $id = (int)$c->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $pub, 0, '', '', true, $caller)['CourtAwardId'];

        $again = (int)$c->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $hid, 0, '', '', true, $caller)['CourtAwardId'];
        $this->assertSame($id, $again);
        $this->assertSame(0, (int)$this->fx->fetchAward($id)['public_comment_cleared']);
    }

    // ---- Final-review fixes -------------------------------------------------

    /** Finding 1: the client builds the new row from addAward's response, so it must say the line is cleared and why. */
    public function testAddAwardResponseCarriesCitationFlags(): void
    {
        [$court, $k, $recip, $ka, $rec, $caller] = $this->hiddenRecSetup();
        $res = (new \Court())->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, $caller);
        $this->assertFalse($res['PublicCommentCleared'], 'item 6: no forced clear');
        $this->assertTrue($res['RecMonarchyOnly']);

        $rows = array_column((new \Court())->getCourtAwards($court, $caller), null, 'CourtAwardId');
        $this->assertTrue($rows[(int)$res['CourtAwardId']]['RecMonarchyOnly']);
    }

    public function testAddAwardResponseForPublicRecIsNotFlagged(): void
    {
        $k     = $this->fx->firstKingdomId();
        $p     = $this->fx->firstParkId($k);
        $ka    = $this->fx->firstKingdomAwardId($k);
        $recip = $this->fx->createPlayer('mo-pubflag', $k, $p)['mundane_id'];
        $rec   = $this->fx->createRecommendation($recip, (int)$ka['kingdomaward_id'], (int)$ka['award_id'], ['reason' => 'fine']);
        $court = $this->fx->createCourt(['kingdom_id' => $k]);
        $res = (new \Court())->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, 0);
        $this->assertFalse($res['PublicCommentCleared']);
        $this->assertFalse($res['RecMonarchyOnly']);
    }

    /** Item 6: updateAward stores exactly what the officer posts; a hidden rec no longer forces 1. */
    public function testUpdateAwardStoresThePostedClearForAHiddenRecLine(): void
    {
        [$court, $k, $recip, $ka, $rec, $caller] = $this->hiddenRecSetup();
        $c  = new \Court();
        $id = (int)$c->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, $caller)['CourtAwardId'];
        $this->assertTrue($c->updateAward($id, ['Notes' => 'x', 'PublicComment' => '', 'PublicCommentCleared' => 1]));
        $this->assertSame(1, (int)$this->fx->fetchAward($id)['public_comment_cleared']);
        $this->assertTrue($c->updateAward($id, ['Notes' => 'x', 'PublicComment' => '', 'PublicCommentCleared' => 0]));
        $this->assertSame(0, (int)$this->fx->fetchAward($id)['public_comment_cleared']);
    }

    /** Commit a line drawn from a rec at $level, after optionally changing the rec's level; returns the published note. */
    private function commitLine(int $levelAtAdd, ?int $levelAtCommit, string $typed = ''): string
    {
        $k     = $this->fx->firstKingdomId();
        $p     = $this->fx->firstParkId($k);
        $ka    = $this->fx->firstKingdomAwardId($k);
        $recip = $this->fx->createPlayer('mo-c6', $k, $p)['mundane_id'];
        $rec   = $this->fx->createRecommendation($recip, (int)$ka['kingdomaward_id'], (int)$ka['award_id'], ['monarchy_only' => $levelAtAdd, 'reason' => 'secret reason']);
        $court = $this->fx->createCourt(['kingdom_id' => $k, 'court_date' => date('Y-m-d')]);
        $off   = $this->fx->createPlayerWithToken('mo-c6g', $k, $p);
        $this->fx->insertScopedAuth($off['mundane_id'], 0, $k, 'create');
        $c  = new \Court();
        $id = (int)$c->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', $typed, true, $off['mundane_id'])['CourtAwardId'];
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . "court_award SET status = 'staged', given_by_mundane_id = " . (int)$off['mundane_id'] . ' WHERE court_award_id = ' . $id);
        if ($levelAtCommit !== null) {
            $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . 'recommendations SET monarchy_only = ' . $levelAtCommit . ' WHERE recommendations_id = ' . $rec);
        }
        unset($_SESSION['is_authorized_mundane_id']);
        $res = $c->commitStagedAward($id, ['Token' => $off['token']]);
        $this->assertSame('ok', $res['status'], 'Commit must succeed: ' . json_encode($res));
        $this->ledgerIds[] = (int)$res['award_id'];
        $this->assertSame($res['row']['PublicComment'], $this->fx->fetchAward($id)['public_comment'], 'the frozen citation is what was published');

        return (string)$this->fx->pdo()->query('SELECT note FROM ' . DB_PREFIX . 'awards WHERE awards_id = ' . (int)$res['award_id'])->fetchColumn();
    }

    /** Item 6: hidden at add and at commit — nothing of the reason is published. */
    public function testHiddenRecReasonIsNeverPublished(): void
    {
        $this->assertSame('', $this->commitLine(2, null));
    }

    /** Item 6: hidden at add, unhidden before commit — the reason inherits normally. */
    public function testRecUnhiddenAfterAddInheritsItsReason(): void
    {
        $this->assertSame('secret reason', $this->commitLine(1, 0));
    }

    /** What the officer typed is always the citation, hidden rec or not. */
    public function testTypedCommentOnAHiddenRecLineIsPublished(): void
    {
        $this->assertSame('typed by the crown', $this->commitLine(2, null, 'typed by the crown'));
    }

    /**
     * Review 2, item 1: a kingdom officer may put a kingdom-level hidden rec on a PARK
     * court, but that court's park officers (who cannot see the rec) get only the
     * Monarchy Only flag — never its reason or recommender.
     */
    public function testCourtReadersWithholdAHiddenRecFromCallersWhoCannotSeeIt(): void
    {
        $k      = $this->fx->firstKingdomId();
        $p      = $this->fx->firstParkId($k);
        $ka     = $this->fx->firstKingdomAwardId($k);
        $recip  = $this->fx->createPlayer('mo-pc', $k, $p)['mundane_id'];
        $author = $this->fx->createPlayer('mo-pcauthor', $k, $p);
        $rec    = $this->fx->createRecommendation($recip, (int)$ka['kingdomaward_id'], (int)$ka['award_id'], [
            'monarchy_only' => 2, 'reason' => 'secret reason', 'recommended_by_id' => $author['mundane_id'],
        ]);
        $court  = $this->fx->createCourt(['kingdom_id' => $k, 'park_id' => $p]);
        $kOff   = $this->kingdomOfficer($k, $p)['mundane_id'];
        $parkOff = $this->fx->createPlayerWithToken('mo-pcpark', $k, $p)['mundane_id'];
        $this->fx->insertScopedAuth($parkOff, $p, 0, 'edit');

        $c  = new \Court();
        $id = (int)$c->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, $kOff)['CourtAwardId'];
        $this->assertTrue($c->canManage($parkOff, $k, $p), 'the park officer manages this park court');

        $row = static fn (array $rows) => array_column($rows, null, 'CourtAwardId')[$id];
        foreach (['park officer' => $parkOff, 'no caller' => 0] as $who => $uid) {
            $r = $row($c->getCourtAwards($court, $uid));
            $this->assertTrue($r['RecMonarchyOnly'], "$who still sees the Monarchy Only notice");
            $this->assertSame('', $r['RecReason'], "$who must not get the hidden reason");
            $this->assertNull($r['RecByPersona'], "$who must not get the recommender");
        }
        $r = $row($c->getCourtAwards($court, $kOff));
        $this->assertSame('secret reason', $r['RecReason']);
        $this->assertSame($author['persona'], $r['RecByPersona']);

        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . "court_award SET status = 'staged' WHERE court_award_id = " . $id);
        $this->assertSame('', $row($c->getStagedAwards($court, $parkOff))['RecReason']);
        $this->assertSame('secret reason', $row($c->getStagedAwards($court, $kOff))['RecReason']);

        // Passed down to local, the rec is park-level: the park officer may now see it.
        $this->fx->setPassedToLocal($rec, true);
        $this->assertSame('secret reason', $row($c->getCourtAwards($court, $parkOff))['RecReason']);
    }

    /** Finding 2: a rec hidden AFTER its line was staged must not publish its reason at commit. */
    public function testCommitAfterRecHiddenPublishesNoReason(): void
    {
        $k     = $this->fx->firstKingdomId();
        $p     = $this->fx->firstParkId($k);
        $ka    = $this->fx->firstKingdomAwardId($k);
        $recip = $this->fx->createPlayer('mo-commit', $k, $p)['mundane_id'];
        $rec   = $this->fx->createRecommendation($recip, (int)$ka['kingdomaward_id'], (int)$ka['award_id'], ['reason' => 'secret reason']);
        $court = $this->fx->createCourt(['kingdom_id' => $k, 'court_date' => date('Y-m-d')]);
        // 'create' at the kingdom: Player::AddAward needs AUTH_CREATE over the recipient's park.
        $off   = $this->fx->createPlayerWithToken('mo-granter', $k, $p);
        $this->fx->insertScopedAuth($off['mundane_id'], 0, $k, 'create');

        $c  = new \Court();
        $id = (int)$c->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, $off['mundane_id'])['CourtAwardId'];
        $this->assertSame(0, (int)$this->fx->fetchAward($id)['public_comment_cleared'], 'Staged while public: inherits.');
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . "court_award SET status = 'staged', given_by_mundane_id = " . (int)$off['mundane_id'] . ' WHERE court_award_id = ' . $id);

        // The rec is hidden after its line was staged.
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . 'recommendations SET monarchy_only = 1 WHERE recommendations_id = ' . $rec);

        unset($_SESSION['is_authorized_mundane_id']);
        $res = $c->commitStagedAward($id, ['Token' => $off['token']]);
        $this->assertSame('ok', $res['status'], 'Commit must succeed: ' . json_encode($res));
        $this->ledgerIds[] = (int)$res['award_id'];
        $note = (string)$this->fx->pdo()->query('SELECT note FROM ' . DB_PREFIX . 'awards WHERE awards_id = ' . (int)$res['award_id'])->fetchColumn();
        $this->assertSame('', $note);
    }

    /** Finding 2, batched finalize: the preloaded row must not carry a stale "public" verdict. */
    public function testCommitFieldsReReadTheRecsHiddenState(): void
    {
        [$court, $k, $recip, $ka, $rec, $caller] = $this->hiddenRecSetup();
        $c  = new \Court();
        $id = (int)$c->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, $caller)['CourtAwardId'];
        $this->assertTrue($c->getAwardForCommit($id)['RecMonarchyOnly']);
        $this->assertTrue($c->getAwardCommitFields($id)['RecMonarchyOnly']);
    }

    /** Finding 5: a recommendation can only back a line for its own recipient. */
    public function testAddAwardRefusesARecForAnotherPlayer(): void
    {
        $k     = $this->fx->firstKingdomId();
        $p     = $this->fx->firstParkId($k);
        $ka    = $this->fx->firstKingdomAwardId($k);
        $recip = $this->fx->createPlayer('mo-own', $k, $p)['mundane_id'];
        $other = $this->fx->createPlayer('mo-other', $k, $p)['mundane_id'];
        $rec   = $this->fx->createRecommendation($recip, (int)$ka['kingdomaward_id'], (int)$ka['award_id'], ['reason' => 'about someone else']);
        $court = $this->fx->createCourt(['kingdom_id' => $k]);
        $caller = $this->kingdomOfficer($k, $p)['mundane_id'];

        $this->assertFalse((new \Court())->addAward($court, $k, $other, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, $caller));
        $this->assertFalse((new \Court())->canUseRecForLine($rec, $other, $caller));
    }

    /** Finding 5: a hidden rec needs a caller who can see it (no caller = refused). */
    public function testAddAwardRefusesAHiddenRecTheCallerCannotSee(): void
    {
        $k     = $this->fx->firstKingdomId();
        $p     = $this->fx->firstParkId($k);
        $ka    = $this->fx->firstKingdomAwardId($k);
        $recip = $this->fx->createPlayer('mo-cant', $k, $p)['mundane_id'];
        $rec   = $this->fx->createRecommendation($recip, (int)$ka['kingdomaward_id'], (int)$ka['award_id'], ['monarchy_only' => 2, 'reason' => 'secret reason']);
        $court = $this->fx->createCourt(['kingdom_id' => $k]);
        $parkOff = $this->fx->createPlayerWithToken('mo-pcaller', $k, $p)['mundane_id'];
        $this->fx->insertScopedAuth($parkOff, $p, 0, 'edit');

        $c = new \Court();
        $this->assertFalse($c->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', ''));
        $this->assertFalse($c->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, $parkOff));
        $this->assertSame(0, (int)$this->fx->pdo()->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'court_award WHERE court_id = ' . $court)->fetchColumn());

        $kOff = $this->kingdomOfficer($k, $p)['mundane_id'];
        $this->assertIsArray($c->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, $kOff));
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
