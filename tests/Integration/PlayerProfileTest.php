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

    /**
     * @param array{Token?: string, MundaneId?: int} $request
     */
    private function assertPlayerProfileGetterDeniedWithoutToken(callable $call, array $request): void
    {
        unset($_SESSION['is_authorized_mundane_id']);
        unset($request['Token']);
        $denied = $call($request);
        $this->assertSame(ServiceErrorIds::SecureTokenFailure, $denied['Status'] ?? null);
    }

    /**
     * @param array{Token?: string, MundaneId?: int} $request
     */
    private function assertPlayerProfileGetterDeniedForStranger(callable $call, array $request, string $suffix): void
    {
        $parkId = $this->fixture->firstParkId();
        $stranger = $this->fixture->createPlayer($parkId, $suffix);
        unset($_SESSION['is_authorized_mundane_id']);
        $request['Token'] = $stranger['token'];
        $denied = $call($request);
        $this->assertSame(ServiceErrorIds::NoAuthorization, $denied['Status'] ?? null);
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
        unset($_SESSION['is_authorized_mundane_id']);
        $roles = $this->playerDomain->GetOfficerRoles([
            'Token' => $player['token'],
            'MundaneId' => $player['mundane_id'],
        ]);

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

        unset($_SESSION['is_authorized_mundane_id']);
        $grants = $this->playerDomain->GetDisplayGrants([
            'Token' => $player['token'],
            'MundaneId' => $player['mundane_id'],
        ]);

        $this->assertFalse($grants['IsOrkAdmin']);
        $this->assertNotEmpty($grants['AdminGrants']);
        $this->assertSame('Park', $grants['AdminGrants'][0]['scope']);

        $admin = $this->fixture->createPlayer($parkId, 'auth-domain');
        $this->fixture->insertScopedAuth($admin['mundane_id'], $parkId, $player['kingdom_id'], 'create');
        $this->assertTrue(
            Ork3::$Lib->authorization->HasAuthority($admin['mundane_id'], AUTH_PARK, $parkId, AUTH_EDIT)
        );
    }

    public function testGetDisplayGrantsRequiresToken(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'c21-grants-token');
        $request = ['MundaneId' => $player['mundane_id']];
        $this->assertPlayerProfileGetterDeniedWithoutToken(
            fn (array $req) => $this->playerDomain->GetDisplayGrants($req),
            $request,
        );
    }

    public function testGetDisplayGrantsParkEditorCanView(): void
    {
        $parkId = $this->fixture->firstParkId();
        $subject = $this->fixture->createPlayer($parkId, 'c21-grants-subject');
        $this->fixture->insertScopedAuth($subject['mundane_id'], $parkId, $subject['kingdom_id'], 'admin');
        $editor = $this->fixture->createPlayer($parkId, 'c21-grants-editor');
        $this->fixture->insertScopedAuth($editor['mundane_id'], $parkId, $subject['kingdom_id'], AUTH_CREATE);

        unset($_SESSION['is_authorized_mundane_id']);
        $this->assertPlayerProfileGetterDeniedForStranger(
            fn (array $req) => $this->playerDomain->GetDisplayGrants($req),
            ['MundaneId' => $subject['mundane_id']],
            'c21-grants-stranger',
        );

        unset($_SESSION['is_authorized_mundane_id']);
        $grants = $this->playerDomain->GetDisplayGrants([
            'Token' => $editor['token'],
            'MundaneId' => $subject['mundane_id'],
        ]);
        $this->assertArrayNotHasKey('Status', $grants);
        $this->assertNotEmpty($grants['AdminGrants']);
    }

    public function testGetBeltlineForPlayer(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'beltline');
        unset($_SESSION['is_authorized_mundane_id']);
        $beltline = $this->playerDomain->GetBeltlineForPlayer([
            'Token' => $player['token'],
            'MundaneId' => $player['mundane_id'],
        ]);
        $peers = $beltline['Peers'];

        $this->assertIsArray($peers);
        foreach ($peers as $peer) {
            $this->assertArrayHasKey('PeerId', $peer);
            $this->assertArrayHasKey('Peerage', $peer);
        }
    }

    public function testPlayerProfileGetterGateShared(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'c21-gate-shared');
        $request = ['MundaneId' => $player['mundane_id']];

        $this->assertPlayerProfileGetterDeniedWithoutToken(
            fn (array $req) => $this->playerDomain->GetOfficerRoles($req),
            $request,
        );
        $this->assertPlayerProfileGetterDeniedWithoutToken(
            fn (array $req) => $this->playerDomain->GetBeltlineForPlayer($req),
            $request,
        );
        $this->assertPlayerProfileGetterDeniedForStranger(
            fn (array $req) => $this->playerDomain->GetOfficerRoles($req),
            $request,
            'c21-gate-officer-x',
        );
        $this->assertPlayerProfileGetterDeniedForStranger(
            fn (array $req) => $this->playerDomain->GetBeltlineForPlayer($req),
            $request,
            'c21-gate-beltline-x',
        );
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

        unset($_SESSION['is_authorized_mundane_id']);
        $revoked = $this->playerDomain->GetRevokedAwardsForPlayer([
            'Token' => $player['token'],
            'MundaneId' => $player['mundane_id'],
        ]);
        $titleIds = array_column($revoked['RevokedTitles'], 'AwardsId');
        $awardIds = array_column($revoked['RevokedAwards'], 'AwardsId');

        $this->assertContains($aliasTitleId, $titleIds);
        $this->assertNotContains($aliasTitleId, $awardIds);
        $this->assertContains($plainLadderId, $awardIds);
        $this->assertNotContains($plainLadderId, $titleIds);
    }

    public function testGetRevokedAwardsForPlayerRequiresToken(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'c21-revoked-token');
        $request = ['MundaneId' => $player['mundane_id']];
        $this->assertPlayerProfileGetterDeniedWithoutToken(
            fn (array $req) => $this->playerDomain->GetRevokedAwardsForPlayer($req),
            $request,
        );
    }

    public function testGetRevokedAwardsForPlayerParkEditorCanView(): void
    {
        $parkId = $this->fixture->firstParkId();
        $subject = $this->fixture->createPlayer($parkId, 'c21-revoked-subject');
        $ladderId = $this->fixture->ladderAwardId();
        $this->assertGreaterThan(0, $ladderId);
        $awardId = $this->fixture->insertRevokedAward($subject['mundane_id'], $ladderId, 0);
        $editor = $this->fixture->createPlayer($parkId, 'c21-revoked-editor');
        $this->fixture->insertScopedAuth($editor['mundane_id'], $parkId, $subject['kingdom_id'], AUTH_CREATE);

        unset($_SESSION['is_authorized_mundane_id']);
        $this->assertPlayerProfileGetterDeniedForStranger(
            fn (array $req) => $this->playerDomain->GetRevokedAwardsForPlayer($req),
            ['MundaneId' => $subject['mundane_id']],
            'c21-revoked-stranger',
        );

        unset($_SESSION['is_authorized_mundane_id']);
        $revoked = $this->playerDomain->GetRevokedAwardsForPlayer([
            'Token' => $editor['token'],
            'MundaneId' => $subject['mundane_id'],
        ]);
        $this->assertArrayNotHasKey('Status', $revoked);
        $this->assertContains($awardId, array_column($revoked['RevokedAwards'], 'AwardsId'));
    }

    public function testReportDatabaseInitialized(): void
    {
        $report = new Report();
        $this->assertNotNull($report->db);
    }
}
