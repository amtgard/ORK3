<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for abbreviation uniqueness AJAX (T-ADM-06, T-ADM-07).
 */
final class AbbreviationUniqueTest extends TestCase
{
    private AdminDashboardFixture $fixture;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = AdminDashboardFixture::create();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testParkAbbreviationExcludeSelf(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->firstKingdomId();

        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet('SELECT abbreviation FROM ' . DB_PREFIX . "park WHERE park_id = {$parkId} LIMIT 1");
        $this->assertTrue((bool) ($rs && $rs->Next()));
        $abbr = strtoupper((string) $rs->abbreviation);

        $takenOther = $this->mirrorParkAbbreviationTaken($kingdomId, $abbr, excludeParkId: 0);
        $availableSelf = $this->mirrorParkAbbreviationTaken($kingdomId, $abbr, excludeParkId: $parkId);

        $this->assertTrue($takenOther);
        $this->assertFalse($availableSelf);

        $parkDomain = new Park();
        $response = $parkDomain->GetParkDetails(['ParkId' => $parkId]);
        $this->assertSame(0, $response['Status']['Status']);
    }

    public function testKingdomAbbreviationExcludeSelf(): void
    {
        $kingdomId = $this->fixture->firstKingdomId();
        $abbr = $this->fixture->kingdomAbbreviation($kingdomId);

        $takenOther = $this->mirrorKingdomAbbreviationTaken($abbr, excludeKingdomId: 0);
        $availableSelf = $this->mirrorKingdomAbbreviationTaken($abbr, excludeKingdomId: $kingdomId);

        $this->assertTrue($takenOther);
        $this->assertFalse($availableSelf);
    }

    private function mirrorParkAbbreviationTaken(int $kingdomId, string $abbr, int $excludeParkId): bool
    {
        global $DB;
        $abbrEsc = mysql_real_escape_string($abbr);
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT name FROM ' . DB_PREFIX . "park
             WHERE kingdom_id = {$kingdomId}
               AND abbreviation = '{$abbrEsc}'
               AND park_id != {$excludeParkId}
               AND active = 'Active'
             LIMIT 1"
        );

        return (bool) ($rs && $rs->Next());
    }

    private function mirrorKingdomAbbreviationTaken(string $abbr, int $excludeKingdomId): bool
    {
        $abbr = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($abbr)));
        if ($abbr === '') {
            return false;
        }

        global $DB;
        $excludeClause = $excludeKingdomId > 0 ? " AND kingdom_id != {$excludeKingdomId}" : '';
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT kingdom_id FROM ' . DB_PREFIX . "kingdom WHERE abbreviation = '{$abbr}'{$excludeClause} LIMIT 1"
        );

        return (bool) ($rs && $rs->Next());
    }
}
