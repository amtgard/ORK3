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
     * How long two prints of the same award by the same person are treated as one.
     *
     * Scrollery/render is a GET. A refresh, a browser prefetch, a back-button, or
     * the print dialog being cancelled and reopened would each log another row and
     * make "how many certificates has this kingdom printed" meaningless. Thirty
     * seconds is long enough to swallow those and short enough that a genuine
     * second printing — someone reprinting because the first came out wrong — is
     * still recorded.
     */
    public const DEDUPE_WINDOW_SECONDS = 30;

    /**
     * Record one printed certificate. Returns the new scroll_print_id, or 0 when
     * nothing was written (a duplicate inside the window, or a bad argument).
     *
     * NEVER throws and never blocks the print: a logging failure must not stop an
     * officer getting their scrolls. Every failure path returns 0.
     *
     * @param array{
     *   AwardsId?: int|null,
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
        $printedById = (int) ($entry['PrintedById'] ?? 0);
        if ($printedById <= 0) {
            return 0;
        }

        // Nullable on purpose: Scrollery/compose prints without an award row.
        $awardsId = isset($entry['AwardsId']) ? (int) $entry['AwardsId'] : 0;
        $awardsId = $awardsId > 0 ? $awardsId : null;

        if ($awardsId !== null && $this->printedRecently($awardsId, $printedById)) {
            return 0;
        }

        $scopeType = in_array($entry['ScopeType'] ?? '', ['park', 'kingdom'], true)
            ? (string) $entry['ScopeType']
            : '';

        $recipe = $entry['Recipe'] ?? null;
        // yapo silently DROPS a null from an INSERT, so an absent recipe is stored
        // as the empty string rather than left off the statement.
        $recipeJson = is_array($recipe) ? (string) json_encode($recipe) : '';

        try {
            $row = new yapo($this->db, DB_PREFIX . 'scroll_print');
            $row->clear();
            if ($awardsId !== null) {
                $row->awards_id = $awardsId;
            }
            $row->mundane_id    = (int) ($entry['MundaneId'] ?? 0);
            $row->printed_by_id = $printedById;
            $row->scope_type    = $scopeType;
            $row->scope_id      = (int) ($entry['ScopeId'] ?? 0);
            $row->order_id      = substr((string) ($entry['OrderId'] ?? ''), 0, 32);
            $row->rank          = (int) ($entry['Rank'] ?? 0);
            $row->variant       = substr((string) ($entry['Variant'] ?? ''), 0, 32);
            $row->recipe_json   = $recipeJson;
            // PHP's clock, matching every other datetime column the ORK writes
            // (ork_awards.entered_at, ork_mundane.modified, ork_selfreg_link.created_at
            // are all date('Y-m-d H:i:s')). See printedRecently() for why the window is
            // computed the same way rather than with MySQL's NOW().
            $row->printed_at    = date('Y-m-d H:i:s');
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
     * Whether this exact (award, printer) pair was already logged inside the dedupe
     * window.
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
     * is written with date() above, the comparison has to use date() too.
     */
    private function printedRecently(int $awardsId, int $printedById): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::DEDUPE_WINDOW_SECONDS);

        try {
            $this->db->Clear();
            $rs = $this->db->DataSet(
                'SELECT scroll_print_id FROM ' . DB_PREFIX . 'scroll_print
                  WHERE awards_id = ' . $awardsId . '
                    AND printed_by_id = ' . $printedById . "
                    AND printed_at >= '" . $since . "'
                  LIMIT 1"
            );

            return is_object($rs) && $rs->Size() > 0;
        } catch (Throwable $e) {
            // Fail OPEN on a read error: an unrecorded print is a worse outcome than
            // a duplicated one, and the table may simply not exist yet on an
            // environment where the migration has not run.
            return false;
        }
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
}
