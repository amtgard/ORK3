<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization for P3-R2 GetPlayerMilestones (dedup / sort / catalogue use).
 */
final class PlayerMilestonesTest extends TestCase
{
    private Player $player;

    protected function setUp(): void
    {
        $this->player = new Player();
    }

    public function testFirstSigninKnightMasterParagonChronological(): void
    {
        $response = $this->player->GetPlayerMilestones([
            'MundaneId' => 0,
            'PlayerSinceDate' => '2018-01-15',
            'Awards' => [
                [
                    'AwardId' => 17,
                    'Date' => '2020-06-01',
                    'Name' => 'Knight of the Flame',
                    'OfficerRole' => 'none',
                    'IsTitle' => 0,
                ],
                [
                    'AwardId' => 1,
                    'Date' => '2019-03-01',
                    'Name' => 'Master Rose',
                    'OfficerRole' => 'none',
                    'IsTitle' => 0,
                ],
                [
                    'AwardId' => 37,
                    'Date' => '2021-01-01',
                    'Name' => 'Paragon Warrior',
                    'OfficerRole' => 'none',
                    'IsTitle' => 1,
                ],
            ],
            'BeltlinePeers' => [],
            'BeltlineAssociates' => [],
            'IncludeCustom' => false,
        ]);

        $this->assertSame(0, $response['Status']);
        $rows = $response['Detail'];
        $this->assertCount(4, $rows);
        $this->assertSame('first_signin', $rows[0]['type']);
        $this->assertSame('master', $rows[1]['type']);
        $this->assertSame('knight', $rows[2]['type']);
        $this->assertSame('paragon', $rows[3]['type']);
        $this->assertSame('fa-shield-alt', $rows[2]['icon']);
    }

    public function testDedupesMasterTitleAgainstMasterAward(): void
    {
        $response = $this->player->GetPlayerMilestones([
            'MundaneId' => 0,
            'PlayerSinceDate' => null,
            'Awards' => [
                [
                    'AwardId' => 1,
                    'Date' => '2019-03-01',
                    'Name' => 'Master Rose',
                    'OfficerRole' => 'none',
                    'IsTitle' => 0,
                ],
                [
                    'AwardId' => 99,
                    'Date' => '2019-03-01',
                    'Name' => 'Master Rose',
                    'CustomAwardName' => 'Master Rose',
                    'OfficerRole' => 'none',
                    'IsTitle' => 1,
                    'AliasPeerage' => '',
                ],
            ],
            'BeltlinePeers' => [],
            'BeltlineAssociates' => [],
            'IncludeCustom' => false,
        ]);

        $types = array_column($response['Detail'], 'type');
        $this->assertContains('master', $types);
        $this->assertNotContains('title', $types);
    }

    public function testDedupesExactDescriptionAndDate(): void
    {
        $response = $this->player->GetPlayerMilestones([
            'MundaneId' => 0,
            'PlayerSinceDate' => null,
            'Awards' => [
                [
                    'AwardId' => 17,
                    'Date' => '2020-06-01',
                    'Name' => 'Knight of the Flame',
                    'OfficerRole' => 'none',
                    'IsTitle' => 0,
                ],
                [
                    'AwardId' => 17,
                    'Date' => '2020-06-01',
                    'Name' => 'Knight of the Flame',
                    'OfficerRole' => 'none',
                    'IsTitle' => 0,
                ],
            ],
            'BeltlinePeers' => [],
            'BeltlineAssociates' => [],
            'IncludeCustom' => false,
        ]);

        $this->assertCount(1, $response['Detail']);
        $this->assertSame('knight', $response['Detail'][0]['type']);
    }

    public function testSuppressesBeltlineAliasedTitleWhenPeerMilestoneExists(): void
    {
        $response = $this->player->GetPlayerMilestones([
            'MundaneId' => 0,
            'PlayerSinceDate' => null,
            'Awards' => [
                [
                    'AwardId' => 50,
                    'Date' => '2020-01-01',
                    'Name' => 'Squire',
                    'OfficerRole' => 'none',
                    'IsTitle' => 1,
                    'AliasPeerage' => 'Squire',
                ],
            ],
            'BeltlinePeers' => [
                [
                    'Date' => '2020-01-01',
                    'Peerage' => 'Squire',
                    'Persona' => 'Sir Example',
                ],
            ],
            'BeltlineAssociates' => [],
            'IncludeCustom' => false,
        ]);

        $types = array_column($response['Detail'], 'type');
        $this->assertContains('became_associate', $types);
        $this->assertNotContains('title', $types);
    }

    public function testWarlordMasterAwardIsMilestone(): void
    {
        $response = $this->player->GetPlayerMilestones([
            'MundaneId' => 0,
            'PlayerSinceDate' => null,
            'Awards' => [
                [
                    'AwardId' => 12,
                    'Date' => '2022-05-05',
                    'Name' => 'Warlord',
                    'OfficerRole' => 'none',
                    'IsTitle' => 0,
                ],
            ],
            'BeltlinePeers' => [],
            'BeltlineAssociates' => [],
            'IncludeCustom' => false,
        ]);

        $this->assertCount(1, $response['Detail']);
        $this->assertSame('master', $response['Detail'][0]['type']);
        $this->assertStringContainsString('Warlord', $response['Detail'][0]['description']);
    }
}
