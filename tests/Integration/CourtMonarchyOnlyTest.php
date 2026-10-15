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

    // ---- Review 2, re-review follow-ups (A, B, C) ----------------------------

    /** @return array{0:int,1:int,2:int,3:array,4:int,5:array,6:\Court} court, kingdom, recipient, ka, rec, kingdom officer, Court */
    private function publicLineSetup(): array
    {
        $k     = $this->fx->firstKingdomId();
        $p     = $this->fx->firstParkId($k);
        $ka    = $this->fx->firstKingdomAwardId($k);
        $recip = $this->fx->createPlayer('mo-a', $k, $p)['mundane_id'];
        $rec   = $this->fx->createRecommendation($recip, (int)$ka['kingdomaward_id'], (int)$ka['award_id'], ['reason' => 'secret reason']);
        $court = $this->fx->createCourt(['kingdom_id' => $k]);
        $kOff  = $this->kingdomOfficer($k, $p);
        $c     = new \Court();
        $id    = (int)$c->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, $kOff['mundane_id'])['CourtAwardId'];
        return [$id, $k, $recip, $ka, $rec, $kOff, $c];
    }

    private function rowVersion(int $id): int
    {
        return (int)$this->fx->fetchAward($id)['row_version'];
    }

    /** A: hiding (or passing down) a rec bumps its open court lines, so the heartbeat resends them and stale writes are refused. */
    public function testVisibilityChangeBumpsTheRecsOpenCourtLines(): void
    {
        [$id, $k, $recip, $ka, $rec, $kOff] = $this->publicLineSetup();
        $player = \Ork3::$Lib->player;
        $v0 = $this->rowVersion($id);
        unset($_SESSION['is_authorized_mundane_id']);
        $this->assertSame(0, $player->SetRecommendationMonarchyOnly(['Token' => $kOff['token'], 'RecommendationsId' => $rec, 'Hidden' => 1])['Status']);
        $v1 = $this->rowVersion($id);
        $this->assertGreaterThan($v0, $v1, 'hiding the rec must bump its open line');

        // Pass-down needs kingdom CREATE authority.
        $this->fx->insertScopedAuth($kOff['mundane_id'], 0, $k, 'create');
        unset($_SESSION['is_authorized_mundane_id']);
        $this->assertSame(0, $player->SetRecommendationPassedToLocal(['Token' => $kOff['token'], 'RecommendationsId' => $rec, 'Passed' => 1])['Status']);
        $this->assertGreaterThan($v1, $this->rowVersion($id), 'passing a hidden rec down changes its effective level');
    }

    /** A: a grant staged by a planner that still holds the pre-hide RowVersion is refused. */
    public function testStaleStageAfterHideIsRefused(): void
    {
        [$id, $k, $recip, $ka, $rec, $kOff, $c] = $this->publicLineSetup();
        $stale = $this->rowVersion($id);
        unset($_SESSION['is_authorized_mundane_id']);
        \Ork3::$Lib->player->SetRecommendationMonarchyOnly(['Token' => $kOff['token'], 'RecommendationsId' => $rec, 'Hidden' => 1]);
        $this->assertFalse($c->stageAward($id, $kOff['mundane_id'], 'secret reason', 1, $stale));
        $this->assertSame('planned', $this->fx->fetchAward($id)['status']);
    }

    /** A: whatever the RowVersion, a citation that IS the hidden rec's reason is refused. */
    public function testCitationEqualToAHiddenReasonIsRefused(): void
    {
        [$id, $k, $recip, $ka, $rec, $kOff, $c] = $this->publicLineSetup();
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . 'recommendations SET monarchy_only = 2 WHERE recommendations_id = ' . $rec);

        $this->assertFalse($c->updateAward($id, ['PublicComment' => 'secret reason']));
        $this->assertFalse($c->updateAward($id, ['PublicComment' => '  secret reason '], $this->rowVersion($id)));
        $this->assertFalse($c->stageAward($id, $kOff['mundane_id'], 'secret reason', 1));
        $this->assertSame('', $this->fx->fetchAward($id)['public_comment']);
        $this->assertSame('planned', $this->fx->fetchAward($id)['status']);

        // Anything the officer actually wrote is fine.
        $this->assertTrue($c->updateAward($id, ['PublicComment' => 'For service to the realm.']));
        $this->assertTrue($c->stageAward($id, $kOff['mundane_id'], 'For service to the realm.', 1));
    }

    /** A: the guard is about hidden recs only — a public rec's reason may still be the citation. */
    public function testCitationEqualToAPublicReasonIsAllowed(): void
    {
        [$id, $k, $recip, $ka, $rec, $kOff, $c] = $this->publicLineSetup();
        $this->assertTrue($c->updateAward($id, ['PublicComment' => 'secret reason']));
    }

    /**
     * B: a park officer finalizing a park-court line built from a kingdom-level hide
     * (added by a kingdom officer) closes that rec — it cannot be granted again —
     * and cascades its seconds, even though the park officer cannot see it.
     */
    public function testParkCourtCommitClosesTheLinesOwnHiddenRec(): void
    {
        $k      = $this->fx->firstKingdomId();
        $p      = $this->fx->firstParkId($k);
        $ka     = $this->fx->firstKingdomAwardId($k);
        $recip  = $this->fx->createPlayer('mo-b', $k, $p)['mundane_id'];
        $author = $this->fx->createPlayer('mo-bauthor', $k, $p)['mundane_id'];
        $rec    = $this->fx->createRecommendation($recip, (int)$ka['kingdomaward_id'], (int)$ka['award_id'], [
            'monarchy_only' => 2, 'reason' => 'secret reason', 'recommended_by_id' => $author,
        ]);
        $supporter = $this->fx->createPlayer('mo-bsec', $k, $p)['mundane_id'];
        $this->fx->pdo()->exec('INSERT INTO ' . DB_PREFIX . "recommendation_seconds (recommendations_id, supporter_mundane_id, notes) VALUES ($rec, $supporter, 'me too')");
        $secondId = (int)$this->fx->pdo()->lastInsertId();
        $court  = $this->fx->createCourt(['kingdom_id' => $k, 'park_id' => $p, 'court_date' => date('Y-m-d')]);
        $kOff   = $this->kingdomOfficer($k, $p);
        $parkOff = $this->fx->createPlayerWithToken('mo-bpark', $k, $p);
        $this->fx->insertScopedAuth($parkOff['mundane_id'], $p, 0, 'create');

        $c  = new \Court();
        $id = (int)$c->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, $kOff['mundane_id'])['CourtAwardId'];
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . "court_award SET status = 'staged', given_by_mundane_id = " . (int)$parkOff['mundane_id'] . ' WHERE court_award_id = ' . $id);

        try {
            unset($_SESSION['is_authorized_mundane_id']);
            $res = $c->commitStagedAward($id, ['Token' => $parkOff['token']]);
            $this->assertSame('ok', $res['status'], 'Commit must succeed: ' . json_encode($res));
            $this->ledgerIds[] = (int)$res['award_id'];

            $row = $this->fx->fetchRecommendation($rec);
            $this->assertNotNull($row['deleted_at'], 'the granted hidden rec is closed');
            $this->assertSame((int)$parkOff['mundane_id'], (int)$row['deleted_by']);
            $sec = $this->fx->pdo()->query('SELECT deleted_at, deleted_by FROM ' . DB_PREFIX . 'recommendation_seconds WHERE recommendation_seconds_id = ' . $secondId)->fetch(\PDO::FETCH_ASSOC);
            $this->assertNotNull($sec['deleted_at'], 'its seconds cascade');
            $this->assertSame($row['deleted_at'], $sec['deleted_at']);
        } finally {
            $this->fx->pdo()->exec('DELETE FROM ' . DB_PREFIX . 'recommendation_seconds WHERE recommendation_seconds_id = ' . $secondId);
            $this->fx->pdo()->exec('DELETE FROM ' . DB_PREFIX . 'notification WHERE mundane_id IN (' . (int)$author . ',' . (int)$supporter . ')');
        }
    }

    /** C: a court manager who is the line's recipient is not told a hidden rec backs their own line. */
    public function testRecipientManagerIsNotToldAHiddenRecBacksTheirLine(): void
    {
        $k      = $this->fx->firstKingdomId();
        $p      = $this->fx->firstParkId($k);
        $ka     = $this->fx->firstKingdomAwardId($k);
        $recipOff = $this->kingdomOfficer($k, $p)['mundane_id'];
        $author = $this->fx->createPlayer('mo-cauthor', $k, $p);
        $rec    = $this->fx->createRecommendation($recipOff, (int)$ka['kingdomaward_id'], (int)$ka['award_id'], [
            'monarchy_only' => 2, 'reason' => 'secret reason', 'recommended_by_id' => $author['mundane_id'],
        ]);
        $court  = $this->fx->createCourt(['kingdom_id' => $k]);
        $kOff   = $this->kingdomOfficer($k, $p)['mundane_id'];
        $c  = new \Court();
        $id = (int)$c->addAward($court, $k, $recipOff, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, $kOff)['CourtAwardId'];
        $this->assertTrue($c->canManage($recipOff, $k, 0));

        $mine = array_column($c->getCourtAwards($court, $recipOff), null, 'CourtAwardId')[$id];
        $this->assertFalse($mine['RecMonarchyOnly'], 'no Monarchy Only notice on your own line');
        $this->assertSame('', $mine['RecReason']);
        $this->assertNull($mine['RecByPersona']);
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . "court_award SET status = 'staged' WHERE court_award_id = " . $id);
        $staged = array_column($c->getStagedAwards($court, $recipOff), null, 'CourtAwardId')[$id];
        $this->assertFalse($staged['RecMonarchyOnly']);
        $this->assertSame('', $staged['RecReason']);

        // Another officer still sees the flag and the reason.
        $theirs = array_column($c->getCourtAwards($court, $kOff), null, 'CourtAwardId')[$id];
        $this->assertTrue($theirs['RecMonarchyOnly']);
        $this->assertSame('secret reason', $theirs['RecReason']);
    }

    // ---- Re-review 2 (D, E, F) ----------------------------------------------

    /**
     * Line drawn from a PUBLIC rec on a dated court, with a granting officer who has
     * kingdom create authority. @return array{0:int,1:int,2:array,3:\Court,4:int,5:int}
     * line id, rec id, officer, Court, court id, recipient
     */
    private function commitableLine(): array
    {
        $k     = $this->fx->firstKingdomId();
        $p     = $this->fx->firstParkId($k);
        $ka    = $this->fx->firstKingdomAwardId($k);
        $recip = $this->fx->createPlayer('mo-d', $k, $p)['mundane_id'];
        $rec   = $this->fx->createRecommendation($recip, (int)$ka['kingdomaward_id'], (int)$ka['award_id'], ['reason' => 'secret reason']);
        $court = $this->fx->createCourt(['kingdom_id' => $k, 'court_date' => date('Y-m-d')]);
        $off   = $this->fx->createPlayerWithToken('mo-dg', $k, $p);
        $this->fx->insertScopedAuth($off['mundane_id'], 0, $k, 'create');
        $c  = new \Court();
        $id = (int)$c->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, $off['mundane_id'])['CourtAwardId'];
        return [$id, $rec, $off, $c, $court, $recip];
    }

    private function commitAndNote(\Court $c, int $id, array $off, ?array $row = null): string
    {
        unset($_SESSION['is_authorized_mundane_id']);
        $ctx = ['Token' => $off['token']];
        if ($row !== null) {
            $ctx['Row'] = $row;
        }
        $res = $c->commitStagedAward($id, $ctx);
        $this->assertSame('ok', $res['status'], 'Commit must succeed: ' . json_encode($res));
        $this->ledgerIds[] = (int)$res['award_id'];
        $this->assertSame($res['row']['PublicComment'], $this->fx->fetchAward($id)['public_comment'], 'the Court Report shows what was published');
        return (string)$this->fx->pdo()->query('SELECT note FROM ' . DB_PREFIX . 'awards WHERE awards_id = ' . (int)$res['award_id'])->fetchColumn();
    }

    private function hide(int $rec): void
    {
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . 'recommendations SET monarchy_only = 2 WHERE recommendations_id = ' . $rec);
    }

    /** D: the reviewer's sequence — staged with the reason while public, hidden before finalize. */
    public function testReasonStagedWhilePublicIsNotPublishedOnceHidden(): void
    {
        [$id, $rec, $off, $c] = $this->commitableLine();
        $this->assertTrue($c->stageAward($id, $off['mundane_id'], 'secret reason', 1));
        $this->hide($rec);
        $this->assertSame('', $this->commitAndNote($c, $id, $off));
    }

    /** D: the batched finalize path (preloaded Row) and the bulk-record path. */
    public function testBulkRecordedLineWithTheReasonIsNotPublishedOnceHidden(): void
    {
        [$id, $rec, $off, $c, $court] = $this->commitableLine();
        // "(Start from Rec)" + Save while public, then Bulk Record Grants.
        $this->assertTrue($c->updateAward($id, ['PublicComment' => 'secret reason']));
        $this->assertSame(1, $c->bulkStagePlanned($court, $off['mundane_id']));
        $staged = array_column($c->getStagedAwards($court, $off['mundane_id']), null, 'CourtAwardId')[$id];
        $this->hide($rec);
        $this->assertSame('', $this->commitAndNote($c, $id, $off, $staged));
    }

    /** D: what the officer actually wrote is still published, hidden rec or not. */
    public function testDifferentTypedCitationIsStillPublishedOnceHidden(): void
    {
        [$id, $rec, $off, $c] = $this->commitableLine();
        $this->assertTrue($c->stageAward($id, $off['mundane_id'], 'For service to the realm.', 1));
        $this->hide($rec);
        $this->assertSame('For service to the realm.', $this->commitAndNote($c, $id, $off));
    }

    /**
     * E: a line whose stored citation is a now-hidden reason must not be stuck. Court
     * reads present that citation as empty (so Grant does not pre-fill it and Save does
     * not re-post it), and a post of it gets an honest error, not "reload".
     */
    public function testStoredHiddenReasonIsPresentedEmptyAndRefusedHonestly(): void
    {
        [$id, $rec, $off, $c, $court] = $this->commitableLine();
        $this->assertTrue($c->updateAward($id, ['PublicComment' => 'secret reason']));
        $this->hide($rec);

        foreach ([$off['mundane_id'], 0] as $caller) {
            $row = array_column($c->getCourtAwards($court, $caller), null, 'CourtAwardId')[$id];
            $this->assertSame('', $row['PublicComment'], "caller $caller must not be handed the hidden reason as the citation");
        }
        $this->assertTrue($c->isHiddenRecCitation($id, ' secret reason '));
        $this->assertFalse($c->isHiddenRecCitation($id, 'something else'));
        $this->assertSame(
            'This citation is the text of a Monarchy Only recommendation, which is never published. Edit or clear it to continue.',
            \Court::HIDDEN_CITATION_MESSAGE
        );
        // What the presented (empty) field saves back unsticks the line.
        $this->assertTrue($c->updateAward($id, ['PublicComment' => '', 'PublicCommentCleared' => 0]));
        $this->assertSame('', $this->fx->fetchAward($id)['public_comment']);
    }

    /** F: a recipient who manages the court gets no rec id on a hidden-rec line — and commit still closes the rec. */
    public function testRecipientManagerGetsNoRecIdAndCommitStillResolves(): void
    {
        $k      = $this->fx->firstKingdomId();
        $p      = $this->fx->firstParkId($k);
        $ka     = $this->fx->firstKingdomAwardId($k);
        $recipOff = $this->fx->createPlayerWithToken('mo-frecip', $k, $p);
        $this->fx->insertScopedAuth($recipOff['mundane_id'], 0, $k, 'create');
        $author = $this->fx->createPlayer('mo-fauthor', $k, $p)['mundane_id'];
        $rec    = $this->fx->createRecommendation($recipOff['mundane_id'], (int)$ka['kingdomaward_id'], (int)$ka['award_id'], [
            'monarchy_only' => 2, 'reason' => 'secret reason', 'recommended_by_id' => $author,
        ]);
        $court  = $this->fx->createCourt(['kingdom_id' => $k, 'court_date' => date('Y-m-d')]);
        $kOff   = $this->kingdomOfficer($k, $p)['mundane_id'];
        $c  = new \Court();
        $id = (int)$c->addAward($court, $k, $recipOff['mundane_id'], (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '', true, $kOff)['CourtAwardId'];

        $mine = array_column($c->getCourtAwards($court, $recipOff['mundane_id']), null, 'CourtAwardId')[$id];
        $this->assertNull($mine['RecommendationsId']);
        $this->assertTrue($mine['IsWalkOn'], 'reads like a line with no recommendation behind it');
        $this->assertNotNull(array_column($c->getCourtAwards($court, $kOff), null, 'CourtAwardId')[$id]['RecommendationsId']);

        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . "court_award SET status = 'staged', given_by_mundane_id = " . (int)$kOff . ' WHERE court_award_id = ' . $id);
        $staged = array_column($c->getStagedAwards($court, $recipOff['mundane_id']), null, 'CourtAwardId')[$id];
        $this->assertSame(0, $staged['RecommendationsId']);
        try {
            // Finalized by the recipient-manager with their own (blanked) preloaded row:
            // the rec id must come from the database.
            $this->commitAndNote($c, $id, $recipOff, $staged);
            $this->assertNotNull($this->fx->fetchRecommendation($rec)['deleted_at'], 'the line still closes its own rec');
        } finally {
            $this->fx->pdo()->exec('DELETE FROM ' . DB_PREFIX . 'notification WHERE mundane_id = ' . (int)$author);
        }
    }

    /** Spec: the committed line fulfills its own rec even at a different rank than recommended. */
    public function testCommitAtADifferentRankStillClosesTheLinesOwnRec(): void
    {
        [$id, $rec, $off, $c] = $this->commitableLine();
        $this->assertTrue($c->stageAward($id, $off['mundane_id'], '', 3));
        $this->commitAndNote($c, $id, $off);
        $this->assertSame(1, (int)$this->fx->fetchRecommendation($rec)['rank']);
        $this->assertNotNull($this->fx->fetchRecommendation($rec)['deleted_at']);
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
