<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The insert-only Scrollery print log (db-migrations/2026-09-05-scroll-print-log.sql).
 *
 * Nothing recorded that a scroll was printed, which blocks the scribe's "not yet
 * printed" filter, the Court Planner handshake, the Release Feature Utilization
 * report and the upstreaming gate. This pins the write half.
 */
final class ScrollPrintLogTest extends TestCase
{
    private const PRINTER = 987654;

    private ScrollPrintLog $log;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        if (!$this->tableExists()) {
            $this->markTestSkipped('ork_scroll_print is absent — re-render the test schema.');
        }

        $this->log = new ScrollPrintLog();
        $this->wipe();
    }

    protected function tearDown(): void
    {
        if (isset($this->log)) {
            $this->wipe();
        }
    }

    /** @param array<string, mixed> $o */
    private function entry(array $o = []): array
    {
        return array_merge([
            'AwardsId'    => 424242,
            'MundaneId'   => 11,
            'PrintedById' => self::PRINTER,
            'ScopeType'   => 'kingdom',
            'ScopeId'     => 4,
            'OrderId'     => 'rose',
            'Rank'        => 3,
            'Variant'     => '',
        ], $o);
    }

    public function testAPrintIsRecordedAndReadableBack(): void
    {
        $id = $this->log->RecordPrint($this->entry(['Recipe' => ['ground' => 'vellum']]));

        $this->assertGreaterThan(0, $id, 'RecordPrint must return the new row id');
        $this->assertNotSame('', $this->log->LastPrintedAt(424242));

        $row = $this->rows()[0];
        $this->assertSame(424242, (int) $row['awards_id']);
        $this->assertSame(self::PRINTER, (int) $row['printed_by_id']);
        $this->assertSame('kingdom', $row['scope_type']);
        $this->assertSame('rose', $row['order_id']);
        $this->assertSame(3, (int) $row['rank']);
        $this->assertSame('{"ground":"vellum"}', $row['recipe_json']);
    }

    /**
     * Scrollery/render is a GET. A refresh, a prefetch or a cancelled print dialog
     * would each log another row and make "how many certificates has this kingdom
     * printed" meaningless.
     */
    public function testARefreshInsideTheWindowIsSuppressed(): void
    {
        $first = $this->log->RecordPrint($this->entry());
        $again = $this->log->RecordPrint($this->entry());

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $again, 'a duplicate inside the window must not be logged');
        $this->assertCount(1, $this->rows());
    }

    /**
     * The dedupe key is (awards_id, printed_by_id): two officers printing the same
     * court are two real prints, and the same officer printing two awards is two
     * real prints.
     */
    public function testDedupeIsPerAwardAndPerPrinter(): void
    {
        $this->log->RecordPrint($this->entry());
        $this->log->RecordPrint($this->entry(['PrintedById' => self::PRINTER + 1]));
        $this->log->RecordPrint($this->entry(['AwardsId' => 424243]));

        $this->assertCount(3, $this->rows());
    }

    /**
     * THE CLOCK. PHP and MySQL disagree here by five hours — PHP runs in the
     * configured local zone, the database in UTC — so a window written as
     * `printed_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND)` matched nothing at all and
     * every refresh logged a second row. Observed: a row written at PHP-time
     * 07:35:11 against a MySQL NOW() of 12:35:11. This asserts the two agree in the
     * only place it matters — a row this class just wrote is inside its own window.
     */
    public function testTheDedupeWindowIsMeasuredOnTheSameClockThatWritesTheRow(): void
    {
        $this->log->RecordPrint($this->entry());

        $probe = new ReflectionMethod(ScrollPrintLog::class, 'printedRecently');
        $probe->setAccessible(true);

        $this->assertTrue(
            $probe->invoke($this->log, 424242, self::PRINTER),
            'a row written a moment ago must fall inside the dedupe window'
        );
    }

    /**
     * awards_id is nullable because Scrollery/compose prints a hand-composed
     * certificate with no award row at all. A repeat of the same composition by the
     * same printer inside the window — (printed_by_id, mundane_id, order_id, rank,
     * scope_type, scope_id) — is suppressed like any other repeat.
     */
    public function testAComposedPrintLogsWithNoAwardRow(): void
    {
        $composed = ['AwardsId' => null, 'MundaneId' => 0, 'PrintedById' => self::PRINTER,
            'ScopeType' => 'park', 'ScopeId' => 9, 'OrderId' => 'custom', 'AuthBasis' => 'compose'];
        $a = $this->log->RecordPrint($composed);
        $b = $this->log->RecordPrint($composed);

        $this->assertGreaterThan(0, $a);
        $this->assertSame(0, $b, 'a repeat of the same composition inside the window must not be logged');
        $this->assertCount(1, $this->rows());
        $this->assertNull($this->rows()[0]['awards_id']);
        $this->assertSame('compose', $this->rows()[0]['auth_basis']);
    }

    /** A different composition by the same printer is a different print. */
    public function testDifferentCompositionsAreNotDeduped(): void
    {
        $composed = ['MundaneId' => 0, 'PrintedById' => self::PRINTER,
            'ScopeType' => 'park', 'ScopeId' => 9, 'OrderId' => 'custom', 'Rank' => 1];
        $this->log->RecordPrint($composed);
        $this->log->RecordPrint(['Rank' => 2] + $composed);
        $this->log->RecordPrint(['MundaneId' => 11] + $composed);

        $this->assertCount(3, $this->rows());
    }

    /**
     * A court line with no award row yet is keyed by its court line, and carries the
     * court and the gate. One batched call writes the run; a repeat of the run inside
     * the window writes nothing, and the batched read answers per line.
     */
    public function testACourtRunIsLoggedPerLineAndDedupedAsABatch(): void
    {
        $line = static fn (int $caId): array => ['CourtId' => 77, 'CourtAwardId' => $caId, 'AuthBasis' => 'court-manager',
            'MundaneId' => 11, 'PrintedById' => self::PRINTER, 'ScopeType' => 'kingdom', 'ScopeId' => 4,
            'OrderId' => 'rose', 'Rank' => 1];

        $first = $this->log->RecordPrints(['x' => $line(990001), 'y' => $line(990002)]);
        $again = $this->log->RecordPrints(['x' => $line(990001), 'y' => $line(990002)]);

        $this->assertGreaterThan(0, $first['x']);
        $this->assertGreaterThan(0, $first['y']);
        $this->assertSame(['x' => 0, 'y' => 0], $again);

        $rows = $this->rows();
        $this->assertCount(2, $rows);
        $this->assertSame(77, $rows[0]['court_id']);
        $this->assertSame(990001, $rows[0]['court_award_id']);
        $this->assertSame('court-manager', $rows[0]['auth_basis']);

        $last = $this->log->LastPrintedForCourtAwards([990001, 990002, 990003, 0]);
        $this->assertSame([990001, 990002], array_keys($last));
        $this->assertSame([], $this->log->LastPrintedForCourtAwards([]));
    }

    /** auth_basis is a closed vocabulary. */
    public function testAuthBasisIsNormalised(): void
    {
        $this->log->RecordPrint($this->entry(['AuthBasis' => 'root']));

        $this->assertSame('', $this->rows()[0]['auth_basis']);
    }

    /**
     * THE SEAM. The render route logs straight out of Scroll::BuildScroll()'s array,
     * reading $scroll['mundaneId'] / ['scopeType'] / ['scopeId'] with ?? fallbacks —
     * so if BuildScroll() ever stops returning those three, every logged row silently
     * goes back to mundane_id 0 / scope_type '' / scope_id 0 and nothing fails. That
     * is exactly what happened the first time this was wired.
     *
     * This walks the whole seam: build a scroll, log it the way the controller does,
     * read the row back.
     */
    public function testTheBuiltScrollCarriesTheProvenanceTheLogWritesDown(): void
    {
        $scroll = Scroll::BuildScroll(
            [
                'AwardId' => 33, 'OfficerRole' => 'none', 'Rank' => 3, 'Date' => '2024-05-13',
                'Note' => '', 'GivenBy' => 'Sir Someone', 'KingdomAwardName' => 'Order of the Griffin',
                'MundaneId' => 11956, 'ParkId' => 435, 'KingdomId' => 4, 'ParkName' => 'Rising Sun Station',
            ],
            ['player' => 'Testerly', 'pronoun' => 'her', 'heraldry' => '']
        );

        foreach (['mundaneId', 'scopeType', 'scopeId'] as $key) {
            $this->assertArrayHasKey($key, $scroll, "BuildScroll() must return {$key} — the print log writes it");
        }

        // Verbatim the controller's call site.
        $id = $this->log->RecordPrint([
            'AwardsId'    => 424242,
            'MundaneId'   => (int) ($scroll['mundaneId'] ?? 0),
            'PrintedById' => self::PRINTER,
            'ScopeType'   => $scroll['scopeType'] ?? '',
            'ScopeId'     => (int) ($scroll['scopeId'] ?? 0),
            'OrderId'     => (string) ($scroll['order'] ?? ''),
            'Rank'        => (int) ($scroll['rank'] ?? 0),
            'Variant'     => (string) ($scroll['variant'] ?? ''),
            'Recipe'      => $scroll['recipe'] ?? null,
        ]);
        $this->assertGreaterThan(0, $id);

        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet('SELECT * FROM ' . DB_PREFIX . 'scroll_print WHERE scroll_print_id = ' . $id);
        $rs->Next();

        $this->assertSame(11956, (int) $rs->mundane_id, 'the recipient must reach the log');
        $this->assertSame('park', (string) $rs->scope_type, 'the issuing body must reach the log');
        $this->assertSame(435, (int) $rs->scope_id);
        $this->assertSame('griffon', (string) $rs->order_id);
        $this->assertSame(3, (int) $rs->rank);
    }

    /** Logging must never be the thing that stops an officer getting their scrolls. */
    public function testABadEntryIsRefusedRatherThanThrowing(): void
    {
        $this->assertSame(0, $this->log->RecordPrint([]));
        $this->assertSame(0, $this->log->RecordPrint(['AwardsId' => 1, 'PrintedById' => 0]));
        $this->assertCount(0, $this->rows());
    }

    /** scope_type is a closed vocabulary; anything else is stored as unknown, not echoed. */
    public function testScopeTypeIsNormalised(): void
    {
        $this->log->RecordPrint($this->entry(['ScopeType' => "kingdom'; DROP TABLE x; --"]));

        $this->assertSame('', $this->rows()[0]['scope_type']);
    }

    public function testLastPrintedAtIsEmptyForAnUnprintedAward(): void
    {
        $this->assertSame('', $this->log->LastPrintedAt(424242));
        $this->assertSame('', $this->log->LastPrintedAt(0));
        $this->assertSame('', $this->log->LastPrintedAt(-1));
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT * FROM ' . DB_PREFIX . 'scroll_print WHERE printed_by_id IN ('
            . self::PRINTER . ', ' . (self::PRINTER + 1) . ') ORDER BY scroll_print_id'
        );
        $rows = [];
        if (is_object($rs) && $rs->Size() > 0) {
            while ($rs->Next()) {
                $rows[] = [
                    'awards_id' => $rs->awards_id === null ? null : (int) $rs->awards_id,
                    'court_id' => $rs->court_id === null ? null : (int) $rs->court_id,
                    'court_award_id' => $rs->court_award_id === null ? null : (int) $rs->court_award_id,
                    'auth_basis' => (string) $rs->auth_basis,
                    'printed_by_id' => $rs->printed_by_id,
                    'scope_type' => (string) $rs->scope_type,
                    'order_id' => (string) $rs->order_id,
                    'rank' => $rs->rank,
                    'recipe_json' => (string) $rs->recipe_json,
                ];
            }
        }

        return $rows;
    }

    private function wipe(): void
    {
        global $DB;
        $DB->Clear();
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'scroll_print WHERE printed_by_id IN ('
            . self::PRINTER . ', ' . (self::PRINTER + 1) . ')'
        );
    }

    private function tableExists(): bool
    {
        global $DB;
        $DB->Clear();

        try {
            $rs = $DB->DataSet("SHOW TABLES LIKE '" . DB_PREFIX . "scroll_print'");

            return is_object($rs) && $rs->Size() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
