<?php

declare(strict_types=1);

namespace Tests\Integration;

use CourtFixture;
use PHPUnit\Framework\TestCase;
use RecommendationVisibility;

final class RecommendationVisibilityTest extends TestCase
{
    private CourtFixture $fx;
    private RecommendationVisibility $vis;
    private int $k = 0;
    private int $p = 0;
    private int $otherPark = 0;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Sandbox database not reachable.');
        }
        $this->fx  = CourtFixture::create();
        $this->vis = new RecommendationVisibility();
        $this->k   = $this->fx->firstKingdomId();
        $this->p   = $this->fx->firstParkId($this->k);
        $this->otherPark = $this->fx->secondParkId($this->k, $this->p);
        if (!$this->otherPark) {
            $this->markTestSkipped('Sandbox kingdom needs two parks.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
    }

    private function rec(int $recipient, int $author, int $level, bool $passed = false): array
    {
        return [
            'MundaneId' => $recipient, 'RecommendedById' => $author,
            'KingdomId' => $this->k, 'ParkId' => $this->p,
            'MonarchyOnly' => $level, 'PassedToLocal' => $passed ? 1 : 0,
        ];
    }

    /** @return array{recipient:int, author:int, parkOfficer:int, otherParkOfficer:int, kingdomOfficer:int, seatMonarch:int, plain:int, admin:int} */
    private function cast(): array
    {
        $recipient = $this->fx->createPlayerWithToken('recip', $this->k, $this->p)['mundane_id'];
        $author    = $this->fx->createPlayerWithToken('author', $this->k, $this->p)['mundane_id'];
        $parkOff   = $this->fx->createPlayerWithToken('parkoff', $this->k, $this->p)['mundane_id'];
        $this->fx->insertScopedAuth($parkOff, $this->p, 0, 'edit');
        $otherOff  = $this->fx->createPlayerWithToken('otheroff', $this->k, $this->otherPark)['mundane_id'];
        $this->fx->insertScopedAuth($otherOff, $this->otherPark, 0, 'edit');
        $kOff      = $this->fx->createPlayerWithToken('koff', $this->k, $this->p)['mundane_id'];
        $this->fx->insertScopedAuth($kOff, 0, $this->k, 'edit');
        $seat      = $this->fx->createPlayerWithToken('seat', $this->k, $this->p)['mundane_id'];
        $this->fx->insertOfficer($seat, $this->k, 0, 'Monarch');
        $plain     = $this->fx->createPlayerWithToken('plain', $this->k, $this->p)['mundane_id'];
        $admin     = $this->fx->createPlayerWithToken('admin', $this->k, $this->p)['mundane_id'];
        $this->fx->insertGlobalAdmin($admin);

        return ['recipient' => $recipient, 'author' => $author, 'parkOfficer' => $parkOff,
            'otherParkOfficer' => $otherOff, 'kingdomOfficer' => $kOff, 'seatMonarch' => $seat,
            'plain' => $plain, 'admin' => $admin];
    }

    public function testEffectiveLevel(): void
    {
        $this->assertSame(0, RecommendationVisibility::effectiveLevel(0, true));
        $this->assertSame(1, RecommendationVisibility::effectiveLevel(1, false));
        $this->assertSame(2, RecommendationVisibility::effectiveLevel(2, false));
        $this->assertSame(1, RecommendationVisibility::effectiveLevel(2, true));
    }

    public function testPublicRecVisibleToEveryoneIncludingNoViewer(): void
    {
        $c = $this->cast();
        $this->assertTrue($this->vis->canSee(0, $this->rec($c['recipient'], $c['author'], 0)));
        $this->assertTrue($this->vis->canSee($c['recipient'], $this->rec($c['recipient'], $c['author'], 0)));
    }

    public function testParkLevelMatrix(): void
    {
        $c = $this->cast();
        $r = $this->rec($c['recipient'], $c['author'], 1);
        $this->assertFalse($this->vis->canSee(0, $r));
        $this->assertFalse($this->vis->canSee($c['recipient'], $r));
        $this->assertTrue($this->vis->canSee($c['author'], $r));
        $this->assertTrue($this->vis->canSee($c['parkOfficer'], $r));
        $this->assertFalse($this->vis->canSee($c['otherParkOfficer'], $r));
        $this->assertTrue($this->vis->canSee($c['kingdomOfficer'], $r));
        $this->assertTrue($this->vis->canSee($c['seatMonarch'], $r));
        $this->assertFalse($this->vis->canSee($c['plain'], $r));
        $this->assertTrue($this->vis->canSee($c['admin'], $r));
    }

    public function testKingdomLevelMatrix(): void
    {
        $c = $this->cast();
        $r = $this->rec($c['recipient'], $c['author'], 2);
        $this->assertFalse($this->vis->canSee($c['recipient'], $r));
        $this->assertTrue($this->vis->canSee($c['author'], $r));
        $this->assertFalse($this->vis->canSee($c['parkOfficer'], $r));
        $this->assertTrue($this->vis->canSee($c['kingdomOfficer'], $r));
        $this->assertTrue($this->vis->canSee($c['seatMonarch'], $r));
        $this->assertFalse($this->vis->canSee($c['plain'], $r));
        $this->assertTrue($this->vis->canSee($c['admin'], $r));
    }

    public function testPassDownMakesKingdomHideParkLevelAndUnpassRestores(): void
    {
        $c = $this->cast();
        $this->assertTrue($this->vis->canSee($c['parkOfficer'], $this->rec($c['recipient'], $c['author'], 2, true)));
        $this->assertFalse($this->vis->canSee($c['parkOfficer'], $this->rec($c['recipient'], $c['author'], 2, false)));
    }

    public function testRecipientNeverSeesEvenIfOfficer(): void
    {
        $c = $this->cast();
        // The kingdom officer is the recipient; an author is someone else.
        $r = $this->rec($c['kingdomOfficer'], $c['author'], 2);
        $this->assertFalse($this->vis->canSee($c['kingdomOfficer'], $r));
        $r = $this->rec($c['admin'], $c['author'], 1);
        $this->assertFalse($this->vis->canSee($c['admin'], $r));
    }

    public function testCreatorLevel(): void
    {
        $c = $this->cast();
        $this->assertSame(2, $this->vis->creatorLevel($c['kingdomOfficer'], $c['recipient']));
        $this->assertSame(2, $this->vis->creatorLevel($c['seatMonarch'], $c['recipient']));
        $this->assertSame(1, $this->vis->creatorLevel($c['parkOfficer'], $c['recipient']));
        $this->assertSame(0, $this->vis->creatorLevel($c['otherParkOfficer'], $c['recipient']));
        $this->assertSame(0, $this->vis->creatorLevel($c['plain'], $c['recipient']));
        $this->assertSame(0, $this->vis->creatorLevel($c['recipient'], $c['recipient']));
    }

    public function testCanManageHiddenNeedsOfficeNotAuthorship(): void
    {
        $c = $this->cast();
        $r = $this->rec($c['recipient'], $c['author'], 2);
        $this->assertFalse($this->vis->canManageHidden($c['author'], $r));
        $this->assertFalse($this->vis->canManageHidden($c['parkOfficer'], $r));
        $this->assertTrue($this->vis->canManageHidden($c['kingdomOfficer'], $r));
        $this->assertTrue($this->vis->canManageHidden($c['parkOfficer'], $this->rec($c['recipient'], $c['author'], 1)));
        // Passed down, a kingdom hide READS as park-level, but lifting it stays the
        // kingdom's call: authority is measured against the STORED level.
        $passed = $this->rec($c['recipient'], $c['author'], 2, true);
        $this->assertTrue($this->vis->canSee($c['parkOfficer'], $passed));
        $this->assertFalse($this->vis->canManageHidden($c['parkOfficer'], $passed));
        $this->assertTrue($this->vis->canManageHidden($c['kingdomOfficer'], $passed));
        // Out-of-range stored levels need kingdom authority (fail closed).
        $this->assertFalse($this->vis->canManageHidden($c['parkOfficer'], $this->rec($c['recipient'], $c['author'], -1)));
        $this->assertTrue($this->vis->canManageHidden($c['kingdomOfficer'], $this->rec($c['recipient'], $c['author'], 3)));
    }

    public function testOutOfRangeStoredLevelFailsClosed(): void
    {
        foreach ([-1, 3, 99] as $bad) {
            $this->assertSame(2, RecommendationVisibility::effectiveLevel($bad, false), "stored $bad");
            $this->assertSame(2, RecommendationVisibility::effectiveLevel($bad, true), "stored $bad passed");
        }
        $c = $this->cast();
        $r = $this->rec($c['recipient'], $c['author'], -1);
        $this->assertFalse($this->vis->canSee(0, $r));
        $this->assertFalse($this->vis->canSee($c['plain'], $r));
        $this->assertFalse($this->vis->canSee($c['parkOfficer'], $r));
        $this->assertFalse($this->vis->canSee($c['recipient'], $r));
        $this->assertTrue($this->vis->canSee($c['kingdomOfficer'], $r));

        $ka = $this->fx->firstKingdomAwardId($this->k);
        $ids = [];
        foreach ([-1, 3] as $i => $bad) {
            $ids[] = $this->fx->createRecommendation(
                $c['recipient'],
                (int) $ka['kingdomaward_id'],
                (int) $ka['award_id'],
                ['monarchy_only' => $bad, 'recommended_by_id' => $c['author'], 'rank' => 1 + $i]
            );
        }
        foreach (['plain' => [], 'parkOfficer' => [], 'kingdomOfficer' => $ids] as $role => $expect) {
            $pred = $this->vis->sqlPredicate($c[$role], 'r', 'm', [$this->k]);
            $sees = array_map('intval', $this->fx->pdo()->query(
                'SELECT r.recommendations_id FROM ' . DB_PREFIX . 'recommendations r JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = r.mundane_id
                 WHERE r.recommendations_id IN (' . implode(',', $ids) . ') AND ' . $pred . ' ORDER BY r.recommendations_id'
            )->fetchAll(\PDO::FETCH_COLUMN));
            $this->assertSame($expect, $sees, "sqlPredicate for $role");
        }
        $this->assertSame([], $this->fx->pdo()->query(
            'SELECT r.recommendations_id FROM ' . DB_PREFIX . 'recommendations r JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = r.mundane_id
             WHERE r.recommendations_id IN (' . implode(',', $ids) . ') AND ' . $this->vis->sqlPredicate(0, 'r', 'm', [$this->k])
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testSqlPredicateAgreesWithCanSee(): void
    {
        $c  = $this->cast();
        $ka = $this->fx->firstKingdomAwardId($this->k);
        $ids = [];
        foreach ([0, 1, 2] as $lvl) {
            $ids[$lvl] = $this->fx->createRecommendation(
                $c['recipient'],
                (int) $ka['kingdomaward_id'],
                (int) $ka['award_id'],
                ['monarchy_only' => $lvl, 'recommended_by_id' => $c['author'], 'rank' => 1 + $lvl]
            );
        }
        $passed = $this->fx->createRecommendation(
            $c['recipient'],
            (int) $ka['kingdomaward_id'],
            (int) $ka['award_id'],
            ['monarchy_only' => 2, 'passed_to_local' => 1, 'recommended_by_id' => $c['author'], 'rank' => 4]
        );
        $all = array_merge(array_values($ids), [$passed]);

        foreach ($c as $role => $viewer) {
            $pred = $this->vis->sqlPredicate($viewer, 'r', 'm', [$this->k]);
            $st = $this->fx->pdo()->query(
                'SELECT r.recommendations_id FROM ' . DB_PREFIX . 'recommendations r
                 JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = r.mundane_id
                 WHERE r.recommendations_id IN (' . implode(',', $all) . ') AND ' . $pred
            );
            $sqlSees = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
            sort($sqlSees);
            $phpSees = [];
            foreach ($all as $id) {
                $row = $this->fx->fetchRecommendation($id);
                if ($this->vis->canSee($viewer, [
                    'MundaneId' => (int) $row['mundane_id'], 'RecommendedById' => (int) $row['recommended_by_id'],
                    'KingdomId' => $this->k, 'ParkId' => $this->p,
                    'MonarchyOnly' => (int) $row['monarchy_only'], 'PassedToLocal' => (int) $row['passed_to_local'],
                ])) {
                    $phpSees[] = $id;
                }
            }
            sort($phpSees);
            $this->assertSame($phpSees, $sqlSees, "SQL and PHP disagree for $role");
        }
        $this->assertSame([$ids[0]], array_map('intval', $this->fx->pdo()->query(
            'SELECT r.recommendations_id FROM ' . DB_PREFIX . 'recommendations r JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = r.mundane_id
             WHERE r.recommendations_id IN (' . implode(',', $all) . ') AND ' . $this->vis->sqlPredicate(0, 'r', 'm', [$this->k])
        )->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function testViewerFromToken(): void
    {
        $p = $this->fx->createPlayerWithToken('tok', $this->k, $this->p);
        unset($_SESSION['is_authorized_mundane_id']);
        $this->assertSame($p['mundane_id'], $this->vis->viewerFromToken($p['token']));
        $this->assertSame(0, $this->vis->viewerFromToken(''));
        $this->assertSame(0, $this->vis->viewerFromToken(null));
        $this->assertSame(0, $this->vis->viewerFromToken(str_repeat('x', 32)));
    }
}
