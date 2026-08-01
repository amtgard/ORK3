<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for kingdom officer directory (T-RPT-09).
 */
final class OfficerDirectoryTest extends TestCase
{
    private ReportsFixture $fixture;

    private Model_Reports $reportsModel;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = ReportsFixture::create();
        $this->reportsModel = new Model_Reports();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testKingdomOfficerDirectory(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $editor = $this->fixture->createPlayer($parkId, 'off-dir');
        $this->fixture->insertScopedAuth($editor['mundane_id'], 0, $kid, AUTH_CREATE);
        $this->reportsModel->session = (object) ['token' => $editor['token']];
        unset($_SESSION['is_authorized_mundane_id']);

        $result = $this->reportsModel->kingdom_officer_directory($kid);

        $this->assertArrayHasKey('Rows', $result);
        $this->assertArrayHasKey('Mode', $result);
        $this->assertArrayHasKey('Principalities', $result);
        $this->assertSame('parks', $result['Mode']);

        foreach ($result['Rows'] as $row) {
            $this->assertArrayHasKey('KingdomId', $row);
            $this->assertArrayHasKey('KingdomName', $row);
        }

        $domain = new Report();
        $raw = $domain->KingdomOfficerDirectory(['KingdomId' => $kid]);
        $this->assertSame(0, $raw['Status']['Status']);
        $this->assertSame($raw['Kingdoms'], $result['Rows']);
    }

    public function testPrincipalityMerge(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $editor = $this->fixture->createPlayer($parkId, 'off-pr');
        $this->fixture->insertScopedAuth($editor['mundane_id'], 0, $kid, AUTH_CREATE);
        $this->reportsModel->session = (object) ['token' => $editor['token']];
        unset($_SESSION['is_authorized_mundane_id']);

        $includesPrincipality = Ork3::$Lib->kingdom->StatsIncludesPrincipalities($kid);

        $result = $this->reportsModel->kingdom_officer_directory($kid);
        if ($includesPrincipality) {
            $prList = Ork3::$Lib->kingdom->GetPrincipalities(['KingdomId' => $kid]);
            $expectedCount = count($prList['Principalities'] ?? []);
            $withRows = array_filter(
                $result['Principalities'],
                static fn (array $pr): bool => !empty($pr['Rows']),
            );
            $this->assertLessThanOrEqual($expectedCount, count($result['Principalities']));
            $this->assertIsArray($withRows);
        } else {
            $this->assertSame([], $result['Principalities']);
        }
    }
}
