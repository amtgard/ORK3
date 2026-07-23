<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for Player profile domain reads (T-PLR-01 through T-PLR-07).
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
        $this->assertGreaterThanOrEqual(0, $domainId);
    }

    public function testHasNotesCount(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'notes-empty');
        $this->assertFalse($this->playerDomain->GetNotesCount($player['mundane_id']));

        $this->fixture->insertNote($player['mundane_id']);
        $this->assertTrue($this->playerDomain->GetNotesCount($player['mundane_id']));
    }

    public function testGetOfficerRoles(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'officer-roles');
        $roles = $this->playerDomain->GetOfficerRoles($player['mundane_id']);

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

        $grants = $this->playerDomain->GetDisplayGrants($player['mundane_id']);

        $this->assertFalse($grants['IsOrkAdmin']);
        $this->assertNotEmpty($grants['AdminGrants']);
        $this->assertSame('Park', $grants['AdminGrants'][0]['scope']);

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
        $beltline = $this->playerDomain->GetBeltlineForPlayer($player['mundane_id']);
        $peers = $beltline['Peers'];

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
        $domain = $this->playerDomain->GetReconcileAwardMap($player['kingdom_id']);
        $fixture = $this->fixture->fetchReconcileAwardMap($player['kingdom_id']);

        $this->assertSame($fixture, $domain);
        $this->assertNotEmpty($domain);
    }

    public function testGetRevokedAwardsClassifiesAliasTitles(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'c18-revoked');
        $ladderId = $this->fixture->ladderAwardId();
        $titleAliasId = $this->fixture->titleAliasAwardId();
        $this->assertGreaterThan(0, $ladderId);
        $this->assertGreaterThan(0, $titleAliasId);

        $aliasTitleId = $this->fixture->insertRevokedAward(
            $player['mundane_id'],
            $ladderId,
            $titleAliasId,
        );
        $plainLadderId = $this->fixture->insertRevokedAward(
            $player['mundane_id'],
            $ladderId,
            0,
        );

        $revoked = $this->playerDomain->GetRevokedAwardsForPlayer($player['mundane_id']);
        $titleIds = array_column($revoked['RevokedTitles'], 'AwardsId');
        $awardIds = array_column($revoked['RevokedAwards'], 'AwardsId');

        $this->assertContains($aliasTitleId, $titleIds);
        $this->assertNotContains($aliasTitleId, $awardIds);
        $this->assertContains($plainLadderId, $awardIds);
        $this->assertNotContains($plainLadderId, $titleIds);
    }

    public function testReportDatabaseInitialized(): void
    {
        $report = new Report();
        $this->assertNotNull($report->db);
    }
}
