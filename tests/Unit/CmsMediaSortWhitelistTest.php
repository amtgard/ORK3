<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * CmsMedia::_orderBy — the ORDER BY whitelist behind the media picker's List view.
 *
 * ORDER BY takes no bound parameters, so the caller's sort key can never reach
 * the SQL as text. CmsAjax/medialist forwards $_GET['sort'] and ['dir'] straight
 * through on purpose, which makes this whitelist the ONLY thing between a query
 * string and the clause — hence a test at this layer rather than at the endpoint.
 */
final class CmsMediaSortWhitelistTest extends TestCase
{
    private static function orderBy($sort, $dir): string
    {
        $m = new ReflectionMethod('CmsMedia', '_orderBy');
        $m->setAccessible(true);
        return $m->invoke(new CmsMedia(), $sort, $dir);
    }

    /**
     * Anything not in the table yields the default clause, with the caller's
     * text appearing nowhere in it.
     *
     * @dataProvider rejectedKeys
     */
    public function testUnknownSortKeyFallsBackToTheDefault($sort): void
    {
        $this->assertSame(' ORDER BY media_id DESC', self::orderBy($sort, 'asc'));
    }

    /** @return list<array{0: mixed}> */
    public static function rejectedKeys(): array
    {
        return [
            ['media_id; DROP TABLE ork_cms_media'],
            ['bytes, (SELECT password FROM ork_player LIMIT 1)'],
            ['(CASE WHEN 1=1 THEN SLEEP(5) END)'],
            ['scope_id'],
            ['deleted_at'],
            ['unknown'],
            [''],
            ['   '],
            [null],
            [123],
            [['bytes']],
        ];
    }

    /**
     * @dataProvider acceptedKeys
     */
    public function testWhitelistedKeysProduceTheirClause(string $sort, string $dir, string $expected): void
    {
        $this->assertSame($expected, self::orderBy($sort, $dir));
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function acceptedKeys(): array
    {
        return [
            ['filename', 'asc', ' ORDER BY filename ASC, media_id DESC'],
            ['filename', 'desc', ' ORDER BY filename DESC, media_id DESC'],
            ['bytes', 'desc', ' ORDER BY bytes DESC, media_id DESC'],
            ['created', 'asc', ' ORDER BY created_at ASC, media_id DESC'],
            ['px', 'desc', ' ORDER BY (COALESCE(width, 0) * COALESCE(height, 0)) DESC, media_id DESC'],
            // Key lookup is case-insensitive; the clause is not built from the input.
            ['BYTES', 'asc', ' ORDER BY bytes ASC, media_id DESC'],
            ['  created  ', 'asc', ' ORDER BY created_at ASC, media_id DESC'],
        ];
    }

    /**
     * Direction collapses to one of two literals, so a hostile $dir cannot append
     * anything either.
     *
     * @dataProvider directions
     */
    public function testDirectionIsNeverInterpolated($dir, string $expectedDirection): void
    {
        $this->assertSame(
            ' ORDER BY bytes ' . $expectedDirection . ', media_id DESC',
            self::orderBy('bytes', $dir)
        );
    }

    /** @return list<array{0: mixed, 1: string}> */
    public static function directions(): array
    {
        return [
            ['asc', 'ASC'],
            ['ASC', 'ASC'],
            [' asc ', 'ASC'],
            ['desc', 'DESC'],
            ['sideways', 'DESC'],
            ['asc; DROP TABLE ork_cms_media', 'DESC'],
            ['', 'DESC'],
            [null, 'DESC'],
            [7, 'DESC'],
        ];
    }

    /**
     * The description sort carries two terms, and ASCENDING must put the rows
     * with no description first — sorting by Description is what an author does
     * to find the gaps, so one click has to land on them.
     */
    public function testAscendingDescriptionSortSurfacesTheGapsFirst(): void
    {
        $this->assertSame(
            " ORDER BY (CASE WHEN alt IS NULL OR alt = '' THEN 0 ELSE 1 END) ASC, alt ASC, media_id DESC",
            self::orderBy('alt', 'asc')
        );
    }

    /**
     * Every clause ends in the media_id tiebreak. Without it, rows sharing a sort
     * value can reorder between LIMIT windows and the picker's lazy-load would
     * show the same image twice while skipping another.
     *
     * @dataProvider acceptedKeys
     */
    public function testEveryClauseEndsInAStableTiebreak(string $sort, string $dir, string $expected): void
    {
        $this->assertStringEndsWith(', media_id DESC', self::orderBy($sort, $dir));
    }
}
