# Circle of Knights Manager — design

Status: approved 2026-11-28. Branch `feature/knight-management`.

## Intent

Give a kingdom's knights one place to see who the knights are and whether they are eligible, and to run
the pipeline from a first mention to knighthood. Kingdom-level module. User guide: `docs/knight-circle-guide.md`.

## Orders

`KnightCircle::ORDERS` mirrors `Award::GetKnightAwardMap()`: 17 Flame, 18 Crown, 19 Serpent, 20 Sword,
245 Battle. The fifth order is Knight of Battle; there is no "Chivalry" award in the repo. Holding a
knighthood is a live (non-revoked) award whose effective award id (alias first) is in that set, as in
`Report::PlayerAwards`. Ladder awards map to orders through `LADDER_ORDER` (mirrors the private
`Report::ladderKnightGroupMap`); master awards via `Award::GetLadderMasterMap()`. Ladder rank is
`GREATEST(MAX(rank), COUNT(awards))` per ladder, excluding revoked.

## Data model (`db-migrations/2026-11-28-knight-circle.sql`)

* `ork_knight_circle_config` — one row per kingdom: min sign-ins, window, sign-in mode and scope,
  online mode, dues/waiver/residence/principality/suspension rules, minimum knight months, use
  kingdom voting rules, watchlist minimum rank, officers-see-pipeline, caveat. No row means code defaults.
* `ork_knight_circle_admin` — GMoK and deputies, unique per kingdom and player.
* `ork_knight_candidate` — one row per (kingdom, player, order); stage, source, sort order.
* `ork_knight_candidate_history` and `ork_knight_candidate_note` cascade from the candidate.

Candidates are never hard-deleted. Dismissal is a stage, so reconcile does not re-add. The suggested
watchlist is computed, not stored. Migration is idempotent; restart the app container afterwards.

## Server

* `KnightCircle` (system/lib/ork3): permissions (`access`), config (pure `normalizeConfig`), officers
  (`setGmok`, `addDeputy`, `removeAdmin`), `roster` and pure `evaluateEligibility`. Roster = resident
  active non-Legend knights plus non-resident knights with in-scope sign-ins in the window. Attendance and
  dues scoping copy `Report::GetVotingEligible`. Cached 300s via ghettocache; saving config busts it.
* `KnightCandidate`: stages and `TRANSITIONS` (pure `canTransition`), `board`, `suggestions`, idempotent
  `reconcile`, `addCandidate`, `moveCandidate`, notes, and best-effort `onAwardGranted`, called from
  `Player::AddAward` inside try/catch.
* Transitions: watchlist->[masterhood, discussion, dismissed]; masterhood->[watchlist, discussion,
  dismissed]; discussion->[voting, holding, watchlist, dismissed]; voting->[elect, discussion, holding];
  holding->[discussion, voting, dismissed]; elect->[voting, discussion, holding]; dismissed->[watchlist];
  knighted->[]. The system alone may enter knighted and move watchlist->masterhood.
* Reconcile: candidates whose player now holds the order become knighted; watchlist rows with the master
  become masterhood; resident masters without the knighthood and no row are inserted as masterhood
  (`master_auto`). Never moves backwards.
* Model `Model_KnightCircle` is a thin pass-through; controllers `KnightCircle` (page) and
  `KnightCircleAjax` (GET roster/board/candidate; POST candidate_add/move, note_add/delete,
  config_save, gmok_set, deputy_add, admin_remove). Writes are POST only; audit via `dangeraudit`.

## Permissions

ORK admin: all. GMoK and deputies: view and manage. Kingdom AUTH_CREATE: view and appoint GMoK/deputies.
AUTH_EDIT officers: view (pipeline when `officers_see_pipeline`). Resident knights: view-only, own
candidacies hidden. Others: none. GMoK must be an active, non-Legend knight (ORK admin bypass).

## UI

`KnightCircle_index.tpl` in the `.rp-*` shell with Roster / Candidates / Settings tabs. Candidates is a
chevron path Watchlist > Masterhood > Under Discussion > Voting > Knight-Elect > Knighted with Holding as a
side column, a Suggested drawer, drag and drop plus a Move-to menu, a leaving-Voting modal (outcome,
votes, comment), and a side panel for facts, history and notes. Assets `knightcircle.js` / `knightcircle.css`.

## Kingdom page integration

`Controller_Kingdom::profile()` sets `CanViewKnightCircle` from `Model_KnightCircle->access()['CanView']`
(logged-in only, wrapped so a failure never breaks the page). `Kingdomnew_index.tpl` adds a Circle of
Knights link in Reports > Awards when allowed, and a "Circle of Knights: Guildmaster" link
(`KnightCircle/index/{kid}&tab=settings`) in the Admin tab for CanManageKingdom. A What's New item is
added to the unreleased block; version constants are not bumped here.

## Decisions on open questions

Fifth order is Battle (245). Roster includes residents plus signed-in non-residents. Own candidacies
are hidden from non-admin viewers. Officers see the pipeline by default. The GMoK must be a knight.
Deputies are supported. A knighthood revoked after Knighted stays `knighted`, with a flag.
