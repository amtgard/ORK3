# Monarchy Only Recommendations — Design

Date: 2026-10-15 · Branch: `feature/hidden-recommendations`

## Intent

Officers sometimes need to record an award recommendation that the wider
populace (and especially the recipient) must not see — a surprise, a sensitive
peerage discussion. A **Monarchy Only** recommendation is a normal
recommendation with a visibility restriction: officers of the right scope see it
with a `(Monarchy Only)` badge; everyone else never sees it at all — not in
lists, counts, duplicate checks, exports, or the JSON service.

## Decisions (agreed)

| Topic | Decision |
|---|---|
| Who is an "officer" | `Court::canManage` — ORK authorization (edit) at the park/kingdom, or a Monarch/Regent/Prime Minister seat there. Same rule as the Recs Manager and Court planner. |
| Measured against | The **recipient's** current kingdom and home park (how every rec list is scoped). Parent-kingdom officers count for principality recipients. |
| Level set at create | The creator's **highest** authority over the recipient: kingdom officer → kingdom-level; park-only officer → park-level. |
| Non-officer sets flag | Rejected with a clear error (never silently dropped). |
| Author | Always sees their own rec, even after leaving office. |
| Recipient | Never sees hidden recs about themselves, even if they are an officer. |
| Pass down to local | A kingdom-level hide that is passed to local is **effectively** park-level. Un-pass restores kingdom-level. |
| Toggle later | Recs Manager row action + bulk action: Hide / Unhide. |
| Court finalize | A hidden rec's reason is never auto-published as the public award note; the officer may type one. |
| ORK global admin | Sees everything (as today), except hidden recs about themselves: the recipient exclusion is checked before the admin grant. |

## Data model

Migration `db-migrations/2026-10-15-recommendation-monarchy-only.sql`:

```sql
ALTER TABLE ork_recommendations
  ADD COLUMN monarchy_only TINYINT NOT NULL DEFAULT 0;
```

Values: `0` public · `1` park-level · `2` kingdom-level. Classified in
`tools/ork-db/manifests/migration-classification.json5`. (MyISAM — one ALTER.)

**Effective level** (the only value visibility logic reads):

```
effective = (monarchy_only = 2 AND passed_to_local = 1) ? 1 : monarchy_only
```

Computed, not written, so pass-down/un-pass need no extra write and un-pass is
lossless. SQL twin:
`CASE WHEN r.monarchy_only = 2 AND r.passed_to_local = 1 THEN 1 ELSE r.monarchy_only END`.

## Visibility rule

One domain class owns the rule: `system/lib/ork3/class.RecommendationVisibility.php`
(auto-registered by `startup.php` as `Ork3::$Lib->recommendationvisibility`;
not whitelisted in `orkservice/Json/index.php`, so not JSON-callable). It is the
only place the rule is expressed; Report, Player and Court call it. API:
`viewerFromToken($token): int`, `canSee(int $viewer, array $row): bool`,
`sqlPredicate(int $viewer, string $recAlias, string $mundaneAlias, array $kingdomIds): string`,
`creatorLevel(int $creator, int $recipientId): int` (0 = not an officer). For a row with recipient `R` (kingdom `K`, park `P`), author
`A`, effective level `L`, and verified viewer `V`:

```
visible =  L == 0
        || (V != R && (V is ORK global admin
                       || V == A
                       || (L == 1 && canManage(V, K, P) or canManage(V, parent(K), 0))
                       || (L == 2 && canManage(V, K, 0) or canManage(V, parent(K), 0))))
```

The recipient exclusion (`V != R`) is checked first and gates every grant,
including the admin grant, so no hidden rec about a player reaches that player.
`canManage` results are memoized per
`(V, K, P)` for the request — lists hit a handful of distinct parks, not one
lookup per row.

For SQL readers (paging, counts) the helper precomputes the viewer's authority
into a predicate:

```
( eff = 0
  OR (r.recommended_by_id = V AND r.mundane_id <> V)
  OR (r.mundane_id <> V AND eff = 1 AND (m.kingdom_id IN (:officerKingdoms) OR m.park_id IN (:officerParks)))
  OR (r.mundane_id <> V AND eff = 2 AND m.kingdom_id IN (:officerKingdoms)) )
```

`officerKingdoms` / `officerParks` are resolved for the context scope (the
kingdom(s) and parks the list covers), using `canManage`. The helper takes no
separate park-list parameter. The recipient exclusion is applied before the
admin grant, as in the row rule. Admin → predicate
`1=1` (after the recipient exclusion). No viewer → `eff = 0`.

## Viewer identity (security)

The JSON service exposes the `Report`/`Player` readers with caller-chosen
request keys, so the viewer for hidden-rec visibility comes **only** from a
validated `Token` (`Ork3::$Lib->authorization->IsAuthorized`). `RequestedBy`
is never trusted for this. No/invalid token → hidden rows dropped (fail
closed). Every in-app caller passes `$this->session->token` (controllers) or
the token it already holds (Court).

Controllers pass `$this->session->token` to every reader. The Court Planner
forwards that same token to Court's readers.

The cache of `PlayerAwardRecommendations` stays viewer-agnostic: rows carry
`MonarchyOnly` (raw), `PassedToLocal`, recipient park/kingdom, and author;
`applyViewerFlags` drops invisible rows per request and sets
`MonarchyOnlyLevel` (effective) on the survivors. Bump `REC_CACHE_VERSION`
(the row shape changed) and mirror it in `Player::bust_player_award_recs_cache`.

## Write paths

- **Create** — `Player::AddAwardRecommendation` accepts `MonarchyOnly`
  (truthy). If set: compute the creator's highest authority over the recipient
  (`2` if kingdom officer, else `1` if park officer); neither → error
  `"Only officers of this player's park or kingdom can mark a recommendation Monarchy Only."`.
  Store the level. Model → controllers: Player profile form, KingdomAjax and
  ParkAjax `addrecommendation` pass the field.
- **Hide / Unhide** — new `Player::SetRecommendationMonarchyOnly(Token,
  RecommendationsId, Hidden)`. Hide: level = actor's highest authority over the
  recipient, written with `GREATEST(monarchy_only, :level)` so re-hiding never
  downgrades an existing level. Unhide: requires officer authority at the
  **stored** level (`monarchy_only`, not the effective level). A kingdom-level
  hide therefore stays the kingdom's to lift even while it is passed down to
  local. Author-only visibility is **not** enough to unhide an officer's hide.
  Bust caches, `dangeraudit`. Wired as a Recs Manager row action and
  `Recommendations/bulk` actions `monarchyonly` / `unmonarchyonly`.
- **Pass down** — unchanged write; effective level does the rest. The park
  pass-down notification text stays generic (it already exposes no detail).
- **Seconds** — `AddSecondToRecommendation` refuses a rec the token viewer can't see.

## Read paths to filter

| Reader | Treatment |
|---|---|
| `Report::PlayerAwardRecommendations` | carry columns; filter in `applyViewerFlags` by token viewer |
| `Report::PlayerAwardRecommendationsPage` | SQL predicate in `$base` (rows, `Total`) and in the cluster `SupportCount` |
| `Report::PlayerAwardRecommendationsCount` (tab badge) | Public count cached (viewer-agnostic); hidden portion counted uncached per request via SQL predicate |
| `Report::DeletedAwardRecommendations` | SQL predicate; no longer cached |
| `Player::GetPeerAwardRecommendations` (dup pre-check) | SQL predicate |
| `GetSecondsForRecommendations` | Drops hidden recs; the in-process reader uses `secondsForRecommendations` |
| `Court::getPendingRecommendations` | inherits via Report (pass token) |
| Legacy `Controller_Player::index`, `Reports::player_award_recommendations` | pass token → filtered |
| Recs Manager CSV export | inherits via Page |
| `PlayerAjax/recommendations` (profile tab) | pass token → filtered |

Court lines already staged (`getCourtAwards`, `getAwardForCommit`) are inside a
court the officer can manage; a park court can only stage recs a park officer
can see. No change beyond the citation rule below.

## Court citation

`Court::addAward` with a `rec_id` whose rec has `monarchy_only > 0` inserts the
line with `public_comment_cleared = 1`. `commitStagedAward` then publishes only
what the officer typed. The planner still shows officers the reason.

The citation rule applies on every path that creates or reuses a court line:
insert, revive of a cancelled line, and reuse of an existing line. The commit
step also re-checks the recommendation's current Monarchy Only state, so a rec
hidden after its line was staged still does not publish its reason.

## UI

- **Add Rec modals** (`Playernew_index.tpl` `#pn-rec-*`, `Kingdomnew_index.tpl`
  `#kn-rec-*`, `Parknew_index.tpl` `#pk-rec-*`): a "Monarchy Only" checkbox
  beside Anonymous, with helper text "Only officers will see this
  recommendation; the player will not." Rendered only when the viewer is an
  officer of the page's park/kingdom (Player page: the recipient's park). The
  server remains the authority. Dark mode checked.
- **Badge** `.rm-badge.rm-badge-monarchy` (+ `html[data-theme="dark"]`
  variant), text `Monarchy Only`, `data-tip`: park → "Visible to park and
  kingdom officers"; kingdom → "Visible only to kingdom officers". Markup comes
  from one output-free PHP helper, `_monarchy_only_badge.tpl`. Its CSS and JS
  twin (for JS-built rows) live in one assets partial, `_monarchy_only_assets.tpl`.
  Shown in `_rm_row.tpl`, Park recs tab, Kingdom recs panel, and Player profile
  recs table (JS-built rows — the JSON carries `MonarchyOnlyLevel`).
- **Recs Manager** Hide/Unhide row action + bulk menu items. The toggle's
  active style lives in `recs-manager.css`.
- **What's New** entry: `notes_only`; see Out of scope.

## Testing

- PHPUnit (Integration, against `ork_test`): visibility matrix — {public, park,
  kingdom, kingdom+passed} × {author non-officer, recipient, recipient-who-is-officer,
  park officer of P, park officer of other park, kingdom officer, seat-only
  Monarch, plain player, no token, admin}. Create-level derivation and the
  rejection error. Hide/Unhide authority. Page `Total` equals delivered rows.
  Count badge. Peer check. Court line `public_comment_cleared`.
- curl: JSON service `Report/PlayerAwardRecommendations` etc. with and without a
  token, forged `RequestedBy` → no hidden rows.
- Browser: kingdom officer, park officer, plain player, recipient; light + dark.

## Out of scope

- Pre-existing: anonymous-recommender unmasking trusts `RequestedBy` (JSON
  caller can claim an admin id). Noted, not fixed here.
- Pre-existing: private-mode "own recs" filter matches `RecommendedById`, which
  masking nulls for anonymous recs.
- The What's New entry is `notes_only`, and `WHATS_NEW_VERSION` was not bumped.
  Release timing is the maintainer's call.
