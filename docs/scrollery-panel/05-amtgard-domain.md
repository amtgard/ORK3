# Panel report — Amtgard Awards & Recognition Domain

## Summary
Scrollery is a genuinely good port, and its data discipline (title precedence, park rank words, derived pronouns) already beats every hand-run Photoshop workflow in Amtgard. But it models a CERTIFICATE, not the SCROLL WORKFLOW: the module has no idea a court exists, no idea a scribe who is not the granting officer exists, and no memory that anything was ever printed. Those three absences are why a kingdom would keep the Google Sheet next to it. Meanwhile the ORK already holds the missing pieces — this repo's Court Planner branch carries `ork_court_award.scroll_maker_id`/`scroll_status` and a per-scribe Prep Sheet, `ork_officer_history` knows who wore the crown, and `Award::GetLadderMasterMap()` is a canonical Masterhood<->ladder map. Separately, nothing anywhere filters `revoked`, so the module will print a stripped award.

## Findings

- **Court Planner is Scrollery's real front door**
  - Now: `feature/court-planner` (180 commits, unmerged) carries `ork_court_award.scroll_maker_id` + `scroll_status` (0 not started -> 1 in progress -> 2 ready), a "Prep Sheet grouped by maker" (spec `2026-09-01-court-recs-workflow-design.md` §Sheet 3), `ork_court.last_printed_at`, and `ork_awards.court_award_id` — which exists in the shared DB today. Scrollery references none of it; `Scrollery/bulk` finds awards by event id or date range only (`model.Scrollery.php:331-357`).
  - Proposal: `Scrollery/bulk/court/<court_id>`: rows in `sort_order` (COURT order, so the printed stack matches the herald's script — not `order by m.persona` as at `model.Scrollery.php:382`), filtered by `scroll_maker_id` so a scribe prints only their own, stamping `scroll_status=2` when printed. For a STAGED, not-yet-finalized court there is no `awards` row — but `Scrollery/compose` (`controller.Scrollery.php:255`) already prints without one, which is exactly the surprise-award case.
  - Why: this is the actual timeline — PM plans court, awards are assigned to scribes, scrolls are made days ahead, court runs, records are finalized after. Scrollery only serves the last step.
  - Effort: L — Risk: the two branches must merge first; keep the coupling one-directional (Scrollery reads Court, Court never requires Scrollery).

- **[BUG] Revoked awards are printable**
  - Now: `bulkSqlForTest()` has no `revoked` predicate (`model.Scrollery.php:367-383`) and `Player::AwardsForPlayer()` has none either (`class.Player.php:1154`). Live: 2,856 revoked rows, **2,287 carrying a park/kingdom scope** so they appear in bulk; 2,230 of those have `mundane_id=0` (revocation nulls it — `class.Player.php:3461`) so they print as blank-recipient certificates. The other **59 still carry a live `mundane_id`** — the player can print their own stripped award from `Scrollery/mine`.
  - Proposal: `and awards.revoked = 0 and awards.mundane_id > 0` in the bulk query; a `revoked` guard in `viewer_may_print()`/`scroll_for_awards_id()` (`model.Scrollery.php:694`).
  - Why: an award stripped for cause is the one document that must never reprint; blank-name pages in a 200-sheet run also waste a scribe's night.
  - Effort: S — Risk: none; `AwardsForPlayer()` is shared, so filter in Scrollery rather than changing it.

- **Masterhoods should print their own ladder's artwork**
  - Now: all 3,195 Master grants fall to the generic composer. `Scroll::OrderForAwardRow()` (`class.Scroll.php:372`) goes specific-id -> archery-by-name -> custom. Phase 2 justified this with "no ladder gives its Master tier the ladder's own artwork."
  - Proposal: a fourth branch using `Award::GetLadderMasterMap()` (`class.Award.php:18`) — Master Rose (1) -> `rose`, Warlord (12) -> `warrior`, Battlemaster (244) -> `battle`, all 14 — rendered at rank 0 with `ranked=>false` so no numeral and no write-in blank appear.
  - Why: the spec's premise is an ARCANYST limitation, not an Amtgard fact. A Master Rose IS the apex of the Order of the Rose; every kingdom's Master Rose scroll looks like a Rose scroll. 3,195 grants moved from beige generic to correct, zero new art.
  - Effort: S — Risk: rank-0 files are already required for 18 of 19 orders; `warrior` starts at rank 1 and needs an explicit rank-0 path or a fallback to its rank-1 plate.

- **Nothing records that a scroll was printed**
  - Now: no printed-state anywhere in Scrollery. Court Planner solved the paper half (`ork_court.last_printed_at`, `last_printed_award_count`).
  - Proposal: one nullable `ork_awards.scroll_printed_at` (or a small `ork_scroll_print_log` keyed on `awards_id`), stamped when a run launches; surfaced as a "Printed" column plus a **"not yet printed"** filter in bulk, and a "reprint" label on `mine`.
  - Why: the single question a Guildmaster of Scribes asks every week — "who is still owed a scroll from Clan?" Passes the useful-AND-used test: written by one surface, read by two.
  - Effort: M — Risk: `render` is a GET; a refresh or prefetch would double-stamp. Stamp from the bulk page's own action; treat as "last printed", not a count.

- **The signature blank is pre-filled, so there is nowhere to sign — and there is only one**
  - Now: `_text.tpl:96` prints `givenBy` into the "By my hand…" blank. One signatory only.
  - Proposal: (a) a "leave signature blank" toggle on create and a `&sign=blank` parameter honoured by render/bulk — the underline image is already drawn (`_text.tpl:98`), so this is a suppression not a layout change; (b) an optional second signatory blank with a caption ("Monarch"/"Regent"/the granting officer's role). `SignAndDate` is already a two-column flex.
  - Why: a scroll is signed in ink, usually twice. A typed name where the signature goes is the most visible tell that this came out of a database.
  - Effort: M — Risk: five orders override `section.SignAndDate` positioning (`scrollery.css:341-350`); a third column needs each checked.

- **Knighthood should not resolve to the default beige recipe** — 2,017 grants, ~200/year across all of Amtgard
  - Now: all five Knighthoods (17,18,19,20,245) hit `Scroll::DEFAULT_RECIPE` (`class.Scroll.php:47`) from `mine` and `bulk` — parchment, knot border, griffon's tan/gold — identical to a Paragon Bard's. Only the create tool lets an officer choose otherwise, and nothing persists that choice.
  - Proposal: a small `AWARD_RECIPES` map in `scroll-recipes.php` giving specific awards a fixed recipe from the existing vocabulary — Knight of the Flame -> flame's ground and colours, Crown -> crown's border, Sword/Serpent/Battle -> distinct curated pairs — consulted before `DEFAULT_RECIPE`. Same for Squire/Man-at-Arms/Page.
  - Why: a knighting scroll is the most important document most Amtgarders ever receive, and it deterministically reprints, so fidelity across years is free. This is the "saved recipes" idea the spec parked, narrowed to the awards that actually justify it.
  - Effort: S — Risk: values must stay valid vocabulary names — the existing `ScrollRecipeTest` guard extends to this table for free.

- **Bulk cannot reach a kingdom's parks or its principality**
  - Now: the kingdom branch is `awards.at_kingdom_id = <id>` alone (`model.Scrollery.php:348`). Live: 1,616 rows park-only, 209,106 carry both. A principality is an `ork_kingdom` row — Crimson Sands (39) holds 1,351 awards — and `HasAuthority()` already walks `parent_kingdom_id` (`class.Authorization.php:931-947`), so a parent-kingdom officer MAY print them but has no link that reaches them: `Scrollery_index.tpl` only ever offers `kingdom/<session kingdom_id>`.
  - Proposal: a scope selector on bulk — "this kingdom" / "+ its parks" / "+ its principalities" — the last derived from `Kingdom::GetStatsKingdomIds()` (`class.Kingdom.php:781`) so it honours the existing Include-Principality-in-Statistics setting instead of inventing a second rule.
  - Why: kingdom-level scribes work the whole kingdom's backlog, not one park at a time.
  - Effort: M — Risk: this widens exactly the predicate the cross-tenant fix hardened. Derive the id set SERVER-SIDE from the authorised scope; never accept it from the URL.

- **31% of awards can be printed by nobody but the recipient**
  - Now: `viewer_may_print()` (`model.Scrollery.php:694`) needs `ParkId` or `KingdomId`. **120,284 rows carry neither** — 6,831 since 2023 — and `render` redirects silently.
  - Proposal: fall back to `awards.kingdomaward_id -> ork_kingdomaward.kingdom_id` as the authorising scope when both attribution columns are 0 (that key is never blank), add the AUTH_ADMIN branch, replace the redirect with a stated reason.
  - Why: "print my grandfather's Lion from 2009" is the reprint case, and today the park that gave it cannot help.
  - Effort: S/M — Risk: the catalogue kingdom is not always the granting body — use it to authorise a SINGLE reprint, never to widen a bulk listing.

- **ORK knows who was sitting; the certificate should offer it**
  - Now: `givenBy` comes from `awards.given_by_id`; **112,182 rows have none** (1,117 since 2023), printing a blank. `ork_officer` (5,590 rows), `ork_officer_history` (2,514) and `ork_officer_position` (`canonical_key`=`monarch`,`regent`) are untouched.
  - Proposal: resolve the sitting monarch/regent of the granting body at the award's date and offer it as a DEFAULT in create and as the caption under the second signature blank ("Sir X, Monarch") — a suggestion an officer can clear, never an assertion.
  - Effort: M — Risk: `ork_officer.role` is free-text `varchar(80)` and local roles have been lowercased — match on `ork_officer_position.canonical_key`, not the string; history coverage is thin, so fall back to blank rather than guess.

- **Officer positions: agree with the default, disagree that it is the module's call**
  - Now: `Scroll::IsOfficerPosition()` (`class.Scroll.php:341`) excludes 54 entries / 7,143 grants everywhere, including `Kingdom Monarch` (303), `Kingdom Prime Minister` (311), `Baronial Regent` (443).
  - Proposal: keep them out of `mine` and `bulk` unconditionally; allow the **create** tool — one officer, one certificate, chosen deliberately — to compose one when a per-kingdom `ork_configuration` flag is set (read as `(int)Value === 1`).
  - Why: some kingdoms genuinely scroll a completed term of service, and a completed reign is an honour by any reading. But it should never appear by default in a 200-row batch.
  - Effort: S — Risk: `IsOfficerPosition()` is deliberately the one choke point and fails closed on a missing key — thread the exception through the row it inspects, don't add a second predicate beside it.

- **Zodiac's month is now a real column, not a derivation** *(re-opening a PERMANENT DEFERRAL, with a new fact)*
  - Now: `ork_awards.zodiac_month TINYINT` exists in the shared dev database (6 rows populated) — another branch's work arriving through the shared DB, exactly as `pronoun_freetext` did (§3.6). `Scroll::ExpandFlavour()` (`class.Scroll.php:451`) already accepts `$context['month']` and always takes the else branch.
  - Proposal: when the key is present and > 0, pass it through. This is explicitly NOT deriving the month from the award date — it is READING the field the owning branch created.
  - Effort: S — Risk: ranks 11-12 and the entry UI stay with the owning branch; claim the read only, and guard on the key's presence so this works either side of that merge.
