<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A Legend vanishes from every player search unless the caller opts in with
 * include_legend, in all three backends, and include_inactive alone never
 * surfaces one.
 */
final class LegendSearchTest extends TestCase
{
    private LegendFixture $fixture;
    private SearchService $search;
    private int $park = 0;
    private int $kingdom = 0;
    private int $legend = 0;
    private int $living = 0;
    private string $term = '';

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        Legend::clearMemo();
        $this->fixture = LegendFixture::create();
        $this->search  = new SearchService();
        $this->park    = $this->fixture->firstParkId();
        $this->kingdom = $this->fixture->kingdomIdForPark($this->park);
        $tag           = 'lgs' . bin2hex(random_bytes(3));
        $this->term    = $tag;
        $this->legend  = $this->fixture->addPlayer($this->park, $tag . 'dead', 1999)['mundane_id'];
        $this->fixture->pdo()->prepare('UPDATE ' . DB_PREFIX . 'mundane SET active = 0 WHERE mundane_id = ?')
            ->execute([$this->legend]);
        $this->living = $this->fixture->addPlayer($this->park, $tag . 'live')['mundane_id'];
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
        Legend::clearMemo();
    }

    /** @param list<array<string, mixed>> $rows */
    private function ids(array $rows, string $key): array
    {
        return array_map(fn ($r) => (int) $r[$key], array_values($rows));
    }

    /** @return array<string, list<int>> the ids each backend returns */
    private function everyBackend(array $opts): array
    {
        $legacy = $this->search->Player('PERSONA', $this->term, 15, null, null, null, null, !empty($opts['legend']), true);
        $scoped = $this->search->ScopedPlayerSearch([
            'Query' => $this->term, 'Scope' => 'global', 'Limit' => 20,
            'IncludeInactive' => !empty($opts['inactive']), 'IncludeLegend' => !empty($opts['legend']),
        ]);
        $universal = $this->search->UniversalSearch([
            'Query' => $this->term, 'IncludeInactive' => !empty($opts['inactive']), 'IncludeLegend' => !empty($opts['legend']),
        ]);

        return [
            'legacy'    => $this->ids($legacy, 'MundaneId'),
            'scoped'    => $this->ids($scoped, 'MundaneId'),
            'universal' => $this->ids($universal['players'], 'id'),
        ];
    }

    public function test_each_backend_hides_legend_by_default(): void
    {
        foreach ($this->everyBackend([]) as $name => $ids) {
            $this->assertNotContains($this->legend, $ids, $name);
            $this->assertContains($this->living, $ids, $name);
        }
    }

    public function test_include_inactive_alone_does_not_show_legend(): void
    {
        foreach ($this->everyBackend(['inactive' => true]) as $name => $ids) {
            $this->assertNotContains($this->legend, $ids, $name);
        }
    }

    public function test_include_legend_shows_with_flag_in_each_backend(): void
    {
        foreach ($this->everyBackend(['legend' => true]) as $name => $ids) {
            $this->assertContains($this->legend, $ids, $name);
            $this->assertContains($this->living, $ids, $name);
        }
        foreach ($this->everyBackend(['legend' => true, 'inactive' => true]) as $name => $ids) {
            $this->assertContains($this->legend, $ids, $name . ' with inactive');
        }
    }

    public function test_scope_still_applies_with_include_legend(): void
    {
        $other = $this->fixture->parkOutsideKingdom($this->kingdom);
        $this->assertGreaterThan(0, $other, 'needs a second kingdom');
        $otherKingdom = $this->fixture->kingdomIdForPark($other);
        $rows = $this->search->ScopedPlayerSearch([
            'Query' => $this->term, 'Scope' => 'kingdom_own', 'KingdomId' => $otherKingdom,
            'IncludeLegend' => true,
        ]);
        $this->assertNotContains($this->legend, $this->ids($rows, 'MundaneId'));
        $rows = $this->search->ScopedPlayerSearch([
            'Query' => $this->term, 'Scope' => 'kingdom_own', 'KingdomId' => $this->kingdom,
            'IncludeLegend' => true,
        ]);
        $this->assertContains($this->legend, $this->ids($rows, 'MundaneId'));
        $legacy = $this->search->Player('PERSONA', $this->term, 15, $otherKingdom, null, null, null, true);
        $this->assertNotContains($this->legend, $this->ids($legacy, 'MundaneId'));
    }

    public function test_universal_finds_legend_when_opted_in(): void
    {
        $r = $this->search->UniversalSearch(['Query' => $this->term . 'dead', 'IncludeLegend' => true]);
        $this->assertSame([$this->legend], $this->ids($r['players'], 'id'));
        $r = $this->search->UniversalSearch(['Query' => $this->term . 'dead']);
        $this->assertSame([], $r['players']);
    }

    public function test_output_carries_legend_fields(): void
    {
        $legacy = $this->search->Player('PERSONA', $this->term, 15, null, null, null, null, true);
        $scoped = $this->search->ScopedPlayerSearch([
            'Query' => $this->term, 'Scope' => 'global', 'IncludeLegend' => true, 'Format' => 'admin',
        ]);
        $universal = $this->search->UniversalSearch(['Query' => $this->term, 'IncludeLegend' => true])['players'];
        $rows = ['legacy' => [$legacy, 'MundaneId'], 'scoped' => [$scoped, 'MundaneId'], 'universal' => [$universal, 'id']];
        foreach ($rows as $name => [$list, $key]) {
            $byId = [];
            foreach ($list as $row) {
                $byId[(int) $row[$key]] = $row;
            }
            $this->assertSame(1, (int) $byId[$this->legend]['Legend'], $name);
            $this->assertSame(1999, (int) $byId[$this->legend]['LegendYear'], $name);
            $this->assertSame(0, (int) $byId[$this->living]['Legend'], $name);
            $this->assertSame(0, (int) $byId[$this->living]['LegendYear'], $name);
        }
    }

    public function test_admin_opt_in_is_honored_only_for_a_global_admin(): void
    {
        $admin    = $this->fixture->addOrkAdmin($this->park);
        $officer  = $this->fixture->addKingdomOfficer($this->kingdom, $this->park, 'kofficer');
        $token    = $this->fixture->addSession($admin);
        $officerT = $this->fixture->addSession($officer);
        unset($_SESSION['is_authorized_mundane_id']);

        $seen = fn (array $rows, string $key) => in_array($this->legend, $this->ids($rows, $key), true);
        $req  = fn (int $uid) => ['Query' => $this->term, 'Scope' => 'global', 'IncludeLegend' => 'admin', 'CallerUserId' => $uid];

        $this->assertTrue($seen($this->search->ScopedPlayerSearch($req($admin)), 'MundaneId'));
        $this->assertFalse($seen($this->search->ScopedPlayerSearch($req($officer)), 'MundaneId'));
        $this->assertFalse($seen($this->search->ScopedPlayerSearch($req(0)), 'MundaneId'));
        $this->assertTrue($seen($this->search->UniversalSearch(['Query' => $this->term, 'IncludeLegend' => 'admin', 'CallerUserId' => $admin])['players'], 'id'));
        $this->assertFalse($seen($this->search->UniversalSearch(['Query' => $this->term, 'IncludeLegend' => 'admin', 'CallerUserId' => $officer])['players'], 'id'));

        $this->assertTrue($seen($this->search->Player('PERSONA', $this->term, 15, null, null, null, $token, 'admin'), 'MundaneId'));
        unset($_SESSION['is_authorized_mundane_id']);
        $this->assertFalse($seen($this->search->Player('PERSONA', $this->term, 15, null, null, null, $officerT, 'admin'), 'MundaneId'));
        $this->assertFalse($seen($this->search->Player('PERSONA', $this->term, 15, null, null, null, null, 'admin'), 'MundaneId'));
    }
}
