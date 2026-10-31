<?php

/**
 * Every SQL statement Scrollery owns, and the readers that run them.
 *
 * WHY THIS CLASS EXISTS. `model.Scrollery.php` was the only file of the 33 under
 * `orkui/model/` that named DB_PREFIX or reached `Ork3::$Lib->player->db` — ten
 * references between them. The project rule is that DB work lives in
 * `system/lib/ork3/` and `orkui/model/` is the membrane, so the statements and
 * their fetchers moved here and the model kept the shaping loops.
 *
 * WHY NOT class.Scroll.php. Scroll is a pure resolution/rendering utility with no
 * database of its own — ScrollPrintLog's own docblock says so, and that is why the
 * insert-only print log is a separate class rather than more statics on Scroll.
 * Scroll is also already 1,200 lines. ScrollQuery is the READ half of the pair
 * ScrollPrintLog is the WRITE half of, and both extend Ork3 for `$this->db`.
 *
 * WHAT DID NOT CHANGE, deliberately:
 *
 * - The `$DB->Clear()`-before-every-raw-query and manual-`->next()`-per-row shape
 *   is verbatim. Stale PDO bindings cause silent failures and DataSet() returns
 *   null fields without the Next().
 * - The scope predicate sits on `awards.at_park_id` / `awards.at_kingdom_id`, not
 *   on the event — that is the fix for a real cross-tenant disclosure
 *   (docs/scrollery-deferred.md §4) and moving the code must not soften it.
 * - The three readers stay separate (count_query / event_rows / db_query) rather
 *   than being merged: their field reads are hardcoded per query shape, so a shared
 *   reader would silently return null for every column instead of failing.
 *
 * startup.php instantiates every class in system/lib/ork3/, so this is reachable
 * as Ork3::$Lib->scrollquery.
 */
class ScrollQuery extends Ork3
{
    /**
     * The sort key, as SQL expressions, in order.
     *
     * m.persona and a.name are both nullable — a recipient row can be missing and a
     * dangling kingdomaward has no base award — and a NULL anywhere inside a
     * row-value comparison makes the whole comparison NULL, which silently drops the
     * row from a keyset page. COALESCE keeps both the ORDER BY and the cursor
     * predicate total.
     *
     * awards.awards_id is the tiebreaker and is what makes the sort a TOTAL order:
     * m.persona is not unique (a player has many awards, and two players can share a
     * persona), so without it a keyset cursor would either skip rows or loop.
     */
    private const BULK_SORT = [
        "coalesce(m.persona, '')",
        "coalesce(a.name, ka.name, '')",
        'awards.rank',
        'awards.awards_id',
    ];

    /**
     * The FROM and WHERE shared by the page query and the count query, so the total
     * can never be a count of a different population than the page.
     *
     * $officerNames is the granting kingdom's own set of resolved officer-position
     * names, from Model_Award::officer_names_for_kingdom(). It exists to push the
     * dangling-award correction into SQL: a kingdomaward whose award_id resolves to
     * nothing is either a real archery grant (must print) or an officer position
     * whose base award has gone missing (must not), and the only thing telling them
     * apart is whether a resolved sibling in the same catalog carries the same name.
     * Passing [] leaves that correction to the PHP pass, which still runs.
     *
     * The surrounding code interpolates rather than binds, and this codebase's
     * mysql_real_escape_string() is a no-op shim, so what protects this is the (int)
     * cast on every id, the ISO-date regex, and sqlString() on the two places a real
     * string reaches the statement — not escaping.
     *
     * @param array<string, true>|list<string> $officerNames
     */
    private static function bulkFromWhere(
        string $type,
        string $id,
        string $scopeType,
        string $scopeId,
        string $from,
        string $to,
        array $officerNames,
        string $cursor
    ): string {
        $id      = (int) $id;
        $scopeId = (int) $scopeId;

        switch ($type) {
            case 'event':
                // The scope gate belongs on the AWARD row, not on the event. An event
                // does not reliably carry the granting body: 974 award rows sit at an
                // event whose kingdom_id differs from the award's own at_kingdom_id,
                // and single events (8462, 7, 15975, 14761, 11193) carry awards from
                // three kingdoms at once. Filtering on awards.at_park_id /
                // at_kingdom_id both closes the cross-body disclosure — the other
                // kingdoms' rows at a shared event are no longer returned — and keeps
                // the legitimate case working, where this body's own awards were
                // granted at somebody else's event.
                $scope = 'awards.at_event_id = ' . $id
                       . ($scopeType === 'kingdom'
                           ? ' and awards.at_kingdom_id = ' . $scopeId
                           : ' and awards.at_park_id = ' . $scopeId);
                break;
            case 'kingdom':
                $scope = 'awards.at_kingdom_id = ' . $id;
                break;
            case 'park':
                $scope = 'awards.at_park_id = ' . $id;
                break;
            default:
                // Never widen to "everything" on an unrecognised scope.
                $scope = 'awards.at_park_id = 0';
                break;
        }

        $range = '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $range .= " and awards.date >= '" . $from . "'";
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $range .= " and awards.date <= '" . $to . "'";
        }

        // The printable filter, in SQL rather than after the LIMIT. This is the whole
        // point of the rewrite: the cap used to be applied to RAW rows and the
        // printable/excluded split happened in PHP afterwards, so an officer asking
        // for event 12250 in kingdom 4 (495 rows, every one printable) got the
        // alphabetically-first 200 and could not reach the other 295 by any
        // narrowing — the date filter's finest grain is one day and that event is one
        // day. 24 event/kingdom pairs exceed the cap; the largest is 741.
        $printable = "\n                   and awards.revoked = 0"
            // A revoked grant normally has its mundane_id nulled, which would print a
            // blank recipient; a bulk run must not offer those even before the
            // revoked filter above catches them.
            . "\n                   and awards.mundane_id > 0"
            // Scroll::IsOfficerPosition(): a resolved officer_role that is neither
            // NULL nor 'none' is a job someone held, not an honour. NULL stays open —
            // that is the dangling-archery shape.
            . "\n                   and (a.officer_role is null or a.officer_role = 'none')"
            // Scroll::OrderForAwardRow()'s ALLOWLIST, in SQL. It has to be here rather
            // than in the PHP pass below because it runs BEFORE the LIMIT: filtering
            // afterwards is what made a 495-row court silently become its
            // alphabetically-first 200, and it would make Total a lie again.
            //
            // Three branches, matching the three returns in OrderForAwardRow():
            // a real art-backed order or a custom entry by id; archery by
            // kingdomaward name, confined (as the PHP is) to rows with no resolvable
            // base award; and the alias, which re-points the grant at a different
            // catalogue entry and must itself be admitted — all 11 of the ORK's
            // alias rows sit on Custom Title and point at a real title.
            . "\n                   and (coalesce(a.award_id, 0) in (" . implode(',', Scroll::PrintableAwardIds()) . ')'
            . "\n                        or (a.award_id is null and lower(trim(coalesce(ka.name, ''))) in ("
                . self::sqlStringList(Scroll::ARCHERY_NAMES) . ')))'
            . "\n                   and coalesce(awards.alias_award_id, 0) in (0,"
                . implode(',', Scroll::PrintableAwardIds()) . ')';

        $names = self::officerNameList($officerNames);
        if ($names !== '') {
            $printable .= "\n                   and (a.award_id is not null or coalesce(ka.name, '') not in (" . $names . '))';
        }

        $keyset = '';
        $after  = self::decodeBulkCursorForTest($cursor);
        if ($after !== null) {
            $keyset = "\n                   and (" . implode(', ', self::BULK_SORT) . ') > ('
                . self::sqlString($after['Persona']) . ', '
                . self::sqlString($after['Name']) . ', '
                . (int) $after['Rank'] . ', '
                . (int) $after['AwardsId'] . ')';
        }

        return 'from ' . DB_PREFIX . 'awards awards
                  left join ' . DB_PREFIX . 'kingdomaward ka on ka.kingdomaward_id = awards.kingdomaward_id
                  left join ' . DB_PREFIX . 'award a on a.award_id = ka.award_id
                  left join ' . DB_PREFIX . 'award alias on alias.award_id = awards.alias_award_id
                  left join ' . DB_PREFIX . 'mundane m on m.mundane_id = awards.mundane_id
                  left join ' . DB_PREFIX . 'mundane gb on gb.mundane_id = awards.given_by_id
                  left join ' . DB_PREFIX . 'park p on p.park_id = awards.at_park_id
                  left join ' . DB_PREFIX . 'kingdom k on k.kingdom_id = awards.at_kingdom_id
                 where ' . $scope . $range . $printable . $keyset;
    }

    /**
     * The bulk selection — the module's ONE new query. Everything else reuses
     * Player::AwardsForPlayer().
     *
     * Public and named ...ForTest so ScrolleryBulkQueryTest can assert on the
     * generated statement without reflection and without a database. It builds a
     * string and touches nothing.
     *
     * @param array<string, true>|list<string> $officerNames see bulkFromWhere()
     * @param string $cursor an opaque keyset cursor from bulkCursorForTest()
     */
    public static function bulkSqlForTest(
        string $type,
        string $id,
        string $scopeType,
        string $scopeId,
        string $from = '',
        string $to = '',
        array $officerNames = [],
        string $cursor = '',
        int $limit = 0
    ): string {
        $limit = $limit > 0 ? min($limit, Scroll::MAX_PRINT_RUN) : Scroll::MAX_PRINT_RUN;

        // The alias join exists for the exclusion predicate above; alias.award_id is
        // selected as well so the PHP-side Scroll::IsExcludedFromScrollery() check on
        // the shaped row sees the same thing the WHERE did. It is deliberately NOT
        // coalesced into officer_role / is_ladder / the title — that inconsistency
        // with AwardsForPlayer() predates this change and is not fixed here.
        return 'select awards.awards_id, awards.mundane_id, awards.rank, awards.date, awards.note,
                       awards.custom_name, awards.at_park_id, awards.at_kingdom_id, awards.at_event_id,
                       a.award_id, a.name, a.officer_role, a.is_ladder,
                       alias.award_id as alias_award_id,
                       ka.name as kingdom_awardname,
                       m.persona as recipient,
                       -- Carried so a bulk list can MARK a suspended or inactive
                       -- recipient rather than an officer discovering it after
                       -- printing. Deliberately NOT part of the WHERE: a past award
                       -- held by a suspended member is still theirs, so those rows
                       -- stay selected by default. Both columns are NOT NULL in
                       -- ork_mundane, but the join is a LEFT one and a revoked grant
                       -- can point at nothing, so the shaping side coalesces.
                       m.suspended as recipient_suspended,
                       m.active as recipient_active,
                       gb.persona as given_by,
                       p.name as park_name, k.name as kingdom_name
                  ' . self::bulkFromWhere($type, $id, $scopeType, $scopeId, $from, $to, $officerNames, $cursor) . '
                 order by ' . implode(', ', self::BULK_SORT) . '
                 limit ' . ($limit + 1);
    }

    /**
     * The TRUE number of printable rows the filter matches, so the page can say
     * "200 of 495" rather than quietly hand back 200 and call it the answer.
     *
     * Same predicate as the page query with the cursor dropped — a count of the whole
     * filter, not of the current page.
     *
     * @param array<string, true>|list<string> $officerNames see bulkFromWhere()
     */
    public static function bulkCountSqlForTest(
        string $type,
        string $id,
        string $scopeType,
        string $scopeId,
        string $from = '',
        string $to = '',
        array $officerNames = []
    ): string {
        return 'select count(*) as total
                  ' . self::bulkFromWhere($type, $id, $scopeType, $scopeId, $from, $to, $officerNames, '');
    }

    /**
     * The cursor for "everything after this row", built from a shaped bulk row.
     *
     * Opaque and base64'd so nothing downstream is tempted to parse or hand-edit it,
     * but its contents are re-validated on decode rather than trusted: the two string
     * members go through sqlString() and the two ids through (int).
     *
     * @param array<string, mixed> $row a row from awards_for_bulk()
     */
    public static function bulkCursorForTest(array $row): string
    {
        return base64_encode((string) json_encode([
            'p' => (string) ($row['Recipient'] ?? ''),
            'n' => (string) ($row['SortName'] ?? ''),
            'r' => (int) ($row['Rank'] ?? 0),
            'a' => (int) ($row['AwardsId'] ?? 0),
        ]));
    }

    /**
     * @return array{Persona: string, Name: string, Rank: int, AwardsId: int}|null
     */
    public static function decodeBulkCursorForTest(string $cursor): ?array
    {
        if (trim($cursor) === '') {
            return null;
        }

        $json = base64_decode(strtr($cursor, ' ', '+'), true);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || (int) ($data['a'] ?? 0) <= 0) {
            return null;
        }

        return [
            'Persona'  => (string) ($data['p'] ?? ''),
            'Name'     => (string) ($data['n'] ?? ''),
            'Rank'     => (int) ($data['r'] ?? 0),
            'AwardsId' => (int) ($data['a'] ?? 0),
        ];
    }

    /**
     * A quoted, comma-separated SQL list of officer-position names, or ''.
     *
     * Accepts either officer_names_for_kingdom()'s name => true map or a plain list.
     *
     * @param array<string, true>|list<string> $officerNames
     */
    private static function officerNameList(array $officerNames): string
    {
        $quoted = [];
        foreach ($officerNames as $key => $value) {
            $name = is_string($key) ? $key : (string) $value;
            $name = trim($name);
            if ($name !== '') {
                $quoted[self::sqlString($name)] = true;
            }
        }

        return implode(', ', array_keys($quoted));
    }

    /**
     * A single-quoted SQL string literal.
     *
     * mysql_real_escape_string() is a NO-OP SHIM in this codebase, so it is not used.
     * Doubling the quote is standard SQL and holds whether or not NO_BACKSLASH_ESCAPES
     * is set; the backslash and control characters are removed outright rather than
     * escaped, because nothing that legitimately reaches here — a persona, an award
     * name — contains them.
     */
    private static function sqlString(string $value): string
    {
        $value = str_replace(["\\", "\0", "\n", "\r", "\x1a"], '', $value);

        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * A comma-separated list of SQL string literals, for an IN () clause.
     *
     * @param list<string> $values
     */
    private static function sqlStringList(array $values): string
    {
        return implode(', ', array_map(static fn (string $v): string => self::sqlString($v), $values));
    }

    /**
     * One page of raw bulk-print rows, or null when the query itself failed.
     *
     * Raw, not shaped: Model_Scrollery::awards_for_bulk() owns the row shape
     * (ResolveTitle, the dangling-officer correction, HasArt bucketing) because
     * that is presentation of domain facts, not a database concern.
     *
     * Asks for $limit + 1 rows so the caller can detect that another page exists
     * without a second count.
     *
     * @param array<string, true>|list<string> $officerNames see bulkFromWhere()
     * @return array<int, array<string, mixed>>|null
     */
    public function BulkAwardRows(
        string $type,
        int $id,
        string $scopeType,
        int $scopeId,
        string $from = '',
        string $to = '',
        array $officerNames = [],
        string $cursor = '',
        int $limit = 0
    ): ?array {
        return $this->db_query(self::bulkSqlForTest(
            $type,
            (string) $id,
            $scopeType,
            (string) $scopeId,
            $from,
            $to,
            $officerNames,
            $cursor,
            $limit
        ));
    }

    /**
     * The TRUE number of printable rows the same filter matches, so a page can say
     * "200 of 495" rather than quietly hand back 200 and call it the answer.
     *
     * @param array<string, true>|list<string> $officerNames see bulkFromWhere()
     */
    public function BulkAwardCount(
        string $type,
        int $id,
        string $scopeType,
        int $scopeId,
        string $from = '',
        string $to = '',
        array $officerNames = []
    ): int {
        return $this->count_query(
            self::bulkCountSqlForTest($type, (string) $id, $scopeType, (string) $scopeId, $from, $to, $officerNames)
        );
    }

    /**
     * Raw rows for the bulk-print event picker, or null on a query failure.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function EventsWithPrintableAwards(string $scopeType, int $scopeId): ?array
    {
        if ($scopeId <= 0) {
            return [];
        }

        return $this->event_rows(self::eventsWithPrintableAwardsSqlForTest($scopeType, (string) $scopeId));
    }

    /**
     * A single scalar `total` from a count query, or 0.
     *
     * A sibling to db_query()/event_rows() for the same reason those are siblings of
     * each other: the field reads are hardcoded per query shape, so a shared reader
     * would silently return null rather than fail.
     */
    private function count_query(string $sql): int
    {
        $db = $this->db;
        $db->Clear();

        try {
            $rs = $db->query($sql);
        } catch (Throwable $e) {
            return 0;
        }

        if (!is_object($rs) || $this->result_failed($rs) || $rs->size() < 1 || !$rs->next()) {
            return 0;
        }

        return (int) $rs->total;
    }

    /**
     * Public and named ...SqlForTest() for the same reason bulkSqlForTest() is:
     * ScrolleryEventsQueryTest can assert on the generated statement with no
     * database. The surrounding code interpolates rather than binds, and this
     * codebase's mysql_real_escape_string() is a no-op shim, so what protects this
     * is the (int) cast on the scope id — never interpolate it as a string.
     *
     * ork_event carries no date of its own (see ork.sql) — only `event_id`,
     * `kingdom_id`, `park_id`, `mundane_id`, `unit_id`, `name`, `has_heraldry` —
     * dates live one join away on ork_event_calendardetail, which is one-to-many
     * per event (multiple dated occurrences, gated by a `current` flag) and would
     * turn this group-by into a fan-out. max(awards.date) avoids that entirely and
     * is the date this scope's officer actually granted things on.
     */
    public static function eventsWithPrintableAwardsSqlForTest(string $scopeType, string $scopeId): string
    {
        $scopeId = (int) $scopeId;

        $scope = ($scopeId > 0 && $scopeType === 'kingdom')
            ? 'awards.at_kingdom_id = ' . $scopeId
            : (($scopeId > 0 && $scopeType === 'park')
                ? 'awards.at_park_id = ' . $scopeId
                // Never widen to "everything" for an unrecognised scope type or an
                // empty/zero scope id — mirrors bulkSqlForTest()'s default: case.
                // at_kingdom_id and at_park_id each sit at 0 on a huge share of real
                // award rows (a park grant never sets at_kingdom_id; a kingdom grant
                // never sets at_park_id), so a bare "column = 0" built from an
                // untrusted or absent scope id would hand back one whole class of
                // body's awards rather than none. -1 can never match a real row.
                : 'awards.at_park_id = -1');

        return 'select awards.at_event_id as event_id,
                       count(*) as award_count,
                       max(awards.date) as last_date,
                       e.name as event_name
                  from ' . DB_PREFIX . 'awards awards
                  left join ' . DB_PREFIX . 'event e on e.event_id = awards.at_event_id
                 where awards.at_event_id > 0
                   and ' . $scope . '
                   -- Same two guards the bulk query applies. Without them an event
                   -- whose only awards were revoked still appears in the picker with
                   -- a count, and choosing it returns nothing — 2,287 revoked rows
                   -- carry a park/kingdom scope, so this is not hypothetical.
                   and awards.revoked = 0
                   and awards.mundane_id > 0
                 group by awards.at_event_id
                 order by last_date desc
                 limit 200';
    }

    /**
     * Rows for events_with_printable_awards(), or null on a query failure.
     *
     * A sibling to db_query() above rather than a reuse of it: db_query()'s field
     * reads are hardcoded to the bulk row's own column set (awards_id, mundane_id,
     * ...), so feeding it this query's different columns (event_id, award_count,
     * ...) would silently read null for every field rather than fail loudly. The
     * $DB->Clear()-before-query and manual-Next()-per-row shape is identical.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function event_rows(string $sql): ?array
    {
        $db = $this->db;
        $db->Clear();

        try {
            $rs = $db->query($sql);
        } catch (Throwable $e) {
            return null;
        }

        if (!is_object($rs) || $this->result_failed($rs)) {
            return null;
        }

        $rows = [];
        if ($rs->size() > 0) {
            while ($rs->next()) {
                $rows[] = [
                    'event_id'    => $rs->event_id,
                    'award_count' => $rs->award_count,
                    'last_date'   => $rs->last_date,
                    'event_name'  => $rs->event_name,
                ];
            }
        }

        return $rows;
    }

    /**
     * Rows, or null when the query itself failed.
     *
     * Null rather than [] on purpose: an empty array here used to mean both "this
     * event had no awards" and "the database errored", and the officer read the
     * second as the first.
     *
     * A false return is NOT the failure signal — YapoDb::Query() delegates to
     * DataSet(), which always hands back a YapoResultSet, and PDO runs in
     * ERRMODE_WARNING so a bad statement neither returns false nor throws (the
     * comment above YapoMysql::ExecuteChecked() says as much). The real signals are
     * the SQLSTATE the result set captures at construction, and — when prepare()
     * itself returns false — the Error raised inside DataSet() by calling
     * execute() on a bool.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function db_query(string $sql): ?array
    {
        $db = $this->db;
        $db->Clear();

        try {
            $rs = $db->query($sql);
        } catch (Throwable $e) {
            return null;
        }

        if (!is_object($rs) || $this->result_failed($rs)) {
            return null;
        }

        $rows = [];
        if ($rs->size() > 0) {
            while ($rs->next()) {
                $rows[] = [
                    'awards_id'         => $rs->awards_id,
                    'award_id'          => $rs->award_id,
                    'alias_award_id'    => $rs->alias_award_id,
                    'mundane_id'        => $rs->mundane_id,
                    'rank'              => $rs->rank,
                    'date'              => $rs->date,
                    'note'              => $rs->note,
                    'custom_name'       => $rs->custom_name,
                    'recipient'         => $rs->recipient,
                    'recipient_suspended' => $rs->recipient_suspended,
                    'recipient_active'  => $rs->recipient_active,
                    'given_by'          => $rs->given_by,
                    'name'              => $rs->name,
                    'officer_role'      => $rs->officer_role,
                    'is_ladder'         => $rs->is_ladder,
                    'kingdom_awardname' => $rs->kingdom_awardname,
                    'park_name'         => $rs->park_name,
                    'kingdom_name'      => $rs->kingdom_name,
                ];
            }
        }

        return $rows;
    }

    /**
     * Whether the statement behind a result set actually failed.
     *
     * YapoResultSet records the statement's SQLSTATE and errorInfo in __ERROR the
     * moment it is built. '00000' is success; anything else is a real error that
     * would otherwise read as "no awards matched".
     *
     * @param object $rs
     */
    private function result_failed($rs): bool
    {
        $state = $rs->__ERROR[0][1] ?? null;

        return $state !== null && $state !== '' && $state !== '00000';
    }
}
