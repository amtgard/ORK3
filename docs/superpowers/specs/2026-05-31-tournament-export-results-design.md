# Tournament Export Results — Design

**Date:** 2026-05-31
**Status:** Approved (design)
**Scope:** One tournament (the one viewed on the Standings page), multi-bracket workbook.

## Overview

Add an **Export Results** button to the Tournament Standings page
(`Tournament/profile/{id}`). Clicking it downloads a single `.xlsx` workbook for
that tournament containing:

1. A **Summary & Standings** tab (first).
2. **One tab per bracket**, with column layout and formatting chosen by the
   bracket's method (single/double elimination, round-robin, swiss, ironman,
   points/judge's score).

The result is a polished, human-readable report — not a raw data dump.

## Key decisions

- **Export scope:** the single tournament currently being viewed (one workbook
  per tournament; one tab per bracket within it).
- **XLSX engine:** vendor a single-file, zero-dependency writer
  (`mk-j/php_xlsxwriter`, MIT). The repo has no Composer / `vendor/`, so this is
  consistent with the codebase — a plain `require_once`. It supports multiple
  worksheets, bold/fills/borders, number + date formats, column widths, freeze
  panes, and auto-filter.
- **Access:** public — matches the Standings page, which is itself a public view.
- **Round-robin cross-table:** included as a second section on RR tabs.

## Architecture & files

1. **Vendored writer** — `system/lib/vendor/XLSXWriter.php`
   (single MIT file, `require_once`, no Composer).

2. **Builder** — new `system/lib/ork3/class.TournamentExport.php`.
   - Public method `BuildWorkbook($tournament_id)`.
   - Gathers data via existing methods: `Tournament::GetBrackets`,
     `GetParticipants`, `GetMatches`, `GetStandings`, `GetPointStandings`, and
     `TournamentReport::GetBracketPlacements`.
   - Composes the workbook with the vendored writer, writes to a temp file,
     returns the temp file path.
   - Keeps all DB/data logic in `system/lib/ork3/` per the architecture rule.

3. **Model pass-through** — `model.Tournament.php` gains a thin
   `export_workbook($id)` forwarding to the builder (consistent with existing
   `get_standings`, `get_point_standings`, etc.).

4. **Controller endpoint** — `controller.Tournament.php` → `export($tournament_id)`.
   - Validate id (same `valid_id` + regex sanitize as `profile()`).
   - Call the model; on success stream the file:
     `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`,
     `Content-Disposition: attachment; filename="..."`,
     `Cache-Control: no-cache, must-revalidate`, then `readfile()` + `exit()`.
   - Mirrors the existing `.ics` download pattern in `controller.Kingdom.php`
     (lines ~700–747).
   - Clean up the temp file after streaming.

5. **Route** — `Tournament/export/{id}`, plain GET link (no AJAX; the browser
   handles the download).

6. **Button** — in `Tournametnew_index.tpl` near the standings header (~line
   2721), styled with existing `.tn-btn` classes, dark-mode compatible.

**Filename:** `{Kingdom-or-Park}-{Tournament}-results.xlsx`, sanitized with
`preg_replace('/[^a-z0-9]/i', '-', ...)` like the ICS export.

## Tab 1 — Summary & Standings

- **Header block:** Tournament name, date (formatted, not raw ISO), Kingdom/Park,
  Event name, totals (brackets / participants / matches).
- **Bracket roster table:** one row per bracket —
  Bracket (style label) | Format (friendly method name) | Type
  (Individual/Team) | Participants | Matches | Status | Champion | 2nd | 3rd.
  - Champion/2nd/3rd from `GetBracketPlacements`.
  - Podium cells get gold/silver/bronze fills.

## Per-bracket tabs (column layout by method)

- Sheet name = bracket style/label, ≤31 chars, sanitized + de-duplicated
  (Excel sheet-name constraints).
- Common formatting: bold filled frozen header row, auto-filter, sized columns,
  podium rows (rank 1–3) tinted.
- Team brackets: team alias in the competitor column + a Members column.

Canonical bracket methods (from `class.Tournament.php`): `single`, `double`,
`swiss`, `round-robin`, `ironman`, `points`.

| Method | Tab contents |
|---|---|
| **single / double** (elimination) | Podium block (1/2/3) → Final standings (Rank, Competitor, Park, W, L, Byes, Points) → Bracket results (Round, Side [Winners/Losers/Grand Final], Competitor 1, Competitor 2, Winner). |
| **round-robin** | Standings (Rank, Competitor, Park, W, L, T, Byes, Points) → results cross-table (competitors on both axes, cell = W/L/T). |
| **swiss** | Standings (Rank, Competitor, Park, W, L, T, Points) → round-by-round results list. |
| **ironman** | Standings (Rank, Competitor, Park, Wins, Max Streak, Current Streak, Points). |
| **points** (Judge's Score) | Place, Competitor, Round 1…N scores, Total (number format), Tied flag. |

### Data shapes (confirmed)

- `GetStandings($bracket)` → rows with `Rank, Alias, ParkName, Wins, Losses,
  Ties, Byes, Points, IsTeam, Members[], MaxStreak, CurrentStreak` (ironman adds
  streaks; routes to point standings when method is `points`).
- `GetPointStandings($bracket)` → rows with `Alias, RoundScores[], Total, Place,
  Tied, Status`.
- `GetBracketPlacements($bracket)` → `{ Method, Placements:[{Place, Alias,
  MundaneId, ...}] }` (podium 1/2/3).

## Edge cases

- Invalid/missing tournament → graceful error (redirect or plain message).
- No brackets → Summary tab only, with a note.
- Bracket with no matches → tab renders the participant list + "No matches
  recorded yet."

## Verification

No test framework in the repo, so:

1. `curl` the endpoint for a seeded tournament; `unzip -l` the result to confirm
   one sheet per bracket + the summary sheet.
2. Open in a spreadsheet app to eyeball formatting.
3. Browser-verify the button (per the Chrome-usage rule: verify after
   implementation).
4. Cover all methods: single, double, round-robin, swiss, ironman, points, plus
   a team bracket.
