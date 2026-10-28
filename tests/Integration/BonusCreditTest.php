<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** In-memory ghettocache, so a grant's cache bust is observable without memcached. */
final class BonusCreditInMemoryGhettocache extends Ghettocache
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get($call, $key, $lifetime)
    {
        return $this->store[$this->prefix . '.' . $call . '.' . $key] ?? false;
    }

    public function cache($call, $key, $content)
    {
        $this->store[$this->prefix . '.' . $call . '.' . $key] = $content;
        return $content;
    }

    public function bust($call, $key): void
    {
        unset($this->store[$this->prefix . '.' . $call . '.' . $key]);
    }
}

/**
 * Bonus credits (ork_bonus_credit + ork_bonus_credit_category): class credits
 * that are not attendance. Kingdoms define them, parks hand them out to their
 * own players, once per category unless repeatable; revocation; and the fold
 * into Player::GetPlayerClasses, the only place that reads them.
 */
final class BonusCreditTest extends TestCase
{
    private AttendanceFixture $fixture;
    private BonusCredit $bonus;
    private Player $player;
    private Ghettocache $originalCache;
    private int $park = 0;          // the home park
    private int $kingdom = 0;       // its kingdom
    private int $siblingPark = 0;   // another park in the same kingdom (0 when the sandbox has none)
    private int $otherPark = 0;     // a park in another kingdom
    private int $otherKingdom = 0;
    private int $classId = 0;
    /** @var int[] */
    private array $mundaneIds = [];
    /** @var int[] */
    private array $categoryIds = [];
    /** @var int[] kingdoms whose accept-outside setting a test touched */
    private array $settingKingdoms = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        unset($_SESSION['is_authorized_mundane_id']);
        $this->fixture = AttendanceFixture::create();
        $this->bonus   = new BonusCredit();
        $this->player  = new Player();
        $this->originalCache = Ork3::$Lib->ghettocache;
        Ork3::$Lib->ghettocache = new BonusCreditInMemoryGhettocache();

        $pdo = $this->pdo();
        $this->park    = $this->fixture->firstParkId();
        $this->kingdom = $this->fixture->kingdomIdForPark($this->park);
        $this->classId = $this->fixture->firstClassId();
        $this->siblingPark = (int) $pdo->query(
            'SELECT park_id FROM ' . DB_PREFIX . "park WHERE active = 'Active' AND kingdom_id = " . $this->kingdom . ' AND park_id <> ' . $this->park . ' ORDER BY park_id LIMIT 1'
        )->fetchColumn();
        $this->otherPark = (int) $pdo->query(
            'SELECT park_id FROM ' . DB_PREFIX . "park WHERE active = 'Active' AND kingdom_id <> " . $this->kingdom . ' AND kingdom_id > 0 ORDER BY park_id LIMIT 1'
        )->fetchColumn();
        $this->assertGreaterThan(0, $this->otherPark, 'the sandbox needs a park in a second kingdom');
        $this->otherKingdom = $this->fixture->kingdomIdForPark($this->otherPark);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $pdo = $this->pdo();
            if ($this->mundaneIds) {
                $pdo->exec('DELETE FROM ' . DB_PREFIX . 'bonus_credit WHERE mundane_id IN (' . implode(',', $this->mundaneIds) . ')');
            }
            if ($this->categoryIds) {
                $pdo->exec('DELETE FROM ' . DB_PREFIX . 'bonus_credit WHERE category_id IN (' . implode(',', $this->categoryIds) . ')');
                $pdo->exec('DELETE FROM ' . DB_PREFIX . 'bonus_credit_category WHERE category_id IN (' . implode(',', $this->categoryIds) . ')');
            }
            if ($this->mundaneIds) {
                $pdo->exec('DELETE FROM ' . DB_PREFIX . 'bonus_credit_granter WHERE mundane_id IN (' . implode(',', $this->mundaneIds) . ')');
            }
            if ($this->categoryIds) {
                $pdo->exec('DELETE FROM ' . DB_PREFIX . 'bonus_credit_adoption WHERE category_id IN (' . implode(',', $this->categoryIds) . ')');
            }
            if ($this->settingKingdoms) {
                $pdo->exec('DELETE FROM ' . DB_PREFIX . 'bonus_credit_kingdom_setting WHERE kingdom_id IN (' . implode(',', $this->settingKingdoms) . ')');
            }
            $this->fixture->cleanup();
        }
        if (isset($this->originalCache)) {
            Ork3::$Lib->ghettocache = $this->originalCache;
        }
    }

    private function pdo(): PDO
    {
        $r = new ReflectionProperty(AttendanceFixture::class, 'pdo');
        $r->setAccessible(true);
        return $r->getValue($this->fixture);
    }

    /** A park officer: EDIT on one park. */
    private function parkOfficer(int $parkId, string $suffix): int
    {
        $o = $this->fixture->createParkOfficer($parkId, $suffix);
        $this->mundaneIds[] = (int) $o['mundane_id'];
        return (int) $o['mundane_id'];
    }

    /**
     * A kingdom officer with the given role on the kingdom (CREATE by default,
     * the level that defines credits). The fixture's cleanup removes the
     * authorization row along with the player.
     */
    private function kingdomOfficer(int $kingdomId, int $homeParkId, string $suffix, string $role = AUTH_CREATE): int
    {
        $p = $this->fixture->createPlayer($homeParkId, $suffix);
        $uid = (int) $p['mundane_id'];
        $this->mundaneIds[] = $uid;
        $this->pdo()->prepare('INSERT INTO ' . DB_PREFIX . 'authorization (mundane_id, park_id, kingdom_id, event_id, unit_id, role) VALUES (?, 0, ?, 0, 0, ?)')
            ->execute([$uid, $kingdomId, $role]);
        return $uid;
    }

    /** A global ORK admin: an unscoped admin authorization row. */
    private function orkAdmin(string $suffix): int
    {
        $p = $this->fixture->createPlayer($this->park, $suffix);
        $uid = (int) $p['mundane_id'];
        $this->mundaneIds[] = $uid;
        $this->pdo()->prepare('INSERT INTO ' . DB_PREFIX . 'authorization (mundane_id, park_id, kingdom_id, event_id, unit_id, role) VALUES (?, 0, 0, 0, 0, ?)')
            ->execute([$uid, AUTH_ADMIN]);
        return $uid;
    }

    private function playerAt(int $parkId, string $suffix): int
    {
        $p = $this->fixture->createPlayer($parkId, $suffix);
        $this->mundaneIds[] = (int) $p['mundane_id'];
        return (int) $p['mundane_id'];
    }

    /** A category defined by $kingdomId, created by $uid. Asserts success. */
    private function category(int $uid, int $kingdomId, string $name, array $over = []): array
    {
        $r = $this->bonus->createCategory($uid, $over + [
            'ScopeType' => 'kingdom', 'ScopeId' => $kingdomId, 'Name' => 'T12BC ' . $name, 'DefaultCredits' => 1,
        ]);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->categoryIds[] = (int) $r['CategoryId'];
        return $r['Category'];
    }

    private function grant(int $uid, int $mundaneId, int $categoryId, array $over = []): array
    {
        return $this->bonus->grant($uid, $over + ['MundaneId' => $mundaneId, 'ClassId' => $this->classId, 'CategoryId' => $categoryId]);
    }

    private function classRow(int $mundaneId): array
    {
        $r = $this->player->GetPlayerClasses(['MundaneId' => $mundaneId]);
        $this->assertSame(0, (int) ($r['Status']['Status'] ?? 1));
        return $r['Classes'][$this->classId] ?? [];
    }

    public function testOnlyKingdomOfficersDefineCreditsAndOnlyForTheirKingdom(): void
    {
        $parkOfficer = $this->parkOfficer($this->park, 'bc-park');
        $kEditor     = $this->kingdomOfficer($this->kingdom, $this->park, 'bc-kedit', AUTH_EDIT);
        $kOfficer    = $this->kingdomOfficer($this->kingdom, $this->park, 'bc-king');

        // A park officer cannot define one, even naming their own kingdom; a park is not a scope at all.
        $this->assertSame(3, $this->bonus->createCategory($parkOfficer, ['ScopeType' => 'kingdom', 'ScopeId' => $this->kingdom, 'Name' => 'T12BC nope'])['Status']);
        // Kingdom EDIT hands credits out but does not define them: CREATE (or ADMIN) does.
        $this->assertSame(3, $this->bonus->createCategory($kEditor, ['ScopeType' => 'kingdom', 'ScopeId' => $this->kingdom, 'Name' => 'T12BC nope'])['Status']);
        $this->assertSame(1, $this->bonus->createCategory($kOfficer, ['ScopeType' => 'park', 'ScopeId' => $this->park, 'Name' => 'T12BC nope'])['Status']);
        // Another kingdom: denied. Bad inputs: refused.
        $this->assertSame(3, $this->bonus->createCategory($kOfficer, ['ScopeType' => 'kingdom', 'ScopeId' => $this->otherKingdom, 'Name' => 'T12BC nope'])['Status']);
        $this->assertSame(1, $this->bonus->createCategory($kOfficer, ['ScopeType' => 'kingdom', 'ScopeId' => $this->kingdom, 'Name' => ''])['Status']);
        $this->assertSame(1, $this->bonus->createCategory($kOfficer, ['ScopeType' => 'kingdom', 'ScopeId' => $this->kingdom, 'Name' => 'T12BC x', 'DefaultCredits' => 0.3])['Status']);

        $purple = $this->category($kOfficer, $this->kingdom, 'Being purple', ['Description' => 'Wore purple all day', 'DefaultCredits' => 1.5, 'Repeatable' => 1]);
        $this->assertSame('kingdom', $purple['ScopeType']);
        $this->assertSame($this->kingdom, $purple['ScopeId']);
        $this->assertNotSame('', $purple['ScopeName']);
        $this->assertSame(1.5, $purple['DefaultCredits']);
        $this->assertTrue($purple['Repeatable']);
        $this->assertSame(0, $purple['Granted']);

        // Same name again at the same kingdom is a duplicate; whitespace is normalised first.
        $this->assertSame(1, $this->bonus->createCategory($kOfficer, ['ScopeType' => 'kingdom', 'ScopeId' => $this->kingdom, 'Name' => '  T12BC   Being purple '])['Status']);

        // The kingdom's list offers it, and so does every park in the kingdom.
        $this->assertContains('T12BC Being purple', array_column($this->bonus->categories('kingdom', $this->kingdom), 'Name'));
        $this->assertContains('T12BC Being purple', array_column($this->bonus->categoriesForPark($this->park), 'Name'));
        $this->assertNotContains('T12BC Being purple', array_column($this->bonus->categoriesForPark($this->otherPark), 'Name'));

        // A kingdom EDIT officer can grant it, though.
        $kPlayer = $this->playerAt($this->park, 'bc-kplayer');
        $this->assertSame(0, $this->grant($kEditor, $kPlayer, $purple['CategoryId'])['Status']);

        // Archiving hides it (from parks too); recreating the name restores it. Only kingdom CREATE may archive.
        $this->assertSame(3, $this->bonus->archiveCategory($parkOfficer, $purple['CategoryId'])['Status']);
        $this->assertSame(3, $this->bonus->archiveCategory($kEditor, $purple['CategoryId'])['Status']);
        $this->assertSame(0, $this->bonus->archiveCategory($kOfficer, $purple['CategoryId'])['Status']);
        $this->assertNotContains('T12BC Being purple', array_column($this->bonus->categories('kingdom', $this->kingdom), 'Name'));
        $this->assertNotContains('T12BC Being purple', array_column($this->bonus->categoriesForPark($this->park), 'Name'));
        $this->assertContains('T12BC Being purple', array_column($this->bonus->categories('kingdom', $this->kingdom, true), 'Name'));
        $back = $this->bonus->createCategory($kOfficer, ['ScopeType' => 'kingdom', 'ScopeId' => $this->kingdom, 'Name' => 'T12BC Being purple', 'DefaultCredits' => 2]);
        $this->assertSame(0, $back['Status']);
        $this->assertTrue($back['Restored']);
        $this->assertSame($purple['CategoryId'], $back['CategoryId']);
        $this->assertSame(2.0, $back['Category']['DefaultCredits']);
        $this->assertNull($back['Category']['ArchivedAt']);
    }

    public function testAParkOfficerHandsOutTheKingdomsCreditToTheirOwnPlayersAndLevelsSeeIt(): void
    {
        $kOfficer    = $this->kingdomOfficer($this->kingdom, $this->park, 'bc-king');
        $parkOfficer = $this->parkOfficer($this->park, 'bc-park');
        $player      = $this->playerAt($this->park, 'bc-player');
        $purple      = $this->category($kOfficer, $this->kingdom, 'Being purple', ['DefaultCredits' => 1.5]);

        $this->assertSame(0.0, (float) ($this->classRow($player)['Credits'] ?? 0), 'a fresh player has no credits');

        // The PARK officer grants the KINGDOM's credit. No Credits given: the whole allowance.
        $r = $this->grant($parkOfficer, $player, $purple['CategoryId'], ['Date' => '2026-10-01', 'Note' => 'so purple']);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertGreaterThan(0, $r['BonusCreditId']);
        $this->assertSame(1.5, $r['Credit']['Credits']);
        $this->assertSame(0.0, $r['Remaining']);
        $this->assertSame('T12BC Being purple', $r['Credit']['CategoryName']);
        $this->assertSame('kingdom', $r['Credit']['ScopeType']);

        $this->assertSame([$this->classId => 1.5], $this->bonus->totalsByClass($player));

        // The fold: credits include the bonus, attendance counts do not; the cache was busted by the grant.
        $row = $this->classRow($player);
        $this->assertSame(1.5, (float) $row['Credits']);
        $this->assertSame(1.5, (float) $row['BonusCredits']);
        $this->assertSame(0, (int) $row['Attendances']);
        $this->assertSame(0, (int) $row['Weeks']);

        // The ledger row carries what the player page and the modal show.
        $list = $this->bonus->forPlayer($player);
        $this->assertCount(1, $list);
        $this->assertSame('2026-10-01', $list[0]['Date']);
        $this->assertSame('so purple', $list[0]['Note']);
        $this->assertSame($parkOfficer, $list[0]['GrantedBy']);
        $this->assertNull($list[0]['RevokedAt']);
        $this->assertSame([$player], array_column($this->bonus->forCategory($purple['CategoryId']), 'MundaneId'));
        $this->assertSame([$player], array_column($this->bonus->forCategory($purple['CategoryId'], $this->park), 'MundaneId'));
        $this->assertSame([], $this->bonus->forCategory($purple['CategoryId'], $this->otherPark));
        $this->assertSame(1, $this->bonus->category($purple['CategoryId'])['Granted']);

        // The kingdom officer may grant anywhere in the kingdom too.
        $other = $this->playerAt($this->park, 'bc-player2');
        $this->assertSame(0, $this->grant($kOfficer, $other, $purple['CategoryId'])['Status']);

        // Both the grant and a revoke leave danger-audit rows on the player.
        $audit = $this->fixture->fetchLatestDangerAuditForPlayer($player, 'BonusCredit::grant');
        $this->assertNotNull($audit, 'a grant is audited');
        $this->assertStringContainsString('T12BC Being purple', $audit['post_state']);
        $this->assertSame(0, $this->bonus->revoke($parkOfficer, (int) $r['BonusCreditId'], 'oops')['Status']);
        $audit = $this->fixture->fetchLatestDangerAuditForPlayer($player, 'BonusCredit::revoke');
        $this->assertNotNull($audit, 'a revoke is audited');
        $this->assertStringContainsString('oops', $audit['post_state']);
    }

    public function testAParkOnlyCreditsItsOwnPlayers(): void
    {
        $kOfficer    = $this->kingdomOfficer($this->kingdom, $this->park, 'bc-king');
        $parkOfficer = $this->parkOfficer($this->park, 'bc-park');
        $purple      = $this->category($kOfficer, $this->kingdom, 'Being purple');
        if ($this->siblingPark > 0) {
            // Same kingdom, different park: the credit covers them, but this officer has no authority there.
            $sibling = $this->playerAt($this->siblingPark, 'bc-sibling');
            $r = $this->grant($parkOfficer, $sibling, $purple['CategoryId']);
            $this->assertSame(3, $r['Status']);
            $this->assertStringContainsString('authority', (string) $r['Error']);
            $this->assertSame([], $this->bonus->totalsByClass($sibling));
        }
        // Another kingdom's player: that is a visitor, and visitors are fine while their kingdom
        // accepts outside credits (the default), because the officer speaks for the defining kingdom.
        $away = $this->playerAt($this->otherPark, 'bc-away');
        $this->assertSame(0, $this->grant($parkOfficer, $away, $purple['CategoryId'])['Status']);
        $this->assertSame([$this->classId => 1.0], $this->bonus->totalsByClass($away));
    }

    public function testACreditNeverLeavesItsKingdomOnceTheOtherKingdomDeclinesOutsideCredits(): void
    {
        $this->settingKingdoms = [$this->otherKingdom];
        $kOfficer = $this->kingdomOfficer($this->kingdom, $this->park, 'bc-king');
        $purple   = $this->category($kOfficer, $this->kingdom, 'Being purple');
        // An officer who holds the OTHER kingdom's park cannot hand out THIS kingdom's credit there, on or off.
        $awayOfficer = $this->parkOfficer($this->otherPark, 'bc-away-officer');
        $away        = $this->playerAt($this->otherPark, 'bc-away');
        $r = $this->grant($awayOfficer, $away, $purple['CategoryId']);
        $this->assertSame(3, $r['Status']);
        $this->assertStringContainsString('park officers or granters', (string) $r['Error']);
        $this->assertSame([], $this->bonus->totalsByClass($away));
        // Once the other kingdom turns outside credits off, this kingdom's own officer is refused too, and told where the switch is.
        $awayCreate = $this->kingdomOfficer($this->otherKingdom, $this->otherPark, 'bc-away-create');
        $this->assertSame(0, $this->bonus->setAcceptsOutside($awayCreate, $this->otherKingdom, false)['Status']);
        $r = $this->grant($kOfficer, $away, $purple['CategoryId'], ['HostType' => 'kingdom', 'HostId' => $this->kingdom]);
        $this->assertSame(3, $r['Status']);
        $this->assertStringContainsString('does not accept bonus credits from other Kingdoms', (string) $r['Error']);
        $this->assertStringContainsString('Settings', (string) $r['Error']);
        $this->assertSame([], $this->bonus->totalsByClass($away));
    }

    public function testAKingdomMayAcceptOtherKingdomsCredits(): void
    {
        $this->settingKingdoms = [$this->kingdom, $this->otherKingdom];
        $kOfficer   = $this->kingdomOfficer($this->kingdom, $this->park, 'bc-king');                 // CREATE on the defining kingdom
        $kEditor    = $this->kingdomOfficer($this->kingdom, $this->park, 'bc-kedit', AUTH_EDIT);     // EDIT on it: grants, cannot define
        $purple     = $this->category($kOfficer, $this->kingdom, 'Being purple');
        $away       = $this->playerAt($this->otherPark, 'bc-away');
        $awayCreate = $this->kingdomOfficer($this->otherKingdom, $this->otherPark, 'bc-away-create');
        $awayEdit   = $this->kingdomOfficer($this->otherKingdom, $this->otherPark, 'bc-away-edit', AUTH_EDIT);
        $awayPark   = $this->parkOfficer($this->otherPark, 'bc-away-park');
        $fromKingdomPage = ['HostType' => 'kingdom', 'HostId' => $this->kingdom];

        // On by default: no row at all reads as yes, and nobody is named.
        $this->assertTrue($this->bonus->acceptsOutsideCredits($this->otherKingdom));
        $s = $this->bonus->outsideCreditSetting($this->otherKingdom);
        $this->assertNull($s['AcceptsOutside']);
        $this->assertTrue($s['Effective']);
        $this->assertSame('default', $s['Source']);
        $this->assertSame('', $s['InheritedFrom']);
        $this->assertSame(0, $s['UpdatedBy']);
        // Saying yes to what already applies writes nothing: it stays "by default".
        $this->assertSame(0, $this->bonus->setAcceptsOutside($awayCreate, $this->otherKingdom, true)['Status']);
        $this->assertSame('default', $this->bonus->outsideCreditSetting($this->otherKingdom)['Source']);

        // Only the RECEIVING kingdom's CREATE (or an ORK admin) turns it off: not its EDIT, not the giving kingdom.
        $this->assertSame(3, $this->bonus->setAcceptsOutside($awayEdit, $this->otherKingdom, false)['Status']);
        $this->assertSame(3, $this->bonus->setAcceptsOutside($kOfficer, $this->otherKingdom, false)['Status']);
        $this->assertSame(1, $this->bonus->setAcceptsOutside($awayCreate, 999999999, false)['Status']);
        $this->assertSame(0, $this->bonus->setAcceptsOutside($awayCreate, $this->otherKingdom, false)['Status']);
        $this->assertSame(0, $this->bonus->setAcceptsOutside($awayCreate, $this->otherKingdom, false)['Status'], 'saying no twice is a no-op');
        $this->assertFalse($this->bonus->acceptsOutsideCredits($this->otherKingdom));
        $s = $this->bonus->outsideCreditSetting($this->otherKingdom);
        $this->assertFalse($s['AcceptsOutside']);
        $this->assertFalse($s['Effective']);
        $this->assertSame('own', $s['Source']);
        $this->assertSame($awayCreate, $s['UpdatedBy']);
        $this->assertSame(3, $this->grant($kEditor, $away, $purple['CategoryId'], $fromKingdomPage)['Status'], 'off means off');
        // Turning it back on is now that kingdom's own, recorded choice.
        $this->assertSame(0, $this->bonus->setAcceptsOutside($awayCreate, $this->otherKingdom, true)['Status']);
        $s = $this->bonus->outsideCreditSetting($this->otherKingdom);
        $this->assertTrue($s['AcceptsOutside']);
        $this->assertTrue($s['Effective']);
        $this->assertSame('own', $s['Source']);
        // One kingdom's choice says nothing about the other direction: the giving kingdom is still on its default.
        $this->assertSame('default', $this->bonus->outsideCreditSetting($this->kingdom)['Source']);

        // Now the defining kingdom's EDIT officer can grant it from the kingdom page, and the credit counts for the player.
        $g = $this->grant($kEditor, $away, $purple['CategoryId'], $fromKingdomPage);
        $this->assertSame(0, $g['Status'], (string) ($g['Error'] ?? ''));
        $this->assertSame($this->otherKingdom, $g['Credit']['PlayerKingdomId']);
        $this->assertSame([$this->classId => 1.0], $this->bonus->totalsByClass($away));
        $this->assertSame(1.0, (float) ($this->classRow($away)['Credits'] ?? 0));
        // The defining kingdom's holder list shows the outside player; the allowance holds across the line.
        $this->assertContains($away, array_column($this->bonus->forCategory($purple['CategoryId'], 0, 200, $this->kingdom), 'MundaneId'));
        $this->assertSame(1, $this->grant($kEditor, $away, $purple['CategoryId'], $fromKingdomPage)['Status']);

        // A park officer of the defining kingdom can do it too, from their own park page (the visiting player's case)...
        $away2    = $this->playerAt($this->otherPark, 'bc-away2');
        $homePark = $this->parkOfficer($this->park, 'bc-home-park');
        $pg = $this->grant($homePark, $away2, $purple['CategoryId'], ['HostType' => 'park', 'HostId' => $this->park]);
        $this->assertSame(0, $pg['Status'], (string) ($pg['Error'] ?? ''));
        $this->assertSame(0, $this->bonus->revoke($homePark, (int) $pg['BonusCreditId'])['Status'], 'and take it back');
        // ...but not from a park page outside the defining kingdom.
        $r = $this->grant($kEditor, $away2, $purple['CategoryId'], ['HostType' => 'park', 'HostId' => $this->otherPark]);
        $this->assertSame(3, $r['Status']);
        $this->assertStringContainsString('from a page inside', (string) $r['Error']);
        // And the receiving kingdom's own officers cannot hand out the other kingdom's credit, even to their own players.
        foreach ([$awayCreate, $awayEdit, $awayPark] as $who) {
            $r = $this->grant($who, $away2, $purple['CategoryId']);
            $this->assertSame(3, $r['Status']);
            $this->assertStringContainsString('park officers or granters', (string) $r['Error']);
        }
        $this->assertSame([], $this->bonus->totalsByClass($away2));
        // A granter of the defining kingdom, scoped to the credit, can.
        $granter = $this->playerAt($this->park, 'bc-granter');
        $this->assertSame(0, $this->bonus->addGranter($kOfficer, $this->kingdom, $granter, $purple['CategoryId'])['Status']);
        $this->assertSame(0, $this->grant($granter, $away2, $purple['CategoryId'], $fromKingdomPage)['Status']);
        // A granter scoped to a DIFFERENT credit cannot carry this one across.
        $other   = $this->category($kOfficer, $this->kingdom, 'Being orange');
        $granter2 = $this->playerAt($this->park, 'bc-granter2');
        $this->assertSame(0, $this->bonus->addGranter($kOfficer, $this->kingdom, $granter2, $other['CategoryId'])['Status']);
        $this->assertSame(3, $this->grant($granter2, $this->playerAt($this->otherPark, 'bc-away4'), $purple['CategoryId'], $fromKingdomPage)['Status']);

        // Taking it back: either side may, not a bystander.
        $id = (int) $g['BonusCreditId'];
        $nobody = $this->playerAt($this->otherPark, 'bc-nobody');
        $r = $this->bonus->revoke($nobody, $id);
        $this->assertSame(3, $r['Status']);
        $this->assertStringContainsString('own park or Kingdom', (string) $r['Error']);
        $this->assertSame(0, $this->bonus->revoke($awayPark, $id)['Status'], 'the player\'s own park officer can');
        $this->assertSame([], $this->bonus->totalsByClass($away));
        $this->assertSame(0, $this->bonus->revoke($kEditor, $id)['Status'], 'idempotent for the giving side too');
        $g2 = $this->grant($kEditor, $away, $purple['CategoryId'], $fromKingdomPage);
        $this->assertSame(0, $g2['Status']);
        $this->assertSame(0, $this->bonus->revoke($kEditor, (int) $g2['BonusCreditId'])['Status'], 'and so can the giving kingdom');
        $this->assertSame([], $this->bonus->totalsByClass($away));

        // Saying no again stops new grants; what was given stays.
        $this->assertSame(0, $this->bonus->setAcceptsOutside($awayCreate, $this->otherKingdom, false)['Status']);
        $this->assertFalse($this->bonus->acceptsOutsideCredits($this->otherKingdom));
        $this->assertSame(3, $this->grant($kEditor, $this->playerAt($this->otherPark, 'bc-away3'), $purple['CategoryId'], $fromKingdomPage)['Status']);
        $this->assertSame([$this->classId => 1.0], $this->bonus->totalsByClass($away2));

        // An ORK admin may say yes on the kingdom's behalf.
        $admin = $this->orkAdmin('bc-admin');
        $this->assertSame(0, $this->bonus->setAcceptsOutside($admin, $this->otherKingdom, true)['Status']);
        $this->assertTrue($this->bonus->acceptsOutsideCredits($this->otherKingdom));
        // Amtgard-wide credits are not affected: adoption, not this switch, governs them.
        $r = $this->bonus->createCategory($admin, ['ScopeType' => 'ork', 'Name' => 'T12BC Census', 'DefaultCredits' => 1]);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->categoryIds[] = (int) $r['CategoryId'];
        $census = (int) $r['CategoryId'];
        $this->assertSame(3, $this->grant($kEditor, $away2, $census, $fromKingdomPage)['Status'], 'the switch opens nothing for Amtgard-wide credits');
        $this->assertSame(3, $this->grant($admin, $away2, $census, $fromKingdomPage)['Status'], 'not even for an ORK admin until the kingdom adopts it');
        $this->assertSame([$this->classId => 1.0], $this->bonus->totalsByClass($away2));
    }

    public function testInputsAreValidated(): void
    {
        $kOfficer = $this->kingdomOfficer($this->kingdom, $this->park, 'bc-king');
        $player   = $this->playerAt($this->park, 'bc-player');
        $purple   = $this->category($kOfficer, $this->kingdom, 'Being purple');
        $rid = $purple['CategoryId'];
        $this->assertSame(1, $this->grant($kOfficer, $player, $rid, ['Date' => date('Y-m-d', strtotime('+1 day'))])['Status']);
        $this->assertSame(1, $this->grant($kOfficer, $player, $rid, ['ClassId' => 999999])['Status']);
        $this->assertSame(1, $this->grant($kOfficer, $player, 999999999)['Status']);
        $this->assertSame(1, $this->grant($kOfficer, 0, $rid)['Status']);
        $this->assertSame([], $this->bonus->totalsByClass($player));

        // An archived category cannot be granted.
        $this->assertSame(0, $this->bonus->archiveCategory($kOfficer, $rid)['Status']);
        $r = $this->grant($kOfficer, $player, $rid);
        $this->assertSame(1, $r['Status']);
        $this->assertStringContainsString('no longer', (string) $r['Error']);
    }

    public function testACategorysAmountIsAnAllowanceAPlayerCanSplitAcrossGrants(): void
    {
        $kOfficer = $this->kingdomOfficer($this->kingdom, $this->park, 'bc-king');
        $player   = $this->playerAt($this->park, 'bc-player');
        $pool     = $this->category($kOfficer, $this->kingdom, 'Pool', ['DefaultCredits' => 2]);
        $many     = $this->category($kOfficer, $this->kingdom, 'Many', ['DefaultCredits' => 1, 'Repeatable' => 1]);
        $bard     = $this->classId;
        $healer   = $this->fixture->secondClassId($bard);

        // "I want 1 in Bard and 1 in Healer": two grants against a 2-credit allowance.
        $a = $this->bonus->allowance($pool['CategoryId'], $player);
        $this->assertSame(['Allowance' => 2.0, 'Held' => 0.0, 'Remaining' => 2.0, 'Repeatable' => false], $a);
        $r = $this->grant($kOfficer, $player, $pool['CategoryId'], ['Credits' => 1, 'ClassId' => $bard]);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertSame(1.0, $r['Remaining']);
        // Too much for what is left: refused, naming the numbers.
        $r = $this->grant($kOfficer, $player, $pool['CategoryId'], ['Credits' => 1.5, 'ClassId' => $healer]);
        $this->assertSame(1, $r['Status']);
        $this->assertStringContainsString('only 1 left', (string) $r['Error']);
        // Bad amounts: refused.
        $this->assertSame(1, $this->grant($kOfficer, $player, $pool['CategoryId'], ['Credits' => 0])['Status']);
        $this->assertSame(1, $this->grant($kOfficer, $player, $pool['CategoryId'], ['Credits' => 0.3])['Status']);
        // The rest, in another class. No Credits given means "what is left".
        $r = $this->grant($kOfficer, $player, $pool['CategoryId'], ['ClassId' => $healer]);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertSame(1.0, $r['Credit']['Credits']);
        $this->assertSame(0.0, $r['Remaining']);
        $this->assertSame([$bard => 1.0, $healer => 1.0], $this->bonus->totalsByClass($player));
        // Full: a further grant is refused.
        $r = $this->grant($kOfficer, $player, $pool['CategoryId'], ['Credits' => 0.25]);
        $this->assertSame(1, $r['Status']);
        $this->assertStringContainsString('already has the full 2 credits', (string) $r['Error']);
        $this->assertSame(0.0, $this->bonus->allowance($pool['CategoryId'], $player)['Remaining']);

        // A repeatable category has no total cap; the allowance is the most per grant.
        $this->assertSame(0, $this->grant($kOfficer, $player, $many['CategoryId'])['Status']);
        $this->assertSame(0, $this->grant($kOfficer, $player, $many['CategoryId'])['Status']);
        $r = $this->grant($kOfficer, $player, $many['CategoryId'], ['Credits' => 2]);
        $this->assertSame(1, $r['Status']);
        $this->assertStringContainsString('at most 1', (string) $r['Error']);
        $this->assertNull($this->bonus->allowance($many['CategoryId'], $player)['Remaining']);

        // Revoking gives the allowance back.
        $credits = array_filter($this->bonus->forPlayer($player), function ($c) use ($pool) {
            return $c['CategoryId'] === $pool['CategoryId'];
        });
        $this->assertSame(0, $this->bonus->revoke($kOfficer, (int) array_values($credits)[0]['BonusCreditId'])['Status']);
        $this->assertSame(1.0, $this->bonus->allowance($pool['CategoryId'], $player)['Remaining']);
        $this->assertSame(0, $this->grant($kOfficer, $player, $pool['CategoryId'], ['Credits' => 1])['Status']);
    }

    public function testAmtgardWideCreditsAreDefinedByOrkAdminsAndReachAKingdomOnlyWhenAdopted(): void
    {
        $admin    = $this->orkAdmin('bc-orkadmin');
        $kOfficer = $this->kingdomOfficer($this->kingdom, $this->park, 'bc-king');            // CREATE
        $kEditor  = $this->kingdomOfficer($this->kingdom, $this->park, 'bc-kedit', AUTH_EDIT);
        $player   = $this->playerAt($this->park, 'bc-player');

        // Only an ORK admin defines one; a kingdom CREATE officer cannot.
        $this->assertSame(3, $this->bonus->createCategory($kOfficer, ['ScopeType' => 'ork', 'ScopeId' => 0, 'Name' => 'T12BC Census'])['Status']);
        $r = $this->bonus->createCategory($admin, ['ScopeType' => 'ork', 'ScopeId' => 0, 'Name' => 'T12BC Census', 'DefaultCredits' => 2]);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->categoryIds[] = $census = (int) $r['CategoryId'];
        $this->assertSame('ork', $r['Category']['ScopeType']);
        $this->assertSame('Amtgard', $r['Category']['ScopeName']);
        $this->assertSame(0, $r['Category']['Adoptions']);
        $this->assertContains('T12BC Census', array_column($this->bonus->categories('ork', 0), 'Name'));

        // Not adopted: it is offered nowhere in the kingdom, and even the ORK admin cannot grant it here.
        $this->assertNotContains('T12BC Census', array_column($this->bonus->categoriesForKingdom($this->kingdom), 'Name'));
        $this->assertNotContains('T12BC Census', array_column($this->bonus->categoriesForPark($this->park), 'Name'));
        $r = $this->grant($admin, $player, $census);
        $this->assertSame(3, $r['Status']);
        $this->assertStringContainsString('not adopted', (string) $r['Error']);
        $ork = $this->bonus->orkCreditsFor($this->kingdom);
        $this->assertCount(1, array_filter($ork, function ($x) use ($census) {
            return $x['CategoryId'] === $census && !$x['Adopted'];
        }));

        // Adoption: kingdom CREATE or an ORK admin, not kingdom EDIT.
        $this->assertSame(3, $this->bonus->adopt($kEditor, $census, $this->kingdom)['Status']);
        $this->assertSame(1, $this->bonus->adopt($kOfficer, 999999999, $this->kingdom)['Status']);
        $this->assertSame(0, $this->bonus->adopt($kOfficer, $census, $this->kingdom)['Status']);
        $this->assertSame(0, $this->bonus->adopt($kOfficer, $census, $this->kingdom)['Status'], 'adopting twice is a no-op');
        $this->assertTrue($this->bonus->isAdoptedIn($census, [$this->kingdom]));
        $this->assertSame([$this->kingdom], array_column($this->bonus->adoptions($census), 'KingdomId'));
        $this->assertSame(1, $this->bonus->category($census)['Adoptions']);

        // Now it is offered in the kingdom and its parks, at Amtgard's amount, and a kingdom EDIT officer can grant it.
        $this->assertContains('T12BC Census', array_column($this->bonus->categoriesForKingdom($this->kingdom), 'Name'));
        $this->assertContains('T12BC Census', array_column($this->bonus->categoriesForPark($this->park), 'Name'));
        $g = $this->grant($kEditor, $player, $census);
        $this->assertSame(0, $g['Status'], (string) ($g['Error'] ?? ''));
        $this->assertSame(2.0, $g['Credit']['Credits']);
        $this->assertSame('Amtgard', $g['Credit']['ScopeName']);
        $this->assertSame([$this->classId => 2.0], $this->bonus->totalsByClass($player));
        // The allowance holds across Amtgard: 2 given, nothing left.
        $this->assertSame(1, $this->grant($kEditor, $player, $census)['Status']);
        // The other kingdom has not adopted it, so its players are out of reach.
        $awayOfficer = $this->parkOfficer($this->otherPark, 'bc-away-officer');
        $away        = $this->playerAt($this->otherPark, 'bc-away');
        $this->assertSame(3, $this->grant($awayOfficer, $away, $census)['Status']);
        // A kingdom granter can be scoped to the adopted credit.
        $granter = $this->playerAt($this->park, 'bc-granter');
        $this->assertSame(0, $this->bonus->addGranter($kOfficer, $this->kingdom, $granter, $census)['Status']);
        $this->assertSame(0, $this->grant($granter, $this->playerAt($this->park, 'bc-player2'), $census)['Status']);

        // An ORK admin adopts on another kingdom's behalf; then withdrawal stops new grants but keeps old ones.
        $this->assertSame(0, $this->bonus->adopt($admin, $census, $this->otherKingdom)['Status']);
        $this->assertSame(0, $this->grant($awayOfficer, $away, $census)['Status']);
        $this->assertSame(0, $this->bonus->withdraw($kOfficer, $census, $this->kingdom)['Status']);
        $this->assertFalse($this->bonus->isAdoptedIn($census, [$this->kingdom]));
        $this->assertNotContains('T12BC Census', array_column($this->bonus->categoriesForPark($this->park), 'Name'));
        $this->assertSame(3, $this->grant($kEditor, $this->playerAt($this->park, 'bc-player3'), $census)['Status']);
        $this->assertSame([$this->classId => 2.0], $this->bonus->totalsByClass($player), 'what was granted stays');
        // Re-adopting clears the withdrawal.
        $this->assertSame(0, $this->bonus->adopt($admin, $census, $this->kingdom)['Status']);
        $this->assertTrue($this->bonus->isAdoptedIn($census, [$this->kingdom]));

        // Archiving is the ORK admin's alone, and an archived credit cannot be adopted.
        $this->assertSame(3, $this->bonus->archiveCategory($kOfficer, $census)['Status']);
        $this->assertSame(0, $this->bonus->archiveCategory($admin, $census)['Status']);
        $this->assertSame(1, $this->bonus->adopt($kOfficer, $census, $this->kingdom)['Status']);
    }

    public function testRevokeNeedsAuthorityOverThePlayersParkAndIsIdempotent(): void
    {
        $kOfficer    = $this->kingdomOfficer($this->kingdom, $this->park, 'bc-king');
        $parkOfficer = $this->parkOfficer($this->park, 'bc-park');
        $awayOfficer = $this->parkOfficer($this->otherPark, 'bc-away-officer');
        $player      = $this->playerAt($this->park, 'bc-player');
        $purple      = $this->category($kOfficer, $this->kingdom, 'Being purple');
        $id = (int) $this->grant($kOfficer, $player, $purple['CategoryId'])['BonusCreditId'];
        $this->assertGreaterThan(0, $id);

        $this->assertSame(3, $this->bonus->revoke($awayOfficer, $id, 'not mine')['Status']);
        $this->assertSame([$this->classId => 1.0], $this->bonus->totalsByClass($player));

        // The park officer may remove a credit the kingdom granted to their player.
        $this->assertSame(0, $this->bonus->revoke($parkOfficer, $id, 'entered twice')['Status']);
        $this->assertSame(0, $this->bonus->revoke($parkOfficer, $id)['Status'], 'a second revoke is a no-op success');
        $this->assertSame([], $this->bonus->totalsByClass($player));
        $this->assertSame(0.0, (float) ($this->classRow($player)['Credits'] ?? 0), 'levels no longer see it');

        $this->assertCount(0, $this->bonus->forPlayer($player));
        $this->assertCount(0, $this->bonus->forCategory($purple['CategoryId']));
        $gone = $this->bonus->forPlayer($player, true);
        $this->assertCount(1, $gone);
        $this->assertSame($parkOfficer, $gone[0]['RevokedBy']);
        $this->assertSame('entered twice', $gone[0]['RevokeNote']);
        $this->assertNotNull($gone[0]['RevokedAt']);

        $this->assertSame(1, $this->bonus->revoke($kOfficer, 999999999)['Status']);
    }
}
