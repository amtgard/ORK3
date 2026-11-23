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
        string $to = '',
        array $officerNames = [],
        string $cursor = '',
        int $limit = 0
    ): string {
        return ScrollQuery::bulkSqlForTest($type, $id, $scopeType, $scopeId, $from, $to, $officerNames, $cursor, $limit);
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

    /**
     * The sort has to be a TOTAL order or a keyset cursor cannot work: m.persona is
     * not unique (a player has many awards, and two players can share a persona), so
     * without awards_id as the final tiebreaker paging would either skip rows or
     * loop on them.
     */
    public function testTheSortIsTotalAndEndsWithTheAwardsIdTiebreaker(): void
    {
        $sql = strtolower($this->sql('event', '17'));
        $order = substr($sql, strpos($sql, 'order by'));

        $this->assertStringContainsString('awards.awards_id', $order);
        $this->assertStringEndsWith('awards.awards_id', trim(substr($order, 0, strpos($order, 'limit'))));
    }

    /**
     * The nullable sort terms are COALESCEd in the ORDER BY, because they are also
     * COALESCEd in the cursor's row-value comparison — a NULL anywhere inside a
     * row-value comparison makes the whole comparison NULL, which silently drops the
     * row from the page. m.persona and a.name are both nullable.
     */
    public function testNullableSortTermsAreCoalesced(): void
    {
        $sql = $this->sql('event', '17');

        $this->assertStringContainsString("coalesce(m.persona, '')", $sql);
        $this->assertStringContainsString("coalesce(a.name, ka.name, '')", $sql);
    }

    /**
     * ITEM 1. ork_awards.revoked is NOT NULL DEFAULT 0 and 2,856 rows carry a 1.
     * 2,287 of those carry a park/kingdom scope, so they matched the old bulk
     * predicate and arrived PRE-CHECKED; 2,797 have had mundane_id nulled by
     * revocation and would have printed a blank recipient.
     */
    public function testRevokedAndRecipientlessRowsAreExcludedByTheQueryItself(): void
    {
        $sql = $this->sql('event', '12250', 'kingdom', '4');

        $this->assertStringContainsString('awards.revoked = 0', $sql);
        $this->assertStringContainsString('awards.mundane_id > 0', $sql);
    }

    /**
     * ITEMS 5 + 26. The cap used to be applied to RAW rows, with the
     * printable/excluded split happening in PHP afterwards — so the officer got the
     * alphabetically-first 200 of whatever matched, and the rest were unreachable.
     * The officer-position and allowlist filters now sit in the WHERE, so the cap
     * counts printable rows.
     */
    public function testThePrintableFiltersArePushedIntoTheWhere(): void
    {
        $sql = $this->sql('event', '12250', 'kingdom', '4');

        // Scroll::IsOfficerPosition(): a resolved non-'none' role is excluded, but a
        // NULL must stay open — that is the dangling-archery shape.
        $this->assertStringContainsString("a.officer_role is null or a.officer_role = 'none'", $sql);
        // The ALLOWLIST, on the base award AND the alias. An `in`, not a `not in`:
        // that inversion is the whole change, and a regression to a denylist would
        // fail open on every award the catalogue grows.
        $this->assertStringContainsString('coalesce(a.award_id, 0) in (', $sql);
        $this->assertStringContainsString('coalesce(awards.alias_award_id, 0) in (0,', $sql);
        $this->assertStringNotContainsString('not in (', $sql);
        // Archery has no base award to key on, so it is admitted by NAME — and only
        // for a row whose base award resolved to nothing, exactly as the PHP does.
        $this->assertStringContainsString(
            "a.award_id is null and lower(trim(coalesce(ka.name, ''))) in (",
            $sql
        );
        $this->assertStringContainsString("'order of the sharpshooter'", $sql);
        // 11 ork_awards rows carry an alias, every one of them a Custom Title
        // pointing at a real title (Lord, Baronet, Countess, Paragon Anti-Paladin),
        // and the base AwardId does not see any of them.
        $this->assertStringContainsString(DB_PREFIX . 'award alias on alias.award_id = awards.alias_award_id', $sql);
    }

    /**
     * The id list in the query is Scroll::PrintableAwardIds(), derived from
     * scroll-orders.php — never a restatement. A restatement is how the bulk run
     * would silently keep printing something the domain had stopped printing.
     */
    public function testTheAdmittedIdListIsTheDomainConstantNotARestatement(): void
    {
        $sql = $this->sql('event', '17');

        // Every ladder with artwork, plus the two custom entries.
        foreach (Scroll::PrintableAwardIds() as $admitted) {
            $this->assertMatchesRegularExpression(
                '/[(,]' . $admitted . '[,)]/',
                $sql,
                "admitted award id {$admitted} is missing from the query"
            );
        }
        $this->assertContains(94, Scroll::PrintableAwardIds());
        $this->assertContains(246, Scroll::PrintableAwardIds());

        // Nothing else gets in. Lord (52) and Lady (53) used to survive the denylist
        // and no longer do; 31 is a real is_ladder award with no artwork; 17 is a
        // knighthood; 226/209 are the bare Master and Esquire the last round kept.
        foreach ([17, 1, 37, 13, 54, 147, 35, 52, 53, 31, 36, 207, 226, 209, 231] as $refused) {
            $this->assertNotContains($refused, Scroll::PrintableAwardIds(), "award {$refused} must not be admitted");
        }
    }

    /**
     * The dangling-award correction, pushed into SQL. A kingdomaward whose award_id
     * resolves to nothing is either a real archery grant (must print) or an officer
     * position whose base award went missing (must not), told apart by whether a
     * resolved sibling in the same catalog shares its name.
     */
    public function testOfficerNamesAreExcludedForDanglingRowsOnly(): void
    {
        $sql = $this->sql('event', '17', 'park', '198', '', '', ['sheriff' => true, 'champion' => true]);

        $this->assertStringContainsString("a.award_id is not null or coalesce(ka.name, '') not in ('sheriff', 'champion')", $sql);

        // With no name set the correction is left entirely to the PHP pass rather
        // than emitting a `not in ()`, which is a syntax error.
        $this->assertStringNotContainsString('not in ()', $this->sql('event', '17', 'park', '198'));
    }

    /**
     * mysql_real_escape_string() is a no-op shim, so an award name reaching the
     * statement is protected by quote-doubling and backslash removal, not escaping.
     */
    public function testOfficerNamesCannotCarryInjection(): void
    {
        $sql = $this->sql('event', '17', 'park', '198', '', '', ["o'brien') or (1=1" => true]);

        $this->assertStringContainsString("'o''brien'') or (1=1'", $sql);
        $this->assertStringNotContainsString("'o'brien'", $sql);
    }

    public function testTheCountQueryUsesTheExactSamePredicate(): void
    {
        $page  = ScrollQuery::bulkSqlForTest('event', '12250', 'kingdom', '4', '2024-01-01', '2024-06-30');
        $count = ScrollQuery::bulkCountSqlForTest('event', '12250', 'kingdom', '4', '2024-01-01', '2024-06-30');

        $this->assertStringStartsWith('select count(*) as total', $count);
        // Everything from FROM onwards is byte-identical, so the total can never be a
        // count of a different population than the page.
        $fromPage  = substr($page, strpos($page, 'from ' . DB_PREFIX . 'awards'));
        $fromPage  = substr($fromPage, 0, strpos($fromPage, 'order by'));
        $fromCount = substr($count, strpos($count, 'from ' . DB_PREFIX . 'awards'));
        $this->assertSame(trim($fromPage), trim($fromCount));
        // The count must not carry the page's cursor.
        $this->assertStringNotContainsString('limit', $count);
    }

    public function testACursorNarrowsTheQueryWithARowValueComparison(): void
    {
        $cursor = ScrollQuery::bulkCursorForTest([
            'Recipient' => 'Grym Blackwell III',
            'SortName'  => 'Custom Award',
            'Rank'      => 0,
            'AwardsId'  => 248671,
        ]);

        $sql = $this->sql('event', '12250', 'kingdom', '4', '', '', [], $cursor);

        $this->assertStringContainsString(
            "(coalesce(m.persona, ''), coalesce(a.name, ka.name, ''), awards.rank, awards.awards_id) > "
                . "('Grym Blackwell III', 'Custom Award', 0, 248671)",
            $sql
        );
    }

    public function testCursorsRoundTripAndRejectJunk(): void
    {
        $row = ['Recipient' => "O'Malley", 'SortName' => 'Order of the Rose', 'Rank' => 4, 'AwardsId' => 99];
        $decoded = ScrollQuery::decodeBulkCursorForTest(ScrollQuery::bulkCursorForTest($row));

        $this->assertSame("O'Malley", $decoded['Persona']);
        $this->assertSame('Order of the Rose', $decoded['Name']);
        $this->assertSame(4, $decoded['Rank']);
        $this->assertSame(99, $decoded['AwardsId']);

        $this->assertNull(ScrollQuery::decodeBulkCursorForTest(''));
        $this->assertNull(ScrollQuery::decodeBulkCursorForTest('not base64 at all !!'));
        $this->assertNull(ScrollQuery::decodeBulkCursorForTest(base64_encode('{"a":0}')));
        $this->assertNull(ScrollQuery::decodeBulkCursorForTest(base64_encode('[]')));
    }

    /**
     * A cursor is client-visible, so it has to survive being hostile — an injected
     * persona must land inside quotes, not outside them.
     */
    public function testAHostileCursorCannotEscapeItsQuotes(): void
    {
        $cursor = ScrollQuery::bulkCursorForTest([
            'Recipient' => "x' or '1'='1",
            'SortName'  => 'y',
            'Rank'      => 0,
            'AwardsId'  => 1,
        ]);

        $sql = $this->sql('event', '17', 'kingdom', '4', '', '', [], $cursor);

        $this->assertStringContainsString("'x'' or ''1''=''1'", $sql);
        $this->assertStringNotContainsString("'1'='1'", $sql);
    }

    /**
     * Scroll::MAX_PRINT_RUN stays the hard server-side guard: a caller asking for
     * more than the cap gets the cap, not what it asked for.
     */
    public function testTheServerCapCannotBeRaisedByACaller(): void
    {
        $this->assertStringEndsWith(
            'limit ' . (Scroll::MAX_PRINT_RUN + 1),
            $this->sql('event', '17', 'kingdom', '4', '', '', [], '', 5000)
        );
        $this->assertStringEndsWith(
            'limit 26',
            $this->sql('event', '17', 'kingdom', '4', '', '', [], '', Scroll::PRINT_CHUNK)
        );
    }

    /**
     * Scroll::IsCustomEligible() reads OfficerRole, and without this column every
     * officer position in a bulk selection would look custom-eligible — the query
     * has to select it for the eligibility check to mean anything on this path.
     */
    public function testTheBulkQuerySelectsOfficerRole(): void
    {
        $this->assertStringContainsString('a.officer_role', $this->sql('event', '17', 'park', '198'));
    }

    /**
     * Scroll::BuildScroll() reads IsLadder to decide whether a certificate prints the
     * blank ordinal somebody writes the level into — right for a ladder recorded at
     * rank 0 (292 of Walker in the Middle's 379 grants), wrong for a Knighthood. The
     * bulk selection shapes its rows for that contract, so it has to select the
     * column, exactly as it has to select officer_role for the eligibility check.
     *
     * Base `a.is_ladder`, not the alias-coalesced value AwardsForPlayer() uses: this
     * query has no `award alias` join. That is the same inconsistency officer_role
     * already lives with and is deliberately not fixed by adding one here.
     */
    public function testTheBulkQuerySelectsIsLadder(): void
    {
        $this->assertStringContainsString('a.is_ladder', $this->sql('event', '17', 'park', '198'));
    }

    /**
     * Item 42. model.Scrollery.php was the ONE file of the 33 under orkui/model/
     * that named DB_PREFIX or reached Ork3::$Lib->player->db — ten references. The
     * project rule is that DB work lives in system/lib/ork3/ and orkui/model/ is
     * the membrane, so the statements and their fetchers are ScrollQuery's now and
     * the model keeps the shaping.
     */
    public function testTheModelNoLongerBuildsOrRunsSql(): void
    {
        // Comments stripped: the model's own docblocks explain what moved and why,
        // and they name DB_PREFIX to say so. Only real code counts.
        $model = php_strip_whitespace(dirname(__DIR__, 2) . '/orkui/model/model.Scrollery.php');

        $this->assertStringNotContainsString('DB_PREFIX', $model);
        $this->assertStringNotContainsString('Ork3::$Lib->player->db', $model);
        $this->assertStringNotContainsString('->query(', $model);
        $this->assertStringNotContainsString('->DataSet(', $model);
    }

    /**
     * The $DB->Clear()-before-every-raw-query and manual ->next()-per-row shape had
     * to survive the move VERBATIM: stale PDO bindings cause silent failures, and
     * DataSet() returns null fields without the Next(). Three readers, three
     * Clear()s — and they stay separate because their field reads are hardcoded per
     * query shape, so a shared reader would silently read null rather than fail.
     */
    public function testTheDomainReadersStillClearBeforeEveryStatement(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/system/lib/ork3/class.ScrollQuery.php');

        $this->assertSame(3, substr_count($src, '$db->Clear();'));
        $this->assertSame(3, substr_count($src, '$db->query($sql);'));
        $this->assertSame(3, substr_count($src, '$db = $this->db;'));
        $this->assertStringContainsString('while ($rs->next())', $src);
    }

    /**
     * The cross-tenant fix (docs/scrollery-deferred.md §4): an event is not a
     * scope, so the gate sits on the AWARD's own at_park_id / at_kingdom_id. Pinned
     * again here after the move, because relocating a predicate is exactly how one
     * gets softened.
     */
    public function testTheScopePredicateSurvivedTheMoveOntoTheAwardRow(): void
    {
        $sql = $this->sql('event', '8462', 'kingdom', '9');

        $this->assertStringContainsString('awards.at_event_id = 8462', $sql);
        $this->assertStringContainsString('awards.at_kingdom_id = 9', $sql);
    }

    /**
     * Item 46, third part. A suspended or inactive recipient is MARKED, never
     * filtered — a suspended member's past award is still theirs — so the columns
     * come back on the row and nothing about them appears in the WHERE.
     */
    public function testRecipientStandingIsSelectedButNeverFiltered(): void
    {
        $sql = $this->sql('kingdom', '4', 'kingdom', '4');

        $this->assertStringContainsString('m.suspended as recipient_suspended', $sql);
        $this->assertStringContainsString('m.active as recipient_active', $sql);

        $where = substr($sql, (int) strpos($sql, ' where '));
        $this->assertStringNotContainsString('suspended', $where);
        $this->assertStringNotContainsString('m.active', $where);
    }
}
