<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A memcache backend that actually remembers, so the cache under test is real.
 *
 * tests/bootstrap.php installs a Memcached stub whose get() always returns false —
 * fine for "don't crash without the extension", useless for proving an
 * invalidation works, because a cache that never hits can never go stale. This
 * one stores, so a missing bust is observable as a stale read.
 */
final class RecordingMemcache
{
    /** @var array<string, mixed> */
    public array $store = [];

    public function addServer(string $host, int $port): bool
    {
        return true;
    }

    /** @return mixed */
    public function get(string $key)
    {
        return array_key_exists($key, $this->store) ? $this->store[$key] : false;
    }

    /** @param mixed $value */
    public function set(string $key, $value, int $expiration = 0): bool
    {
        $this->store[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    /** @return array<string, array<string, int>> */
    public function getStats(): array
    {
        return ['localhost:11211' => ['time' => time()]];
    }
}

/**
 * Marking an award a ladder must take effect in the Add Award dropdown NOW.
 *
 * Award::GetAwardOptionListHtml() memcaches its rendered <option> markup for 1200
 * seconds, keyed on {KingdomId, OfficerRole, v}. Toggling ka.is_ladder changes the
 * option's optgroup and its data-is-ladder / data-max-rank attributes but not the
 * key, so nothing evicted the entry.
 *
 * Before ladders that was cosmetic. Now the client's rank-pill builder returns
 * early when data-is-ladder is absent and draws no rank control at all, while
 * Player::RejectUnrankedLadderGrant() refuses the resulting rankless grant with
 * "choose a rank" — pointing at a control the page never rendered. The award is
 * ungrantable for up to twenty minutes and then silently starts working.
 *
 * Version elements in the key (Award's 'v', Report's 'gv') only invalidate at
 * DEPLOY; a monarch toggling a flag at runtime needs an eviction, which is what
 * Award::BustAwardOptionListCache() does and what these tests exercise.
 */
final class AwardOptionListCacheBustTest extends TestCase
{
    private const MARKER = 'OPTCACHE';
    private const KINGDOM_ID = 1;

    private PDO $pdo;
    private Kingdom $kingdom;
    private Award $award;
    private AuthorizedOfficerFixture $officer;
    private string $token;

    /** @var mixed the real memcache handle, restored in tearDown */
    private $realMemcache;

    private RecordingMemcache $cache;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->kingdom = new Kingdom();
        $this->award = new Award();
        $this->officer = new AuthorizedOfficerFixture($this->pdo, self::MARKER, self::KINGDOM_ID);
        $this->token = $this->officer->createAuthorizedOfficer();

        $this->cache = new RecordingMemcache();
        $this->realMemcache = Ork3::$Lib->ghettocache->memcache;
        Ork3::$Lib->ghettocache->memcache = $this->cache;
    }

    protected function tearDown(): void
    {
        Ork3::$Lib->ghettocache->memcache = $this->realMemcache;
        $this->pdo->exec("DELETE FROM ork_kingdomaward WHERE name LIKE '" . self::MARKER . "%'");
        $this->officer->cleanup();
    }

    /**
     * A kingdom-owned award (award_id 0, so no official ladder is involved) whose
     * name cannot be mistaken for an officer title — Kingdom::GetAwardList() routes
     * anything that looks like one into the 'Officers' list instead.
     */
    private function seedPlainAward(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_kingdomaward (kingdom_id, award_id, name, is_ladder, max_level, is_title, title_class, disabled)
             VALUES (:k, 0, :name, 0, 0, 0, 0, 0)'
        );
        $stmt->execute([':k' => self::KINGDOM_ID, ':name' => self::MARKER . '-' . uniqid()]);

        return (int) $this->pdo->lastInsertId();
    }

    /** The <option ...> for one kingdomaward id, or '' if it is not in the list. */
    private function optionFor(string $html, int $kingdomAwardId): string
    {
        $needle = "<option value='" . $kingdomAwardId . "'";
        $at = strpos($html, $needle);
        if ($at === false) {
            return '';
        }
        $end = strpos($html, '>', $at);

        return substr($html, $at, ($end === false ? strlen($html) : $end + 1) - $at);
    }

    private function awardsList(): string
    {
        return (string) $this->award->GetAwardOptionListHtml(self::KINGDOM_ID, 'Awards');
    }

    /**
     * THE FINDING. Warm the cache, ladder-ify the award, read the list again —
     * the very next read must describe a ladder.
     */
    public function testLadderifyingAnAwardIsVisibleInTheNextDropdownRead(): void
    {
        $id = $this->seedPlainAward();

        $before = $this->awardsList();
        $this->assertNotSame('', $this->optionFor($before, $id), 'the seeded award should be in the Awards list');
        $this->assertStringNotContainsString('data-is-ladder', $this->optionFor($before, $id));
        $this->assertNotSame([], $this->cache->store, 'the option list must actually be cached, or this test proves nothing');

        $this->kingdom->EditAward([
            'KingdomAwardId' => $id,
            'KingdomId' => self::KINGDOM_ID,
            'Name' => self::MARKER . '-laddered',
            'ReignLimit' => 0,
            'MonthLimit' => 0,
            'IsTitle' => 0,
            'TitleClass' => 0,
            'IsLadder' => 1,
            'MaxLevel' => 8,
            'Token' => $this->token,
        ]);

        $option = $this->optionFor($this->awardsList(), $id);
        $this->assertStringContainsString(
            "data-is-ladder='1'",
            $option,
            'the dropdown served a stale option with no data-is-ladder: the rank control will not render'
        );
        $this->assertStringContainsString("data-max-rank='8'", $option);
    }

    /**
     * The list is cached once per OfficerRole. Busting only the role that happens
     * to be read first leaves the other one stale, so both must go.
     */
    public function testBothOfficerRoleVariantsAreEvicted(): void
    {
        $id = $this->seedPlainAward();

        $this->awardsList();
        $this->award->GetAwardOptionListHtml(self::KINGDOM_ID, 'Officers');
        $this->assertCount(2, $this->cache->store, 'both role variants should be warm before the edit');

        $this->kingdom->EditAward([
            'KingdomAwardId' => $id,
            'KingdomId' => self::KINGDOM_ID,
            'Name' => self::MARKER . '-laddered',
            'ReignLimit' => 0,
            'MonthLimit' => 0,
            'IsTitle' => 0,
            'TitleClass' => 0,
            'IsLadder' => 1,
            'MaxLevel' => 8,
            'Token' => $this->token,
        ]);

        $this->assertSame([], $this->cache->store, 'every cached role variant for this kingdom must be evicted');
    }

    /**
     * A brand-new award has to be grantable immediately too — an option that is not
     * in the cached list at all is the same dead end by a different route.
     */
    public function testACreatedLadderAwardAppearsInTheNextDropdownRead(): void
    {
        // Seed one row FIRST. With no awards at all Kingdom::GetAwardList() returns
        // an error status and GetAwardOptionListHtml() bails out without caching, so
        // a warm-up on an empty kingdom warms nothing and this test would pass
        // whether or not CreateAward busts anything.
        $this->seedPlainAward();
        $this->awardsList();
        $this->assertNotSame([], $this->cache->store, 'the warm-up must actually have cached something');

        $name = self::MARKER . '-created-' . uniqid();
        $this->kingdom->CreateAward([
            'KingdomId' => self::KINGDOM_ID,
            'AwardId' => 0,
            'Name' => $name,
            'ReignLimit' => 0,
            'MonthLimit' => 0,
            'IsTitle' => 0,
            'TitleClass' => 0,
            'IsLadder' => 1,
            'MaxLevel' => 6,
            'Token' => $this->token,
        ]);

        $html = $this->awardsList();
        $this->assertStringContainsString(
            htmlspecialchars($name, ENT_QUOTES),
            $html,
            'a newly created award must not wait out the cache TTL to become grantable'
        );
    }

    /**
     * Busting must not become "never cache": a second read with no intervening
     * write still has to be served from memcache, or the fix has quietly turned a
     * hot dropdown into a per-request join.
     */
    public function testTheListIsStillCachedBetweenReads(): void
    {
        $this->seedPlainAward();

        $first = $this->awardsList();
        $this->assertNotSame([], $this->cache->store);

        // Poison the stored value; a genuine cache hit returns the poison verbatim.
        $key = array_key_first($this->cache->store);
        $this->cache->store[$key] = '<option value=\'-1\'>CACHED</option>';

        $this->assertSame('<option value=\'-1\'>CACHED</option>', $this->awardsList());
        $this->assertNotSame('', $first);
    }
}
