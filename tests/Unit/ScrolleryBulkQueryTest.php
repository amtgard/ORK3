<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The bulk selection is the module's one genuinely new query — everything else
 * reuses Player::AwardsForPlayer(). It interpolates rather than binds, matching the
 * surrounding code, so what actually protects it is casting. These assert on the
 * generated SQL and touch no database.
 *
 * mysql_real_escape_string() in this codebase is a no-op shim, so escaping is not a
 * defence here; (int) and a date regex are.
 */
final class ScrolleryBulkQueryTest extends TestCase
{
    private function sql(
        string $type,
        string $id,
        string $scopeType = 'park',
        string $scopeId = '42',
        string $from = '',
        string $to = ''
    ): string {
        return (new Model_Scrollery())->bulkSqlForTest($type, $id, $scopeType, $scopeId, $from, $to);
    }

    public function testEventQueryFiltersOnAtEventIdAndCastsTheId(): void
    {
        $sql = $this->sql('event', '17 OR 1=1');

        $this->assertStringContainsString('at_event_id = 17', $sql);
        $this->assertStringNotContainsString('1=1', $sql);
    }

    /**
     * The regression test for the cross-body disclosure. An event is not a scope:
     * one event carries awards granted by several kingdoms, so filtering on
     * at_event_id alone hands an officer the other bodies' rows. The gate has to sit
     * on the AWARD's own at_park_id / at_kingdom_id, which also keeps this body's
     * awards reachable when they were granted at another body's event.
     */
    public function testEventQueryIsAlsoConstrainedToTheAuthorisedScope(): void
    {
        $park = $this->sql('event', '8462', 'park', '31');
        $this->assertStringContainsString('at_event_id = 8462', $park);
        $this->assertStringContainsString('at_park_id = 31', $park);
        $this->assertStringNotContainsString('at_kingdom_id = ', $park);

        $kingdom = $this->sql('event', '8462', 'kingdom', '9');
        $this->assertStringContainsString('at_event_id = 8462', $kingdom);
        $this->assertStringContainsString('at_kingdom_id = 9', $kingdom);
        $this->assertStringNotContainsString('at_park_id = ', $kingdom);
    }

    public function testTheEventScopeIdIsCastAndCannotCarryInjection(): void
    {
        $sql = $this->sql('event', '17', 'kingdom', '9 OR 1=1');

        $this->assertStringContainsString('at_kingdom_id = 9', $sql);
        $this->assertStringNotContainsString('1=1', $sql);
    }

    /**
     * A date range narrows an event as well — the only remedy the page can offer
     * for an event whose award count exceeds the print cap.
     */
    public function testAnEventQueryStillHonoursTheDateRange(): void
    {
        $sql = $this->sql('event', '8462', 'kingdom', '9', '2024-01-01', '2024-06-30');

        $this->assertStringContainsString('at_event_id = 8462', $sql);
        $this->assertStringContainsString("awards.date >= '2024-01-01'", $sql);
        $this->assertStringContainsString("awards.date <= '2024-06-30'", $sql);
    }

    public function testParkAndKingdomQueriesFilterOnTheirOwnColumn(): void
    {
        $this->assertStringContainsString('at_park_id = 42', $this->sql('park', '42', 'park', '42'));
        $this->assertStringContainsString('at_kingdom_id = 7', $this->sql('kingdom', '7', 'kingdom', '7'));

        // A park query must not silently widen to the whole kingdom. The column name
        // itself appears in the select list and the join, so what matters is that no
        // kingdom PREDICATE is generated.
        $this->assertStringNotContainsString('at_kingdom_id = ', $this->sql('park', '42', 'park', '42'));
        $this->assertStringNotContainsString('at_park_id = ', $this->sql('kingdom', '7', 'kingdom', '7'));
    }

    public function testAnUnknownScopeCannotProduceAnUnfilteredQuery(): void
    {
        $sql = $this->sql('everything', '1', 'kingdom', '9');

        // Whatever it does, it must not return every award ever granted.
        $this->assertStringContainsString('at_park_id = 0', $sql);
    }

    public function testDatesAreAcceptedOnlyInIsoFormAndBoundTheRange(): void
    {
        $sql = $this->sql('park', '42', 'park', '42', '2024-01-01', '2024-12-31');
        $this->assertStringContainsString("awards.date >= '2024-01-01'", $sql);
        $this->assertStringContainsString("awards.date <= '2024-12-31'", $sql);

        // Anything not YYYY-MM-DD is dropped rather than interpolated.
        $injected = $this->sql('park', '42', 'park', '42', "2024-01-01' OR '1'='1", '');
        $this->assertStringNotContainsString("1'='1", $injected);
        $this->assertStringNotContainsString('awards.date >=', $injected);
    }

    public function testTheQueryJoinsTheSameTablesAsTheAwardsTab(): void
    {
        $sql = $this->sql('event', '17');

        foreach (['kingdomaward', 'award', 'mundane', 'park', 'kingdom'] as $table) {
            $this->assertStringContainsString(DB_PREFIX . $table, $sql, "missing join on {$table}");
        }
    }

    /**
     * Bulk output is printed in order, so it must be deterministic — a court reads
     * down a list. Recipient then rank is the order the awards are called in.
     */
    public function testResultsAreOrdered(): void
    {
        $this->assertStringContainsString('order by', strtolower($this->sql('event', '17')));
    }
}
