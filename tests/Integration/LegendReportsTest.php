<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Reports and lists: a Legend leaves every list about current players (rosters, active
 * players, qualified lists, voting, dues, reconciliation, Population Explorer) and stays,
 * marked, on every list about honors (award reports, ladder grid, unit roster,
 * recommendations, Court Planner).
 */
final class LegendReportsTest extends TestCase
{
    private LegendFixture $fx;
    private ReportsFixture $rf;
    private PDO $pdo;
    private int $park = 0;
    private int $kingdom = 0;
    private int $legend = 0;
    private int $living = 0;

    /** @var list<int> */
    private array $recIds = [];

    /** @var array{mundane_id: int, token: string}|null one admin per test, so scope counts compare */
    private ?array $explorerAdmin = null;
    private bool $explorerAdminSeated = false;

    /** @var array{award_id: int, crown_points: int, crown_limit: int}|null */
    private ?array $crownAward = null;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        Legend::clearMemo();
        $this->fx      = LegendFixture::create();
        $this->rf      = ReportsFixture::create();
        $this->pdo     = $this->fx->pdo();
        $this->park    = $this->fx->firstParkId();
        $this->kingdom = $this->fx->kingdomIdForPark($this->park);
        $tag           = 'lrp' . bin2hex(random_bytes(3));
        $this->legend  = $this->fx->addPlayer($this->park, $tag . 'dead', 1999)['mundane_id'];
        $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'mundane SET active = 0 WHERE mundane_id = ?')->execute([$this->legend]);
        $this->living  = $this->fx->addPlayer($this->park, $tag . 'live')['mundane_id'];
        unset($_SESSION['is_authorized_mundane_id']);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            if ($this->recIds !== []) {
                $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'recommendations WHERE recommendations_id IN (' . implode(',', $this->recIds) . ')');
            }
            if ($this->crownAward !== null) {
                $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'award SET crown_points = ?, crown_limit = ? WHERE award_id = ?')
                    ->execute([$this->crownAward['crown_points'], $this->crownAward['crown_limit'], $this->crownAward['award_id']]);
            }
        }
        if (isset($this->rf)) {
            $this->rf->cleanup();
        }
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
        Legend::clearMemo();
    }

    /** @param list<array<string, mixed>> $rows */
    private function ids(array $rows, string $key = 'MundaneId'): array
    {
        return array_map(static fn ($r) => (int) $r[$key], array_values($rows));
    }

    /** @param list<array<string, mixed>> $rows */
    private function rowFor(array $rows, int $id, string $key = 'MundaneId'): array
    {
        foreach ($rows as $r) {
            if ((int) $r[$key] === $id) {
                return $r;
            }
        }
        $this->fail("No row for mundane $id");
    }

    // ---- Lists about current players: the Legend is excluded ----

    public function test_roster_excludes_legend(): void
    {
        $report = new Report();
        $req = ['Type' => AUTH_PARK, 'Id' => $this->park];
        $ids = $this->ids($report->GetPlayerRoster($req)['Roster']);
        $this->assertContains($this->living, $ids);
        $this->assertNotContains($this->legend, $ids);

        $rows = $report->GetPlayerRoster($req + ['IncludeLegend' => 1, 'InActive' => true])['Roster'];
        $this->assertSame(1, (int) $this->rowFor($rows, $this->legend)['IsLegend']);
        $this->assertContains($this->legend, $this->ids($rows));
        $kingdomIds = $this->ids($report->GetPlayerRoster(['Type' => AUTH_KINGDOM, 'Id' => $this->kingdom, 'Active' => true])['Roster']);
        $this->assertNotContains($this->legend, $kingdomIds);
    }

    public function test_active_players_excludes_legend(): void
    {
        $this->fx->addAttendance($this->park, $this->kingdom, $this->legend);
        $this->fx->addAttendance($this->park, $this->kingdom, $this->living);
        $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'mundane SET active = 1 WHERE mundane_id = ?')->execute([$this->legend]);
        $r = (new Report())->GetActivePlayers([
            'ParkId' => $this->park, 'MinimumWeeklyAttendance' => 0, 'MinimumDailyAttendance' => 1, 'MinimumCredits' => 0,
            'MonthlyCreditMaximum' => 6, 'PerMonths' => 6,
        ]);
        $ids = $this->ids($r['ActivePlayerSummary']);
        $this->assertContains($this->living, $ids);
        $this->assertNotContains($this->legend, $ids);
    }

    public function test_reeve_corpora_crown_qualified_exclude_legend(): void
    {
        $this->pdo->prepare(
            'UPDATE ' . DB_PREFIX . 'mundane SET reeve_qualified = 1, reeve_qualified_until = DATE_ADD(CURDATE(), INTERVAL 1 YEAR),
                    corpora_qualified = 1, corpora_qualified_until = DATE_ADD(CURDATE(), INTERVAL 1 YEAR) WHERE mundane_id IN (?, ?)'
        )->execute([$this->legend, $this->living]);
        $report = new Report();
        $reeve = $this->ids($report->GetReeveQualified(['ParkId' => $this->park])['ReeveQualified']);
        $corpora = $this->ids($report->GetCorporaQualified(['ParkId' => $this->park])['CorporaQualified']);
        foreach ([$reeve, $corpora] as $ids) {
            $this->assertContains($this->living, $ids);
            $this->assertNotContains($this->legend, $ids);
        }

        $crown = $this->crownAwardFor();
        foreach ([$this->legend, $this->living] as $who) {
            $this->rf->insertLadderAward($who, $this->park, $this->kingdom, $crown['kingdomaward_id'], $crown['award_id'], 1);
        }
        $q = $report->CrownQualed($this->kingdom);
        $ids = $this->ids($q['Awards'] ?? []);
        $this->assertContains($this->living, $ids);
        $this->assertNotContains($this->legend, $ids);
    }

    /** An award worth crown points (6, so one grant qualifies), restored in tearDown. */
    private function crownAwardFor(): array
    {
        $awardId = (int) $this->pdo->query('SELECT award_id FROM ' . DB_PREFIX . "award WHERE peerage = 'None' ORDER BY award_id LIMIT 1")->fetchColumn();
        $row = $this->pdo->query('SELECT crown_points, crown_limit FROM ' . DB_PREFIX . 'award WHERE award_id = ' . $awardId)->fetch(PDO::FETCH_ASSOC);
        $this->crownAward = ['award_id' => $awardId, 'crown_points' => (int) $row['crown_points'], 'crown_limit' => (int) $row['crown_limit']];
        $this->pdo->exec('UPDATE ' . DB_PREFIX . 'award SET crown_points = 6, crown_limit = 0 WHERE award_id = ' . $awardId);

        return ['award_id' => $awardId, 'kingdomaward_id' => $this->rf->insertKingdomAward($this->kingdom, $awardId, 'LRP crown ' . $awardId)];
    }

    public function test_voting_eligibility_excludes_legend_and_never_prompts_reactivation(): void
    {
        foreach ([$this->legend, $this->living] as $who) {
            $this->rf->makeVotingReady($who);
            $this->rf->insertDues($who, $this->park, $this->kingdom);
            $this->fx->addAttendance($this->park, $this->kingdom, $who);
        }
        $rules = ['KingdomId' => $this->kingdom, 'AttendanceMode' => 'count', 'AttendanceRequired' => 1, 'MonthsWindow' => 12,
                  'MinMembershipMonths' => 0, 'ProvinceMode' => false];
        $report = new Report();
        $this->assertNotEmpty($report->GetVotingEligible($rules + ['MundaneId' => $this->living])['Players']);
        $this->assertSame([], $report->GetVotingEligible($rules + ['MundaneId' => $this->legend])['Players']);

        // Reconciliation: an inactive player with recent sign-ins is offered for reactivation, a Legend never is.
        $inactive = $this->fx->addPlayer($this->park, 'inactive')['mundane_id'];
        $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'mundane SET active = 0 WHERE mundane_id = ?')->execute([$inactive]);
        $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'mundane SET active = 0 WHERE mundane_id = ?')->execute([$this->legend]);
        $this->fx->addAttendance($this->park, $this->kingdom, $inactive);
        $rec = $report->GetPlayerStatusReconciliation(['ParkId' => $this->park, 'Salt' => bin2hex(random_bytes(4))]);
        $offered = $this->ids($rec['InactiveWithAttendance']);
        $this->assertContains($inactive, $offered);
        $this->assertNotContains($this->legend, $offered);
        $this->assertNotContains($this->legend, $this->ids($rec['ActiveNoAttendance']));
    }

    public function test_dues_paid_excludes_lifetime_legend(): void
    {
        $this->rf->insertDues($this->living, $this->park, $this->kingdom);
        $this->rf->insertDues($this->legend, $this->park, $this->kingdom, null, true);
        $r = (new Report())->GetDuesPaidList(['Type' => 'Park', 'Id' => $this->park]);
        $ids = $this->ids($r['DuesPaidList'] ?? []);
        $this->assertContains($this->living, $ids);
        $this->assertNotContains($this->legend, $ids);
    }

    // ---- Population Explorer ----

    private function explorerIds(array $tree): array
    {
        $this->explorerAdmin ??= $this->rf->createPlayer($this->park, 'pe-admin');
        $admin = $this->explorerAdmin;
        if (!$this->explorerAdminSeated) {
            $this->rf->insertGlobalAdmin($admin['mundane_id']);
            $this->explorerAdminSeated = true;
        }
        unset($_SESSION['is_authorized_mundane_id']);
        $r = (new PopulationExplorer())->Run([
            'Token' => $admin['token'], 'ScopeType' => 'Park', 'ScopeId' => $this->park, 'Tree' => $tree, 'Columns' => ['persona'],
        ]);
        $this->assertSame(0, $r['Status']['Status'], json_encode($r['Status']));

        return [$this->ids($r['Rows']), $r];
    }

    public function test_population_explorer_excludes_by_default_and_has_criterion(): void
    {
        [$ids, $default] = $this->explorerIds([]);
        $this->assertContains($this->living, $ids);
        $this->assertNotContains($this->legend, $ids);

        [$only] = $this->explorerIds(['op' => 'AND', 'children' => [['c' => 'legend', 'o' => 'is', 'v' => 1]]]);
        $this->assertContains($this->legend, $only);
        $this->assertNotContains($this->living, $only);

        // The scope total ("% of scope") is the living scope by default.
        [, $withLegend] = $this->explorerIds(['op' => 'AND', 'children' => [['c' => 'legend', 'o' => 'is', 'v' => 1]]]);
        $this->assertSame($default['ScopeTotal'] + 1, $withLegend['ScopeTotal']);
    }

    // ---- Lists about honors: the Legend stays, flagged ----

    private function ladderAward(): array
    {
        $kid = $this->rf->kingdomWithLadderAwards();
        $park = $this->rf->parkIdInKingdom($kid);
        $ladder = $this->rf->firstLadderAward($kid);

        return [$kid, $park, $ladder];
    }

    public function test_knights_peerage_masters_custom_awards_keep_legend_with_flag(): void
    {
        [$kid, $park, $ladder] = $this->ladderAward();
        $legend = $this->fx->addPlayer($park, 'honorsdead', 1998)['mundane_id'];
        $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'mundane SET active = 0 WHERE mundane_id = ?')->execute([$legend]);
        $alive = $this->fx->addPlayer($park, 'honorslive')['mundane_id'];
        foreach ([$legend, $alive] as $who) {
            $this->rf->insertLadderAward($who, $park, $kid, $ladder['kingdomaward_id'], $ladder['award_id'], 4);
        }
        $report = new Report();
        $rows = $report->PlayerAwards(['ParkId' => $park, 'IncludeLadder' => 1, 'LadderMinimum' => 1, 'Salt' => bin2hex(random_bytes(4))])['Awards'];
        $this->assertSame(1, (int) $this->rowFor($rows, $legend)['IsLegend']);
        $this->assertSame(0, (int) $this->rowFor($rows, $alive)['IsLegend']);

        // Class Masters / Paragons
        $paragon = (int) $this->pdo->query('SELECT award_id FROM ' . DB_PREFIX . "award WHERE peerage = 'Paragon' ORDER BY award_id LIMIT 1")->fetchColumn();
        $ka = $this->rf->insertKingdomAward($kid, $paragon, 'LRP paragon');
        $this->rf->insertLadderAward($legend, $park, $kid, $ka, $paragon, 0);
        $masters = $report->ClassMasters(['ParkId' => $park, 'Salt' => bin2hex(random_bytes(4))])['Awards'];
        $this->assertSame(1, (int) $this->rowFor($masters, $legend)['IsLegend']);

        // Custom awards: kept even with no sign-in in the past year.
        foreach ([$legend, $alive] as $who) {
            $awardId = $this->rf->insertLadderAward($who, $park, $kid, 0, 0, 0);
            $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'awards SET custom_name = ? WHERE awards_id = ?')->execute(['LRP custom', $awardId]);
        }
        $this->fx->addAttendance($park, $kid, $alive);
        $custom = $report->CustomAwards(['ParkId' => $park, 'Salt' => bin2hex(random_bytes(4))])['Awards'];
        $this->assertSame(1, (int) $this->rowFor($custom, $legend)['IsLegend']);
        $this->assertSame(0, (int) $this->rowFor($custom, $alive)['IsLegend']);
    }

    public function test_ladder_grid_keeps_legend(): void
    {
        [$kid, $park, $ladder] = $this->ladderAward();
        $legend = $this->fx->addPlayer($park, 'griddead', 1998)['mundane_id'];
        $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'mundane SET active = 0 WHERE mundane_id = ?')->execute([$legend]);
        $this->rf->insertLadderAward($legend, $park, $kid, $ladder['kingdomaward_id'], $ladder['award_id'], 5);
        $editor = $this->rf->createPlayer($park, 'grid-editor');
        $this->rf->insertScopedAuth($editor['mundane_id'], $park, $kid, AUTH_CREATE);
        unset($_SESSION['is_authorized_mundane_id']);
        $grid = (new Report())->GetLadderAwardGrid(['KingdomId' => 0, 'ParkId' => $park, 'Token' => $editor['token'], 'Salt' => bin2hex(random_bytes(4))]);
        $row = $this->rowFor($grid['GridRows'], $legend);
        $this->assertSame(1, $row['IsLegend']);
    }

    public function test_unit_roster_keeps_legend_with_flag(): void
    {
        $unit = $this->fx->addUnit();
        $this->fx->addUnitMember($unit, $this->legend);
        $this->fx->addUnitMember($unit, $this->living);
        $roster = (new Report())->GetPlayerRoster(['Type' => AUTH_UNIT, 'Id' => $unit])['Roster'];
        $this->assertSame(1, (int) $this->rowFor($roster, $this->legend)['IsLegend']);
        $this->assertSame(0, (int) $this->rowFor($roster, $this->living)['IsLegend']);
    }

    public function test_recommendations_flag_legend_and_retired_stays(): void
    {
        [$kid, $park, $ladder] = $this->ladderAward();
        $legend = $this->fx->addPlayer($park, 'recdead', 1998)['mundane_id'];
        $retired = $this->fx->addPlayer($park, 'recretired')['mundane_id'];
        $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'mundane SET active = 0 WHERE mundane_id IN (?, ?)')->execute([$legend, $retired]);
        $recs = [];
        foreach ([$legend, $retired] as $who) {
            $this->pdo->prepare(
                'INSERT INTO ' . DB_PREFIX . 'recommendations (mundane_id, kingdomaward_id, award_id, rank, recommended_by_id, date_recommended, mask_giver, reason)
                 VALUES (?, ?, ?, 1, ?, CURDATE(), 0, ?)'
            )->execute([$who, $ladder['kingdomaward_id'], $ladder['award_id'], $this->living, 'LRP rec']);
            $recs[$who] = (int) $this->pdo->lastInsertId();
            $this->recIds[] = $recs[$who];
        }
        $report = new Report();
        $rows = $report->PlayerAwardRecommendations(['PlayerId' => $legend, 'SkipCache' => true])['AwardRecommendations'];
        $this->assertTrue($this->rowFor($rows, $legend, 'MundaneId')['IsLegend'] ?? false);
        $rows = $report->PlayerAwardRecommendations(['PlayerId' => $retired, 'SkipCache' => true])['AwardRecommendations'];
        $row = $this->rowFor($rows, $retired);
        $this->assertTrue($row['IsRetired']);
        $this->assertFalse($row['IsLegend']);

        $groups = $report->groupRecommendations($report->PlayerAwardRecommendations(['PlayerId' => $legend, 'SkipCache' => true])['AwardRecommendations']);
        $this->assertTrue($groups[0]['IsLegend']);
        $this->assertTrue($groups[0]['IsRetired']);
    }

    public function test_court_planner_rows_carry_is_legend(): void
    {
        $court = $this->fx->addCourt($this->kingdom, $this->park);
        $line = $this->fx->addCourtLine($court, $this->legend);
        $other = $this->fx->addCourtLine($court, $this->living);
        $this->fx->addCourtArtisan($line, $this->legend);
        $awards = array_column((new Court())->getCourtAwards($court), null, 'CourtAwardId');
        $this->assertTrue($awards[$line]['IsLegend']);
        $this->assertFalse($awards[$other]['IsLegend']);
        $this->assertTrue($awards[$line]['Artisans'][0]['IsLegend']);
    }
}
