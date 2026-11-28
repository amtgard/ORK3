# Circle of Knights Manager — implementation plan

Branch: `feature/knight-management`. Kingdom-level module. Build contract for all chunks.

## Conventions (must follow)
- SQL only in `system/lib/ork3/class.*.php` (instances auto-created as `Ork3::$Lib-><lowercasename>`; constructors must be cheap). `orkui/model/model.X.php` = thin pass-through. Controllers read session/request and call the model.
- Return envelope `['Status'=>0,...]`; `1` bad request; `3` not authorised. AJAX returns `{status:0|1|3|5,error}` (copy `requireLogin/envelope/jsonOut` from `controller.BonusCreditAjax.php`). Writes are POST only.
- All controller action params optional (tests/Unit/HttpRouteHardeningTest.php). Every `DataSet()` preceded by `$this->db->Clear()` in same function (DataSetClearLintTest). Use `ExecuteChecked()` for writes.
- `mysql_real_escape_string` is a no-op: cast ids `(int)`, whitelist enums, escape text with a private `esc()` copied from BonusCredit (`str_replace(["'", '\\'], ["''", '\\\\'])`).
- Audit via `Ork3::$Lib->dangeraudit->audit(...)` in try/catch (see BonusCredit ~1860). Refuse Legends: `Legend::isLegend($mid)` -> `Legend::REFUSED`.
- Migrations: `db-migrations/YYYY-MM-DD-slug.sql`, idempotent, utf8mb4 InnoDB, header comment; note "restart app container after applying (APCu schema cache)".
- UI: `data-tip` tooltips, no native alert/confirm/prompt, dark mode `html[data-theme="dark"]`, phone layouts, `.rp-*` shell, `?v=filemtime()` cache busting.
- Never stage `system/lib/ork3/class.Authorization.php`. Stage explicit paths only. Never add new classes to `orkservice/Json/index.php`.

## Orders
`KnightCircle::ORDERS` = `Award::GetKnightAwardMap()`: 17 Flame, 18 Crown, 19 Serpent, 20 Sword, 245 Battle ("Chivalry" does not exist in the repo; fifth order is Knight of Battle).
Holding = `COALESCE(alias.award_id, ka.award_id) IN (17,18,19,20,245)` joining `ork_awards ma -> ork_kingdomaward ka -> ork_award a`, LEFT JOIN `ork_award alias ON alias.award_id = ma.alias_award_id`, `(ma.revoked=0 OR ma.revoked IS NULL)` (see `Report::PlayerAwards` ~414 and `GetVotingEligible` is_knight subquery).
LADDER_ORDER (ladder award_id => order): 21,22,23=>17; 24,25,26=>19; 27=>20; 239=>18; 243=>245 (mirrors private `Report::ladderKnightGroupMap` ~9790). Masters: 1,2,3=>17; 4,5,6=>19; 12=>20; 240=>18; 244=>245 (derive via `Award::GetLadderMasterMap()`). Ladder rank = `GREATEST(MAX(ma.rank), COUNT(ma.awards_id))` per ladder excluding revoked (see `Report::GetLadderAwardGrid` ~9257).

## Data model — `db-migrations/2026-11-28-knight-circle.sql`
Tables: `ork_knight_circle_config` (kingdom_id PK; min_signins SMALLINT dflt 6; window_months TINYINT dflt 6; signin_mode ENUM count|days|weeks dflt count; signin_scope ENUM kingdom|anywhere dflt kingdom; online_mode ENUM kingdom_setting|include|exclude; require_dues 1; dues_scope ENUM kingdom|anywhere; require_waiver 0; require_residence 1; include_principalities 1; exclude_suspended 1; min_knight_months 0; use_kingdom_voting_rules 0; watchlist_min_rank 9; officers_see_pipeline 1; caveat VARCHAR(1000); updated_by; updated_at).
`ork_knight_circle_admin` (circle_admin_id AI PK; kingdom_id; mundane_id; role ENUM gmok|deputy; added_by; added_at; UNIQUE(kingdom_id,mundane_id)).
`ork_knight_candidate` (candidate_id AI; kingdom_id; mundane_id; award_id (order); stage ENUM watchlist|masterhood|discussion|voting|holding|elect|knighted|dismissed; source ENUM manual|suggested|master_auto; sort_order; stage_changed_at; stage_changed_by (0=system); knighted_awards_id NULL; created_by; created_at; UNIQUE(kingdom_id,mundane_id,award_id)).
`ork_knight_candidate_history` (history_id; candidate_id FK cascade; from_stage NULL; to_stage; actor_id; comment VARCHAR(500); votes_for/against/abstain SMALLINT NULL; created_at).
`ork_knight_candidate_note` (note_id; candidate_id FK cascade; author_id; body TEXT (cap 4000); created_at; deleted_at; deleted_by).
Candidacy = one row per (kingdom, player, order). Suggested watchlist is computed not stored. Dismissal is a stage (stops reconcile re-adding). Never hard-delete candidates. No config row => code DEFAULTS.

## KnightCircle (`class.KnightCircle.php`)
Consts ORDERS, ORDER_NAMES, LADDER_ORDER, DEFAULTS. Methods: `isOrkAdmin`, `canAppoint` (kingdom AUTH_CREATE; ORK admin), `isCircleAdmin` (gmok/deputy row or ORK admin), `isResidentKnight`, `access($uid,$kid)` => CanView/CanManage/CanAppoint/IsKnight/IsOfficer, static `masterOrderMap`, `kingdomFamily($kid,$includePrincipalities)` (wrap `Kingdom::GetStatsKingdomIds`), `config`, static pure `normalizeConfig` (enum whitelist; ranges min_signins 0-52, window_months 1-24, min_knight_months 0-600, watchlist_min_rank 1-12; returns ok/config/error), `saveConfig` (circle admin; audit; bust cache), `admins`, `setGmok` (canAppoint; target active, non-Legend, holds knighthood unless ORK admin; replaces old gmok), `addDeputy`, `removeAdmin`, `roster($uid,$kid,$orderAwardId=0)` => Knights[{MundaneId,Persona,ParkId,ParkName,HomeKingdomId,Resident,Orders[],SignIns,DuesPaid,DuesUntil,Waivered,Suspended,KnightMonths,Eligible,Reasons}], Summary{Total,Eligible,ByOrder}, Config, WindowStart; static pure `evaluateEligibility(array $facts,array $config)` => Eligible/Reasons.
Roster = active non-Legend holders of a live knighthood whose home kingdom is in family, plus non-resident knights with in-scope sign-ins in window (Resident=false). Attendance scoping copied from `Report::GetVotingEligible`: `a.park_id IN (SELECT park_id FROM ork_park WHERE kingdom_id IN (...))` or `a.park_id=0 AND a.kingdom_id=...`; online via `Kingdom::CountsOnlineAttendance` and `a.online=0`. Dues: `EXISTS(ork_dues d WHERE d.revoked != 1 AND (d.dues_until>=CURDATE() OR d.dues_for_life=1) AND d.park_id IN (...))`. If `use_kingdom_voting_rules` and `VotingRules::rulesForKingdom($k)` non-null, AND with `Report::GetVotingEligible(['KingdomId'=>$k])` (+ `ActiveKnight` if threshold set). Cache roster via `Ork3::$Lib->ghettocache` 300s; saveConfig busts.

## KnightCandidate (`class.KnightCandidate.php`)
STAGES, BOARD_STAGES, TRANSITIONS: watchlist=>[masterhood,discussion,dismissed]; masterhood=>[watchlist,discussion,dismissed]; discussion=>[voting,holding,watchlist,dismissed]; voting=>[elect,discussion,holding]; holding=>[discussion,voting,dismissed]; elect=>[voting,discussion,holding]; knighted=>[] (system entry only); dismissed=>[watchlist]. `canTransition($from,$to,$system=false)` pure; system may do *->knighted and watchlist->masterhood.
Methods: `board` (reconcile first; Stages, Suggestions, Reconciled; a non-admin viewer's own candidacies omitted), `suggestions($kid,$minRank)` (residents, rank>=min, not Legend, exclude holders and any existing candidate row; unmapped ladders => OrderAwardId 0, GMoK picks), `reconcile($kid)` idempotent actor 0 history "Automatic: ...": (1) any candidate whose player now holds the order => knighted w/ knighted_awards_id, from any stage; (2) watchlist rows whose player holds mapped master => masterhood; (3) INSERT IGNORE masterhood/source master_auto for resident active non-Legend masters lacking the matching knighthood and with no row; never move backwards. `addCandidate`, `moveCandidate($uid,$candidateId,$toStage,$comment,$votes)` (votes only when from=voting), `candidate`, `addNote`, `deleteNote` (author or admin, soft), `onAwardGranted($mundaneId,$awardId,$awardsId)` best-effort (knighthood => matching rows -> knighted; master => matching watchlist rows -> masterhood; never creates rows; swallow all errors).
Hook: in `Player::AddAward` after `$new_award_id` set: `try { Ork3::$Lib->knightcandidate->onAwardGranted((int)$request['RecipientId'], (int)$request['AwardId'], $new_award_id); } catch (\Throwable $e) {}`. Optionally same in UpdateAward.

## Model, controllers, permissions
`orkui/model/model.KnightCircle.php` (`Model_KnightCircle`): pass-throughs access, config, save_config, admins, set_gmok, add_deputy, remove_admin, roster, board, candidate, add_candidate, move_candidate, add_note, delete_note.
`controller.KnightCircle.php`: `index($kingdom_id=null)` template `../revised-frontend/KnightCircle_index.tpl`; tab via `$_GET['tab']` roster|pipeline|settings; JSON window.KC_CFG.
`controller.KnightCircleAjax.php`: GET roster, board, candidate; POST candidate_add, candidate_move, note_add, note_delete, config_save, gmok_set, deputy_add, admin_remove.
Matrix: ORK admin all. GMoK/deputy: view all + manage. AUTH_CREATE: view, may appoint GMoK/deputies. AUTH_EDIT officers: view (pipeline if officers_see_pipeline). Resident knights: view-only, own candidacies hidden. Everyone else: none.

## UI
`KnightCircle_index.tpl` (.rp-* shell, back chip to Kingdom/profile/{kid}); tabs Roster / Candidates / Settings. Roster: order pills (All + 5 orders, belt colours from `lg-cell-knight-*` in Reports_laddergrid.tpl), eligible-only/resident-only toggles, stat tiles, DataTables table, CSV export, eligibility rules sentence + caveat. Settings: config form (read-only for non-admins), Circle Officers card with player picker (`KingdomAjax/playersearch/{kid}&scope=own&q=`, fetch-driven). Candidates: Salesforce-style chevron path Watchlist > Masterhood > Under Discussion > Voting > Knight-Elect > Knighted, Holding as side column; Suggested drawer above Watchlist (rank >= min) with Promote (order picker if unmapped) / Dismiss; cards; native DnD + "Move to..." menu; leaving Voting opens in-page modal (outcome, votes, comment); side panel with facts, history timeline, notes; show-dismissed toggle; refresh/reconcile button; order filter. Assets `orkui/template/default/script/knightcircle.js`, `.../style/knightcircle.css` (`.kc-*`). Kingdom page: link in Reports "Awards" group of `Kingdomnew_index.tpl` when `$CanViewKnightCircle`, and a GMoK-designation link in Admin tasks for `CanManageKingdom` (`KnightCircle/index/{kid}&tab=settings`).

## Chunks
1. Schema + KnightCircle + model + stub KnightCandidate (STAGES/TRANSITIONS, methods return Status 1 "Not implemented") + `tests/Unit/KnightCirclePureTest.php` + `tests/Integration/KnightCircleTest.php`.
2. KnightCandidate full + Player::AddAward hook + `tests/Unit/KnightCandidatePureTest.php` + `tests/Integration/KnightCandidateTest.php`.
3. Both controllers + template + JS + CSS.
4. Kingdom controller flag + Kingdomnew_index.tpl links + whats_new_content.php item + `docs/knight-circle-guide.md`.
Order: 1 first; then 2, 3, 4 in parallel.

## Open questions (defaults chosen)
Fifth order = Battle(245); ladder->order map per code; roster = residents + signed-in non-residents; own candidacies hidden from non-admin viewers; officers see pipeline by default; GMoK must be a knight (ORK admin bypass); deputies supported; revoked knighthood after Knighted keeps `knighted` with a flag.
