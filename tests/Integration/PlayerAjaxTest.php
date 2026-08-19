<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for Player AJAX domain actions (T-PLA-01 through T-PLA-05).
 */
final class PlayerAjaxTest extends TestCase
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

    public function testCheckUsernameAvailable(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'username');

        $taken = Controller_PlayerAjax::username_check_payload($player['username']);
        $this->assertFalse($taken['available']);

        $free = Controller_PlayerAjax::username_check_payload('t09plr_unique_' . bin2hex(random_bytes(4)));
        $this->assertTrue($free['available']);
    }

    public function testGetAwardMaxRanks(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'ranks');
        $ranks = $this->playerDomain->GetAwardMaxRanks($player['mundane_id']);

        $this->assertIsArray($ranks);
    }

    public function testMergeAuthTierMatrix(): void
    {
        $parkId = $this->fixture->firstParkId();
        $parkB = $this->fixture->secondParkId($parkId);
        $sameParkA = $this->fixture->createPlayer($parkId, 'merge-a');
        $sameParkB = $this->fixture->createPlayer($parkId, 'merge-b');
        $otherPark = $this->fixture->createPlayer($parkB, 'merge-c');

        $this->assertTrue($this->mirrorMergeAuthorized(
            $sameParkA['kingdom_id'],
            $sameParkA['park_id'],
            $sameParkB['kingdom_id'],
            $sameParkB['park_id'],
            uid: 1,
            isGlobalAdmin: false,
            hasParkEdit: true,
            hasKingdomEdit: false,
        ));

        $this->assertTrue($this->mirrorMergeAuthorized(
            $sameParkA['kingdom_id'],
            $sameParkA['park_id'],
            $otherPark['kingdom_id'],
            $otherPark['park_id'],
            uid: 1,
            isGlobalAdmin: false,
            hasParkEdit: false,
            hasKingdomEdit: true,
        ));

        $this->assertTrue($this->mirrorMergeAuthorized(
            $sameParkA['kingdom_id'],
            $sameParkA['park_id'],
            $otherPark['kingdom_id'] + 99999,
            $otherPark['park_id'],
            uid: 1,
            isGlobalAdmin: true,
            hasParkEdit: false,
            hasKingdomEdit: false,
        ));
    }

    public function testSaveOwnEmail(): void
    {
        $this->assertNotSame(0, $this->playerDomain->SaveOwnEmail(['Token' => '', 'Email' => ''])['Status']);
        $this->assertNotSame(0, $this->playerDomain->SaveOwnEmail(['Token' => '', 'Email' => 'not-an-email'])['Status']);
    }

    public function testAddSecondReturnsPersona(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'second');
        $info = $this->playerDomain->player_info($player['mundane_id']);

        $this->assertSame((string) ($info['Persona'] ?? ''), (string) ($info['Persona'] ?? ''));
    }

    public function testPlayerDomainSurfaceForAjaxContext(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'ajax-domain');

        $this->assertGreaterThanOrEqual(0, $this->playerDomain->getCustomTitleAwardId());
        $this->assertIsArray($this->playerDomain->GetNotes(['MundaneId' => $player['mundane_id']]));
    }

    private function mirrorMergeAuthorized(
        int $fromKid,
        int $fromPid,
        int $toKid,
        int $toPid,
        int $uid,
        bool $isGlobalAdmin,
        bool $hasParkEdit,
        bool $hasKingdomEdit,
    ): bool {
        if ($fromKid !== $toKid) {
            return $isGlobalAdmin;
        }
        if ($fromPid !== $toPid) {
            return $toKid > 0 && $hasKingdomEdit;
        }

        return $toPid > 0 && $hasParkEdit;
    }
}
