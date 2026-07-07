<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_Player profile reads (T-PLR-01 through T-PLR-07).
 */
final class PlayerProfileTest extends TestCase
{
    private PlayerProfileFixture $fixture;

    private Player $playerDomain;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = PlayerProfileFixture::create();
        $this->playerDomain = new Player();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testGetCustomTitleAwardId(): void
    {
        $domainId = $this->playerDomain->getCustomTitleAwardId();
        $controllerId = $this->mirrorCustomTitleAwardId();

        $this->assertSame($controllerId, $domainId);
    }

    public function testHasNotesCount(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'notes-empty');
        $this->assertFalse($this->mirrorHasNotes($player['mundane_id']));

        $this->fixture->insertNote($player['mundane_id']);
        $this->assertTrue($this->mirrorHasNotes($player['mundane_id']));
    }

    public function testGetOfficerRoles(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'officer-roles');
        $roles = $this->mirrorOfficerRoles($player['mundane_id']);

        $this->assertIsArray($roles);
        foreach ($roles as $role) {
            $this->assertArrayHasKey('role', $role);
            $this->assertArrayHasKey('entity_type', $role);
            $this->assertArrayHasKey('entity_name', $role);
        }
    }

    public function testGetDisplayGrants(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'scoped-admin');
        $this->fixture->insertScopedAuth($player['mundane_id'], $parkId, $player['kingdom_id'], 'admin');

        $isGlobal = $this->mirrorIsOrkAdmin($player['mundane_id']);
        $badges = $this->mirrorAdminBadges($player['mundane_id']);

        $this->assertFalse($isGlobal);
        $this->assertNotEmpty($badges);
        $this->assertSame('Park', $badges[0]['scope']);

        $admin = $this->fixture->createPlayer($parkId, 'auth-domain');
        $this->fixture->insertScopedAuth($admin['mundane_id'], $parkId, $player['kingdom_id'], 'create');
        $this->assertTrue(
            Ork3::$Lib->authorization->HasAuthority($admin['mundane_id'], AUTH_PARK, $parkId, AUTH_EDIT)
        );
    }

    public function testGetBeltlineForPlayer(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'beltline');
        $peers = $this->mirrorBeltlinePeers($player['mundane_id']);

        $this->assertIsArray($peers);
        foreach ($peers as $peer) {
            $this->assertArrayHasKey('PeerId', $peer);
            $this->assertArrayHasKey('Peerage', $peer);
        }
    }

    public function testReconcileAwardMap(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'reconcile');
        $mirror = $this->mirrorReconcileAwardMap($player['kingdom_id']);
        $fixture = $this->fixture->fetchReconcileAwardMap($player['kingdom_id']);

        $this->assertSame($fixture, $mirror);
        $this->assertNotEmpty($mirror);
    }

    private function mirrorCustomTitleAwardId(): int
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            "SELECT award_id FROM " . DB_PREFIX . "award WHERE name = 'Custom Title' AND officer_role='none' LIMIT 1"
        );
        if ($rs && $rs->Size() > 0) {
            $rs->Next();

            return (int) $rs->award_id;
        }

        return 0;
    }

    private function mirrorHasNotes(int $mundaneId): bool
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT COUNT(*) AS n FROM ' . DB_PREFIX . 'mundane_note WHERE mundane_id = ' . (int) $mundaneId
        );

        return ($rs && $rs->Next()) ? ((int) $rs->n > 0) : false;
    }

    /**
     * @return list<array{role: mixed, entity_type: mixed, entity_name: mixed}>
     */
    private function mirrorOfficerRoles(int $mundaneId): array
    {
        global $DB;
        $DB->Clear();
        $officerSql = "SELECT o.role, o.park_id,
            CASE WHEN o.park_id > 0 THEN IFNULL(pt.title, 'Park')
                 WHEN k.parent_kingdom_id > 0 THEN 'Principality'
                 ELSE 'Kingdom' END AS entity_type,
            CASE WHEN o.park_id > 0 THEN p.name ELSE k.name END AS entity_name
            FROM " . DB_PREFIX . 'officer o
            LEFT JOIN ' . DB_PREFIX . 'kingdom k ON o.kingdom_id = k.kingdom_id
            LEFT JOIN ' . DB_PREFIX . 'park p ON o.park_id = p.park_id AND o.park_id > 0
            LEFT JOIN ' . DB_PREFIX . 'parktitle pt ON p.parktitle_id = pt.parktitle_id
            WHERE o.mundane_id = ' . (int) $mundaneId . "
              AND k.active = 'Active'
              AND (o.park_id = 0 OR p.active = 'Active')
            ORDER BY o.park_id DESC, o.role";
        $officerResult = $DB->DataSet($officerSql);
        $officerRoles = [];
        if ($officerResult->Size() > 0) {
            while ($officerResult->Next()) {
                $officerRoles[] = [
                    'role' => $officerResult->role,
                    'entity_type' => $officerResult->entity_type,
                    'entity_name' => $officerResult->entity_name,
                ];
            }
        }

        return $officerRoles;
    }

    private function mirrorIsOrkAdmin(int $mundaneId): bool
    {
        global $DB;
        $DB->Clear();
        $adminCheck = $DB->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . 'authorization
             WHERE mundane_id = ' . (int) $mundaneId . "
               AND role = 'admin'
               AND park_id = 0 AND kingdom_id = 0 AND event_id = 0 AND unit_id = 0
             LIMIT 1"
        );

        return (bool) ($adminCheck && $adminCheck->Size() > 0);
    }

    /**
     * @return list<array{scope: string, id: int, name: mixed}>
     */
    private function mirrorAdminBadges(int $mundaneId): array
    {
        global $DB;
        $DB->Clear();
        $adminGrants = $DB->DataSet(
            'SELECT a.park_id, MAX(p.name) AS park_name,
                    a.kingdom_id, MAX(k.name) AS kingdom_name
             FROM ' . DB_PREFIX . 'authorization a
             LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = a.park_id
             LEFT JOIN ' . DB_PREFIX . 'kingdom k ON k.kingdom_id = a.kingdom_id
             LEFT JOIN ' . DB_PREFIX . 'officer o ON o.authorization_id = a.authorization_id
             WHERE a.mundane_id = ' . (int) $mundaneId . "
               AND a.role IN ('admin', 'create')
               AND (a.park_id > 0 OR a.kingdom_id > 0)
               AND o.authorization_id IS NULL
             GROUP BY a.park_id, a.kingdom_id"
        );
        $badges = [];
        if ($adminGrants && $adminGrants->Size() > 0) {
            do {
                if ($adminGrants->park_id > 0) {
                    $badges[] = [
                        'scope' => 'Park',
                        'id' => (int) $adminGrants->park_id,
                        'name' => $adminGrants->park_name,
                    ];
                } elseif ($adminGrants->kingdom_id > 0) {
                    $badges[] = [
                        'scope' => 'Kingdom',
                        'id' => (int) $adminGrants->kingdom_id,
                        'name' => $adminGrants->kingdom_name,
                    ];
                }
            } while ($adminGrants->Next());
        }

        return $badges;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mirrorBeltlinePeers(int $mundaneId): array
    {
        global $DB;
        $DB->Clear();
        $peerSql = "SELECT m.mundane_id AS PeerId, m.persona AS Persona,
            COALESCE(NULLIF(ma.custom_name,''), ka.name, a.name) AS TitleName,
            COALESCE(alias.peerage, a.peerage) AS Peerage, ma.date AS Date
            FROM " . DB_PREFIX . 'awards ma
            JOIN ' . DB_PREFIX . 'award a ON a.award_id = ma.award_id
            LEFT JOIN ' . DB_PREFIX . 'award alias ON alias.award_id = ma.alias_award_id
            LEFT JOIN ' . DB_PREFIX . 'kingdomaward ka ON ka.kingdomaward_id = ma.kingdomaward_id
            JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = ma.given_by_id
            WHERE ma.mundane_id = ' . (int) $mundaneId . "
                AND (COALESCE(alias.peerage, a.peerage) IN ('Squire','Man-At-Arms','Page','Lords-Page')
                    OR LOWER(COALESCE(NULLIF(ma.custom_name,''), ka.name, a.name)) LIKE '%woman%at%arms%')
                AND (ma.revoked = 0 OR ma.revoked IS NULL)
                AND ma.given_by_id > 0
            ORDER BY m.persona ASC";
        $peerResult = $DB->DataSet($peerSql);
        $peers = [];
        if ($peerResult) {
            while ($peerResult->Next()) {
                $peers[] = [
                    'PeerId' => (int) $peerResult->PeerId,
                    'Persona' => $peerResult->Persona,
                    'TitleName' => $peerResult->TitleName,
                    'Peerage' => $peerResult->Peerage,
                    'Date' => $peerResult->Date,
                ];
            }
        }

        return $peers;
    }

    /**
     * @return array<int, int>
     */
    private function mirrorReconcileAwardMap(int $kingdomId): array
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT kingdomaward_id, award_id FROM ' . DB_PREFIX . 'kingdomaward
             WHERE kingdom_id = ' . (int) $kingdomId . ' AND is_title = 0'
        );
        $awardIdMap = [];
        if ($rs) {
            while ($rs->Next()) {
                $awardIdMap[(int) $rs->award_id] = (int) $rs->kingdomaward_id;
            }
        }

        return $awardIdMap;
    }
}
