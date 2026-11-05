# Officer Rolls: a standalone editor and report for officer history

Date: 2026-11-04 · Branch: `feature/officer-admin-expansion`

## Intent

Officers are about to backfill decades of officer history from outside sources, mostly
Amtwiki. Crystal Groves has had 39 officer cycles, and some kingdoms have had about 80,
which is roughly 200–400 terms per kingdom across its offices. Today the only place to
enter them is the **Correct the Rolls** tab inside the Manage Officers modal
(`partials/_correct_the_rolls.tpl`). It is a one-term-at-a-time form (office, player
search, start, end, note) above one long list. That shape works for fixing a single term
and is punishing for a backlog.

This project gives officer history its own page: a simple public **Officer History**
report, with an **Edit** mode on the same page built for fast backlog entry. It covers
kingdoms and parks.

What success looks like: an officer with an Amtwiki page open can work down one office's
list of holders in one sitting, mostly from the keyboard, without inventing dates the
source doesn't give.

## Goals

1. A standalone page per kingdom and per park, reachable from Reports, that shows the
   officer history publicly (read-only) and switches to an edit mode for officers with
   the history permission.
2. Edit mode is a set of **office ledgers**: one collapsible table per office, so each
   office's terms are entered and read on that office's own calendar.
3. Terms are entered as **season spans** by default (`Spring–Fall 23`,
   `Summer 23–Winter 24`), with exact dates as an optional refinement.
4. A term's holder is either a linked ORK player or a **plain-text name** when no ORK
   record exists. A plain-text name can later be linked to a player.
5. Gaps and overlaps within an office are visible where they occur.

## Non-goals (this project)

- **Paste/bulk import** from Amtwiki text (parse a pasted block into a review table).
  It was considered and deferred; the ledger's entry row is the entry mechanism for now.
- **Timeline / lane visualisation** and any richer public presentation of the rolls.
  That belongs to the separate Mask II branch. The public report here is deliberately
  simple.
- Cross-office "reign" grouping. Offices change on different cycles (some in Spring/Fall,
  others in Summer/Winter), so nothing may assume a reign lines up across offices.
- Changing how *current* officers are set. Seating/vacating officers stays in Manage
  Officers (`OfficerPosition`, `record_officer_history` in `common.php`), which keeps
  writing history rows as it does today.

## Anti-goals (would make this a failure even if it works)

- Forcing an exact day when the source only gives a season, e.g. storing "Spring 2023"
  as a made-up March 20 that the report then presents as fact.
- A second, competing editor: the modal tab and the new page must not both be full
  editors that drift apart.
- Rejecting a term because its holder has no ORK account.
- Making a 39-term office render as 39 tall cards, i.e. reproducing today's density.

## Users and permissions

- **Viewers:** anyone who can see the kingdom/park profile. The report is public, the
  same way the officer Details modal's history tab is today. Plain-text names are shown
  as typed; linked holders link to their player profile.
- **Editors:** the existing gates, unchanged. `kingdom.officer_history.manage`
  (kingdom scope, legacy AUTH_EDIT) for kingdom history, and
  `park.officer_history.manage` (park scope, legacy AUTH_EDIT) for park history, as
  checked today in `Kingdom::AddOfficerHistory` / `Park::AddOfficerHistory` and the edit
  and delete twins. The page's Edit control is shown on exactly that check (same key,
  scope and legacy role as the server gate). *(My call: reuse the existing keys rather
  than add new ones.)*

## Where it lives

- A new page, linked from the kingdom and park **Reports** tabs as "Officer History".
  The template goes in `orkui/template/revised-frontend/` (never `template/default/`),
  built on the shared `.rp-*` tool-page shell from
  `template/default/style/reports.css`, as `Recommendations_manage.tpl` does.
  The controller action and route are the builder's choice. A `Reports` action
  (`Reports/officer_history&KingdomId=…` / `&ParkId=…`) matches how the other Reports
  links work.
- The Manage Officers modal's **Correct the Rolls** tab stops being an editor. It becomes
  a short explanation and a link to the new page in edit mode. *(My call.)* The officer
  Details modal's read-only "Officer History" tab may keep its panels, or link to the
  page; builder's call, but it must read the same data.

## The edit mode: office ledgers

- One section per office for the org, in the org's office order (the same order Manage
  Officers uses: `OfficerPosition::sort_order_sql`). That includes crown offices and the
  kingdom's supporting offices, and any office that has history rows even if it's now
  retired or renamed.
- Each section header shows the office name, its term count and its flag counts
  (gaps, overlaps). Sections are collapsed by default except the first one, or the last
  one the user had open; builder's call.
- Inside, a compact table, newest term first: **Holder · Period · Note · actions**.
  One line per term. Exact dates, when present, show under the period in smaller text.
- Every row edits in place (click or Edit). Delete uses the house confirm dialog,
  never a native `confirm()`.
- A **blank entry row** sits at the bottom of each table, ready to type:
  holder field (player search, or plain text), period field, note. Tab moves through the
  fields; Enter saves the row and opens a fresh entry row with the focus in its holder field.
- **Pre-fill for backlog work:** users enter newest-to-oldest. After saving a term, the
  next entry row's period defaults to the span immediately before the term just
  entered, of the same length. For example, after `Spring–Fall 23` it offers
  `Fall 22–Spring 23`. The user can overwrite it freely.
- **Gaps and overlaps** within one office are shown inline between the rows where they
  occur ("Gap: Fall 22 – Spring 23"). They are warnings, never save blockers: real
  history has interregna and co-holders. A gap is only flagged when at least one whole
  season is missing, so a Fall→Fall handoff is not a gap.
- **Current terms belong to Manage Officers** (decided). Every seated officer already has
  a current history row, written by seating (`record_officer_history`) or the 2026-08-29
  backfill. In the ledger that row is shown as "… – present" and only its start (season or
  date) and note can be edited. The ledger can never create a current term, end one, or
  give one to someone who isn't seated; that stays in Manage Officers, so history and
  `ork_officer` can't disagree. The one-current-term-per-office rule stays.
- **Unknown ends** (decided): leaving the end blank on a past term means "end unknown",
  shown as "Spring 1995 – ?". It is a normal, saveable state, distinct from "present".
- **Changing a past term's holder** (decided): allowed on any past term, including rows the
  system wrote automatically. That covers fixing a wrong player and linking a plain-text
  name to a player. Holder changes are written to the audit log with before and after.
  Current terms' holders are not editable here (see above).

## Entering periods

The period field accepts season shorthand as its primary form:

- `Spring–Fall 23`, `Spring 23–Fall 23`, `Summer 23–Winter 24`, `Fall 26–present`
- Abbreviations: `Sp`, `Su`, `F`/`Fa`, `W`/`Wi`. Two- or four-digit years. Any dash style.
- A single season (`Spring 23`) is a valid entry; the builder decides whether that means
  start-only or a one-season term.

The parsed result is shown back immediately (e.g. "Summer 2023 → Winter 2024"), so a
misread is caught before Enter. Unparseable input blocks the save with a message.

**Exact dates** are optional per end. When an exact date is present it is authoritative
for that end; the season is then derived from it (or kept as typed; builder's call).

### What a season means

The seasons are Spring, Summer, Fall and Winter, in that order within a year. Spring,
Summer and Fall each sit inside a single calendar year.

**Winter is resolved from the term's direction.** "Winter YY" is a winter month in
calendar year YY: either early winter (December YY) or late winter (January–February YY),
whichever lies nearer the other end of the term in the right direction. As the user put it:

- `Summer 23 – Winter 23` runs roughly June → December 2023.
- `Summer 23 – Winter 24` runs roughly August 2023 → February 2024.
- At the start of a term the same logic applies: `Winter 23 – Summer 23` starts early
  2023; `Winter 23 – Summer 24` starts December 2023.
- A Winter end with no other end to compare against (start-only, or open) is resolved
  as late winter (January–February). *(My call.)*

- **Both ends Winter**: resolve the start first, as the earlier winter in its labelled
  year (January–February YY), then resolve the end as the winter in its labelled year
  that falls after the start, nearest first. So `Winter 23 – Winter 24` runs
  January–February 2023 → January–February 2024 (one year). `Winter 23 – Winter 23` is the
  same single winter as typing `Winter 23` alone: a single season and an explicit
  same-season pair are stored identically, so they resolve identically. A calendar year
  from one winter to the next is `Winter 23 – Winter 24`, or exact dates. *(My call,
  revised during the final review: the earlier reading, Jan → Dec 2023, made a lone
  "Winter 24" resolve as a year-long term.)*
- **A single season** (`Fall 22`) is a one-season term: both ends are that season, its
  label is the season alone ("Fall 2022"), and the term before it is the single season
  before it.
- **Canonical label for pre-fill and display**: a winter is labelled by the year of its
  January–February half, except a December-only end that the user typed with the December
  year. Pre-fill, which generates labels itself, always uses the January–February year:
  the winter between Fall 22 and Spring 23 is "Winter 23". *(My call.)*

The resolved position is used for ordering, gap/overlap checks and pre-fill only. It is
never presented as a date.

## Data

`ork_officer_history` today: `mundane_id int NOT NULL`, `role`, `position_id`,
`display_label`, `start_date date NULL`, `end_date date NULL`, `notes`, `changed_by`,
`created_at`, scoped by `kingdom_id` + `park_id`.

Additions (one idempotent migration in `db-migrations/`, classified in
`tools/ork-db/manifests/migration-classification.json5`):

- **Holder name** for unlinked holders, e.g. `holder_name varchar(120) NOT NULL DEFAULT ''`.
  A row is linked when `mundane_id > 0`, otherwise it is plain text and `holder_name`
  is required. Linking later sets `mundane_id` (the name may be kept as provenance).
- **Season precision** for each end, e.g. `start_season` / `end_season`
  (`enum('spring','summer','fall','winter') NULL`) plus `start_year` / `end_year`
  (`smallint NULL`). An end is "exact" when its date column is set, "seasonal" when
  season+year are set, and "unknown" when neither is (today's rows with null dates).
- A sortable resolved key per end (e.g. `start_sort` / `end_sort` as a date or
  year·month integer) may be stored or computed; builder's call. The Winter rule above
  needs both ends to resolve, so if stored it must be recomputed whenever either end changes.

- **An explicit current-term marker**, e.g. `is_current tinyint(1) NOT NULL DEFAULT 0`.
  *(My call, forced by the "unknown end" decision.)* Today the code reads
  `end_date IS NULL` as "this is the current officer"; with blank-means-unknown ends, a null
  end no longer means that. The migration sets `is_current = 1` on each office's row that
  matches the seated officer in `ork_officer`, and 0 everywhere else. Every reader and
  writer that treats `end_date IS NULL` as current must move to `is_current`:
  `record_officer_history` and its closing of open rows (`common.php`, around 989–1004),
  `OfficerPosition::hasOtherOpenTerm`, `CloseOfficerHistoryTerm`, the
  transition/vacate/seat paths, and the history readers. The builder must audit these
  with a search for `end_date IS NULL` in `system/lib/ork3/`. Seating closes only the
  `is_current` row, so it can never stamp today's date onto a backfilled 1995 term.

Existing rows keep their exact dates untouched. *(My call.)* The automatic history
written when officers are seated (`record_officer_history`) keeps writing exact dates
and a linked `mundane_id`.

Server rules. **The canonical writer is `OfficerPosition::AddHistoryTerm` /
`EditHistoryTerm` / `DeleteHistoryTerm`** *(my call)*: they are what the live UI calls
(through `OfficerAdminAjax` `addhistory`/`edithistory`/`deletehistory`), and they already
carry the future-end rejection, the open-term guard, the check against the row's own
kingdom/park, and the audit. Extend them. The older `Kingdom`/`Park`
`Add|Edit|DeleteOfficerHistory` writers (reached only through unused model wrappers, and
exposed on the JSON service) should delegate to the `OfficerPosition` methods or be retired,
so there is one set of rules. `EditHistoryTerm` widens from dates and note to also cover the
holder (linked or plain text) on past terms.

- Today the add path requires `MundaneId`, `Role` and `StartDate`. That must relax
  to: an office, plus either a `MundaneId` or a non-empty holder name, plus a start that
  is exact, seasonal, or (for a backlog row) absent. The builder decides whether a fully
  undated term is allowed; the UI never needs one.
- `GetOfficerHistory` returns the new fields, so the report and ledgers read one source.
- All SQL stays in `system/lib/ork3/`; the UI reaches it only through `orkui/model`.

## The public report (view mode)

The same page without edit controls, in the `.rp-*` shell: a header with the org scope
chip, a short context line, then the office sections (collapsible, newest first), each
showing Holder · Period. Plain-text holders are shown as plain text. Gaps can be shown as
quiet "unrecorded" rows. A DataTables table is not required here: these are short
per-office lists, not one big table. *(My call.)*

## Constraints and house rules

- Revised frontend only; `.rp-*` shell; light **and** dark mode via
  `html[data-theme="dark"]`, verified in both.
- Player search uses the house `kn-ac-results` dropdown (never jQuery UI), scoped, curl-tested.
  Officer history holders may come from other kingdoms, so the scope must be global, like
  the award "Given By" search. *(My call: an officer often served elsewhere first.)*
- Tooltips via `data-tip`. No native `alert`/`confirm`/`prompt`.
- Usable on a phone: ledger rows stack; the entry row stays usable at narrow widths.
- Tests: parser and Winter resolution (unit), the relaxed Add/Edit validation and the
  plain-text holder path (integration), the UI flag matching the server gate, and that
  the modal tab no longer writes history.

## Left to the builder

Route and controller placement; exact column names (including the current-term marker) and whether resolved sort keys are
stored; single-season semantics; whether fully undated rows are allowed; which section
opens by default; whether the Details modal keeps its history panels or links to the page;
how "link this name to a player" is triggered (inline action on a plain-text row is the
expectation).
