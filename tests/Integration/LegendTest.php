<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Legend (In Memoriam) status core: the is-legend check and the authority
 * gate that decides who may still change a Legend's record.
 */
final class LegendTest extends TestCase
{
    private LegendFixture $fixture;
    private int $park = 0;
    private int $kingdom = 0;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        unset($_SESSION['is_authorized_mundane_id']);
        Legend::clearMemo();
        $this->fixture = LegendFixture::create();
        $this->park    = $this->fixture->firstParkId();
        $this->kingdom = $this->fixture->kingdomIdForPark($this->park);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function test_is_legend_false_for_a_normal_player(): void
    {
        $p = $this->fixture->addPlayer($this->park, 'normal');

        $this->assertFalse(Legend::isLegend($p['mundane_id']));
        $this->assertFalse(Legend::isLegend(0));
    }

    public function test_is_legend_true_after_flag_set(): void
    {
        $p = $this->fixture->addPlayer($this->park, 'flagged');
        $this->assertFalse(Legend::isLegend($p['mundane_id']));

        // The first answer is memoised; setLegend() clears it the way a real conversion will.
        $this->fixture->setLegend($p['mundane_id'], 2020);

        $this->assertTrue(Legend::isLegend($p['mundane_id']));
    }

    public function test_may_manage_true_for_kingdom_officer_and_ork_admin(): void
    {
        $legend  = $this->fixture->addPlayer($this->park, 'legend', 2019);
        $officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);
        $admin   = $this->fixture->addOrkAdmin($this->park);

        $this->assertTrue(Legend::mayManage($officer, $legend['mundane_id']));
        $this->assertTrue(Legend::mayManage($admin, $legend['mundane_id']));
        $this->assertTrue(Legend::isOrkAdmin($admin));
        $this->assertFalse(Legend::isOrkAdmin($officer));
    }

    public function test_may_manage_false_for_park_officer_and_other_kingdom_officer(): void
    {
        $otherPark = $this->fixture->parkOutsideKingdom($this->kingdom);
        $this->assertGreaterThan(0, $otherPark, 'the sandbox needs a park in a second kingdom');
        $otherKingdom = $this->fixture->kingdomIdForPark($otherPark);

        $legend      = $this->fixture->addPlayer($this->park, 'legend', 2019);
        $parkOfficer = $this->fixture->addParkOfficer($this->park);
        $otherOfficer = $this->fixture->addKingdomOfficer($otherKingdom, $otherPark, 'other');

        $this->assertFalse(Legend::mayManage($parkOfficer, $legend['mundane_id']));
        $this->assertFalse(Legend::mayManage($otherOfficer, $legend['mundane_id']));
        $this->assertFalse(Legend::mayManage(0, $legend['mundane_id']));
    }

    public function test_may_manage_true_for_parent_kingdom_officer_of_a_principality_player(): void
    {
        $pdo = $this->fixture->pdo();
        $row = $pdo->query(
            'SELECT k.kingdom_id, k.parent_kingdom_id, (SELECT p.park_id FROM ' . DB_PREFIX . "park p
              WHERE p.kingdom_id = k.kingdom_id AND p.active = 'Active' ORDER BY p.park_id LIMIT 1) AS park_id
             FROM " . DB_PREFIX . 'kingdom k WHERE k.parent_kingdom_id > 0 ORDER BY k.kingdom_id LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row || !(int) $row['park_id']) {
            $this->markTestSkipped('The sandbox has no principality with a park.');
        }
        $legend = $this->fixture->addPlayer((int) $row['park_id'], 'pplegend', 2018);
        $this->assertSame((int) $row['kingdom_id'], $legend['kingdom_id']);

        $parentOfficer = $this->fixture->addKingdomOfficer((int) $row['parent_kingdom_id'], (int) $row['park_id'], 'parent');

        $this->assertTrue(Legend::mayManage($parentOfficer, $legend['mundane_id']));
    }

    public function test_blocks_edit_only_when_legend_and_not_manager(): void
    {
        $normal  = $this->fixture->addPlayer($this->park, 'normal');
        $legend  = $this->fixture->addPlayer($this->park, 'legend', 2021);
        $stranger = $this->fixture->addPlayer($this->park, 'stranger')['mundane_id'];
        $officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);

        $this->assertFalse(Legend::blocksEdit($stranger, $normal['mundane_id']), 'a non-Legend is never blocked');
        $this->assertTrue(Legend::blocksEdit($stranger, $legend['mundane_id']));
        $this->assertFalse(Legend::blocksEdit($officer, $legend['mundane_id']), 'a manager may still edit');
    }

    public function test_get_player_reports_legend_fields(): void
    {
        $normal = $this->fixture->addPlayer($this->park, 'normal');
        $legend = $this->fixture->addPlayer($this->park, 'legend', 2017);
        $player = new Player();

        $n = $player->GetPlayer(['MundaneId' => $normal['mundane_id']]);
        $l = $player->GetPlayer(['MundaneId' => $legend['mundane_id']]);

        $this->assertSame(0, $n['Player']['IsLegend']);
        $this->assertSame(0, $n['Player']['LegendYear']);
        $this->assertSame(1, $l['Player']['IsLegend']);
        $this->assertSame(2017, $l['Player']['LegendYear']);
    }
}
