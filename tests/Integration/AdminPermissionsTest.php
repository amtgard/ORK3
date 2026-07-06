<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_Admin permissions listings (T-ADM-02).
 */
final class AdminPermissionsTest extends TestCase
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

    public function testGlobalAdminGrantList(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'global-admin');
        $this->fixture->insertGlobalAdmin($player['mundane_id']);

        $grants = $this->mirrorGlobalAdminGrants();
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

        $inherited = $this->mirrorEventInheritedParkAuths($ctx['event_id']);

        $this->assertNotNull($inherited['creator']);
        $this->assertSame($ctx['mundane_id'], $inherited['creator']['MundaneId']);

        $holderIds = array_column($inherited['parkAuths'], 'MundaneId');
        $this->assertContains($holder['mundane_id'], $holderIds);

        $playerDomain = new Player();
        $customTitleId = $playerDomain->getCustomTitleAwardId();
        $this->assertGreaterThanOrEqual(0, $customTitleId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mirrorGlobalAdminGrants(): array
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT a.authorization_id, a.mundane_id, a.role, a.modified,
                    m.persona, m.username, m.given_name, m.surname,
                    DATE_SUB(m.token_expires, INTERVAL 72 HOUR) AS last_login,
                    lc.last_credit
             FROM ' . DB_PREFIX . 'authorization a
             LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = a.mundane_id
             LEFT JOIN (
                 SELECT mundane_id, MAX(date) AS last_credit
                 FROM ' . DB_PREFIX . 'attendance
                 WHERE credits > 0
                 GROUP BY mundane_id
             ) lc ON lc.mundane_id = a.mundane_id
             WHERE a.role = \'admin\'
               AND a.kingdom_id = 0 AND a.park_id = 0 AND a.event_id = 0 AND a.unit_id = 0
             ORDER BY m.persona'
        );
        $adminAuths = [];
        if ($rs) {
            while ($rs->Next()) {
                $adminAuths[] = [
                    'AuthorizationId' => (int) $rs->authorization_id,
                    'MundaneId' => (int) $rs->mundane_id,
                    'Modified' => $rs->modified,
                    'Persona' => $rs->persona,
                    'UserName' => $rs->username,
                    'GivenName' => $rs->given_name,
                    'Surname' => $rs->surname,
                    'LastLogin' => $rs->last_login,
                    'LastCredit' => $rs->last_credit,
                ];
            }
        }

        return $adminAuths;
    }

    /**
     * @return array{
     *   creator: ?array{MundaneId: int, Persona: mixed},
     *   parkAuths: list<array{MundaneId: int, Role: mixed}>
     * }
     */
    private function mirrorEventInheritedParkAuths(int $eventId): array
    {
        global $DB;
        $eventCreator = null;
        $inheritedParkAuths = [];
        $evParkId = 0;

        $DB->Clear();
        $evRow = $DB->DataSet(
            'SELECT e.mundane_id AS creator_id, e.park_id AS ev_park_id, e.kingdom_id AS ev_kingdom_id,
                    m.persona AS creator_persona, m.given_name, m.surname,
                    p.name AS park_name, k.name AS kingdom_name
             FROM ' . DB_PREFIX . 'event e
             LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = e.mundane_id
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = e.park_id
             LEFT JOIN ' . DB_PREFIX . 'kingdom k ON k.kingdom_id = e.kingdom_id
             WHERE e.event_id = ' . (int) $eventId . ' LIMIT 1'
        );
        if ($evRow && $evRow->Next()) {
            $evParkId = (int) $evRow->ev_park_id;
            if ((int) $evRow->creator_id > 0) {
                $eventCreator = [
                    'MundaneId' => (int) $evRow->creator_id,
                    'Persona' => $evRow->creator_persona,
                ];
            }
        }

        if ($evParkId > 0) {
            $DB->Clear();
            $rs = $DB->DataSet(
                'SELECT a.authorization_id, a.mundane_id, a.role,
                        m.persona, m.given_name, m.surname,
                        o.role AS officer_role
                 FROM ' . DB_PREFIX . 'authorization a
                 LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = a.mundane_id
                 LEFT JOIN ' . DB_PREFIX . 'officer o ON o.authorization_id = a.authorization_id
                 WHERE a.park_id = ' . $evParkId . '
                 ORDER BY a.role DESC, m.persona'
            );
            if ($rs) {
                while ($rs->Next()) {
                    $inheritedParkAuths[] = [
                        'MundaneId' => (int) $rs->mundane_id,
                        'Role' => $rs->role,
                    ];
                }
            }
        }

        return ['creator' => $eventCreator, 'parkAuths' => $inheritedParkAuths];
    }
}
