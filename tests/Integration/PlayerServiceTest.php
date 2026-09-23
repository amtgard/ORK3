<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for residual Model_Player / PlayerAjax / WnAjax lib paths (T-LIB-06, T-LIB-15, T-LIB-16).
 */
final class PlayerServiceTest extends TestCase
{
    private PlayerProfileFixture $fixture;

    private Player $playerDomain;

    /** @var list<int> */
    private array $milestoneIds = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);

        $this->fixture = PlayerProfileFixture::create();
        $this->playerDomain = new Player();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);

        if (isset($this->fixture)) {
            foreach ($this->milestoneIds as $id) {
                $this->fixture->deleteMilestone($id);
            }
            $this->fixture->cleanup();
        }
    }

    public function testCustomMilestoneCrud(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'mile-crud');

        $add = $this->playerDomain->AddCustomMilestone([
            'Token' => $player['token'],
            'MundaneId' => $player['mundane_id'],
            'Icon' => 'fa-trophy',
            'Description' => 'T19 milestone',
            'MilestoneDate' => '2020-06-15',
        ]);
        $this->assertSame(0, $add['Status']);
        $milestoneId = (int) $add['Detail'];
        $this->assertGreaterThan(0, $milestoneId);
        $this->milestoneIds[] = $milestoneId;

        $update = $this->playerDomain->UpdateCustomMilestone([
            'Token' => $player['token'],
            'MundaneId' => $player['mundane_id'],
            'MilestoneId' => $milestoneId,
            'Icon' => 'fa-star',
            'Description' => 'T19 updated',
            'MilestoneDate' => '2021-01-01',
        ]);
        $this->assertSame(0, $update['Status']);

        $milestones = $this->playerDomain->GetCustomMilestones($player['mundane_id']);
        $match = array_values(array_filter(
            $milestones,
            static fn (array $row): bool => (int) ($row['MilestoneId'] ?? 0) === $milestoneId,
        ));
        $this->assertCount(1, $match);
        $this->assertSame('T19 updated', $match[0]['Description']);

        $delete = $this->playerDomain->DeleteCustomMilestone([
            'Token' => $player['token'],
            'MundaneId' => $player['mundane_id'],
            'MilestoneId' => $milestoneId,
        ]);
        $this->assertSame(0, $delete['Status']);
        $this->milestoneIds = array_values(array_filter(
            $this->milestoneIds,
            static fn (int $id): bool => $id !== $milestoneId,
        ));
    }

    public function testBustRosterCachesForPlayer(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'cache-bust');

        $this->playerDomain->bustRosterCachesForPlayer($player['mundane_id']);
        $this->assertTrue(true);
    }

    public function testCheckUsernameAvailableDomainAndAjaxPayload(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'uname');

        $this->assertFalse($this->playerDomain->CheckUsernameAvailable($player['username']));
        $this->assertTrue($this->playerDomain->CheckUsernameAvailable('t19_free_' . bin2hex(random_bytes(4))));

        $taken = Controller_PlayerAjax::username_check_payload($player['username']);
        $this->assertFalse($taken['available']);
        $this->assertSame(0, $taken['status']);

        $free = Controller_PlayerAjax::username_check_payload('t19_ajax_' . bin2hex(random_bytes(4)));
        $this->assertTrue($free['available']);
    }

    public function testDismissWhatsNewDomain(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'wn-dismiss');
        $version = 'T19_wn_' . bin2hex(random_bytes(4));

        $this->assertSame(0, $this->playerDomain->DismissWhatsNew($player['mundane_id'], $version)['Status']);
        $this->assertTrue($this->playerDomain->GetWhatsNewSeen($player['mundane_id'], $version));
    }
}
