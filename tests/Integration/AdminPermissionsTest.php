<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_Admin permissions listings (T-ADM-02).
 */
final class AdminPermissionsTest extends TestCase
{
    private AdminDashboardFixture $fixture;
    private Administration $adminDomain;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = AdminDashboardFixture::create();
        $this->adminDomain = new Administration();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testGlobalAdminGrantList(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'global-admin');
        $this->fixture->insertGlobalAdmin($player['mundane_id']);

        unset($_SESSION['is_authorized_mundane_id']);
        $denied = $this->adminDomain->GetGlobalAdminGrants('');
        $this->assertSame(ServiceErrorIds::NoAuthorization, $denied['Status'] ?? null);

        $stranger = $this->fixture->createPlayer($parkId, 'c04-stranger');
        unset($_SESSION['is_authorized_mundane_id']);
        $nonAdmin = $this->adminDomain->GetGlobalAdminGrants($stranger['token']);
        $this->assertSame(ServiceErrorIds::NoAuthorization, $nonAdmin['Status'] ?? null);

        unset($_SESSION['is_authorized_mundane_id']);
        $grants = $this->adminDomain->GetGlobalAdminGrants($player['token']);
        $this->assertArrayNotHasKey('Status', $grants);
        $match = array_filter($grants, static fn ($row) => (int) $row['MundaneId'] === $player['mundane_id']);

        $this->assertNotEmpty($match);
        $row = array_values($match)[0];
        $this->assertArrayHasKey('LastLogin', $row);
        $this->assertArrayHasKey('LastCredit', $row);
        $this->assertSame($player['mundane_id'], $row['MundaneId']);
    }

    public function testEventInheritedPermissions(): void
    {
        $parkId = $this->fixture->firstParkId();
        $ctx = $this->fixture->createPublishedEvent($parkId, 'inherited');
        $holder = $this->fixture->createPlayer($parkId, 'park-holder');
        $this->fixture->insertParkAuth($holder['mundane_id'], $parkId, 'admin');

        $inherited = $this->adminDomain->GetEventInheritedPermissions($ctx['event_id']);

        $this->assertNotNull($inherited['creator']);
        $this->assertSame($ctx['mundane_id'], $inherited['creator']['MundaneId']);
        $this->assertSame($parkId, (int) $inherited['parkId']);
        $this->assertGreaterThan(0, (int) $inherited['kingdomId']);

        $holderIds = array_column($inherited['parkAuths'], 'MundaneId');
        $this->assertContains($holder['mundane_id'], $holderIds);

        $playerDomain = new Player();
        $customTitleId = $playerDomain->getCustomTitleAwardId();
        $this->assertGreaterThanOrEqual(0, $customTitleId);
    }
}
