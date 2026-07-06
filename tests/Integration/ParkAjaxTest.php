<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_ParkAjax actions (T-PRA-03).
 */
final class ParkAjaxTest extends TestCase
{
    private ParkProfileFixture $fixture;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = ParkProfileFixture::create();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testCheckParkAbbreviationUnique(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->kingdomIdForPark($parkId);
        $abbr = $this->fixture->fetchParkAbbreviation($parkId);

        $taken = $this->mirrorParkAbbreviationTaken($kingdomId, $abbr, 0);
        $this->assertTrue($taken);

        $available = $this->mirrorParkAbbreviationTaken($kingdomId, $abbr, $parkId);
        $this->assertFalse($available);
    }

    private function mirrorParkAbbreviationTaken(int $kingdomId, string $abbr, int $excludeParkId): bool
    {
        $abbr = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($abbr)));
        if ($abbr === '') {
            return false;
        }

        global $DB;
        $excludeClause = $excludeParkId > 0 ? ' AND park_id != ' . $excludeParkId : '';
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT park_id FROM ' . DB_PREFIX . "park WHERE abbreviation = '{$abbr}' AND kingdom_id = {$kingdomId}{$excludeClause} LIMIT 1"
        );

        return (bool) ($rs && $rs->Next());
    }
}
