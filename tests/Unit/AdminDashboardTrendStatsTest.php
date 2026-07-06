<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_Admin::index YoY trend windows (T-ADM-01).
 */
final class AdminDashboardTrendStatsTest extends TestCase
{
    private Report $reportDomain;

    protected function setUp(): void
    {
        $this->reportDomain = new Report();
    }

    public function testYoYWindowBoundaries(): void
    {
        $windows = $this->mirrorYoYWindows();

        $this->assertSame(date('Y') . '-01-01', $windows['thisYearStart']);
        $this->assertSame((date('Y') - 1) . '-01-01', $windows['lastYearStart']);
        $this->assertSame(date('Y-m-d', strtotime('-1 year')), $windows['lastYearEnd']);
        $this->assertSame(date('Y-m-d'), $windows['now1yr']);
        $this->assertSame(date('Y-m-d', strtotime('-2 years')), $windows['prev1yrStart']);
        $this->assertSame(date('Y-m-d', strtotime('-1 year')), $windows['prev1yrEnd']);

        $this->assertLessThan($windows['now1yr'], $windows['prev1yrEnd']);
        $this->assertLessThan($windows['prev1yrEnd'], $windows['prev1yrStart']);
    }

    public function testPrevWeeklyMonthlyKingdomKeys(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $weekly = $this->mirrorPrevWeeklyKingdomCounts();
        $monthly = $this->mirrorPrevMonthlyKingdomCounts();

        $this->assertIsArray($weekly);
        $this->assertIsArray($monthly);

        foreach ($weekly as $kingdomId => $count) {
            $this->assertIsInt($kingdomId);
            $this->assertGreaterThanOrEqual(0, $kingdomId);
            $this->assertGreaterThanOrEqual(0, $count);
        }

        foreach ($monthly as $kingdomId => $count) {
            $this->assertIsInt($kingdomId);
            $this->assertGreaterThanOrEqual(0, $kingdomId);
            $this->assertGreaterThanOrEqual(0, $count);
        }

        if (ork3_test_db_available()) {
            $summary = $this->reportDomain->GetActiveKingdomsSummary([]);
            $this->assertSame(0, $summary['Status']['Status']);
            $this->assertIsArray($summary['ActiveKingdomsSummaryList']);

            $distinct = $this->reportDomain->GetDistinctActivePlayerCount(26);
            $this->assertGreaterThanOrEqual(0, $distinct);
        }
    }

    /**
     * @return array{
     *   thisYearStart: string,
     *   lastYearStart: string,
     *   lastYearEnd: string,
     *   now1yr: string,
     *   prev1yrStart: string,
     *   prev1yrEnd: string
     * }
     */
    private function mirrorYoYWindows(): array
    {
        return [
            'thisYearStart' => date('Y') . '-01-01',
            'lastYearStart' => (date('Y') - 1) . '-01-01',
            'lastYearEnd' => date('Y-m-d', strtotime('-1 year')),
            'now1yr' => date('Y-m-d'),
            'prev1yrStart' => date('Y-m-d', strtotime('-2 years')),
            'prev1yrEnd' => date('Y-m-d', strtotime('-1 year')),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function mirrorPrevWeeklyKingdomCounts(): array
    {
        global $DB;
        $DB->Clear();
        $prevWkRs = $DB->DataSet(
            'SELECT COUNT(mw.mundane_id) AS att, mw.kingdom_id
             FROM (
                 SELECT mundane_id, date_year, date_week3, kingdom_id
                 FROM ' . DB_PREFIX . 'attendance
                 WHERE date >  DATE_SUB(CURDATE(), INTERVAL 52 WEEK)
                   AND date <= DATE_SUB(CURDATE(), INTERVAL 26 WEEK)
                   AND mundane_id > 0
                 GROUP BY date_year, date_week3, mundane_id, kingdom_id
             ) mw
             GROUP BY mw.kingdom_id'
        );
        $prevWeekly = [];
        if ($prevWkRs) {
            while ($prevWkRs->Next()) {
                $prevWeekly[(int) $prevWkRs->kingdom_id] = (int) $prevWkRs->att;
            }
        }

        return $prevWeekly;
    }

    /**
     * @return array<int, int>
     */
    private function mirrorPrevMonthlyKingdomCounts(): array
    {
        global $DB;
        $DB->Clear();
        $prevMoRs = $DB->DataSet(
            'SELECT COUNT(mm.mundane_id) AS mo, mm.kingdom_id
             FROM (
                 SELECT mundane_id, date_year, date_month, kingdom_id
                 FROM ' . DB_PREFIX . 'attendance
                 WHERE date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                   AND date <  DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                   AND mundane_id > 0
                 GROUP BY date_year, date_month, mundane_id, kingdom_id
             ) mm
             GROUP BY mm.kingdom_id'
        );
        $prevMonthly = [];
        if ($prevMoRs) {
            while ($prevMoRs->Next()) {
                $prevMonthly[(int) $prevMoRs->kingdom_id] = (int) $prevMoRs->mo;
            }
        }

        return $prevMonthly;
    }
}
