<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The bulk-print event picker's list query. It has to carry the SAME scope
 * predicate bulkSqlForTest()'s 'kingdom'/'park' cases apply — otherwise the
 * picker could offer an event whose awards the bulk query would then refuse to
 * return, which would read to an officer as a bug in the print run rather than
 * as the picker having invited them to click something bogus.
 *
 * Asserts on the generated SQL and touches no database, the same convention as
 * ScrolleryBulkQueryTest. mysql_real_escape_string() in this codebase is a no-op
 * shim, so escaping is not a defence here; (int) is.
 */
final class ScrolleryEventsQueryTest extends TestCase
{
    private function sql(string $scopeType, string $scopeId): string
    {
        return Model_Scrollery::eventsWithPrintableAwardsSqlForTest($scopeType, $scopeId);
    }

    public function testParkScopeFiltersOnAtParkIdOnly(): void
    {
        $sql = $this->sql('park', '42');

        $this->assertStringContainsString('awards.at_park_id = 42', $sql);
        $this->assertStringNotContainsString('at_kingdom_id = ', $sql);
    }

    public function testKingdomScopeFiltersOnAtKingdomIdOnly(): void
    {
        $sql = $this->sql('kingdom', '7');

        $this->assertStringContainsString('awards.at_kingdom_id = 7', $sql);
        $this->assertStringNotContainsString('at_park_id = ', $sql);
    }

    public function testTheScopeIdIsCastAndCannotCarryInjection(): void
    {
        $sql = $this->sql('kingdom', '9 OR 1=1');

        $this->assertStringContainsString('awards.at_kingdom_id = 9', $sql);
        $this->assertStringNotContainsString('1=1', $sql);
    }

    /**
     * An empty or zero scope id must never fall through to a predicate that
     * matches every award of that scope type. Both at_kingdom_id and at_park_id
     * sit at 0 on a huge share of real rows (a park grant never sets
     * at_kingdom_id; a kingdom grant never sets at_park_id), so a bare
     * "column = 0" here would hand back one whole class of body's awards.
     */
    public function testAZeroScopeIdCannotMatchEverything(): void
    {
        $kingdom = $this->sql('kingdom', '0');
        $this->assertStringNotContainsString('at_kingdom_id = 0', $kingdom);
        $this->assertStringNotContainsString('at_park_id = 0', $kingdom);

        $park = $this->sql('park', '');
        $this->assertStringNotContainsString('at_kingdom_id = 0', $park);
        $this->assertStringNotContainsString('at_park_id = 0', $park);
    }

    public function testAnUnrecognisedScopeTypeCannotProduceAnUnfilteredQuery(): void
    {
        $sql = $this->sql('everything', '9');

        $this->assertStringNotContainsString('at_kingdom_id = 9', $sql);
        $this->assertStringNotContainsString('at_park_id = 9', $sql);
    }

    /** Unattached awards (no event) must never surface as an event option. */
    public function testUnattachedAwardsAreExcluded(): void
    {
        $this->assertStringContainsString('awards.at_event_id > 0', $this->sql('park', '42'));
    }

    public function testTheQueryGroupsByEventAndJoinsTheEventTable(): void
    {
        $sql = $this->sql('park', '42');

        $this->assertStringContainsString('group by awards.at_event_id', $sql);
        $this->assertStringContainsString(DB_PREFIX . 'event e', $sql);
        $this->assertStringContainsString('e.name as event_name', $sql);
    }

    public function testTheQueryIsCappedAndOrderedMostRecentFirst(): void
    {
        $sql = $this->sql('park', '42');

        $this->assertStringContainsString('order by last_date desc', $sql);
        $this->assertStringContainsString('limit 200', $sql);
    }
}
