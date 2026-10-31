<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Kingdom Court | Local Court: a court the kingdom holds at a park event keeps its
 * park, but its givers and its place on the kingdom's court list are the kingdom's.
 */
final class CourtIssuedByTest extends TestCase
{
    private CourtFixture $fx;
    private Court $court;
    private int $k;
    private int $p;
    private array $kingdomMonarch;
    private array $parkMonarch;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->fx = CourtFixture::create();
        $this->court = new Court();

        $this->k = $this->fx->otherKingdomId($this->fx->firstKingdomId());
        $this->p = $this->fx->firstParkId($this->k);

        $this->kingdomMonarch = $this->fx->createPlayer('issued-kingdom', $this->k);
        $this->parkMonarch    = $this->fx->createPlayer('issued-park', $this->k, $this->p);
        $this->fx->insertOfficer($this->kingdomMonarch['mundane_id'], $this->k, 0, 'Monarch');
        $this->fx->insertOfficer($this->parkMonarch['mundane_id'], $this->k, $this->p, 'Monarch');
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
    }

    private function parkCourt(): int
    {
        return $this->fx->createCourt([
            'kingdom_id' => $this->k,
            'park_id'    => $this->p,
            'status'     => 'draft',
            'court_date' => date('Y-m-d'),
        ]);
    }

    public function test_only_a_parks_local_court_is_not_the_kingdoms(): void
    {
        $this->assertFalse(Court::isKingdomCourt(5, 'local'));
        $this->assertTrue(Court::isKingdomCourt(5, 'kingdom'));
        $this->assertTrue(Court::isKingdomCourt(0, 'local'));
    }

    public function test_a_kingdom_court_at_a_park_defaults_to_the_kingdom_monarch(): void
    {
        $id = $this->parkCourt();
        $this->assertSame(
            $this->parkMonarch['mundane_id'],
            (int)$this->court->getCourtGiverOptions($id)['default']['mundane_id']
        );

        $this->assertTrue($this->court->updateCourt($id, ['IssuedBy' => 'kingdom']));

        $this->assertSame(
            $this->kingdomMonarch['mundane_id'],
            (int)$this->court->getCourtGiverOptions($id)['default']['mundane_id']
        );
        $detail = $this->court->getCourtDetail($id);
        $this->assertTrue($detail['IsKingdomCourt']);
        $this->assertSame($this->p, $detail['ParkId']);
    }

    public function test_changing_the_issuer_drops_the_stored_giver_roster_and_resaving_keeps_it(): void
    {
        $id = $this->parkCourt();
        $this->assertTrue($this->court->snapshotCourtGivers($id));

        $this->court->updateCourt($id, ['IssuedBy' => 'local']);
        $this->assertNotNull($this->storedRoster($id));

        $this->court->updateCourt($id, ['IssuedBy' => 'kingdom']);
        $this->assertNull($this->storedRoster($id));
    }

    public function test_a_kingdom_court_at_a_park_joins_the_kingdoms_court_list(): void
    {
        $id = $this->parkCourt();
        $this->assertNotContains($id, array_column($this->court->getCourtList($this->k), 'CourtId'));

        $this->court->updateCourt($id, ['IssuedBy' => 'kingdom']);

        $this->assertContains($id, array_column($this->court->getCourtList($this->k), 'CourtId'));
        $this->assertContains($id, array_column($this->court->getCourtList($this->k, $this->p), 'CourtId'));
    }

    private function storedRoster(int $courtId): ?string
    {
        $st = $this->fx->pdo()->prepare('SELECT giver_snapshot FROM ' . DB_PREFIX . 'court WHERE court_id = ?');
        $st->execute([$courtId]);

        return $st->fetchColumn() ?: null;
    }
}
