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
 * Bonus credits (ork_bonus_credit + ork_bonus_credit_reason): class credits
 * that are not attendance. Kingdoms define them, parks hand them out to their
 * own players, once per reason unless repeatable; revocation; and the fold
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
    private array $reasonIds = [];

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
            if ($this->reasonIds) {
                $pdo->exec('DELETE FROM ' . DB_PREFIX . 'bonus_credit WHERE reason_id IN (' . implode(',', $this->reasonIds) . ')');
                $pdo->exec('DELETE FROM ' . DB_PREFIX . 'bonus_credit_reason WHERE reason_id IN (' . implode(',', $this->reasonIds) . ')');
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

    /** A kingdom officer: EDIT on the kingdom (the fixture's cleanup removes the row with the player). */
    private function kingdomOfficer(int $kingdomId, int $homeParkId, string $suffix): int
    {
        $p = $this->fixture->createPlayer($homeParkId, $suffix);
        $uid = (int) $p['mundane_id'];
        $this->mundaneIds[] = $uid;
        $this->pdo()->prepare('INSERT INTO ' . DB_PREFIX . 'authorization (mundane_id, park_id, kingdom_id, event_id, unit_id, role) VALUES (?, 0, ?, 0, 0, ?)')
            ->execute([$uid, $kingdomId, AUTH_EDIT]);
        return $uid;
    }

    private function playerAt(int $parkId, string $suffix): int
    {
        $p = $this->fixture->createPlayer($parkId, $suffix);
        $this->mundaneIds[] = (int) $p['mundane_id'];
        return (int) $p['mundane_id'];
    }

    /** A reason defined by $kingdomId, created by $uid. Asserts success. */
    private function reason(int $uid, int $kingdomId, string $name, array $over = []): array
    {
        $r = $this->bonus->createReason($uid, $over + [
            'ScopeType' => 'kingdom', 'ScopeId' => $kingdomId, 'Name' => 'T12BC ' . $name, 'DefaultCredits' => 1,
        ]);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->reasonIds[] = (int) $r['ReasonId'];
        return $r['Reason'];
    }

    private function grant(int $uid, int $mundaneId, int $reasonId, array $over = []): array
    {
        return $this->bonus->grant($uid, $over + ['MundaneId' => $mundaneId, 'ClassId' => $this->classId, 'ReasonId' => $reasonId]);
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
        $kOfficer    = $this->kingdomOfficer($this->kingdom, $this->park, 'bc-king');

        // A park officer cannot define one, even naming their own kingdom; a park is not a scope at all.
        $this->assertSame(3, $this->bonus->createReason($parkOfficer, ['ScopeType' => 'kingdom', 'ScopeId' => $this->kingdom, 'Name' => 'T12BC nope'])['Status']);
        $this->assertSame(1, $this->bonus->createReason($kOfficer, ['ScopeType' => 'park', 'ScopeId' => $this->park, 'Name' => 'T12BC nope'])['Status']);
        // Another kingdom: denied. Bad inputs: refused.
        $this->assertSame(3, $this->bonus->createReason($kOfficer, ['ScopeType' => 'kingdom', 'ScopeId' => $this->otherKingdom, 'Name' => 'T12BC nope'])['Status']);
        $this->assertSame(1, $this->bonus->createReason($kOfficer, ['ScopeType' => 'kingdom', 'ScopeId' => $this->kingdom, 'Name' => ''])['Status']);
        $this->assertSame(1, $this->bonus->createReason($kOfficer, ['ScopeType' => 'kingdom', 'ScopeId' => $this->kingdom, 'Name' => 'T12BC x', 'DefaultCredits' => 0.3])['Status']);

        $purple = $this->reason($kOfficer, $this->kingdom, 'Being purple', ['Description' => 'Wore purple all day', 'DefaultCredits' => 1.5, 'Repeatable' => 1]);
        $this->assertSame('kingdom', $purple['ScopeType']);
        $this->assertSame($this->kingdom, $purple['ScopeId']);
        $this->assertNotSame('', $purple['ScopeName']);
        $this->assertSame(1.5, $purple['DefaultCredits']);
        $this->assertTrue($purple['Repeatable']);
        $this->assertSame(0, $purple['Granted']);

        // Same name again at the same kingdom is a duplicate; whitespace is normalised first.
        $this->assertSame(1, $this->bonus->createReason($kOfficer, ['ScopeType' => 'kingdom', 'ScopeId' => $this->kingdom, 'Name' => '  T12BC   Being purple '])['Status']);

        // The kingdom's list offers it, and so does every park in the kingdom.
        $this->assertContains('T12BC Being purple', array_column($this->bonus->reasons('kingdom', $this->kingdom), 'Name'));
        $this->assertContains('T12BC Being purple', array_column($this->bonus->reasonsForPark($this->park), 'Name'));
        $this->assertNotContains('T12BC Being purple', array_column($this->bonus->reasonsForPark($this->otherPark), 'Name'));

        // Archiving hides it (from parks too); recreating the name restores it. Only the kingdom may archive.
        $this->assertSame(3, $this->bonus->archiveReason($parkOfficer, $purple['ReasonId'])['Status']);
        $this->assertSame(0, $this->bonus->archiveReason($kOfficer, $purple['ReasonId'])['Status']);
        $this->assertNotContains('T12BC Being purple', array_column($this->bonus->reasons('kingdom', $this->kingdom), 'Name'));
        $this->assertNotContains('T12BC Being purple', array_column($this->bonus->reasonsForPark($this->park), 'Name'));
        $this->assertContains('T12BC Being purple', array_column($this->bonus->reasons('kingdom', $this->kingdom, true), 'Name'));
        $back = $this->bonus->createReason($kOfficer, ['ScopeType' => 'kingdom', 'ScopeId' => $this->kingdom, 'Name' => 'T12BC Being purple', 'DefaultCredits' => 2]);
        $this->assertSame(0, $back['Status']);
        $this->assertTrue($back['Restored']);
        $this->assertSame($purple['ReasonId'], $back['ReasonId']);
        $this->assertSame(2.0, $back['Reason']['DefaultCredits']);
        $this->assertNull($back['Reason']['ArchivedAt']);
    }

    public function testAParkOfficerHandsOutTheKingdomsCreditToTheirOwnPlayersAndLevelsSeeIt(): void
    {
        $kOfficer    = $this->kingdomOfficer($this->kingdom, $this->park, 'bc-king');
        $parkOfficer = $this->parkOfficer($this->park, 'bc-park');
        $player      = $this->playerAt($this->park, 'bc-player');
        $purple      = $this->reason($kOfficer, $this->kingdom, 'Being purple', ['DefaultCredits' => 1.5]);

        $this->assertSame(0.0, (float) ($this->classRow($player)['Credits'] ?? 0), 'a fresh player has no credits');

        // The PARK officer grants the KINGDOM's credit. The amount is the reason's; a Credits override is ignored.
        $r = $this->grant($parkOfficer, $player, $purple['ReasonId'], ['Date' => '2026-10-01', 'Note' => 'so purple', 'Credits' => 9]);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $this->assertGreaterThan(0, $r['BonusCreditId']);
        $this->assertSame(1.5, $r['Credit']['Credits']);
        $this->assertSame('T12BC Being purple', $r['Credit']['ReasonName']);
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
        $this->assertSame([$player], array_column($this->bonus->forReason($purple['ReasonId']), 'MundaneId'));
        $this->assertSame([$player], array_column($this->bonus->forReason($purple['ReasonId'], $this->park), 'MundaneId'));
        $this->assertSame([], $this->bonus->forReason($purple['ReasonId'], $this->otherPark));
        $this->assertSame(1, $this->bonus->reason($purple['ReasonId'])['Granted']);

        // The kingdom officer may grant anywhere in the kingdom too.
        $other = $this->playerAt($this->park, 'bc-player2');
        $this->assertSame(0, $this->grant($kOfficer, $other, $purple['ReasonId'])['Status']);

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
        $purple      = $this->reason($kOfficer, $this->kingdom, 'Being purple');
        if ($this->siblingPark > 0) {
            // Same kingdom, different park: the credit covers them, but this officer has no authority there.
            $sibling = $this->playerAt($this->siblingPark, 'bc-sibling');
            $r = $this->grant($parkOfficer, $sibling, $purple['ReasonId']);
            $this->assertSame(3, $r['Status']);
            $this->assertStringContainsString('park', (string) $r['Error']);
            $this->assertSame([], $this->bonus->totalsByClass($sibling));
        }
        // Another kingdom's player: no authority there either.
        $away = $this->playerAt($this->otherPark, 'bc-away');
        $this->assertSame(3, $this->grant($parkOfficer, $away, $purple['ReasonId'])['Status']);
        $this->assertSame([], $this->bonus->totalsByClass($away));
    }

    public function testACreditNeverLeavesItsKingdom(): void
    {
        $kOfficer = $this->kingdomOfficer($this->kingdom, $this->park, 'bc-king');
        $purple   = $this->reason($kOfficer, $this->kingdom, 'Being purple');
        // An officer who holds the OTHER kingdom's park cannot hand out THIS kingdom's credit there.
        $awayOfficer = $this->parkOfficer($this->otherPark, 'bc-away-officer');
        $away        = $this->playerAt($this->otherPark, 'bc-away');
        $r = $this->grant($awayOfficer, $away, $purple['ReasonId']);
        $this->assertSame(3, $r['Status']);
        $this->assertStringContainsString('another kingdom', (string) $r['Error']);
        $this->assertSame([], $this->bonus->totalsByClass($away));
    }

    public function testInputsAreValidated(): void
    {
        $kOfficer = $this->kingdomOfficer($this->kingdom, $this->park, 'bc-king');
        $player   = $this->playerAt($this->park, 'bc-player');
        $purple   = $this->reason($kOfficer, $this->kingdom, 'Being purple');
        $rid = $purple['ReasonId'];
        $this->assertSame(1, $this->grant($kOfficer, $player, $rid, ['Date' => date('Y-m-d', strtotime('+1 day'))])['Status']);
        $this->assertSame(1, $this->grant($kOfficer, $player, $rid, ['ClassId' => 999999])['Status']);
        $this->assertSame(1, $this->grant($kOfficer, $player, 999999999)['Status']);
        $this->assertSame(1, $this->grant($kOfficer, 0, $rid)['Status']);
        $this->assertSame([], $this->bonus->totalsByClass($player));

        // An archived reason cannot be granted.
        $this->assertSame(0, $this->bonus->archiveReason($kOfficer, $rid)['Status']);
        $r = $this->grant($kOfficer, $player, $rid);
        $this->assertSame(1, $r['Status']);
        $this->assertStringContainsString('no longer', (string) $r['Error']);
    }

    public function testAReasonCreditsAPlayerOnceUnlessRepeatable(): void
    {
        $kOfficer = $this->kingdomOfficer($this->kingdom, $this->park, 'bc-king');
        $player   = $this->playerAt($this->park, 'bc-player');
        $once     = $this->reason($kOfficer, $this->kingdom, 'Once');
        $many     = $this->reason($kOfficer, $this->kingdom, 'Many', ['Repeatable' => 1]);

        $first = $this->grant($kOfficer, $player, $once['ReasonId']);
        $this->assertSame(0, $first['Status'], (string) ($first['Error'] ?? ''));
        $again = $this->grant($kOfficer, $player, $once['ReasonId']);
        $this->assertSame(1, $again['Status']);
        $this->assertStringContainsString('already', (string) $again['Error']);

        $this->assertSame(0, $this->grant($kOfficer, $player, $many['ReasonId'])['Status']);
        $this->assertSame(0, $this->grant($kOfficer, $player, $many['ReasonId'])['Status']);
        $this->assertSame([$this->classId => 3.0], $this->bonus->totalsByClass($player));

        // Revoking frees a once-only reason to be granted again.
        $this->assertSame(0, $this->bonus->revoke($kOfficer, (int) $first['BonusCreditId'])['Status']);
        $this->assertSame(0, $this->grant($kOfficer, $player, $once['ReasonId'])['Status']);
    }

    public function testRevokeNeedsAuthorityOverThePlayersParkAndIsIdempotent(): void
    {
        $kOfficer    = $this->kingdomOfficer($this->kingdom, $this->park, 'bc-king');
        $parkOfficer = $this->parkOfficer($this->park, 'bc-park');
        $awayOfficer = $this->parkOfficer($this->otherPark, 'bc-away-officer');
        $player      = $this->playerAt($this->park, 'bc-player');
        $purple      = $this->reason($kOfficer, $this->kingdom, 'Being purple');
        $id = (int) $this->grant($kOfficer, $player, $purple['ReasonId'])['BonusCreditId'];
        $this->assertGreaterThan(0, $id);

        $this->assertSame(3, $this->bonus->revoke($awayOfficer, $id, 'not mine')['Status']);
        $this->assertSame([$this->classId => 1.0], $this->bonus->totalsByClass($player));

        // The park officer may remove a credit the kingdom granted to their player.
        $this->assertSame(0, $this->bonus->revoke($parkOfficer, $id, 'entered twice')['Status']);
        $this->assertSame(0, $this->bonus->revoke($parkOfficer, $id)['Status'], 'a second revoke is a no-op success');
        $this->assertSame([], $this->bonus->totalsByClass($player));
        $this->assertSame(0.0, (float) ($this->classRow($player)['Credits'] ?? 0), 'levels no longer see it');

        $this->assertCount(0, $this->bonus->forPlayer($player));
        $this->assertCount(0, $this->bonus->forReason($purple['ReasonId']));
        $gone = $this->bonus->forPlayer($player, true);
        $this->assertCount(1, $gone);
        $this->assertSame($parkOfficer, $gone[0]['RevokedBy']);
        $this->assertSame('entered twice', $gone[0]['RevokeNote']);
        $this->assertNotNull($gone[0]['RevokedAt']);

        $this->assertSame(1, $this->bonus->revoke($kOfficer, 999999999)['Status']);
    }
}
