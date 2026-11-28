<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Circle of Knights Manager, pipeline half (KnightCandidate): reconcile, the
 * suggestions, adding and moving candidates, notes, the award hook and the
 * authority rules. Needs the knight-circle migration applied to the test DB;
 * skips otherwise.
 */
final class KnightCandidateTest extends TestCase
{
    private ReportsFixture $fixture;
    private KnightCandidate $kc;
    private KnightCircle $circle;
    private PDO $pdo;
    private int $kingdom = 0;
    private int $park = 0;
    private bool $hadConfigRow = false;
    /** @var int[] */
    private array $mundaneIds = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        unset($_SESSION['is_authorized_mundane_id']);
        $this->fixture = ReportsFixture::create();
        $this->pdo     = $this->fixture->pdo();
        $exists = $this->pdo->query("SHOW TABLES LIKE '" . DB_PREFIX . "knight_candidate'")->fetchColumn();
        if (!$exists) {
            $this->markTestSkipped('db-migrations/2026-11-28-knight-circle.sql is not applied to the test database.');
        }
        $this->kc     = new KnightCandidate();
        $this->circle = new KnightCircle();

        $this->kingdom = $this->fixture->firstKingdomId();
        $this->park    = $this->fixture->parkIdInKingdom($this->kingdom);
        $this->assertGreaterThan(0, $this->park, 'the sandbox needs a park in its first kingdom');
        $this->hadConfigRow = (bool) $this->pdo->query(
            'SELECT 1 FROM ' . DB_PREFIX . 'knight_circle_config WHERE kingdom_id = ' . $this->kingdom
        )->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo, $this->fixture)) {
            if ($this->mundaneIds) {
                $in = implode(',', $this->mundaneIds);
                // History and notes cascade from the candidate rows.
                $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'knight_candidate WHERE mundane_id IN (' . $in . ')');
                $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'knight_circle_admin WHERE mundane_id IN (' . $in . ')');
            }
            if (!$this->hadConfigRow && $this->kingdom > 0) {
                $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'knight_circle_config WHERE kingdom_id = ' . $this->kingdom);
            }
            $this->fixture->cleanup();
        }
    }

    // ---- helpers ----------------------------------------------------------

    private function player(string $suffix): int
    {
        $p = $this->fixture->createPlayer($this->park, $suffix);
        $this->mundaneIds[] = $p['mundane_id'];
        return $p['mundane_id'];
    }

    /** A Guardian of the Circle (a knight made GMoK by an ORK admin). */
    private function gmok(): int
    {
        $mid = $this->player('gmok');
        $this->knight($mid, 20);
        $admin = $this->player('orkadmin');
        $this->fixture->insertGlobalAdmin($admin);
        $r = $this->circle->setGmok($admin, $this->kingdom, $mid);
        $this->assertSame(0, $r['Status'], $r['Error'] ?? '');
        return $mid;
    }

    private function knight(int $mid, int $orderId = 17, int $revoked = 0): int
    {
        $ka = $this->fixture->insertKingdomAward($this->kingdom, $orderId, 'KC test knight ' . $orderId);
        return $this->fixture->insertAwardOn($mid, $this->park, $this->kingdom, $ka, $orderId, 0, '2020-01-01', $revoked);
    }

    /** Award id => awards_id; a Master (award 1 = Master Rose leads to Flame). */
    private function master(int $mid, int $masterId = 1): int
    {
        $ka = $this->fixture->insertKingdomAward($this->kingdom, $masterId, 'KC test master ' . $masterId);
        return $this->fixture->insertAwardOn($mid, $this->park, $this->kingdom, $ka, $masterId, 0, '2020-01-01');
    }

    private function row(int $cid): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ' . DB_PREFIX . 'knight_candidate WHERE candidate_id = ?');
        $st->execute([$cid]);
        return (array) $st->fetch(PDO::FETCH_ASSOC);
    }

    private function historyCount(int $cid): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM ' . DB_PREFIX . 'knight_candidate_history WHERE candidate_id = ?');
        $st->execute([$cid]);
        return (int) $st->fetchColumn();
    }

    private function add(int $gmok, int $mid, int $order = 17): int
    {
        $r = $this->kc->addCandidate($gmok, $this->kingdom, $mid, $order, 'seed');
        $this->assertSame(0, $r['Status'], $r['Error'] ?? '');
        return $r['CandidateId'];
    }

    // ---- add --------------------------------------------------------------

    public function testOnlyACircleAdminAddsCandidates(): void
    {
        $stranger = $this->player('stranger');
        $target   = $this->player('target');
        $this->assertSame(3, $this->kc->addCandidate($stranger, $this->kingdom, $target, 17)['Status']);

        $gmok = $this->gmok();
        $this->assertSame(1, $this->kc->addCandidate($gmok, $this->kingdom, $target, 999)['Status'], 'unknown order');
        $this->assertSame(1, $this->kc->addCandidate($gmok, $this->kingdom, 0, 17)['Status'], 'player required');

        $cid = $this->add($gmok, $target, 17);
        $this->assertSame('watchlist', $this->row($cid)['stage']);
        $this->assertSame(1, $this->historyCount($cid));
        $this->assertSame(1, $this->kc->addCandidate($gmok, $this->kingdom, $target, 17)['Status'], 'already a candidate');
    }

    public function testCannotAddSomeoneWhoAlreadyHoldsTheOrder(): void
    {
        $gmok  = $this->gmok();
        $held  = $this->player('held');
        $this->knight($held, 17);
        $this->assertSame(1, $this->kc->addCandidate($gmok, $this->kingdom, $held, 17)['Status']);
        $this->assertSame(0, $this->kc->addCandidate($gmok, $this->kingdom, $held, 19)['Status'], 'a different order is fine');
    }

    public function testLegendsAreRefused(): void
    {
        $gmok   = $this->gmok();
        $legend = $this->player('legend');
        $this->pdo->exec('UPDATE ' . DB_PREFIX . 'mundane SET is_legend = 1 WHERE mundane_id = ' . $legend);
        $r = $this->kc->addCandidate($gmok, $this->kingdom, $legend, 17);
        $this->assertSame(3, $r['Status']);
        $this->assertSame(Legend::REFUSED, $r['Error']);
        $this->pdo->exec('UPDATE ' . DB_PREFIX . 'mundane SET is_legend = 0 WHERE mundane_id = ' . $legend);
    }

    // ---- moves ------------------------------------------------------------

    public function testMovesFollowTheGraphAndRecordHistory(): void
    {
        $gmok = $this->gmok();
        $cid  = $this->add($gmok, $this->player('walker'));

        $this->assertSame(1, $this->kc->moveCandidate($gmok, $cid, 'voting')['Status'], 'cannot skip Discussion');
        $this->assertSame(1, $this->kc->moveCandidate($gmok, $cid, 'knighted')['Status'], 'Knighted is system-only');
        $this->assertSame(1, $this->kc->moveCandidate($gmok, $cid, 'nonsense')['Status']);

        $this->assertSame(0, $this->kc->moveCandidate($gmok, $cid, 'discussion', 'Talked it over')['Status']);
        $this->assertSame(0, $this->kc->moveCandidate($gmok, $cid, 'voting')['Status']);
        $this->assertSame('voting', $this->row($cid)['stage']);
        $this->assertSame($gmok, (int) $this->row($cid)['stage_changed_by']);
        $this->assertSame(3, $this->historyCount($cid));
    }

    public function testVotesAreRecordedOnlyWhenLeavingVoting(): void
    {
        $gmok = $this->gmok();
        $cid  = $this->add($gmok, $this->player('voted'));
        $this->kc->moveCandidate($gmok, $cid, 'discussion');
        // Votes sent for a move that is not leaving Voting are ignored.
        $this->kc->moveCandidate($gmok, $cid, 'voting', '', ['For' => 9, 'Against' => 9]);
        $st = $this->pdo->prepare('SELECT votes_for FROM ' . DB_PREFIX . 'knight_candidate_history WHERE candidate_id = ? AND to_stage = ?');
        $st->execute([$cid, 'voting']);
        $this->assertNull($st->fetchColumn(), 'the tally is only kept when leaving Voting');

        $this->assertSame(1, $this->kc->moveCandidate($gmok, $cid, 'elect', '', ['For' => -4])['Status'], 'bad tally');
        $this->assertSame(0, $this->kc->moveCandidate($gmok, $cid, 'elect', 'Carried', ['For' => 8, 'Against' => 2, 'Abstain' => 1])['Status']);
        $st = $this->pdo->prepare('SELECT votes_for, votes_against, votes_abstain FROM ' . DB_PREFIX . 'knight_candidate_history WHERE candidate_id = ? AND to_stage = ?');
        $st->execute([$cid, 'elect']);
        $this->assertSame([8, 2, 1], array_map('intval', array_values((array) $st->fetch(PDO::FETCH_ASSOC))));
    }

    public function testMovingNeedsCircleAuthority(): void
    {
        $gmok = $this->gmok();
        $cid  = $this->add($gmok, $this->player('card'));
        $knightViewer = $this->player('viewer');
        $this->knight($knightViewer);
        $this->assertSame(3, $this->kc->moveCandidate($knightViewer, $cid, 'discussion')['Status']);
        $this->assertSame(1, $this->kc->moveCandidate($gmok, 0, 'discussion')['Status']);
    }

    public function testDismissedCanBeReinstatedByAddingAgain(): void
    {
        $gmok = $this->gmok();
        $mid  = $this->player('back');
        $cid  = $this->add($gmok, $mid);
        $this->assertSame(0, $this->kc->moveCandidate($gmok, $cid, 'dismissed', 'Not yet')['Status']);
        $again = $this->kc->addCandidate($gmok, $this->kingdom, $mid, 17);
        $this->assertSame(0, $again['Status'], $again['Error'] ?? '');
        $this->assertSame($cid, $again['CandidateId']);
        $this->assertSame('watchlist', $this->row($cid)['stage']);
    }

    // ---- reconcile --------------------------------------------------------

    public function testReconcileKnightsACandidateAndIsIdempotent(): void
    {
        $gmok = $this->gmok();
        $mid  = $this->player('rising');
        $cid  = $this->add($gmok, $mid, 17);
        $this->kc->moveCandidate($gmok, $cid, 'discussion');

        $awardsId = $this->knight($mid, 17);
        $r = $this->kc->reconcile($this->kingdom);
        $this->assertSame(0, $r['Status']);
        $row = $this->row($cid);
        $this->assertSame('knighted', $row['stage']);
        $this->assertSame($awardsId, (int) $row['knighted_awards_id']);
        $this->assertSame(0, (int) $row['stage_changed_by']);

        $count = $this->historyCount($cid);
        $again = $this->kc->reconcile($this->kingdom);
        $this->assertSame(0, $again['Knighted']);
        $this->assertSame($count, $this->historyCount($cid), 'a second reconcile writes nothing');
    }

    public function testReconcilePromotesWatchlistToMasterhoodAndNeverBackwards(): void
    {
        $gmok = $this->gmok();
        $mid  = $this->player('master');
        $cid  = $this->add($gmok, $mid, 17);
        $this->master($mid, 1);
        $this->kc->reconcile($this->kingdom);
        $this->assertSame('masterhood', $this->row($cid)['stage']);

        $other = $this->player('deeper');
        $cid2  = $this->add($gmok, $other, 17);
        $this->kc->moveCandidate($gmok, $cid2, 'discussion');
        $this->master($other, 1);
        $this->kc->reconcile($this->kingdom);
        $this->assertSame('discussion', $this->row($cid2)['stage'], 'never moved backwards to Masterhood');
    }

    public function testReconcileAutoIncludesMastersWithoutTheKnighthood(): void
    {
        $mid = $this->player('automaster');
        $this->master($mid, 4); // Master Owl -> Serpent
        $this->kc->reconcile($this->kingdom);
        $st = $this->pdo->prepare('SELECT candidate_id, stage, source FROM ' . DB_PREFIX . 'knight_candidate WHERE kingdom_id = ? AND mundane_id = ? AND award_id = 19');
        $st->execute([$this->kingdom, $mid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('masterhood', $row['stage']);
        $this->assertSame('master_auto', $row['source']);
        $this->assertSame(1, $this->historyCount((int) $row['candidate_id']));

        $this->kc->reconcile($this->kingdom);
        $this->assertSame(1, $this->historyCount((int) $row['candidate_id']), 'idempotent');
    }

    public function testReconcileSkipsMastersWhoAreAlreadyKnightsAndDismissedRows(): void
    {
        $gmok = $this->gmok();
        $knight = $this->player('knightmaster');
        $this->master($knight, 4);
        $this->knight($knight, 19);
        $dismissed = $this->player('dismissedmaster');
        $cid = $this->add($gmok, $dismissed, 19);
        $this->kc->moveCandidate($gmok, $cid, 'dismissed');
        $this->master($dismissed, 4);
        $this->kc->reconcile($this->kingdom);

        $st = $this->pdo->prepare('SELECT COUNT(*) FROM ' . DB_PREFIX . 'knight_candidate WHERE mundane_id = ?');
        $st->execute([$knight]);
        $this->assertSame(0, (int) $st->fetchColumn(), 'a knight is not auto-included');
        $this->assertSame('dismissed', $this->row($cid)['stage'], 'dismissal sticks');
    }

    // ---- suggestions ------------------------------------------------------

    public function testSuggestionsUseRankAndExcludeHoldersAndExistingRows(): void
    {
        $gmok   = $this->gmok();
        $high   = $this->player('high');
        $low    = $this->player('low');
        $holder = $this->player('holder');
        $listed = $this->player('listed');
        foreach ([[$high, 9], [$low, 4], [$holder, 9], [$listed, 9]] as [$mid, $rank]) {
            $ka = $this->fixture->insertKingdomAward($this->kingdom, 21, 'KC test rose'); // Rose -> Flame
            $this->fixture->insertLadderAward($mid, $this->park, $this->kingdom, $ka, 21, $rank);
        }
        $this->knight($holder, 17);
        $this->add($gmok, $listed, 17);

        $r = $this->kc->suggestions($this->kingdom, 9);
        $this->assertSame(0, $r['Status'], $r['Error'] ?? '');
        $ids = array_column($r['Suggestions'], 'MundaneId');
        $this->assertContains($high, $ids);
        $this->assertNotContains($low, $ids, 'below the minimum rank');
        $this->assertNotContains($holder, $ids, 'already holds the order');
        $this->assertNotContains($listed, $ids, 'already a candidate');
        foreach ($r['Suggestions'] as $s) {
            if ($s['MundaneId'] === $high) {
                $this->assertSame(17, $s['OrderAwardId']);
            }
        }
        $this->assertContains($low, array_column($this->kc->suggestions($this->kingdom, 3)['Suggestions'], 'MundaneId'));
    }

    // ---- board / candidate / notes ----------------------------------------

    public function testBoardReconcilesHidesOwnCandidacyFromNonAdminsAndShowsSuggestionsToAdmins(): void
    {
        $gmok   = $this->gmok();
        $viewer = $this->player('viewer');
        $this->knight($viewer, 17);
        $own    = $this->add($gmok, $viewer, 19);
        $other  = $this->add($gmok, $this->player('other'), 19);

        $asKnight = $this->kc->board($viewer, $this->kingdom);
        $this->assertSame(0, $asKnight['Status'], $asKnight['Error'] ?? '');
        $seen = array_column($asKnight['Stages']['watchlist'], 'CandidateId');
        $this->assertContains($other, $seen);
        $this->assertNotContains($own, $seen, 'a knight does not see their own candidacy');
        $this->assertSame([], $asKnight['Suggestions']);

        $asAdmin = $this->kc->board($gmok, $this->kingdom);
        $this->assertContains($own, array_column($asAdmin['Stages']['watchlist'], 'CandidateId'));
        $this->assertArrayHasKey('Suggestions', $asAdmin);

        $this->assertSame(3, $this->kc->board($this->player('nobody'), $this->kingdom)['Status']);
        $this->assertSame(3, $this->kc->candidate($viewer, $own)['Status'], 'own candidacy detail is hidden too');
    }

    public function testNotesAreAddedAndSoftDeleted(): void
    {
        $gmok = $this->gmok();
        $cid  = $this->add($gmok, $this->player('noted'));
        $stranger = $this->player('stranger');
        $this->assertSame(3, $this->kc->addNote($stranger, $cid, 'hello')['Status']);
        $this->assertSame(1, $this->kc->addNote($gmok, $cid, "   ")['Status']);

        $n = $this->kc->addNote($gmok, $cid, "It's a 'quoted' \\ note");
        $this->assertSame(0, $n['Status'], $n['Error'] ?? '');
        $detail = $this->kc->candidate($gmok, $cid);
        $this->assertSame(0, $detail['Status'], $detail['Error'] ?? '');
        $this->assertSame("It's a 'quoted' \\ note", $detail['Notes'][0]['Body']);
        $this->assertSame(1, $detail['Candidate']['NoteCount']);

        $this->assertSame(3, $this->kc->deleteNote($stranger, $n['NoteId'])['Status']);
        $this->assertSame(0, $this->kc->deleteNote($gmok, $n['NoteId'])['Status']);
        $this->assertSame(1, $this->kc->deleteNote($gmok, $n['NoteId'])['Status'], 'already deleted');
        $this->assertSame([], $this->kc->candidate($gmok, $cid)['Notes']);
        $row = $this->pdo->query('SELECT deleted_by FROM ' . DB_PREFIX . 'knight_candidate_note WHERE note_id = ' . (int) $n['NoteId'])->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($gmok, (int) $row['deleted_by'], 'soft delete keeps the row');
    }

    // ---- the award hook ---------------------------------------------------

    public function testHookKnightsAndPromotesMatchingCandidatesAndNeverCreatesRows(): void
    {
        $gmok = $this->gmok();
        $mid  = $this->player('hooked');
        $cid  = $this->add($gmok, $mid, 17);

        $masterAwards = $this->master($mid, 1);
        $this->kc->onAwardGranted($mid, 1, $masterAwards);
        $this->assertSame('masterhood', $this->row($cid)['stage']);

        $knightAwards = $this->knight($mid, 17);
        $this->kc->onAwardGranted($mid, 17, $knightAwards);
        $this->assertSame('knighted', $this->row($cid)['stage']);
        $this->assertSame($knightAwards, (int) $this->row($cid)['knighted_awards_id']);

        $stranger = $this->player('norow');
        $a = $this->knight($stranger, 17);
        $this->kc->onAwardGranted($stranger, 17, $a);
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM ' . DB_PREFIX . 'knight_candidate WHERE mundane_id = ?');
        $st->execute([$stranger]);
        $this->assertSame(0, (int) $st->fetchColumn(), 'the hook never creates rows');

        // Garbage in, no exception out.
        $this->kc->onAwardGranted(0, 0, 0);
        $this->kc->onAwardGranted(-5, 99999999, 99999999);
        $this->assertTrue(true);
    }
}
