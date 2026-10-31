<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Court::getPlayerPlannedCourtLines: the planned-but-not-yet-awarded court lines a
 * Player Profile shows to the officers who can manage the court.
 */
final class CourtPlayerPlannedLinesTest extends TestCase
{
    private CourtFixture $fx;
    private int $k;
    private int $p;
    private int $p2;
    private array $ka;
    private int $recipient;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->fx = CourtFixture::create();
        unset($_SESSION['is_authorized_mundane_id']);
        $this->k  = $this->fx->firstKingdomId();
        $this->p  = $this->fx->firstParkId($this->k);
        $this->p2 = $this->fx->secondParkId($this->k, $this->p);
        $this->ka = $this->fx->firstKingdomAwardId($this->k);
        $this->recipient = (int)$this->fx->createPlayer('pl-recip', $this->k, $this->p)['mundane_id'];
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
        unset($_SESSION['is_authorized_mundane_id']);
    }

    private function officer(int $kingdomId, int $parkId): int
    {
        $o = $this->fx->createPlayerWithToken('pl-off', $kingdomId, $parkId);
        $this->fx->insertOfficer((int)$o['mundane_id'], $kingdomId, $parkId, 'Monarch');
        return (int)$o['mundane_id'];
    }

    private function line(int $courtId, array $o = []): int
    {
        return $this->fx->createAward($courtId, $this->recipient, $o + ['kingdomaward_id' => (int)$this->ka['kingdomaward_id']]);
    }

    private function lines(int $caller): array
    {
        return (new \Court())->getPlayerPlannedCourtLines($this->recipient, $caller);
    }

    private function courtIds(array $lines): array
    {
        $ids = array_map(static fn ($l) => $l['CourtId'], $lines);
        sort($ids);
        return $ids;
    }

    public function testKingdomOfficerSeesKingdomAndParkCourtLines(): void
    {
        $kc = $this->fx->createCourt(['kingdom_id' => $this->k, 'name' => 'T0CRT-kc']);
        $pc = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => $this->p, 'name' => 'T0CRT-pc']);
        $this->line($kc);
        $this->line($pc);
        $lines = $this->lines($this->officer($this->k, 0));
        $want = [$kc, $pc];
        sort($want);
        $this->assertSame($want, $this->courtIds($lines));
    }

    public function testParkOfficerDoesNotSeeKingdomCourtLines(): void
    {
        $kc = $this->fx->createCourt(['kingdom_id' => $this->k, 'name' => 'T0CRT-kc']);
        $pc = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => $this->p, 'name' => 'T0CRT-pc']);
        $this->line($kc);
        $this->line($pc);
        $lines = $this->lines($this->officer($this->k, $this->p));
        $this->assertSame([$pc], $this->courtIds($lines));
    }

    public function testParkOfficerDoesNotSeeAnotherParksCourt(): void
    {
        if ($this->p2 <= 0) {
            $this->markTestSkipped('Kingdom has no second park.');
        }
        $other = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => $this->p2, 'name' => 'T0CRT-other']);
        $this->line($other);
        $this->assertSame([], $this->lines($this->officer($this->k, $this->p)));
    }

    public function testOrdinaryPlayerSeesNothing(): void
    {
        $kc = $this->fx->createCourt(['kingdom_id' => $this->k]);
        $this->line($kc);
        $nobody = (int)$this->fx->createPlayerWithToken('pl-plain', $this->k, $this->p)['mundane_id'];
        $this->assertSame([], $this->lines($nobody));
        $this->assertSame([], $this->lines(0));
        // A kingdom officer viewing their own profile is not spoiled either.
        $off = $this->officer($this->k, 0);
        $this->assertSame([], (new \Court())->getPlayerPlannedCourtLines($off, $off));
    }

    public function testCompleteCourtExcluded(): void
    {
        $done = $this->fx->createCourt(['kingdom_id' => $this->k, 'status' => 'complete']);
        $live = $this->fx->createCourt(['kingdom_id' => $this->k, 'status' => 'published']);
        $this->line($done);
        $this->line($live);
        $this->assertSame([$live], $this->courtIds($this->lines($this->officer($this->k, 0))));
    }

    public function testCancelledAndGivenLinesExcluded(): void
    {
        $c = $this->fx->createCourt(['kingdom_id' => $this->k]);
        $this->line($c, ['status' => 'cancelled']);
        $this->line($c, ['status' => 'given']);
        $off = $this->officer($this->k, 0);
        $this->assertSame([], $this->lines($off));
        $keep = $this->line($c, ['status' => 'planned', 'rank' => 2]);
        $lines = $this->lines($off);
        $this->assertCount(1, $lines);
        $this->assertSame($keep, $lines[0]['CourtAwardId']);
    }

    public function testStagedAndAnnouncedLinesStillPlanned(): void
    {
        $c = $this->fx->createCourt(['kingdom_id' => $this->k]);
        $staged    = $this->line($c, ['status' => 'staged']);
        $announced = $this->line($c, ['status' => 'announced']);
        $ids = array_map(static fn ($l) => $l['CourtAwardId'], $this->lines($this->officer($this->k, 0)));
        sort($ids);
        $want = [$staged, $announced];
        sort($want);
        $this->assertSame($want, $ids);
    }

    public function testHiddenRecLineWithheldFromOfficerWhoCannotSeeIt(): void
    {
        $pc  = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => $this->p]);
        $hid = $this->fx->createRecommendation($this->recipient, (int)$this->ka['kingdomaward_id'], (int)$this->ka['award_id'], ['monarchy_only' => 2]);
        $pub = $this->fx->createRecommendation($this->recipient, (int)$this->ka['kingdomaward_id'], (int)$this->ka['award_id'], ['monarchy_only' => 0]);
        $this->line($pc, ['recommendations_id' => $hid, 'rank' => 1]);
        $this->line($pc, ['recommendations_id' => $pub, 'rank' => 2]);

        $parkLines = $this->lines($this->officer($this->k, $this->p));
        $this->assertSame([$pub], array_column($parkLines, 'RecommendationsId'));

        $kingdomLines = $this->lines($this->officer($this->k, 0));
        $this->assertEqualsCanonicalizing([$hid, $pub], array_column($kingdomLines, 'RecommendationsId'));
    }

    public function testRankAndCourtFieldsAreCorrect(): void
    {
        $dated   = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => $this->p, 'name' => 'T0CRT-dated', 'court_date' => '2099-01-15']);
        $undated = $this->fx->createCourt(['kingdom_id' => $this->k, 'name' => 'T0CRT-undated', 'court_date' => null]);
        $early   = $this->fx->createCourt(['kingdom_id' => $this->k, 'name' => 'T0CRT-early', 'court_date' => '2098-06-01']);
        $rec = $this->fx->createRecommendation($this->recipient, (int)$this->ka['kingdomaward_id'], (int)$this->ka['award_id'], ['monarchy_only' => 0]);
        $a = $this->line($dated, ['rank' => 3, 'recommendations_id' => $rec]);
        $this->line($undated, ['rank' => 0]);
        $this->line($early, ['rank' => 1]);

        $lines = $this->lines($this->officer($this->k, 0));
        $this->assertSame([$early, $dated, $undated], array_column($lines, 'CourtId'), 'Date ascending, undated last.');

        $row = $lines[1];
        $this->assertSame(['CourtId', 'CourtName', 'CourtDate', 'KingdomId', 'ParkId', 'ParkName', 'CourtAwardId', 'KingdomAwardId', 'AwardName', 'Rank', 'RecommendationsId'], array_keys($row));
        $this->assertSame('T0CRT-dated', $row['CourtName']);
        $this->assertSame('2099-01-15', $row['CourtDate']);
        $this->assertSame($this->k, $row['KingdomId']);
        $this->assertSame($this->p, $row['ParkId']);
        $this->assertNotSame('', $row['ParkName']);
        $this->assertSame($a, $row['CourtAwardId']);
        $this->assertSame((int)$this->ka['kingdomaward_id'], $row['KingdomAwardId']);
        $this->assertNotSame('', $row['AwardName']);
        $this->assertSame(3, $row['Rank']);
        $this->assertSame($rec, $row['RecommendationsId']);

        $this->assertNull($lines[2]['CourtDate']);
        $this->assertSame(0, $lines[2]['ParkId']);
        $this->assertSame('', $lines[2]['ParkName']);
        $this->assertSame(0, $lines[2]['RecommendationsId']);
    }
}
