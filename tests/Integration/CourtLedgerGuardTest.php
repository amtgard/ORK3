<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the two ledger/publication guards on the court path:
 *
 *  (a) commitStagedAward must not write a SECOND ork_awards row for an honor the
 *      permanent record already carries (a retried finalize, or the same honor
 *      staged on two different courts). Repeatable custom awards stay grantable.
 *  (b) The login-free Court Report must only publish COMPLETE courts — a draft or
 *      published court can already carry a 'given' line via the Recs-Manager
 *      "Grant & Leave on Court" path.
 */
final class CourtLedgerGuardTest extends TestCase
{
    private CourtFixture $fixture;
    private Court $court;
    /** @var list<int> */
    private array $ledgerRowIds = [];
    /** @var list<int> */
    private array $touchedMundaneIds = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->fixture = CourtFixture::create();
        $this->court = new Court();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            // Drop every ork_awards row that touches an ephemeral test player —
            // both the ones seeded here and anything a commit path may have written.
            $ids = array_unique(array_merge($this->ledgerRowIds, []));
            if ($ids) {
                $this->fixture->pdo()->exec(
                    'DELETE FROM ' . DB_PREFIX . 'awards WHERE awards_id IN (' . implode(',', $ids) . ')'
                );
            }
            if ($this->touchedMundaneIds) {
                $this->fixture->pdo()->exec(
                    'DELETE FROM ' . DB_PREFIX . 'awards WHERE mundane_id IN ('
                    . implode(',', $this->touchedMundaneIds) . ')'
                );
            }
            $this->fixture->cleanup();
        }
    }

    /** A kingdomaward in $kingdomId whose base award is a ladder award. */
    private function ladderKingdomAwardId(int $kingdomId): int
    {
        $st = $this->fixture->pdo()->prepare(
            'SELECT ka.kingdomaward_id FROM ' . DB_PREFIX . 'kingdomaward ka
             JOIN ' . DB_PREFIX . 'award a ON a.award_id = ka.award_id
             WHERE ka.kingdom_id = ? AND a.is_ladder = 1
             ORDER BY ka.kingdomaward_id ASC LIMIT 1'
        );
        $st->execute([$kingdomId]);

        return (int) $st->fetchColumn();
    }

    /** A kingdomaward that is custom by both readings (repeatable). */
    private function customKingdomAwardId(int $kingdomId): int
    {
        $st = $this->fixture->pdo()->prepare(
            'SELECT ka.kingdomaward_id FROM ' . DB_PREFIX . 'kingdomaward ka
             JOIN ' . DB_PREFIX . 'award a ON a.award_id = ka.award_id
             WHERE ka.kingdom_id = ? AND a.is_ladder = 0 AND a.is_title = 0
               AND ka.is_title = 0
             ORDER BY ka.kingdomaward_id ASC LIMIT 1'
        );
        $st->execute([$kingdomId]);

        return (int) $st->fetchColumn();
    }

    /**
     * $date matters: the guard is scoped to the court date, so a ledger row only
     * counts as a duplicate of a court line held on the SAME day. Tests that want a
     * true duplicate must seed at the court's date; tests proving a legitimate
     * re-earning seed a different one.
     */
    private function seedLedgerAward(int $mundaneId, int $kaId, int $rank, string $date = ''): int
    {
        $st = $this->fixture->pdo()->prepare(
            'INSERT INTO ' . DB_PREFIX . 'awards
             (kingdomaward_id, mundane_id, rank, date, given_by_id, note, at_park_id,
              at_kingdom_id, at_event_id, custom_name, award_id, by_whom_id, entered_at, revoked)
             VALUES (?, ?, ?, ?, 0, \'\', 0, 0, 0, \'\', 0, 0, NOW(), 0)'
        );
        $st->execute([$kaId, $mundaneId, $rank, $date !== '' ? $date : date('Y-m-d')]);
        $id = (int) $this->fixture->pdo()->lastInsertId();
        $this->ledgerRowIds[] = $id;

        return $id;
    }

    private function countLedgerRows(int $mundaneId, int $kaId): int
    {
        $st = $this->fixture->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'awards WHERE mundane_id = ? AND kingdomaward_id = ?'
        );
        $st->execute([$mundaneId, $kaId]);

        return (int) $st->fetchColumn();
    }

    private function stageLine(int $courtId, int $mundaneId, int $kaId, int $rank, int $giverId): int
    {
        $lineId = $this->fixture->createAward($courtId, $mundaneId, [
            'kingdomaward_id' => $kaId,
            'rank'            => $rank,
            'status'          => 'staged',
        ]);
        $up = $this->fixture->pdo()->prepare(
            'UPDATE ' . DB_PREFIX . 'court_award SET given_by_mundane_id = ? WHERE court_award_id = ?'
        );
        $up->execute([$giverId, $lineId]);

        return $lineId;
    }

    public function testCommitDoesNotWriteASecondLedgerRowForAnHonorAlreadyHeld(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $kaId = $this->ladderKingdomAwardId($kid);
        $this->assertGreaterThan(0, $kaId, 'Fixture needs a ladder kingdomaward.');

        $player = $this->fixture->createPlayer('dup', $kid);
        $giver  = $this->fixture->createPlayer('giver', $kid);
        $this->touchedMundaneIds[] = (int) $player['mundane_id'];

        // The honor is already on the permanent record at the SAME rank — that is
        // what a retried finalize, or the same honor staged on two courts, produces.
        $this->seedLedgerAward((int) $player['mundane_id'], $kaId, 3, '2026-01-01');

        $courtId = $this->fixture->createCourt(['kingdom_id' => $kid, 'court_date' => '2026-01-01']);
        $lineId  = $this->stageLine($courtId, (int) $player['mundane_id'], $kaId, 3, (int) $giver['mundane_id']);

        $res = $this->court->commitStagedAward($lineId, ['Token' => '']);

        $this->assertSame('duplicate', $res['status'], 'An honor already on the ledger must not be granted again.');
        $this->assertSame(
            1,
            $this->countLedgerRows((int) $player['mundane_id'], $kaId),
            'commitStagedAward wrote a second ork_awards row for an honor already held.'
        );
        $this->assertSame(
            'cancelled',
            $this->fixture->fetchAward($lineId)['status'],
            'The duplicate line must be resolved, not left staged to retry forever.'
        );
    }

    /**
     * A LOWER rank recorded after a higher one is a legitimate backfill, not a
     * duplicate: the guard matches on EXACT rank equality, so a held rank 5 must
     * not block a rank-3 line. Asserts only that the line got PAST the guard —
     * commitStagedAward then reaches AddAward, which rejects the empty Token, so
     * the real outcome is 'error'. That is enough to prove the guard let it
     * through: a blocked line returns 'duplicate' and never calls AddAward.
     */
    public function testCommitAllowsALowerRankAfterAHigherOne(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $kaId = $this->ladderKingdomAwardId($kid);
        $this->assertGreaterThan(0, $kaId, 'Fixture needs a ladder kingdomaward.');

        $player = $this->fixture->createPlayer('bkfl', $kid);
        $giver  = $this->fixture->createPlayer('bgiver', $kid);
        $this->touchedMundaneIds[] = (int) $player['mundane_id'];

        $this->seedLedgerAward((int) $player['mundane_id'], $kaId, 5);

        $courtId = $this->fixture->createCourt(['kingdom_id' => $kid, 'court_date' => '2026-01-01']);
        $lineId  = $this->stageLine($courtId, (int) $player['mundane_id'], $kaId, 3, (int) $giver['mundane_id']);

        $res = $this->court->commitStagedAward($lineId, ['Token' => '']);

        $this->assertNotSame(
            'duplicate',
            $res['status'],
            'A lower rank after a higher one is a backfill and must still be grantable.'
        );
        $this->assertNotSame(
            'cancelled',
            $this->fixture->fetchAward($lineId)['status'],
            'A legitimate backfill line must never be cancelled by the ledger guard.'
        );
    }

    /**
     * The guard is scoped to the COURT DATE, not to all time. The same honor at the
     * same rank is legitimately re-earned years later — measured on the prod-derived
     * snapshot, 1,584 exact-rank repeats since 2022 on guarded awards are more than a
     * year apart (Order of the Warrior rank 6 re-earned in 2026 after 2004), and
     * repeatable tournament titles like Weaponmaster carry is_title = 1 so the flag
     * test alone would not save them. A time-blind guard would refuse every one.
     */
    public function testCommitAllowsTheSameHonorReEarnedOnADifferentDate(): void
    {
        $kid  = $this->fixture->firstKingdomId();
        $kaId = $this->ladderKingdomAwardId($kid);
        $this->assertGreaterThan(0, $kaId, 'Fixture needs a ladder kingdomaward.');

        $player = $this->fixture->createPlayer('reearn', $kid);
        $giver  = $this->fixture->createPlayer('regiver', $kid);
        $this->touchedMundaneIds[] = (int) $player['mundane_id'];

        // Same award, same rank — but won at an earlier ceremony.
        $this->seedLedgerAward((int) $player['mundane_id'], $kaId, 3, '2019-06-01');

        $courtId = $this->fixture->createCourt(['kingdom_id' => $kid, 'court_date' => '2026-01-01']);
        $lineId  = $this->stageLine($courtId, (int) $player['mundane_id'], $kaId, 3, (int) $giver['mundane_id']);

        $res = $this->court->commitStagedAward($lineId, ['Token' => '']);

        $this->assertNotSame(
            'duplicate',
            $res['status'],
            'The same honor re-earned on a later date is not a duplicate.'
        );
        $this->assertNotSame(
            'cancelled',
            $this->fixture->fetchAward($lineId)['status'],
            'A re-earned honor must never be cancelled by the ledger guard.'
        );
    }

    /**
     * A rank-0 line is only blocked by another rank-0 row — rank is normalized the
     * same way on both sides, so a held rank 4 must not swallow it.
     */
    public function testCommitAllowsARankZeroLineWhenAHigherRankIsHeld(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $kaId = $this->ladderKingdomAwardId($kid);
        $this->assertGreaterThan(0, $kaId, 'Fixture needs a ladder kingdomaward.');

        $player = $this->fixture->createPlayer('rnk0', $kid);
        $giver  = $this->fixture->createPlayer('r0giver', $kid);
        $this->touchedMundaneIds[] = (int) $player['mundane_id'];

        $this->seedLedgerAward((int) $player['mundane_id'], $kaId, 4);

        $courtId = $this->fixture->createCourt(['kingdom_id' => $kid, 'court_date' => '2026-01-01']);
        $lineId  = $this->stageLine($courtId, (int) $player['mundane_id'], $kaId, 0, (int) $giver['mundane_id']);

        $res = $this->court->commitStagedAward($lineId, ['Token' => '']);

        $this->assertNotSame(
            'duplicate',
            $res['status'],
            'A rank-0 line must not be blocked by a held rank 4.'
        );
    }

    /**
     * A repeatable CUSTOM award is exempt from the ledger guard even at the exact
     * same rank.
     *
     * WHAT THIS PROVES: the line reaches the AddAward call — i.e. the guard did not
     * short-circuit it as a 'duplicate'.
     * WHAT IT DOES NOT PROVE: that the grant succeeds. Token => '' has no session,
     * so AddAward returns "You do not have privileges..." and commitStagedAward
     * reports 'error' and reverts the line to 'staged'. Exercising the real grant
     * path needs an authenticated session token, which this fixture does not mint.
     */
    public function testCommitOnARepeatableCustomAwardIsNotBlockedAsDuplicate(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $kaId = $this->customKingdomAwardId($kid);
        $this->assertGreaterThan(0, $kaId, 'Fixture needs a custom (non-ladder, non-title) kingdomaward.');

        $player = $this->fixture->createPlayer('cust', $kid);
        $giver  = $this->fixture->createPlayer('cgiver', $kid);
        $this->touchedMundaneIds[] = (int) $player['mundane_id'];

        $this->seedLedgerAward((int) $player['mundane_id'], $kaId, 0);

        $courtId = $this->fixture->createCourt(['kingdom_id' => $kid, 'court_date' => '2026-01-01']);
        $lineId  = $this->stageLine($courtId, (int) $player['mundane_id'], $kaId, 0, (int) $giver['mundane_id']);

        $res = $this->court->commitStagedAward($lineId, ['Token' => '']);

        $this->assertSame(
            'error',
            $res['status'],
            'Expected the unauthenticated AddAward rejection — i.e. the guard let the line through.'
        );
        $this->assertStringContainsString(
            'privileges',
            (string) ($res['error'] ?? ''),
            'The error must be the AddAward authorization refusal, not some earlier failure.'
        );
    }

    public function testCourtReportListOnlyShowsCompleteCourts(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('rpt', $kid);

        $draft = $this->fixture->createCourt([
            'kingdom_id' => $kid, 'court_date' => '2026-02-01', 'status' => 'draft',
        ]);
        $this->fixture->createAward($draft, $player['mundane_id'], ['status' => 'given']);

        $published = $this->fixture->createCourt([
            'kingdom_id' => $kid, 'court_date' => '2026-02-02', 'status' => 'published',
        ]);
        $this->fixture->createAward($published, $player['mundane_id'], ['status' => 'given']);

        $complete = $this->fixture->createCourt([
            'kingdom_id' => $kid, 'court_date' => '2026-02-03', 'status' => 'complete',
        ]);
        $this->fixture->createAward($complete, $player['mundane_id'], ['status' => 'given']);

        $ids = array_column($this->court->getCourtReportList($kid, 0, '2026-01-01', '2026-03-01'), 'CourtId');

        $this->assertNotContains($draft, $ids, 'A draft court has not been held — never publish it.');
        $this->assertNotContains($published, $ids, 'A published court is not finalized — never publish it.');
        $this->assertContains($complete, $ids, 'A finalized court is the public record and must be listed.');
    }

    public function testCourtReportDetailIsEmptyForANonCompleteCourt(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('rptd', $kid);

        $draft = $this->fixture->createCourt([
            'kingdom_id' => $kid, 'court_date' => '2099-02-01', 'status' => 'draft',
        ]);
        $this->fixture->createAward($draft, $player['mundane_id'], ['status' => 'given']);

        $complete = $this->fixture->createCourt([
            'kingdom_id' => $kid, 'court_date' => '2026-02-03', 'status' => 'complete',
        ]);
        $this->fixture->createAward($complete, $player['mundane_id'], ['status' => 'given']);

        $this->assertNull(
            $this->court->getCourtReportDetail($draft),
            'A future-dated draft court must not be readable on the login-free report.'
        );
        $this->assertNotNull(
            $this->court->getCourtReportDetail($complete),
            'A finalized court must still render.'
        );
    }
}
