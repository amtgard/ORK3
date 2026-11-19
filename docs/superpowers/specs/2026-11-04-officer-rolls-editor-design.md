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
- **Every edit entry point reads that one gate** (`OfficerPosition::can_edit_history_for`,
  through the model; decided). Manage Officers' Correct the Rolls tab shows "Edit the
  rolls" only to someone who has it; anyone else gets "View the rolls" and a line naming
  the kingdom or park Officer History permission. The Details modal's history tab links
  "Full officer history" for everyone and "Edit the rolls" for editors. A refused
  `&edit=1` still shows the report, with a one-line notice naming the permission.

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
- **Manage Officers shows the season start** (decided). Each occupant carries the
  domain's StartLabel/StartOrdinal (and the end twins) from `latest_term_by_holder`, so
  the term line reads "Spring 2024 – present" and the "Start date unknown" nudge shows
  only when StartOrdinal is null. For a history editor, the nudge's Set it dialog takes
  season text ("Spring 24", "Mar 2024"), reads it back through `parseperiod` as a current
  term's start, and saves it as a Period on the seat's open row through `edithistory`
  (which on a current row sets the start only). The save sends `ExpectCurrent`, so if the
  seat changed hands after the list loaded, `EditHistoryTerm` refuses it and the dialog
  shows the error inline. It never rewrites the now-closed term. There is no date-only
  picker; for an exact day the dialog links the ledger's Calendar mode at
  `#office-<PositionId>`, which opens and scrolls to that office. A seat with no open
  history row gets a muted "(no history row for this seat yet)" note and no action: the
  ledger's synthetic seat row defers to Manage Officers, so a link would point back here.
  Without the history permission the nudge names it instead of offering Set it.

## The edit mode: office ledgers

- One section per office for the org, in the org's office order (the same order Manage
  Officers uses: `OfficerPosition::sort_order_sql`). That includes crown offices and the
  kingdom's supporting offices, and any office that has history rows even if it's now
  retired or renamed.
- Each section header shows the office name, its term count and its flag counts
  (gaps, overlaps). Sections are collapsed by default except the first one, or the last
  one the user had open; builder's call.
- Inside, a compact table, newest term first: **Holder · Period · Note · actions**.
  One line per term. An end with an exact date shows that date in the period itself
  ("Mar 30, 2019 – Fall 2019"), so there is no separate exact-dates line.
- Every row edits in place (click or Edit). Delete uses the house confirm dialog,
  never a native `confirm()`.
- A **blank entry row** sits at the bottom of each table, ready to type:
  holder field (player search, or plain text), period field, note. Tab moves through the
  fields; Enter saves the row and opens a fresh entry row with the focus in its holder field.
- **Enter in the holder field never drops a listed match.** The search results highlight
  an exact name match, else the first name that starts with the typed text, and Enter
  picks the highlighted player. With players listed and none highlighted, the first Enter
  only closes the list and a second Enter saves the typed name as plain text. With no
  players found, Enter saves it as plain text. An Enter pressed during the search (the
  debounce or the fetch) waits for the results and is then handled on them. Arrow keys
  move the highlight, Esc closes the list, and a hint under the results says what Enter
  will do.
- **Saving redraws one office.** Add, edit, link and delete send the section's Key and
  answer with that one office (and, for an add, PreviousSpan, the next pre-fill), so a
  save is one round trip. The rolls action takes a Key to read one office; nothing
  refetches the whole org after a save.
- **Pre-fill for backlog work:** users enter newest-to-oldest. After saving a term, the
  next entry row's period defaults to the span immediately before the term just
  entered, of the same length. For example, after `Spring–Fall 23` it offers
  `Fall 22–Spring 23`. The user can overwrite it freely.
- **Gaps and overlaps** within one office are shown inline between the rows where they
  occur ("Gap: Fall 22 – Spring 23"). They are warnings, never save blockers: real
  history has interregna and co-holders. A gap is only flagged when at least one whole
  season is missing, so a Fall→Fall handoff is not a gap.
  Both are found against **coverage**, not between neighbouring rows: walking the terms
  oldest to newest, coverage is the furthest end reached so far. A gap is flagged when a
  term starts 2 or more seasons beyond it, on that term (so it is drawn below it in the
  newest-first list). On a single-holder (crown) office a term that starts inside
  coverage and runs past it, or is current, is an overlap, named by the holder whose term
  reached furthest. A term wholly inside coverage is an acting or nested term and is not
  flagged. Supporting offices have co-holders by design, so they never flag overlaps;
  their gaps are found against the union of their terms. A term with an unknown start is
  not flagged but its end counts as coverage; an unknown end on a past term leaves
  coverage unknown, so the next start raises no gap. Terms are walked in start order, so
  a current term is placed by its start, and covers through the present. A current term
  with an unknown start covers nothing. (`OfficerPosition::flag_rolls_terms`.)
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

The parsed result is shown back immediately (e.g. "Summer 2023 – Winter 2023–24"), so a
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
- **Months and two-year winters are exact.** A month name (`Jan 23`, `Dec 2003`) is the
  season its month falls in, exactly, wherever it sits in the span: `Jan 23 – Fall 24`
  starts in January 2023. A winter may also be typed by both its years, `Winter 2022–23`
  (or `W 22/23`), which names one winter outright; its years must be consecutive. The
  dash inside it is not the span's dash, so a current term's start may be written that way.
- **A stored term's meaning is fixed.** The resolved position of each end (a season
  ordinal, year·4 + season, a winter counted in the year of its January–February) is
  stored with the row (`start_ordinal` / `end_ordinal`) when that end is saved, and every
  reader uses the stored value; nothing re-resolves the season columns later. So closing a
  current "Winter 24" (January–February 2024, as a lone winter reads) from Manage Officers
  writes the end's ordinal and leaves the start where it was: it does not become December
  2024 because an end now follows it. An edit that sets an end (a Period, or an exact date)
  stamps that end; an end it leaves alone keeps its stored ordinal. The season columns stay
  the text as entered, used to offer the same short form again for editing.
- **Every label comes from the stored ordinal**, and a winter is named by both its years:
  "Winter 2022–23" (across a century, "Winter 1999–2000"). `Summer 22 – Winter 22` and
  `Summer 22 – Winter 23` are the same span and read back identically, as
  "Summer 2022 – Winter 2022–23". An exact date still shows as the date. *(Revised in the
  distributed review: the earlier rule labelled a winter by whichever year was typed, so
  the read-back could not confirm which winter was meant.)*
- **Text meant to be typed back in round-trips.** The ledger's edit text and the pre-fill
  for the term before use the short typed form (`Winter 23 – Summer 23`), choosing for each
  winter whichever of its two years the other end makes mean that winter, and checking that
  the text parses back to exactly the stored ordinals (falling back to the two-year name,
  which always does). Gap labels use the display form, which also parses back to the gap.

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
  is required. Linking later sets `mundane_id` and **keeps `holder_name` as the name of
  record** (decided): the term shows that name linked to the profile, with a muted "now
  known as <persona>" when today's persona differs (ignoring case), and a blank persona
  falls back to it, never "Player #id". Relinking to another player keeps it too; unlinking
  (MundaneId 0 plus a name) replaces it. A ledger add of a picked player records the persona at
  the pick as its name. A linked row with no recorded name (a row Manage Officers wrote)
  is named by the persona. The rolls
  page, the ledger and the Details modal all render it this way. The migration's legacy
  test (`start_season IS NULL AND holder_name = ''`) is unchanged: a linked row that kept
  its recorded name is a ledger row and is never re-marked current.
- **Season precision** for each end, e.g. `start_season` / `end_season`
  (`enum('spring','summer','fall','winter') NULL`) plus `start_year` / `end_year`
  (`smallint NULL`). An end is "exact" when its date column is set, "seasonal" when
  season+year are set, and "unknown" when neither is (today's rows with null dates).
- **A stored season ordinal per end**, `start_ordinal` / `end_ordinal` (`INT NULL`,
  year·4 + season, a winter counted in its January–February year; null when that end is
  unknown). It is the term's meaning (see "A stored term's meaning is fixed" above):
  written once by the write that sets that end (the ledger's Add/Edit, and every seat
  change that opens or closes a term: `record_officer_history`, the transition and vacate
  paths, the supporting-seat insert), never recomputed when the other end changes. The
  migration stamps existing rows from their dates (by month) and seasons (each winter
  resolved against the end entered with it; a single typed winter is the lone winter even
  beside an exact end date). `GetOfficerHistory` orders in SQL on the rolls key; per-org
  lists are small, so it needs no dedicated index. A side with no stored ordinal but an
  exact date reads that date (one date is one ordinal), so a row written in the deploy
  window still reads correctly. **Deploy:** apply the migration before the code deploy
  (the new code writes these columns, and its checked history writes fail and record
  nothing against the old schema), then re-run it once right after the deploy to stamp,
  mark and clear anything the old code wrote in between; it is idempotent.

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
  Current is per seat (office + holder). A crown seat change closes the outgoing holder's
  row (today) and unmarks any other current row on that office, leaving its end unknown.
  Multi-holder supporting offices close per holder. Merging a park away closes its
  officers' current rows today, as their seats are deleted.
  **A retired park or kingdom has no current officers.** The migration neither marks nor
  opens current rows for retired orgs and clears any it finds (end left unknown);
  `RetirePark` / `RetireKingdom` clear the org's marks the same way, and the rolls
  synthesize no current holder for a retired org. `RestorePark` / `RestoreKingdom`
  re-mark them: for each seat whose holder still sits in it and that has no current row,
  the newest row with no end becomes current again (a park or kingdom still retired is
  skipped).
  **Every re-run repairs current marks:** it clears `is_current` on any row with an end
  set, or whose holder no longer sits in that seat, before re-marking seated holders.
  **A park's history follows the park.** `TransferPark` moves the park's
  `ork_officer_history.kingdom_id` with its officers (inside the same audit), the
  migration realigns park rows filed under a former kingdom, and the Park reader and the
  close paths match a park's rows by `park_id` alone (`park_id` is globally unique;
  kingdom rows, `park_id = 0`, still match on the kingdom).

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
showing Holder · Period. Plain-text holders are shown as plain text; linked holders as
their recorded name, linked, with "now known as" when the persona differs. Gaps can be shown as
quiet "unrecorded" rows. The report words an unknown end for readers: a current term with
no recorded start reads "Start unknown – present", and a past term with no end ends in a
muted "end unrecorded". The edit ledger keeps "?", which editors read as "fill this in". A DataTables table is not required here: these are short
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
