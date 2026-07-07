<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for cross-cutting HasAuthority usage (T-LIB-03, T-LIB-04, DS-14).
 */
final class AuthorizationLibTest extends TestCase
{
    private AdminDashboardFixture $fixture;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);

        $this->fixture = AdminDashboardFixture::create();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);

        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testHasAuthorityOrkAdmin(): void
    {
        $parkId = $this->fixture->firstParkId();
        $admin = $this->fixture->createPlayer($parkId, 'ork-admin');
        $this->fixture->insertGlobalAdmin($admin['mundane_id']);

        $auth = Ork3::$Lib->authorization;
        $this->assertTrue((bool) $auth->HasAuthority($admin['mundane_id'], AUTH_PARK, $parkId, AUTH_EDIT));
        $this->assertTrue((bool) $auth->HasAuthority($admin['mundane_id'], AUTH_KINGDOM, 999999, AUTH_EDIT));
    }

    public function testHasAuthorityParkEdit(): void
    {
        $parkId = $this->fixture->firstParkId();
        $editor = $this->fixture->createPlayer($parkId, 'park-editor');
        $viewer = $this->fixture->createPlayer($parkId, 'park-viewer');
        $this->fixture->insertParkAuth($editor['mundane_id'], $parkId, AUTH_EDIT);

        $auth = Ork3::$Lib->authorization;
        $this->assertTrue((bool) $auth->HasAuthority($editor['mundane_id'], AUTH_PARK, $parkId, AUTH_EDIT));
        $this->assertFalse((bool) $auth->HasAuthority($viewer['mundane_id'], AUTH_PARK, $parkId, AUTH_EDIT));
    }

    public function testHasAuthorityEventCreate(): void
    {
        $parkId = $this->fixture->firstParkId();
        $ctx = $this->fixture->createPublishedEvent($parkId, 'auth-ev');
        $creator = $this->fixture->createPlayer($parkId, 'ev-create');
        $this->insertEventAuth($creator['mundane_id'], $ctx['event_id'], AUTH_CREATE);

        $auth = Ork3::$Lib->authorization;
        $this->assertTrue((bool) $auth->HasAuthority($creator['mundane_id'], AUTH_EVENT, $ctx['event_id'], AUTH_CREATE));

        $stranger = $this->fixture->createPlayer($parkId, 'ev-stranger');
        $this->assertFalse((bool) $auth->HasAuthority($stranger['mundane_id'], AUTH_EVENT, $ctx['event_id'], AUTH_CREATE));
    }

    public function testHasAuthorityInvalidScope(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'invalid-scope');
        $auth = Ork3::$Lib->authorization;

        $this->assertFalse((bool) $auth->HasAuthority($player['mundane_id'], AUTH_PARK, 0, AUTH_EDIT));
        $this->assertFalse((bool) $auth->HasAuthority($player['mundane_id'], AUTH_PARK, -1, AUTH_EDIT));
    }

    private function insertEventAuth(int $mundaneId, int $eventId, string $role): void
    {
        global $DB;
        $DB->Clear();
        $DB->Execute(
            'INSERT INTO ' . DB_PREFIX . 'authorization
             (mundane_id, park_id, kingdom_id, event_id, unit_id, role, modified)
             VALUES (' . (int) $mundaneId . ', 0, 0, ' . (int) $eventId . ', 0, \'' . addslashes($role) . '\', NOW())'
        );
    }
}
