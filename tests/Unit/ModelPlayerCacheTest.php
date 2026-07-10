<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * In-memory ghettocache for model cache-bust characterization tests (T-09).
 */
final class InMemoryGhettocache extends Ghettocache
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function get($call, $key, $lifetime)
    {
        $this->lifetime[$this->prefix . '.' . $call . '.' . $key] = $lifetime;
        $fullKey = $this->prefix . '.' . $call . '.' . $key;

        return $this->store[$fullKey] ?? false;
    }

    public function cache($call, $key, $content)
    {
        $fullKey = $this->prefix . '.' . $call . '.' . $key;
        $this->store[$fullKey] = $content;

        return $content;
    }

    public function bust($call, $key): void
    {
        unset($this->store[$this->prefix . '.' . $call . '.' . $key]);
    }

    public function has(string $call, string $key): bool
    {
        return array_key_exists($this->prefix . '.' . $call . '.' . $key, $this->store);
    }
}

/**
 * Characterization tests for model.Player cache and domain helpers (T-PLM-01 through T-PLM-04).
 */
final class ModelPlayerCacheTest extends TestCase
{
    private PlayerProfileFixture $fixture;

    private Ghettocache $originalCache;

    private InMemoryGhettocache $cache;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = PlayerProfileFixture::create();
        $this->originalCache = Ork3::$Lib->ghettocache;
        $this->cache = new InMemoryGhettocache();
        Ork3::$Lib->ghettocache = $this->cache;
    }

    protected function tearDown(): void
    {
        Ork3::$Lib->ghettocache = $this->originalCache;

        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testRosterCacheBustOnUpdate(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'cache-bust');
        $kKey = Ork3::$Lib->ghettocache->key(['KingdomId' => $player['kingdom_id']]);
        $pKey = Ork3::$Lib->ghettocache->key(['ParkId' => $player['park_id']]);

        $this->cache->cache('Controller_Kingdom.players_json', $kKey, ['cached' => true]);
        $this->cache->cache('Controller_Park.park_players', $pKey, ['cached' => true]);
        $this->assertTrue($this->cache->has('Controller_Kingdom.players_json', $kKey));
        $this->assertTrue($this->cache->has('Controller_Park.park_players', $pKey));

        $model = new Model_Player();
        $ref = new ReflectionClass($model);
        $method = $ref->getMethod('bust_player_roster_caches');
        $method->setAccessible(true);
        $method->invoke($model, ['MundaneId' => $player['mundane_id']]);

        $this->assertFalse($this->cache->has('Controller_Kingdom.players_json', $kKey));
        $this->assertFalse($this->cache->has('Controller_Park.park_players', $pKey));
    }

    public function testEditNoteViaService(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'note-domain');
        $this->fixture->insertNote($player['mundane_id'], 'Domain note check');

        $playerDomain = new Player();
        $notes = $playerDomain->GetNotes(['MundaneId' => $player['mundane_id']]);
        $this->assertIsArray($notes);
        $this->assertNotEmpty($notes);
        $this->assertArrayHasKey('Note', $notes[0]);
    }

    public function testCustomMilestonesAndDates(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'milestones');

        $playerDomain = new Player();

        $milestones = $playerDomain->GetCustomMilestones($player['mundane_id']);
        $this->assertIsArray($milestones);

        $latest = $playerDomain->get_latest_attendance_date($player['mundane_id']);
        $earliest = $playerDomain->get_earliest_attendance_date($player['mundane_id']);
        $this->assertTrue($latest === null || is_string($latest));
        $this->assertTrue($earliest === null || is_string($earliest));
    }
}
