<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The heartbeat version stamp (Court::getCourtState) must move when a line's artisans
 * change. addArtisan/removeArtisan touch only court_award_artisan and deliberately leave
 * row_version alone, so without the artisan aggregate in the stamp a peer's artisan
 * never reached the other officers' Court Planner (Resource Planning reads them).
 */
final class CourtStateArtisanVersionTest extends TestCase
{
    private CourtFixture $fixture;
    private Court $court;
    private int $courtId;
    private int $playerId;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->fixture  = CourtFixture::create();
        $this->court    = new Court();
        $kid            = $this->fixture->firstKingdomId();
        $this->courtId  = $this->fixture->createCourt(['kingdom_id' => $kid, 'status' => 'published']);
        $this->playerId = $this->fixture->createPlayer('art', $kid)['mundane_id'];
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            // The fixture does not sweep artisans; clear any a failed assertion left.
            $this->fixture->pdo()->exec(
                'DELETE caa FROM ' . DB_PREFIX . 'court_award_artisan caa
                 JOIN ' . DB_PREFIX . 'court_award ca ON ca.court_award_id = caa.court_award_id
                 WHERE ca.court_id = ' . (int)($this->courtId ?? 0)
            );
            $this->fixture->cleanup();
        }
    }

    private function version(): string
    {
        return (string)$this->court->getCourtState($this->courtId)['version'];
    }

    public function testVersionMovesOnArtisanAddRemoveAndSwap(): void
    {
        $caid = $this->fixture->createAward($this->courtId, $this->playerId);
        $rv   = (int)$this->fixture->fetchAward($caid)['row_version'];

        $v0 = $this->version();
        $this->assertSame($v0, $this->version(), 'The stamp is stable while nothing changes.');

        $a = $this->court->addArtisan($caid, $this->playerId, 'Illumination');
        $this->assertGreaterThan(0, $a['CourtAwardArtisanId']);
        $v1 = $this->version();
        $this->assertNotSame($v0, $v1, 'Adding an artisan must flip the version.');

        $b = $this->court->addArtisan($caid, $this->playerId, '');
        $v2 = $this->version();
        $this->assertNotSame($v1, $v2, 'A second artisan must flip it again.');

        $this->court->removeArtisan($a['CourtAwardArtisanId']);
        $v3 = $this->version();
        $this->assertNotSame($v2, $v3, 'Removing an artisan must flip the version.');

        // Remove one and add another: the count is back where it was, the stamp is not.
        $this->court->removeArtisan($b['CourtAwardArtisanId']);
        $this->court->addArtisan($caid, $this->playerId, 'Calligraphy');
        $v4 = $this->version();
        $this->assertNotSame($v3, $v4, 'A remove-then-add must flip the version.');
        $this->assertNotSame($v1, $v4);

        // The artisan writes never touch the line's optimistic-lock token.
        $this->assertSame($rv, (int)$this->fixture->fetchAward($caid)['row_version']);
    }
}
