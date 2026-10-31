# Smart Planning Grid — Design

Date: 2026-10-30
Branch: `feature/smart-court-planner-grid`
Status: approved 2026-10-30

Items marked **(call)** were not stated by the requester. They are the writer's decisions and are listed again at the end for review.

## Why

An officer planning court uses the Ladder Award Grid to find players who have been active in a ladder but sit below what their park can give. For example: "this person has been doing service, the park can give up to Rose 5, and they are only at Rose 1." The grid shows who is eligible, but acting on it means leaving for the Court Planner and re-finding each person.

Smart Planning Grid turns the report into the planning surface. The officer sees who is due, clicks a cell, and that award lands on a court, carrying the recommendation behind it.

## Goals

- An officer with court authority can build a court straight from the grid by clicking cells.
- The grid guides the choice: recommendation badges, last award, last sign-in, an overdue marker, and an optional ladder limit.
- A line added from the grid is the same as a line added through Add from Recommendations, whenever a recommendation exactly matches it.
- Players with no ladder awards can be found and awarded too.

## Non-goals

- Storing or enforcing a park's limit. Ladder Limit Assist is a planning aid and never blocks anything. Kingdoms are not bound by it, but any court planner may use it, for example to help a local park.
- Finalizing, staging, or recording a court from the grid. That stays in the Court Planner.
- Editing citations or notes, or reordering lines, on the floating card.
- Live sync between two officers. The card reflects what was loaded plus what this officer did.
- Changing the Court Planner's own Add from Recommendations modal.
- Schema changes. None are expected.

## Anti-goals

These would be failures even if everything else worked:

- A Monarchy Only recommendation is revealed to someone who cannot see it, including by an (R) badge that hints it exists.
- Ordinary players see (R) badges. The report is open to every logged-in player.
- A recommendation is used up by an award of a different level.
- One honor reaches the same court twice, or one line finalizes twice. The same honor on two different open courts is allowed, as it is in the planner, but the grid must make it visible, through the crown, for open courts in the kingdom that this officer can manage (see Crown marker).
- The report gets noticeably slower for ordinary viewers.

## Who sees what

| Surface | Every logged-in player | Officer with court authority, Smart Court off | Smart Court on |
|---|---|---|---|
| Last Award and Last Sign-in columns | yes | yes | yes |
| "Show recent players without awards?" toggle and New Players box | yes | yes | yes, cells clickable |
| Smart Court button | no | yes | yes |
| (R) badges, crown marker, hourglass, Ladder Limit Assist, floating card, clickable cells | no | no | yes |
| Suspended players | shown, tinted | shown, tinted | hidden |

"Court authority" means `Court::canManage($uid, $kingdomId, $parkId)` for the page's scope. A park grid uses that park. A kingdom grid uses the kingdom. A park officer on a kingdom grid does not get the button. **(call)**

## Entering Smart Court

A **Smart Court** button sits in the report header beside Export CSV and Print. Clicking it opens a modal asking whether to add to an existing court or start a new one. The Court Planner section of the kingdom and park Admin tabs links here as "Plan Court from Smart Grid", and `SmartCourt=1` opens the Smart Court modal on arrival.

- **New court:** the same fields as the Court Planner's create modal: name, court date, event (optional), and run/plan mode. The date uses Flatpickr with `altInput`.
- **Existing court:** a dropdown of courts in the page's scope, using `getCourtList` semantics, excluding `complete` courts. Each option shows name, date, status, and award count. Park grid: that park's courts. Kingdom grid: the kingdom's own courts. **(call)**
- A court created here belongs to the page's scope: a park grid creates a park court, a kingdom grid a kingdom court. **(call)** This is how a kingdom officer plans for a local park: open that park's grid.
- A new court is created on the first Save to Court, not when the modal closes, so an abandoned session leaves no empty court. **(call)** The modal validates name and date up front.

The header button then shows Smart Court is active, and clicking it again exits. A bar under the header shows the court name, Ladder Limit Assist, and a legend for (R) and the hourglass.

## Clicking cells

Clicking a cell adds that ladder's next rank for that player to the card.

- Next rank = the highest of (cell value, this player's live lines on this court for this ladder, unsaved rows for this ladder) + 1. So repeat clicks give Rose 3, Rose 4, Rose 5. Lines on other courts do not affect it.
- Clicks always step up one rank at a time. This is the requester's explicit decision. A player at Rose 1 with a Rose 4 rec reaches it by adding Rose 2 and Rose 3, or the officer gives it directly from the Court Planner. The grid offers no rank jump.
- The cell value is the grid's existing value, `MAX(rank, grant count)`, so the number the officer sees is the number the click continues from.
- An empty cell adds rank 1.
- **Not clickable (call):**
  - Master (M) cells.
  - Cells already at the top rank: 10, or 12 for Zodiac, following the planner's rule. A `data-tip` explains why.
  - Cells on a column with no awardable row in the court's kingdom (see Mapping columns to awards).
- A cell shows a small green `+N` counting its lines on this court, whether unsaved, just saved, or already there when an existing court was loaded.

Clicks use delegated handlers on the table body, because DataTables re-renders rows.

**Crown marker.** In Smart Court, a cell gets a small crown icon when that player has a live line for that ladder on another open court, with the tooltip `Already on Court: {Name}`. This is the requester's wording. The cell stays clickable and next rank is unchanged.

- "Open" means the court is not `complete` and the line is not `cancelled`. **(call)**
- Check the open courts in the kingdom that this officer can manage. A park officer does not see a crown for a kingdom court or another park's court. This is the requester's explicit decision.
- Several courts are comma-separated in the tooltip. **(call)**
- Any rank on that ladder counts, since a line for a different rank on another court still affects what this one should give. **(call)**
- The crown sits top left so it never collides with the (R) at top right. **(call)**
- This data loads with the recommendation data, behind court authority, and stays out of the shared grid cache.

**Suspended players.** While Smart Court is on, suspended players are hidden from both grids. This is the requester's explicit decision. Lines already on an existing court still load on the card. An unsaved row for a player who has since been suspended is dropped on resume with a message. **(call)**

## The floating card

Fixed to the bottom right. A header shows "Pending Court" and the court name. Rows read `Persona - Award Rank`, for example `Bane Keeper - Rose 3`.

- New rows join the bottom and the list scrolls up. Five rows are visible, and the officer can hover and scroll to see older ones.
- Each row ends with an X.
- A row linked to a recommendation shows a small R. **(call)**
- A row above the Ladder Limit shows a red `fa-arrows-up-to-line` icon with a `data-tip`.
- The footer has Clear All, the open-in-new-tab button, and Save to Court.

**Save to Court**
- Saves unsaved rows in card order, one at a time, through `CourtAjax/add_award`, so sort order follows the card. The button is locked during the save.
- Immediately before saving, re-reads the court's live lines through the same read that loads them into the card. (`getCourtAwardKeys` cannot be used: it also returns cancelled lines.) `addAward` only deduplicates recommendation lines, so a stale card could otherwise add a second unlinked line for an honor another officer just added. A row that now duplicates a live line stays unsaved with a message saying so. A small race remains, which matches the planner's walk-on path and is accepted, since finalize cancels a duplicate line. **(call)**
- On success the button turns green, each saved row gets a check-square icon and is slightly grayed, and the card keeps accepting rows. A new unsaved row returns the button to its normal state. **(call)**
- If some rows fail, those rows stay unsaved with the server's error message shown on the row, and the rest stay saved. **(call)**
- Once a court has been saved, an out-to-new-tab button opens `Court/detail/{id}` in a new tab.

**Removing**
- X on an unsaved row removes it. Later unsaved rows for that player and ladder renumber down, and recommendations rematch. **(call)**
- X shows on saved rows only while the line's status is `planned`, and calls `CourtAjax/remove_award`. Lines that are `staged`, `announced`, or `given` show no X, because `removeAward` only refuses `given`, and a staged line reads as Given in Run mode. Those are changed in the Court Planner, where their citation and notes are visible. **(call)**
- Clear All takes a second click to confirm, shown inline (no native dialogs), and resets after about 3 seconds. It clears unsaved rows only. **(call)**

**Existing courts:** when joining an existing court, its live (non-cancelled) lines load as saved rows, so ranks continue from them and nothing is duplicated. **(call)**

**Minimize:** a button shrinks the card with an animation toward the bottom right into a round floating button with a clipboard icon, so the officer can see where it went. The FAB shows a count badge **(call)** and reopens the card. Respect `prefers-reduced-motion`.

**Persistence (call):** unsaved rows, the chosen court, and the limit live in `localStorage`, keyed by report scope, so a refresh does not lose work. Returning to the page resumes Smart Court with them. Rows are revalidated against fresh grid and recommendation data on resume.

**Print:** Smart Court controls and the card do not print.

## Recommendation matching

The requester's rule: treat each add as though the officer had used Add from Recommendations, and be smart about matching.

**What linking means.** Pass the recommendation's id as `RecommendationsId` to `add_award`, with the rec's `KingdomAwardId` and rank, `PassToLocal` 0, and empty notes: the same payload `cpSubmitRecs` sends. Everything else follows from existing behavior: the rec badge, inherited reason as citation, Monarchy Only handling, and closing the rec at finalize.

**A rec links only to a line of the same award and the same rank.** A rec for Rose 5 never links to a Rose 4 line, because finalize closes a linked rec regardless of rank. This is the requester's explicit decision.

**Candidates** for a line are open recommendations that are:
- about that player, for that ladder, at exactly that rank;
- visible to this officer, using the same path as the planner (`Report::PlayerAwardRecommendations` with `RequestedBy` and `Token`, plus `RecommendationVisibility`);
- for an award row in the court's kingdom;
- not `AlreadyHas` or `CoveredByMaster`;
- not already claimed by another row on the card or a live line on this court.

**Choosing among candidates:**
1. Prefer recs that are neither snoozed nor on another court. Those are used only when nothing else matches. **(call)**
2. Most +1s (`SecondsCount`, the rec's own seconds). Other people separately recommending the same award do not count as +1s. **(call)**
3. Oldest `DateRecommended`.
4. Lowest rec id, so the result is deterministic. **(call)**

This intentionally differs from the planner modal, which shows the oldest rec in a cluster as its representative. Do not use `getPendingRecommendations`: it collapses clusters and would hide the most-seconded rec. Read the uncollapsed rows.

Each rank claims its own rec, so a 4th and a 5th take separate recs when both exist. Matching reruns for unsaved rows whenever the card changes, and saved rows keep their rec.

Rank-0 ladder recs (142 rows on the local copy) have no level, so they never match a line and never produce an (R). **(call)**

If `add_award` refuses a line because its rec has become unusable since loading, the row stays unsaved with the message and the recommendation data refreshes. It is not silently saved without its rec. **(call)**

## (R) badges

In Smart Court, a cell gets a small (R) in the top right if the player has a usable rec for that ladder with rank above the cell's current value. Candidates use the same filters as matching. Snoozed recs and recs on another court still show. **(call)** Master cells show none. A `data-tip` names the recommended rank and +1 count, so the officer knows how far up the rec sits, and never the recommender or the reason.

Recommendation data loads through a court-authorized endpoint when Smart Court starts. It is never in the page for ordinary viewers, and it must not enter the shared grid cache.

## Ladder Limit Assist

Shown in the Smart Court bar to any court planner: label "Ladder Limit Assist:", a dropdown of Off and 1–10 (Off is the default **(call)**), and a (?) with an instant tooltip reading:

> If your park or region cannot give awards above a certain amount, set that limit here and any cell at or above that limit will gray out.

- A cell grays out when the player's effective value is at or above the limit. Effective value counts rows already on the card, so a cell grays as soon as the next click would exceed the limit. **(call)**
- Gray cells stay clickable.
- A card row whose rank is above the limit shows the red icon. Changing the limit updates cells and rows at once.

## New columns

After Persona (and Park on kingdom grids):

- **Last Award:** the player's most recent award of any kind, ladder or not, as `2026-04-06 Rose 1` or `2026-04-06 Paragon Wizard`. Exclude revoked awards and dates before 1980 (`ork_awards` holds `0000-00-00`). Ties take the highest award row id. **(call)** Award names drop "Order of (the)" and use the same display wording as the grid, followed by the rank if there is one.
- **Last Sign-in:** the player's latest `ork_attendance.date`, also filtered for placeholder dates, in `YYYY-MM-DD`.

Both are sortable by date through `data-order`. A player with no date shows a dash and sorts as oldest. **(call)**

**Color:** both columns use a green-to-red gradient on a fixed scale, today to 365+ days. **(call)** A scale relative to the visible rows would let one player from 2012 turn everyone else green. Dark mode needs its own tuned values. The date text stays readable in both themes.

**Hourglass:** in Smart Court, a red hourglass follows the name when the player has a sign-in within the past 60 days and a last award more than 180 days ago, with a `data-tip` saying so. A player with no award on record shows no hourglass. **(call)**

CSV export gains both columns as plain dates and text. **(call)** Adding columns shifts the DataTables `columnDefs` targets, the default sort, and the CSV column logic in `Reports_laddergrid.tpl`, so all of those need updating.

## New Players box

A toggle at the top, next to Include Inactive, labeled "Show recent players without awards?". Off by default. **(call)**

When on, a second grid box appears below the main one:

- Title: **New Players**. Subhead: **Players without awards, with at least two sign-ins in the past 90 days.** **(call — the requester used both "Players Without Awards" and "New Players".)**
- Membership: active players in the scope, using the main grid's home-park and home-kingdom rule, with no non-revoked ladder award, and at least two sign-ins on different dates in the past 90 days. **(call — no ladder award, matching who the main grid hides; two dates, so one day counted twice does not qualify.)**
- Same columns as the main grid, every ladder cell empty. In Smart Court the cells behave the same: a click adds rank 1, and (R), the limit, and the hourglass rule apply.
- Uses DataTables, and the player name filter applies to it. **(call)** The rank slider, award pills, and Knights/Masters cards do not, since these players hold no ranks.

The membership query should not slow the report when the toggle is off. Loading it on demand is one way to do that.

## Mapping columns to awards

Grid columns are keyed by `award_id`, or `k<kingdomaward_id>` for kingdom-original ladders, and may merge several rows. `add_award` needs a `kingdomaward_id` that belongs to the court's kingdom, and refuses others.

Smart Court needs a resolver from column key to `kingdomaward_id` for the court's kingdom. A linked line uses its rec's `KingdomAwardId`. An unlinked line uses the resolved row.

A principality's grid can show a ladder inherited from its parent, with no row in the principality. The cell cannot be saved to that court, so it is not clickable and a `data-tip` says why. **(call)**

## Constraints

- Layers: SQL only in `system/lib/ork3/`, `orkui/model` is the only membrane, and controllers stay thin.
- `GetLadderAwardGrid` caches rows for 1200 seconds. New row fields must not read as missing from old cache entries, so the cache key has to change with the shape. Grid values can also lag up to 20 minutes, so existing court lines must feed next-rank.
- The report is `orkui/template/default/Reports_laddergrid.tpl`, already there. This extends an existing report and adds no new entry point in `default/`.
- Use Smart-Court-specific `lg-` classes in `reports.css`. Support dark mode via `html[data-theme="dark"]`. Use `data-tip` for new tooltips, never `title`, positioned by a single fixed body-level node, because the grid's `overflow-x` wrapper clips `::after` tooltips. No native `confirm`, `alert`, or `prompt`.
- Reuse existing AJAX endpoints for writes (`create_court`, `add_award`, `remove_award`), which already enforce court authority, idempotency for rec lines, and hidden-rec rules.
- New recommendation endpoints must require court authority. Note that the JSON service exposes public methods of whitelisted classes.
- The card must remain usable on a phone, but the grid itself stays a wide scrolling table.

## Left to the builder

- How the lib, model, and AJAX endpoints are shaped, as long as the sort rule lives in one place.
- File layout for the new JS (inline, matching the template, or a separate file).
- Exact visual polish. The approved mockup is the reference for layout. It was a throwaway and is not checked in.
- Test design. Expect integration coverage for the new row fields, New Players membership, recommendation ordering, and visibility, plus a browser pass in both themes.
- What's New and in-app docs entries.

## Decisions to review

All items marked **(call)** above. The ones that most affect behavior:

1. New courts are created on first Save.
2. Existing court lines load into the card as saved rows.
3. X on a saved row removes it from the court. Clear All never touches saved rows.
4. Unsaved rows survive a refresh via `localStorage`.
5. Snoozed recs and recs on another court are used only as a last resort.
6. The color gradient uses a fixed 0 to 365+ day scale.
7. A ladder limit grays a cell based on held rank plus pending rows.
8. New Players means no ladder awards, with sign-ins on two different dates.
9. A park officer has no Smart Court on the kingdom grid. Kingdom officers plan for a park from that park's grid.
10. The crown checks open courts in the kingdom that this officer can manage, at any rank on that ladder.
11. X on saved rows only appears while the line is `planned`.
12. Save re-checks the court's live lines first, and a duplicate row stays unsaved with a message.

## Player Profile

Planned courts surface on the Player Profile so officers can see what is already lined up for a player.

- A card titled `Awards Planned on Future Courts` sits at the top of both the Awards tab and the Recommendations tab. It is collapsed by default (native `<details>`, `pn-planned-courts`) and absent when there is nothing to show.
- Each court lists its name (linking to `Court/detail/{id}`), its date as `YYYY-MM-DD` (or "No date set"), its level (`Kingdom`, or `Park: {park name}`), and its awards, like `Rose 3`. A rank-less award shows the name only, and a leading "Order of (the)" is stripped. Courts sort by date ascending, undated last.
- Only courts the viewer can manage appear: `canManage($uid, court kingdom, court park)`, evaluated once per distinct court. A park officer does not see kingdom courts or another park's courts. Ordinary players see nothing, and the page does not emit this data for them.
- A line backed by a Monarchy Only recommendation the viewer cannot see is withheld entirely, using the same `RecommendationVisibility` rule as `Court::getCourtAwards` and the Smart Grid's other-court lines.
- "Planned but not yet awarded" means the court status is not `complete`, and the line status is neither `cancelled` nor `given`.
- Nothing shows on the viewer's own profile, neither the card nor the icon, so an officer is not spoiled on a surprise award. Logged-out viewers skip the lookup too.
- In the Recommendations table, a clipboard icon (`fas fa-clipboard`) follows the award name when a planned line matches the rec: the line's `recommendations_id` equals the rec, or it has the same `kingdomaward_id` and rank. The tooltip (`data-tip`) reads `This award is planned for Court: {Name} on {Date}`, with several courts comma-joined and the ` on {Date}` dropped for an undated court. The icon adds no cell text, so the award-name sort, the search, and the CSV export are unchanged. The tooltip is drawn by the page's fixed body-level bubble so the table's scroll box cannot clip it.
- Data comes from `Court::getPlayerPlannedCourtLines($mundane_id, $caller_uid)`, loaded in `Controller_Player::profile` only for a logged-in viewer who is not on their own profile.
