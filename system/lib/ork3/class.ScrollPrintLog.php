<?php

/**
 * The insert-only record of "a certificate was printed".
 *
 * A separate domain class rather than more statics on Scroll: Scroll is a pure
 * resolution/rendering utility with no database of its own, and this is the one
 * part of Scrollery that writes. startup.php instantiates every class in
 * system/lib/ork3/, so this is reachable as Ork3::$Lib->scrollprintlog.
 *
 * Nothing here updates or deletes. A reprint is a new row; "last printed" is
 * max(printed_at). See db-migrations/2026-09-05-scroll-print-log.sql.
 */
class ScrollPrintLog extends Ork3
{
    /**
     * How long two prints of the same certificate by the same person are treated as one.
     *
     * Rows are written from the print beacon (ScrolleryAjax/log_print), at most once
     * per page load — but a heraldry reload, a second tab of the same run, or the
     * print dialog being cancelled and reopened after a reload would each log another
     * row and make "how many certificates has this kingdom printed" meaningless. Thirty
     * seconds is long enough to swallow those and short enough that a genuine
     * second printing — someone reprinting because the first came out wrong — is
     * still recorded.
     */
    public const DEDUPE_WINDOW_SECONDS = 30;

    /**
     * Which gate let a print through — the auth_basis column's whole vocabulary.
     * Anything else is stored as '' rather than echoed.
     */
    public const AUTH_BASES = ['recipient', 'park-officer', 'kingdom-officer', 'admin', 'court-manager', 'compose'];

    /**
     * Record one printed certificate. Returns the new scroll_print_id, or 0 when
     * nothing was written (a duplicate inside the window, or a bad argument).
     *
     * NEVER throws and never blocks the print: a logging failure must not stop an
     * officer getting their scrolls. Every failure path returns 0.
     *
     * @param array{
     *   AwardsId?: int|null,
     *   CourtId?: int|null,
     *   CourtAwardId?: int|null,
     *   AuthBasis?: string,
     *   MundaneId?: int,
     *   PrintedById?: int,
     *   ScopeType?: string,
     *   ScopeId?: int,
     *   OrderId?: string,
     *   Rank?: int,
     *   Variant?: string,
     *   Recipe?: array<string, mixed>|null
     * } $entry
     */
    public function RecordPrint(array $entry): int
    {
        return $this->RecordPrints([$entry])[0] ?? 0;
    }

    /**
     * Record a whole print run: ONE dedupe read for every printer in the run, then
     * one insert per certificate that is not a repeat. Returns the new
     * scroll_print_id per input key (0 where nothing was written).
     *
     * The dedupe key (see dedupeKey()):
     *   - an award row:              (printed_by_id, awards_id);
     *   - a court line with no award row yet: (printed_by_id, court_award_id);
     *   - anything else (a composed certificate): (printed_by_id, mundane_id,
     *     order_id, rank, scope_type, scope_id).
     * Entries repeated inside the same call collapse the same way.
     *
     * A court line that has already been finalized is logged against the award row
     * it became (court_award.award_id), so "last printed" for that award sees it.
     *
     * Same contract as RecordPrint(): never throws, never blocks a print.
     *
     * @param array<int|string, array<string, mixed>> $entries
     * @return array<int|string, int>
     */
    public function RecordPrints(array $entries): array
    {
        $out  = [];
        $rows = [];
        foreach ($entries as $k => $entry) {
            $out[$k] = 0;
            $row     = is_array($entry) ? self::normaliseEntry($entry) : null;
            if ($row !== null) {
                $rows[$k] = $row;
            }
        }
        if ($rows === []) {
            return $out;
        }

        $rows = $this->withFinalizedAwardsIds($rows);
        $seen = $this->recentKeys(array_values(array_unique(array_column($rows, 'printed_by_id'))));
        // PHP's clock, matching every other datetime column the ORK writes
        // (ork_awards.entered_at, ork_mundane.modified, ork_selfreg_link.created_at
        // are all date('Y-m-d H:i:s')). See recentKeys() for why the window is
        // computed the same way rather than with MySQL's NOW().
        $now = date('Y-m-d H:i:s');

        foreach ($rows as $k => $row) {
            $key = self::dedupeKey($row);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[$k]    = $this->insertRow($row, $now);
        }

        return $out;
    }

    /**
     * An entry as the column values it will be written with, or null when it cannot
     * be written at all (no printer). Nullable ids are null rather than 0.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|null
     */
    private static function normaliseEntry(array $entry): ?array
    {
        $printedById = (int) ($entry['PrintedById'] ?? 0);
        if ($printedById <= 0) {
            return null;
        }

        $nullableId = static function ($v): ?int {
            $v = (int) ($v ?? 0);

            return $v > 0 ? $v : null;
        };

        $recipe = $entry['Recipe'] ?? null;
        $basis  = (string) ($entry['AuthBasis'] ?? '');

        return [
            // Nullable on purpose: Scrollery/compose prints without an award row.
            'awards_id'      => $nullableId($entry['AwardsId'] ?? null),
            'court_id'       => $nullableId($entry['CourtId'] ?? null),
            'court_award_id' => $nullableId($entry['CourtAwardId'] ?? null),
            'auth_basis'     => in_array($basis, self::AUTH_BASES, true) ? $basis : '',
            'mundane_id'     => max(0, (int) ($entry['MundaneId'] ?? 0)),
            'printed_by_id'  => $printedById,
            'scope_type'     => in_array($entry['ScopeType'] ?? '', ['park', 'kingdom'], true)
                ? (string) $entry['ScopeType']
                : '',
            'scope_id'       => (int) ($entry['ScopeId'] ?? 0),
            'order_id'       => substr((string) ($entry['OrderId'] ?? ''), 0, 32),
            'rank'           => (int) ($entry['Rank'] ?? 0),
            'variant'        => substr((string) ($entry['Variant'] ?? ''), 0, 32),
            // yapo silently DROPS a null from an INSERT, so an absent recipe is stored
            // as the empty string rather than left off the statement.
            'recipe_json'    => is_array($recipe) ? (string) json_encode($recipe) : '',
        ];
    }

    /** @param array<string, mixed> $row a normaliseEntry() row, or a scroll_print row read back */
    private static function dedupeKey(array $row): string
    {
        $by = (int) $row['printed_by_id'];
        if ((int) ($row['awards_id'] ?? 0) > 0) {
            return 'a:' . $by . ':' . (int) $row['awards_id'];
        }
        if ((int) ($row['court_award_id'] ?? 0) > 0) {
            return 'c:' . $by . ':' . (int) $row['court_award_id'];
        }

        return 'n:' . implode('|', [
            $by,
            (int) ($row['mundane_id'] ?? 0),
            (string) ($row['order_id'] ?? ''),
            (int) ($row['rank'] ?? 0),
            (string) ($row['scope_type'] ?? ''),
            (int) ($row['scope_id'] ?? 0),
        ]);
    }

    /**
     * A court line that has been finalized already names its award row
     * (court_award.award_id); log against it. One read for the whole run.
     *
     * @param array<int|string, array<string, mixed>> $rows
     * @return array<int|string, array<string, mixed>>
     */
    private function withFinalizedAwardsIds(array $rows): array
    {
        $caIds = [];
        foreach ($rows as $row) {
            if ($row['awards_id'] === null && $row['court_award_id'] !== null) {
                $caIds[(int) $row['court_award_id']] = true;
            }
        }
        if ($caIds === []) {
            return $rows;
        }

        $awardsByLine = [];
        try {
            $this->db->Clear();
            $rs = $this->db->DataSet(
                'SELECT court_award_id, award_id FROM ' . DB_PREFIX . 'court_award
                  WHERE court_award_id IN (' . implode(',', array_keys($caIds)) . ')
                    AND award_id > 0'
            );
            if (is_object($rs) && $rs->Size() > 0) {
                while ($rs->Next()) {
                    $awardsByLine[(int) $rs->court_award_id] = (int) $rs->award_id;
                }
            }
        } catch (Throwable $e) {
            // A line that cannot be resolved is still logged, by its court line.
            return $rows;
        }

        foreach ($rows as $k => $row) {
            $caId = (int) ($row['court_award_id'] ?? 0);
            if ($row['awards_id'] === null && isset($awardsByLine[$caId])) {
                $rows[$k]['awards_id'] = $awardsByLine[$caId];
            }
        }

        return $rows;
    }

    /** One INSERT. Returns the new id, or 0 on any failure. */
    private function insertRow(array $values, string $printedAt): int
    {
        try {
            $row = new yapo($this->db, DB_PREFIX . 'scroll_print');
            $row->clear();
            foreach ($values as $column => $value) {
                // yapo drops a null from an INSERT anyway; leaving the nullable ids
                // off the statement is what makes them NULL rather than 0.
                if ($value !== null) {
                    $row->$column = $value;
                }
            }
            $row->printed_at = $printedAt;
            $row->save();

            // NOT $this->db->GetLastInsertId(): yapo's save() runs a SELECT to re-find
            // the row it just wrote, and PDO's mysql lastInsertId does not survive
            // that — it comes back 0. save() leaves the new key on the object, which
            // is the only reliable read.
            return (int) $row->scroll_print_id;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * The dedupe key of every row these printers wrote inside the window, as a set.
     * One read for a whole run.
     *
     * Read rather than a unique index: a unique key on (awards_id, printed_by_id)
     * would make a legitimate reprint a month later impossible, and lastInsertId()
     * is not a reliable duplicate-key signal in this codebase anyway (a duplicate
     * INSERT hands back the stale prior id).
     *
     * THE WINDOW IS COMPUTED FROM PHP'S CLOCK, NOT MySQL's NOW(). The two disagree
     * here by five hours — PHP runs in the configured local zone and the database
     * in UTC — so `printed_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND)` matched
     * nothing at all and every refresh logged a second row. Verified: a row written
     * at PHP-time 07:35:11 sat against a MySQL NOW() of 12:35:11. Since printed_at
     * is written with date(), the comparison has to use date() too.
     *
     * @param array<int, int> $printerIds
     * @return array<string, true>
     */
    private function recentKeys(array $printerIds): array
    {
        $printerIds = array_values(array_filter(array_map('intval', $printerIds), static fn (int $id): bool => $id > 0));
        if ($printerIds === []) {
            return [];
        }

        $since = date('Y-m-d H:i:s', time() - self::DEDUPE_WINDOW_SECONDS);
        $keys  = [];

        try {
            $this->db->Clear();
            $rs = $this->db->DataSet(
                'SELECT awards_id, court_award_id, printed_by_id, mundane_id, order_id, `rank`, scope_type, scope_id
                   FROM ' . DB_PREFIX . 'scroll_print
                  WHERE printed_by_id IN (' . implode(',', $printerIds) . ")
                    AND printed_at >= '" . $since . "'"
            );
            if (is_object($rs) && $rs->Size() > 0) {
                while ($rs->Next()) {
                    $keys[self::dedupeKey([
                        'awards_id'      => $rs->awards_id,
                        'court_award_id' => $rs->court_award_id,
                        'printed_by_id'  => $rs->printed_by_id,
                        'mundane_id'     => $rs->mundane_id,
                        'order_id'       => $rs->order_id,
                        'rank'           => $rs->rank,
                        'scope_type'     => $rs->scope_type,
                        'scope_id'       => $rs->scope_id,
                    ])] = true;
                }
            }
        } catch (Throwable $e) {
            // Fail OPEN on a read error: an unrecorded print is a worse outcome than
            // a duplicated one, and the table may simply not exist yet on an
            // environment where the migration has not run.
            return [];
        }

        return $keys;
    }

    /** Whether this exact (award, printer) pair was already logged inside the dedupe window. */
    private function printedRecently(int $awardsId, int $printedById): bool
    {
        return isset($this->recentKeys([$printedById])['a:' . $printedById . ':' . $awardsId]);
    }

    /**
     * When a given award's certificate was last printed, or '' if never.
     *
     * The read half the scribe's "not yet printed" filter and the Release Feature
     * Utilization report will both build on.
     */
    public function LastPrintedAt(int $awardsId): string
    {
        if ($awardsId <= 0) {
            return '';
        }

        try {
            $this->db->Clear();
            $rs = $this->db->DataSet(
                'SELECT MAX(printed_at) AS last_printed FROM ' . DB_PREFIX . 'scroll_print
                  WHERE awards_id = ' . $awardsId
            );
            if (!is_object($rs) || $rs->Size() < 1 || !$rs->Next()) {
                return '';
            }

            return (string) ($rs->last_printed ?? '');
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * When each of these Court Planner lines was last printed, in one read:
     * court_award_id => 'Y-m-d H:i:s'. A line never printed is absent from the map.
     *
     * @param array<int, mixed> $courtAwardIds
     * @return array<int, string>
     */
    public function LastPrintedForCourtAwards(array $courtAwardIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $courtAwardIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        $out = [];
        try {
            $this->db->Clear();
            $rs = $this->db->DataSet(
                'SELECT court_award_id, MAX(printed_at) AS last_printed FROM ' . DB_PREFIX . 'scroll_print
                  WHERE court_award_id IN (' . implode(',', $ids) . ')
                  GROUP BY court_award_id'
            );
            if (is_object($rs) && $rs->Size() > 0) {
                while ($rs->Next()) {
                    $out[(int) $rs->court_award_id] = (string) $rs->last_printed;
                }
            }
        } catch (Throwable $e) {
            return [];
        }

        return $out;
    }
}
