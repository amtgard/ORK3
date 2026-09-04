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
    private function sql(string $type, string $id, string $from = '', string $to = ''): string
    {
        return (new Model_Scrollery())->bulkSqlForTest($type, $id, $from, $to);
    }

    public function testEventQueryFiltersOnAtEventIdAndCastsTheId(): void
    {
        $sql = $this->sql('event', '17 OR 1=1');

        $this->assertStringContainsString('at_event_id = 17', $sql);
        $this->assertStringNotContainsString('1=1', $sql);
    }

    public function testParkAndKingdomQueriesFilterOnTheirOwnColumn(): void
    {
        $this->assertStringContainsString('at_park_id = 42', $this->sql('park', '42'));
        $this->assertStringContainsString('at_kingdom_id = 7', $this->sql('kingdom', '7'));

        // A park query must not silently widen to the whole kingdom. The column name
        // itself appears in the select list and the join, so what matters is that no
        // kingdom PREDICATE is generated.
        $this->assertStringNotContainsString('at_kingdom_id = ', $this->sql('park', '42'));
        $this->assertStringNotContainsString('at_park_id = ', $this->sql('kingdom', '7'));
    }

    public function testAnUnknownScopeCannotProduceAnUnfilteredQuery(): void
    {
        $sql = $this->sql('everything', '1');

        // Whatever it does, it must not return every award ever granted.
        $this->assertStringContainsString('at_park_id = 0', $sql);
    }

    public function testDatesAreAcceptedOnlyInIsoFormAndBoundTheRange(): void
    {
        $sql = $this->sql('park', '42', '2024-01-01', '2024-12-31');
        $this->assertStringContainsString("awards.date >= '2024-01-01'", $sql);
        $this->assertStringContainsString("awards.date <= '2024-12-31'", $sql);

        // Anything not YYYY-MM-DD is dropped rather than interpolated.
        $injected = $this->sql('park', '42', "2024-01-01' OR '1'='1", '');
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
