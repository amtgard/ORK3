<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * OfficerPosition::rolls_for_org(): the one reader behind the public officer
 * rolls report and the edit ledgers.
 *
 * Fixture rows are raw INSERTs under the MARKER prefix (role / canonical_key /
 * username), removed in tearDown() so a failing assertion never leaves rows
 * behind. KINGDOM_ID is a fake id of this test's own, so no seeded officer
 * data from ork_test can leak into the sections asserted on.
 */
final class OfficerRollsReaderTest extends TestCase
{
    private const MARKER = 'zzrolls';
    private const KINGDOM_ID = 100051;
    private const PARK_A = 100052;
    private const PARK_B = 100053;

    private PDO $pdo;
    private OfficerPosition $positions;
    /** @var list<int> */
    private array $seededMundanes = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->purge();
        $this->positions = new OfficerPosition();
        $this->seedPark(self::PARK_A);
        $this->seedPark(self::PARK_B);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->purge();
        }
    }

    private function purge(): void
    {
        $this->pdo->exec("DELETE FROM ork_officer_history WHERE role LIKE '" . self::MARKER . "%'");
        $this->pdo->exec("DELETE FROM ork_officer WHERE role LIKE '" . self::MARKER . "%'");
        $this->pdo->exec("DELETE FROM ork_officer_position WHERE canonical_key LIKE '" . self::MARKER . "%'");
        $this->pdo->exec('DELETE FROM ork_park WHERE park_id IN (' . self::PARK_A . ', ' . self::PARK_B . ')');
        $this->pdo->exec("DELETE FROM ork_mundane WHERE username LIKE '" . self::MARKER . "%'");
        $this->seededMundanes = [];
    }

    // ============================================================
    // TESTS
    // ============================================================

    public function testOfficesInOrgOrderWithCounts(): void
    {
        $crownLate  = $this->seedPosition('crown_late', 'crown', 20);
        $crownEarly = $this->seedPosition('crown_early', 'crown', 10);
        $support    = $this->seedPosition('support', 'supporting', 5);

        // Three terms on crown_early: Spring-Fall 2019, Spring-Fall 2021 (gap: Winter 2020 - Winter 2021),
        // Fall 2021 - Spring 2022 (a Fall->Fall handoff, no gap).
        $this->term($crownEarly, 'crown_early', ['holder_name' => 'A', 'start_season' => 'spring', 'start_year' => 2019, 'end_season' => 'fall', 'end_year' => 2019]);
        $this->term($crownEarly, 'crown_early', ['holder_name' => 'B', 'start_season' => 'spring', 'start_year' => 2021, 'end_season' => 'fall', 'end_year' => 2021]);
        $this->term($crownEarly, 'crown_early', ['holder_name' => 'C', 'start_season' => 'fall', 'start_year' => 2021, 'end_season' => 'spring', 'end_year' => 2022]);

        $offices = $this->rolls(0);
        $mine = array_values(array_filter(
            $offices,
            fn ($o) => in_array($o['PositionId'], [$crownLate, $crownEarly, $support], true)
        ));

        self::assertSame(
            [$crownEarly, $crownLate, $support],
            array_column($mine, 'PositionId'),
            'crown before supporting, then by sort order'
        );
        self::assertSame('Test crown_early', $mine[0]['Title']);
        self::assertSame('crown', $mine[0]['Classification']);
        self::assertFalse($mine[0]['Retired']);
        self::assertSame('position:' . $crownEarly, $mine[0]['Key']);
        self::assertSame(3, $mine[0]['TermCount']);
        self::assertSame(1, $mine[0]['GapCount']);
        self::assertSame(0, $mine[0]['OverlapCount']);
        self::assertSame(0, $mine[1]['TermCount'], 'an office with no history is still listed');
        self::assertSame([], $mine[1]['Terms']);
        self::assertSame('supporting', $mine[2]['Classification']);

        $term = $mine[0]['Terms'][0];
        foreach ([
            'OfficerHistoryId', 'MundaneId', 'Persona', 'HolderName', 'IsCurrent', 'StartSeason', 'StartYear',
            'EndSeason', 'EndYear', 'StartDate', 'EndDate', 'PeriodLabel', 'Note', 'StartOrdinal', 'EndOrdinal',
            'FlagAfter', 'Synthetic',
        ] as $key) {
            self::assertArrayHasKey($key, $term, 'term must carry ' . $key);
        }
        self::assertSame('Fall 2021 – Spring 2022', $term['PeriodLabel']);
        self::assertFalse($term['Synthetic']);
    }

    public function testTermsNewestFirstWithCurrentOnTop(): void
    {
        $pos = $this->seedPosition('office', 'crown', 10);
        $member = $this->seedMundane('current');
        $this->seatOfficer($pos, 'office', $member, 0);

        $ancient = $this->term($pos, 'office', ['holder_name' => 'Ancient', 'end_season' => 'fall', 'end_year' => 2005]);
        $old     = $this->term($pos, 'office', ['holder_name' => 'Old', 'start_season' => 'spring', 'start_year' => 2010, 'end_season' => 'fall', 'end_year' => 2010]);
        $recent  = $this->term($pos, 'office', ['holder_name' => 'Recent', 'start_season' => 'fall', 'start_year' => 2024, 'end_season' => 'spring', 'end_year' => 2025]);
        $current = $this->term($pos, 'office', ['mundane_id' => $member, 'start_date' => '2020-04-01', 'is_current' => 1]);

        $office = $this->office($this->rolls(0), $pos);
        self::assertSame([$current, $recent, $old, $ancient], array_column($office['Terms'], 'OfficerHistoryId'));
        self::assertTrue($office['Terms'][0]['IsCurrent']);
        self::assertSame($member, $office['Terms'][0]['MundaneId']);
        self::assertSame(self::MARKER . ' current', $office['Terms'][0]['Persona']);
        self::assertSame('Apr 1, 2020 – present', $office['Terms'][0]['PeriodLabel']);
        self::assertNull($office['Terms'][3]['StartOrdinal'], 'unknown start sorts last');
        self::assertSame('? – Fall 2005', $office['Terms'][3]['PeriodLabel']);
        self::assertSame(
            ['Type' => 'gap', 'Label' => 'Gap: Winter 2005–06 – Winter 2009–10', 'Span' => 'Winter 2005–06 – Winter 2009–10'],
            $office['Terms'][2]['FlagAfter'],
            'an unknown START on the older term does not face the newer one'
        );
        self::assertSame(4, $office['TermCount'], 'a seat with a real current row gets no synthetic term');
    }

    public function testGapNeedsAWholeMissingSeason(): void
    {
        $pos = $this->seedPosition('gap', 'crown', 10);
        $this->term($pos, 'gap', ['holder_name' => 'Older', 'start_season' => 'fall', 'start_year' => 2022, 'end_season' => 'spring', 'end_year' => 2023]);
        $this->term($pos, 'gap', ['holder_name' => 'Newer', 'start_season' => 'spring', 'start_year' => 2023, 'end_season' => 'fall', 'end_year' => 2023]);

        $office = $this->office($this->rolls(0), $pos);
        self::assertNull($office['Terms'][0]['FlagAfter'], 'Spring 23 follows Spring 23: no gap');
        self::assertSame(0, $office['GapCount']);

        $pos2 = $this->seedPosition('gap2', 'crown', 11);
        $this->term($pos2, 'gap2', ['holder_name' => 'Older', 'start_season' => 'spring', 'start_year' => 2021, 'end_season' => 'fall', 'end_year' => 2021]);
        $this->term($pos2, 'gap2', ['holder_name' => 'Newer', 'start_season' => 'spring', 'start_year' => 2023, 'end_season' => 'fall', 'end_year' => 2023]);

        $office2 = $this->office($this->rolls(0), $pos2);
        self::assertSame(
            ['Type' => 'gap', 'Label' => 'Gap: Winter 2021–22 – Winter 2022–23', 'Span' => 'Winter 2021–22 – Winter 2022–23'],
            $office2['Terms'][0]['FlagAfter']
        );
        self::assertNull($office2['Terms'][1]['FlagAfter'], 'the oldest term has nothing after it');
        self::assertSame(1, $office2['GapCount']);
    }

    public function testAOneSeasonGapNamesThatSeason(): void
    {
        $pos = $this->seedPosition('gap1', 'crown', 10);
        $this->term($pos, 'gap1', ['holder_name' => 'Older', 'start_season' => 'spring', 'start_year' => 2022, 'end_season' => 'summer', 'end_year' => 2022]);
        $this->term($pos, 'gap1', ['holder_name' => 'Newer', 'start_season' => 'winter', 'start_year' => 2023, 'end_season' => 'spring', 'end_year' => 2023]);

        $office = $this->office($this->rolls(0), $pos);
        self::assertSame(['Type' => 'gap', 'Label' => 'Gap: Fall 2022', 'Span' => 'Fall 2022'], $office['Terms'][0]['FlagAfter']);
    }

    /**
     * Current is per seat: in a supporting office with two holders, a holder
     * with a current row does not hide the other holder's synthetic term.
     */
    public function testEverySeatedHolderOfASupportingOfficeShowsAsCurrent(): void
    {
        $pos = $this->seedPosition('multi', 'supporting', 10);
        $withRow = $this->seedMundane('withrow');
        $without = $this->seedMundane('without');
        $this->seatOfficer($pos, 'multi', $withRow, 0);
        $this->seatOfficer($pos, 'multi', $without, 0);
        $row = $this->term($pos, 'multi', ['mundane_id' => $withRow, 'start_date' => '2024-04-01', 'is_current' => 1]);

        $office = $this->office($this->rolls(0), $pos);
        $current = array_values(array_filter($office['Terms'], fn ($t) => $t['IsCurrent']));
        self::assertCount(2, $current);
        $byHolder = array_column($current, null, 'MundaneId');
        self::assertSame($row, $byHolder[$withRow]['OfficerHistoryId']);
        self::assertFalse($byHolder[$withRow]['Synthetic']);
        self::assertTrue($byHolder[$without]['Synthetic']);
        self::assertSame(2, $office['TermCount']);
    }

    public function testOverlapFlagged(): void
    {
        $pos = $this->seedPosition('overlap', 'crown', 10);
        $this->term($pos, 'overlap', ['holder_name' => 'Old Sam', 'start_season' => 'spring', 'start_year' => 2021, 'end_season' => 'fall', 'end_year' => 2022]);
        $this->term($pos, 'overlap', ['holder_name' => 'No End', 'start_season' => 'spring', 'start_year' => 2015]);
        $this->term($pos, 'overlap', ['holder_name' => 'New Pat', 'start_season' => 'spring', 'start_year' => 2022, 'end_season' => 'fall', 'end_year' => 2023]);

        $office = $this->office($this->rolls(0), $pos);
        self::assertSame('New Pat', $office['Terms'][0]['HolderName']);
        self::assertSame(['Type' => 'overlap', 'Label' => 'Overlap with Old Sam'], $office['Terms'][0]['FlagAfter']);
        self::assertSame('Old Sam', $office['Terms'][1]['HolderName']);
        self::assertNull($office['Terms'][1]['FlagAfter'], 'no flag against an unknown facing end');
        self::assertSame(1, $office['OverlapCount']);
        self::assertSame(0, $office['GapCount']);
    }

    public function testRetiredAndLegacyOfficesKeepTheirSection(): void
    {
        $active  = $this->seedPosition('active', 'crown', 10);
        $retired = $this->seedPosition('retired', 'supporting', 1, true);
        $emptyRetired = $this->seedPosition('retired_empty', 'supporting', 2, true);

        $this->term($retired, 'retired', ['holder_name' => 'R1', 'display_label' => 'Old Name', 'start_season' => 'spring', 'start_year' => 2001, 'end_season' => 'fall', 'end_year' => 2001]);
        $this->term($retired, 'retired', ['holder_name' => 'R2', 'display_label' => 'Newest Name', 'start_season' => 'spring', 'start_year' => 2003, 'end_season' => 'fall', 'end_year' => 2003]);
        $this->term(0, 'legacy_herald', ['holder_name' => 'L1', 'display_label' => 'Herald', 'start_season' => 'spring', 'start_year' => 1999, 'end_season' => 'fall', 'end_year' => 1999]);
        $this->term(0, 'legacy_herald', ['holder_name' => 'L2', 'display_label' => 'Royal Herald', 'start_season' => 'spring', 'start_year' => 2000, 'end_season' => 'fall', 'end_year' => 2000]);

        $offices = $this->rolls(0);
        $keys = array_column($offices, 'Key');
        $iActive  = array_search('position:' . $active, $keys, true);
        $iRetired = array_search('position:' . $retired, $keys, true);
        $iLegacy  = array_search('role:' . self::MARKER . '_legacy_herald', $keys, true);

        self::assertNotFalse($iActive);
        self::assertNotFalse($iRetired, 'a retired office with history keeps its section');
        self::assertNotFalse($iLegacy, 'position_id = 0 rows are grouped by role');
        self::assertFalse(
            array_search('position:' . $emptyRetired, $keys, true),
            'a retired office with no history is not listed'
        );
        self::assertGreaterThan($iActive, $iRetired, 'history-only offices come after the registry offices');
        self::assertGreaterThan($iActive, $iLegacy);

        $r = $offices[$iRetired];
        self::assertSame('Newest Name', $r['Title'], 'titled by the newest row');
        self::assertTrue($r['Retired']);
        self::assertSame('supporting', $r['Classification']);
        self::assertSame(2, $r['TermCount']);

        $l = $offices[$iLegacy];
        self::assertSame('Royal Herald', $l['Title']);
        self::assertSame(0, $l['PositionId']);
        self::assertNull($l['Classification']);
        self::assertSame(['L2', 'L1'], array_column($l['Terms'], 'HolderName'));
    }

    public function testParkScopeReadsOnlyThatPark(): void
    {
        $pos = $this->seedPosition('scoped', 'crown', 10);
        $this->term($pos, 'scoped', ['holder_name' => 'Kingdom row', 'start_season' => 'spring', 'start_year' => 2010]);
        $this->term($pos, 'scoped', ['park_id' => self::PARK_A, 'holder_name' => 'Park A row', 'start_season' => 'spring', 'start_year' => 2011]);
        $this->term($pos, 'scoped', ['park_id' => self::PARK_B, 'holder_name' => 'Park B row', 'start_season' => 'spring', 'start_year' => 2012]);

        $parkA = $this->office($this->rolls(self::PARK_A), $pos);
        self::assertSame(['Park A row'], array_column($parkA['Terms'], 'HolderName'));

        $kingdom = $this->office($this->rolls(0), $pos);
        self::assertSame(['Kingdom row'], array_column($kingdom['Terms'], 'HolderName'));

        // The kingdom is derived from the park, never trusted from the caller.
        $mismatched = $this->office($this->positions->rolls_for_org(999999, self::PARK_A)['Offices'], $pos);
        self::assertSame(['Park A row'], array_column($mismatched['Terms'], 'HolderName'));

        self::assertSame([], $this->positions->rolls_for_org(self::KINGDOM_ID, 999999)['Offices'], 'unknown park');
    }

    public function testSeatedOfficerWithoutAHistoryRowStillShowsAsCurrent(): void
    {
        $pos = $this->seedPosition('seated', 'crown', 10);
        $member = $this->seedMundane('seated');
        $this->seatOfficer($pos, 'seated', $member, 0);
        $this->term($pos, 'seated', ['holder_name' => 'Before', 'start_season' => 'spring', 'start_year' => 2015, 'end_season' => 'fall', 'end_year' => 2015]);

        // A vacant seat elsewhere gets nothing synthesized.
        $vacant = $this->seedPosition('vacant', 'crown', 11);
        $this->seatOfficer($vacant, 'vacant', 0, 0);

        $offices = $this->rolls(0);
        $office = $this->office($offices, $pos);
        self::assertSame(2, $office['TermCount'], 'the synthetic current term counts');
        $t = $office['Terms'][0];
        self::assertSame(0, $t['OfficerHistoryId']);
        self::assertTrue($t['IsCurrent']);
        self::assertTrue($t['Synthetic']);
        self::assertSame($member, $t['MundaneId']);
        self::assertSame(self::MARKER . ' seated', $t['Persona']);
        self::assertSame('', $t['HolderName']);
        self::assertNull($t['StartSeason']);
        self::assertNull($t['StartYear']);
        self::assertNull($t['StartDate']);
        self::assertNull($t['EndDate']);
        self::assertNull($t['StartOrdinal']);
        self::assertNull($t['EndOrdinal']);
        self::assertSame('? – present', $t['PeriodLabel']);
        self::assertNull($t['FlagAfter']);
        self::assertFalse($office['Terms'][1]['Synthetic']);

        self::assertSame(0, $this->office($offices, $vacant)['TermCount']);

        // A park seat is synthesized in the park's rolls only.
        $parkMember = $this->seedMundane('parkseat');
        $this->seatOfficer($pos, 'seated', $parkMember, self::PARK_A);
        $park = $this->office($this->rolls(self::PARK_A), $pos);
        self::assertSame([$parkMember], array_column($park['Terms'], 'MundaneId'));
        self::assertSame(2, $this->office($this->rolls(0), $pos)['TermCount'], 'kingdom rolls unchanged by the park seat');
    }

    public function testGetOfficerHistoryCarriesHolderAndSeasons(): void
    {
        $pos = $this->seedPosition('shape', 'crown', 10);
        $this->term($pos, 'shape', ['holder_name' => 'Plain', 'start_season' => 'spring', 'start_year' => 2019, 'end_season' => 'fall', 'end_year' => 2020]);
        $this->term($pos, 'shape', ['park_id' => self::PARK_A, 'holder_name' => 'Parky', 'start_season' => 'winter', 'start_year' => 2019]);

        $k = (new Kingdom())->GetOfficerHistory(['KingdomId' => self::KINGDOM_ID])['History'];
        $k = array_values(array_filter($k, fn ($r) => $r['Role'] === self::MARKER . '_shape'));
        self::assertCount(1, $k);
        self::assertSame('Plain', $k[0]['HolderName']);
        self::assertSame('spring', $k[0]['StartSeason']);
        self::assertSame(2019, $k[0]['StartYear']);
        self::assertSame('fall', $k[0]['EndSeason']);
        self::assertSame(2020, $k[0]['EndYear']);
        self::assertFalse($k[0]['IsCurrent']);
        self::assertSame('Spring 2019 – Fall 2020', $k[0]['PeriodLabel']);
        self::assertSame(2019 * 4 + 1, $k[0]['StartOrdinal'], 'the Details modal sorts on the rolls key');
        self::assertSame(2020 * 4 + 3, $k[0]['EndOrdinal']);

        $p = (new Park())->GetOfficerHistory(['ParkId' => self::PARK_A])['History'];
        $p = array_values(array_filter($p, fn ($r) => $r['Role'] === self::MARKER . '_shape'));
        self::assertCount(1, $p);
        self::assertSame('Parky', $p[0]['HolderName']);
        self::assertSame('winter', $p[0]['StartSeason']);
        self::assertSame(2019, $p[0]['StartYear']);
        self::assertNull($p[0]['EndSeason']);
        self::assertNull($p[0]['EndYear']);
        self::assertSame('Winter 2018–19 – ?', $p[0]['PeriodLabel']);
        self::assertSame(2019 * 4, $p[0]['StartOrdinal']);
        self::assertNull($p[0]['EndOrdinal']);
    }

    /**
     * Both GetOfficerHistory scopes return the whole list in the rolls sort key
     * (the Details modal keeps that order within each office, no re-sort): the
     * current term first, then a season-only backfilled term (null StartDate)
     * above an older dated term, regardless of the role's case.
     */
    public function testGetOfficerHistoryOrdersOnTheRollsKey(): void
    {
        $pos = $this->seedPosition('order', 'crown', 10);
        $member = $this->seedMundane('ordercur');
        foreach ([0, self::PARK_A] as $parkId) {
            $dated = $this->term($pos, 'order', ['park_id' => $parkId, 'holder_name' => 'Dated', 'start_date' => '2015-04-10', 'end_date' => '2015-10-01']);
            // Mixed-case legacy role on the same office: still one newest-first run.
            $season = $this->term($pos, 'order', ['park_id' => $parkId, 'role' => strtoupper(self::MARKER) . '_order', 'holder_name' => 'Season',
                'start_season' => 'spring', 'start_year' => 2018, 'end_season' => 'fall', 'end_year' => 2018]);
            $current = $this->term($pos, 'order', ['park_id' => $parkId, 'mundane_id' => $member, 'start_date' => '2012-04-01', 'is_current' => 1]);

            $rows = $parkId > 0
                ? (new Park())->GetOfficerHistory(['ParkId' => $parkId])['History']
                : (new Kingdom())->GetOfficerHistory(['KingdomId' => self::KINGDOM_ID])['History'];
            $mine = array_values(array_filter($rows, fn ($r) => (int) $r['PositionId'] === $pos));
            $scope = $parkId > 0 ? 'Park' : 'Kingdom';
            self::assertSame([$current, $season, $dated], array_column($mine, 'OfficerHistoryId'), $scope . ': current first, then the rolls key');
            self::assertNull($mine[1]['StartDate'], 'the season-only term has no exact start');
            for ($i = 0; $i + 1 < count($rows); $i++) {
                self::assertLessThanOrEqual(0, OfficerPosition::compare_rolls_terms($rows[$i], $rows[$i + 1]), $scope . ': the whole list is in the rolls key');
            }
        }
    }

    /**
     * Item 1: the migration stamps unstamped rows with the ordinals the app
     * would have saved -- every season pair as parse() reads it, dates by month
     * -- and a current winter start that was closed later keeps its lone-winter
     * start. A re-run changes nothing.
     */
    public function testMigrationStampsOrdinalsAsTheAppReadsThem(): void
    {
        $pos = $this->seedPosition('stamp', 'crown', 10);
        $seasons = ['winter', 'spring', 'summer', 'fall'];
        $want = [];
        foreach ([2021, 2022] as $sy) {
            foreach ($seasons as $ss) {
                foreach ([null, 2021, 2022, 2023] as $ey) {
                    foreach ($ey === null ? [null] : $seasons as $es) {
                        $text = ucfirst($ss) . ' ' . $sy . ($ey === null ? ' –' : ' – ' . ucfirst($es) . ' ' . $ey);
                        $p = OfficerTermPeriod::parse($text, 2026);
                        if (is_string($p)) {
                            continue;
                        }
                        $id = $this->term($pos, 'stamp', ['holder_name' => $text, 'start_ordinal' => null, 'end_ordinal' => null,
                            'start_season' => $ss, 'start_year' => $sy, 'end_season' => $es, 'end_year' => $ey]);
                        $want[$id] = [$p['start']['ordinal'], $p['end']['ordinal'] ?? null];
                    }
                }
            }
        }
        $closed = $this->term($pos, 'stamp', ['holder_name' => 'closed current', 'start_ordinal' => null, 'end_ordinal' => null,
            'start_season' => 'winter', 'start_year' => 2024, 'end_date' => '2025-01-10']);
        $want[$closed] = [2024 * 4, 2025 * 4];
        // A single typed "Winter 24" saved with an exact end date: the lone reading, as parse() gives.
        $single = $this->term($pos, 'stamp', ['holder_name' => 'single winter + end date', 'start_ordinal' => null, 'end_ordinal' => null,
            'start_season' => 'winter', 'start_year' => 2024, 'end_season' => 'winter', 'end_year' => 2024, 'end_date' => '2025-01-10']);
        $want[$single] = [OfficerTermPeriod::parse('Winter 24', 2026)['start']['ordinal'], 2025 * 4];
        $dated = $this->term($pos, 'stamp', ['holder_name' => 'dated', 'start_ordinal' => null, 'end_ordinal' => null,
            'start_date' => '2020-12-05', 'end_date' => '2021-03-01']);
        $want[$dated] = [2021 * 4, 2021 * 4 + 1];
        self::assertGreaterThan(80, count($want));

        $read = function (): array {
            $out = [];
            foreach ($this->pdo->query("SELECT officer_history_id, start_ordinal, end_ordinal FROM ork_officer_history WHERE role = '"
                . self::MARKER . "_stamp'") as $r) {
                $out[(int) $r['officer_history_id']] = [
                    $r['start_ordinal'] === null ? null : (int) $r['start_ordinal'],
                    $r['end_ordinal'] === null ? null : (int) $r['end_ordinal'],
                ];
            }
            ksort($out);
            return $out;
        };
        $this->runOrdinalBackfill();
        ksort($want);
        self::assertSame($want, $read());
        $this->runOrdinalBackfill();
        self::assertSame($want, $read(), 'a re-run changes nothing');
    }

    /**
     * Gaps and overlaps against coverage: a nested acting term on a crown office
     * raises neither, co-holders of a supporting office raise no overlaps, and a
     * Fall-to-Fall handoff is clean.
     */
    public function testFlagsFollowCoverageNotAdjacency(): void
    {
        $crown = $this->seedPosition('cover', 'crown', 10);
        $this->term($crown, 'cover', ['holder_name' => 'Reign', 'start_season' => 'spring', 'start_year' => 2023, 'end_season' => 'spring', 'end_year' => 2024]);
        $this->term($crown, 'cover', ['holder_name' => 'Acting', 'start_season' => 'summer', 'start_year' => 2023, 'end_season' => 'summer', 'end_year' => 2023]);
        $this->term($crown, 'cover', ['holder_name' => 'Next', 'start_season' => 'summer', 'start_year' => 2024, 'end_season' => 'fall', 'end_year' => 2024]);
        $this->term($crown, 'cover', ['holder_name' => 'Handoff', 'start_season' => 'fall', 'start_year' => 2024, 'end_season' => 'fall', 'end_year' => 2025]);

        $office = $this->office($this->rolls(0), $crown);
        self::assertSame(['Handoff', 'Next', 'Acting', 'Reign'], array_column($office['Terms'], 'HolderName'));
        self::assertSame([null, null, null, null], array_column($office['Terms'], 'FlagAfter'));
        self::assertSame(0, $office['GapCount'], 'the reign covered the seat around the acting term');
        self::assertSame(0, $office['OverlapCount'], 'a nested acting term is not an overlap; Fall to Fall is a handoff');

        $support = $this->seedPosition('cohold', 'supporting', 11);
        $this->term($support, 'cohold', ['holder_name' => 'X', 'start_season' => 'spring', 'start_year' => 2021, 'end_season' => 'fall', 'end_year' => 2022]);
        $this->term($support, 'cohold', ['holder_name' => 'Y', 'start_season' => 'summer', 'start_year' => 2021, 'end_season' => 'spring', 'end_year' => 2023]);
        $this->term($support, 'cohold', ['holder_name' => 'Z', 'start_season' => 'summer', 'start_year' => 2024, 'end_season' => 'fall', 'end_year' => 2024]);

        $co = $this->office($this->rolls(0), $support);
        self::assertSame(['Z', 'Y', 'X'], array_column($co['Terms'], 'HolderName'));
        self::assertSame(0, $co['OverlapCount'], 'co-holders overlap by design');
        self::assertSame(1, $co['GapCount']);
        self::assertSame('gap', $co['Terms'][0]['FlagAfter']['Type'] ?? null, 'the gap sits under Z, newest first');
        self::assertSame('Summer 2023 – Spring 2024', $co['Terms'][0]['FlagAfter']['Span']);
    }

    /** A linked term keeps the name it was recorded under beside the live persona. */
    public function testALinkedTermCarriesItsRecordedName(): void
    {
        $pos = $this->seedPosition('recorded', 'crown', 10);
        $member = $this->seedMundane('recorded');
        $this->term($pos, 'recorded', ['mundane_id' => $member, 'holder_name' => 'Squire Bob', 'start_season' => 'spring', 'start_year' => 1997, 'end_season' => 'fall', 'end_year' => 1997]);

        $t = $this->office($this->rolls(0), $pos)['Terms'][0];
        self::assertSame($member, $t['MundaneId']);
        self::assertSame('Squire Bob', $t['HolderName']);
        self::assertSame(self::MARKER . ' recorded', $t['Persona']);
    }

    /** The ledger refreshes one office: a Key scopes the reader to that section alone. */
    public function testTheReaderCanBeScopedToOneOffice(): void
    {
        $a = $this->seedPosition('scope_a', 'crown', 10);
        $b = $this->seedPosition('scope_b', 'crown', 11);
        $this->term($a, 'scope_a', ['holder_name' => 'A1', 'start_season' => 'spring', 'start_year' => 2020, 'end_season' => 'fall', 'end_year' => 2020]);
        $this->term($b, 'scope_b', ['holder_name' => 'B1', 'start_season' => 'spring', 'start_year' => 2010, 'end_season' => 'fall', 'end_year' => 2010]);
        $this->term($b, 'scope_b', ['holder_name' => 'B2', 'start_season' => 'spring', 'start_year' => 2015, 'end_season' => 'fall', 'end_year' => 2015]);
        $this->term(0, 'scope_legacy', ['holder_name' => 'L1', 'display_label' => 'Old Office', 'start_season' => 'spring', 'start_year' => 1999]);

        $full = $this->office($this->rolls(0), $b);
        $one = $this->positions->rolls_for_org(self::KINGDOM_ID, 0, 'position:' . $b)['Offices'];
        self::assertCount(1, $one);
        self::assertSame($full, $one[0], 'the scoped section is the same section the full read builds');

        $legacy = $this->positions->rolls_for_org(self::KINGDOM_ID, 0, 'role:' . self::MARKER . '_scope_legacy')['Offices'];
        self::assertCount(1, $legacy);
        self::assertSame('Old Office', $legacy[0]['Title']);
        self::assertSame(['L1'], array_column($legacy[0]['Terms'], 'HolderName'));

        self::assertSame([], $this->positions->rolls_for_org(self::KINGDOM_ID, 0, 'position:99999999')['Offices'], 'an unknown key has no section');
    }

    // ============================================================
    // HELPERS
    // ============================================================

    /** The migration's ordinal section: from its marker to the is_current backfill. */
    private function runOrdinalBackfill(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/db-migrations/2026-11-04-officer-history-rolls.sql');
        $from = strpos($sql, '-- Stored season ordinals');
        $to = strpos($sql, '-- is_current backfill');
        self::assertNotFalse($from);
        self::assertNotFalse($to);
        foreach (preg_split('/;\s*$/m', substr($sql, $from, $to - $from)) as $chunk) {
            $body = trim(preg_replace('/^\s*--.*$/m', '', $chunk));
            if ($body !== '') {
                $this->pdo->exec($body);
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function rolls(int $parkId): array
    {
        $r = $this->positions->rolls_for_org(self::KINGDOM_ID, $parkId);
        self::assertIsArray($r['Offices'] ?? null);
        return $r['Offices'];
    }

    /** @return array<string,mixed> */
    private function office(array $offices, int $positionId): array
    {
        foreach ($offices as $o) {
            if ($o['PositionId'] === $positionId) {
                return $o;
            }
        }
        self::fail('no section for position ' . $positionId);
    }

    /**
     * A raw history row. Unless the fixture gives them, start_ordinal /
     * end_ordinal are stamped as a write path would stamp them (the stored
     * ordinals are what the readers use).
     *
     * @param array<string,mixed> $cols
     */
    private function term(int $positionId, string $suffix, array $cols): int
    {
        if (!array_key_exists('start_ordinal', $cols) && !array_key_exists('end_ordinal', $cols)) {
            $ord = OfficerTermPeriod::resolve([
                'StartSeason' => $cols['start_season'] ?? null, 'StartYear' => $cols['start_year'] ?? null,
                'StartDate' => $cols['start_date'] ?? null,
                'EndSeason' => $cols['end_season'] ?? null, 'EndYear' => $cols['end_year'] ?? null,
                'EndDate' => $cols['end_date'] ?? null,
            ]);
            $cols += ['start_ordinal' => $ord['start'], 'end_ordinal' => $ord['end']];
        }
        $row = $cols + [
            'kingdom_id' => self::KINGDOM_ID, 'park_id' => 0, 'mundane_id' => 0, 'holder_name' => '',
            'role' => self::MARKER . '_' . $suffix, 'position_id' => $positionId,
            'display_label' => 'Test ' . $suffix, 'start_date' => null, 'end_date' => null,
            'start_season' => null, 'start_year' => null, 'end_season' => null, 'end_year' => null,
            'is_current' => 0, 'notes' => '', 'changed_by' => 0,
        ];
        $names = array_keys($row);
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_officer_history (' . implode(', ', $names) . ', created_at) VALUES (:'
            . implode(', :', $names) . ', NOW())'
        );
        $stmt->execute($row);
        return (int) $this->pdo->lastInsertId();
    }

    private function seatOfficer(int $positionId, string $suffix, int $mundaneId, int $parkId): void
    {
        $this->pdo->prepare(
            'INSERT INTO ork_officer (kingdom_id, park_id, mundane_id, role, position_id, system, authorization_id)
             VALUES (:kid, :pid, :mid, :role, :pos, 0, 0)'
        )->execute([
            ':kid' => self::KINGDOM_ID, ':pid' => $parkId, ':mid' => $mundaneId,
            ':role' => self::MARKER . '_' . $suffix, ':pos' => $positionId,
        ]);
    }

    private function seedPark(int $parkId): void
    {
        $this->pdo->prepare(
            'INSERT INTO ork_park
                (park_id, kingdom_id, name, abbreviation, url, address, city, province,
                 postal_code, google_geocode, latitude, longitude, location,
                 map_url, description, directions)
             VALUES (:pid, :kid, :name, "ZZR", "", "", "", "", "", "", 0, 0, "", "", "", "")'
        )->execute([':pid' => $parkId, ':kid' => self::KINGDOM_ID, ':name' => self::MARKER . '_park_' . $parkId]);
    }

    private function seedPosition(string $suffix, string $classification, int $sortOrder, bool $retired = false): int
    {
        $this->pdo->prepare(
            'INSERT INTO ork_officer_position
                (kingdom_id, canonical_key, title, title_alias, classification,
                 is_pinned, is_system, rbac_role_id, has_auth_role, sort_order,
                 parent_position_id, hide_when_vacant, retired_at, created_by, created_at)
             VALUES (:kid, :key, :title, "", :cls, 0, 0, 0, 0, :sort, NULL, 0, '
            . ($retired ? 'NOW()' : 'NULL') . ', 0, NOW())'
        )->execute([
            ':kid' => self::KINGDOM_ID,
            ':key' => self::MARKER . '_' . $suffix,
            ':title' => 'Test ' . $suffix,
            ':cls' => $classification,
            ':sort' => $sortOrder,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function seedMundane(string $suffix): int
    {
        $username = self::MARKER . '_' . $suffix . '_' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            'INSERT INTO ork_mundane
                (given_name, surname, other_name, username, persona, email, park_id, kingdom_id,
                 token, waiver_ext, password_expires, password_salt, xtoken, reeve_qualified_until)
             VALUES (:g, "Test", "", :u, :p, :e, 0, :kid, "", "", "0000-00-00", "", "", "0000-00-00")'
        )->execute([
            ':g' => self::MARKER,
            ':u' => $username,
            ':p' => self::MARKER . ' ' . $suffix,
            ':e' => $username . '@example.test',
            ':kid' => self::KINGDOM_ID,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->seededMundanes[] = $id;
        return $id;
    }
}
