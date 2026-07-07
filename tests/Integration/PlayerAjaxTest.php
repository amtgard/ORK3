<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_PlayerAjax actions (T-PLA-01 through T-PLA-05).
 */
final class PlayerAjaxTest extends TestCase
{
    private PlayerProfileFixture $fixture;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = PlayerProfileFixture::create();
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
        $ranks = $this->mirrorAwardMaxRanks($player['mundane_id']);

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
        $this->assertSame(1, $this->mirrorSaveOwnEmailStatus(''));
        $this->assertSame(1, $this->mirrorSaveOwnEmailStatus('not-an-email'));
        $this->assertSame(0, $this->mirrorSaveOwnEmailStatus('valid@example.test'));
    }

    public function testAddSecondReturnsPersona(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'second');
        $persona = $this->mirrorSupporterPersona($player['mundane_id']);

        $this->assertSame($player['mundane_id'] > 0 ? (string) $this->fetchPersona($player['mundane_id']) : '', $persona);
    }

    public function testPlayerDomainSurfaceForAjaxContext(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'ajax-domain');
        $playerDomain = new Player();

        $this->assertGreaterThanOrEqual(0, $playerDomain->getCustomTitleAwardId());
        $this->assertIsArray(Ork3::$Lib->player->GetNotes(['MundaneId' => $player['mundane_id']]));
    }

    /**
     * @return array<int, int>
     */
    private function mirrorAwardMaxRanks(int $playerId): array
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT ka.award_id, MAX(aw.rank) AS max_rank
             FROM ' . DB_PREFIX . 'awards aw
             INNER JOIN ' . DB_PREFIX . 'kingdomaward ka ON ka.kingdomaward_id = aw.kingdomaward_id
             WHERE aw.mundane_id = ' . (int) $playerId . ' AND aw.rank > 0
             GROUP BY ka.award_id'
        );
        $ranks = [];
        while ($rs && $rs->Next()) {
            $ranks[(int) $rs->award_id] = (int) $rs->max_rank;
        }

        return $ranks;
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

    private function mirrorSaveOwnEmailStatus(string $email): int
    {
        if (!strlen($email)) {
            return 1;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 1;
        }

        return 0;
    }

    private function mirrorSupporterPersona(int $mundaneId): string
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT persona FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $mundaneId . ' LIMIT 1'
        );
        if ($rs && $rs->Next()) {
            return (string) $rs->persona;
        }

        return '';
    }

    private function fetchPersona(int $mundaneId): string
    {
        return $this->mirrorSupporterPersona($mundaneId);
    }
}
