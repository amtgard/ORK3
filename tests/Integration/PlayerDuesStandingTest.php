<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Player::GetDuesStanding, the one query behind the dues expiry notice.
 */
final class PlayerDuesStandingTest extends TestCase
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

    public function testNoDuesOnFile(): void
    {
        $p = $this->fixture->createPlayer($this->fixture->firstParkId(), 'nodues');
        $this->assertSame(['DuesUntil' => '', 'DuesForLife' => 0], $this->playerDomain->GetDuesStanding($p['mundane_id']));
        $this->assertSame(['DuesUntil' => '', 'DuesForLife' => 0], $this->playerDomain->GetDuesStanding(0));
    }

    public function testFurthestNonRevokedRowWins(): void
    {
        $p = $this->fixture->createPlayer($this->fixture->firstParkId(), 'dues');
        $this->fixture->insertDues($p['mundane_id'], $p['park_id'], $p['kingdom_id'], '2026-05-01');
        $this->fixture->insertDues($p['mundane_id'], $p['park_id'], $p['kingdom_id'], '2026-11-21');
        $this->fixture->insertDues($p['mundane_id'], $p['park_id'], $p['kingdom_id'], '2027-11-21', 0, 1); // revoked: ignored
        $this->assertSame(['DuesUntil' => '2026-11-21', 'DuesForLife' => 0], $this->playerDomain->GetDuesStanding($p['mundane_id']));
    }

    public function testLifetimeRowIsReportedEvenWhenAnotherRowIsLater(): void
    {
        $p = $this->fixture->createPlayer($this->fixture->firstParkId(), 'life');
        $this->fixture->insertDues($p['mundane_id'], $p['park_id'], $p['kingdom_id'], '2020-01-01', 1);
        $this->fixture->insertDues($p['mundane_id'], $p['park_id'], $p['kingdom_id'], '2026-11-21');
        $standing = $this->playerDomain->GetDuesStanding($p['mundane_id']);
        $this->assertSame(1, $standing['DuesForLife']);
        $this->assertNull(DuesNotice::classify($standing['DuesUntil'], (bool) $standing['DuesForLife'], '2026-11-19'));
    }

    public function testRevokedLifetimeRowDoesNotCount(): void
    {
        $p = $this->fixture->createPlayer($this->fixture->firstParkId(), 'exlife');
        $this->fixture->insertDues($p['mundane_id'], $p['park_id'], $p['kingdom_id'], '2020-01-01', 1, 1);
        $this->fixture->insertDues($p['mundane_id'], $p['park_id'], $p['kingdom_id'], '2026-11-10');
        $standing = $this->playerDomain->GetDuesStanding($p['mundane_id']);
        $this->assertSame(['DuesUntil' => '2026-11-10', 'DuesForLife' => 0], $standing);
        $this->assertSame('lapsed', DuesNotice::classify($standing['DuesUntil'], false, '2026-11-19')['State']);
    }
}
