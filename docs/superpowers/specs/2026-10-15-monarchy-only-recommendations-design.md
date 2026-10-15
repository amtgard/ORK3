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
`CASE WHEN r.monarchy_only = 0 THEN 0 WHEN r.monarchy_only = 2 AND r.passed_to_local = 1 THEN 1 WHEN r.monarchy_only = 1 THEN 1 ELSE 2 END`.

**Fail closed on bad data.** A stored value outside `0..2` is read as
kingdom-level (`2`) by every reader — `effectiveLevel`, the SQL twin,
`Court::isRecHidden` (`<> 0`), the hidden half of the count (`<> 0`) and the
seconds check — while the public readers keep matching `monarchy_only = 0`
exactly. An unexpected value can only hide a rec from more people.

## Visibility rule

One domain class owns the rule: `system/lib/ork3/class.RecommendationVisibility.php`
(auto-registered by `startup.php` as `Ork3::$Lib->recommendationvisibility`;
not whitelisted in `orkservice/Json/index.php`, so not JSON-callable). It is the
only place the rule is expressed; Report, Player and Court call it. API:
`viewerFromToken($token): int`, `canSee(int $viewer, array $row): bool`,
`loadRec(int $recId): ?array` (the canSee input — recipient's own mundane
kingdom/park, author, level, pass-down, dismissed — in one query; null when
missing), `canSeeRecId(int $viewer, int $recId): bool` (a missing rec is never
visible), `sqlPredicate(int $viewer, string $recAlias, string $mundaneAlias, array $kingdomIds): string`,
`creatorLevel(int $creator, int $recipientId): int` (0 = not an officer),
`isOfficerInKingdom(int $viewer, int $kingdomId): bool` (officer of the kingdom
or of any park in it). Code that acts on one rec by id uses `loadRec` /
`canSeeRecId`; nothing else assembles the canSee input. For a row with recipient `R` (kingdom `K`, park `P`), author
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
into a predicate that reproduces `canManage` against the recipient's OWN
`m.kingdom_id` / `m.park_id` — the same values `canSee` reads — so a "drifted"
recipient (mundane kingdom ≠ their park's kingdom) gets the same verdict from
SQL and PHP:

```
( eff = 0
  OR (r.recommended_by_id = V AND r.mundane_id <> V)
  OR (r.mundane_id <> V AND m.kingdom_id IN (:officerKingdoms))
  OR (r.mundane_id <> V AND eff = 1 AND m.kingdom_id > 0 AND (
         m.park_id IN (:authParks)
      OR m.park_id IN (SELECT park_id FROM ork_park WHERE kingdom_id IN (:authKingdoms))
      OR (m.park_id = :seatPark AND m.kingdom_id = :seatKingdom) ...)) )
```

The viewer's grants come from their own authorization rows and
Monarch/Regent/Prime Minister seats, plus every descendant principality, and
each is confirmed with the same functions `canSee` uses:
`officerKingdoms` = kingdoms where `officerLevel(V, K, 0)` is kingdom-level
(the caller's `$kingdomIds` scope only adds candidates); `authKingdoms` =
`HasAuthority(V, AUTH_KINGDOM, K)` (park authority climbs to the PARK's own
kingdom); `authParks` = direct park authority; park seats stay
`(kingdom_id, park_id)` pairs because `canManage` matches a seat on both.
Grants and predicates are memoized per request (per viewer / per viewer +
aliases + scope), and the helper leaves the shared DB handle cleared for
callers that build it inline. The recipient exclusion is applied before the
admin grant, as in the row rule. Admin → `eff = 0 OR r.mundane_id <> V`. No
viewer → `eff = 0`.

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
  **stored** level (`RecommendationVisibility::canManageHidden`:
  `max(1, stored)`, out-of-range → kingdom). A kingdom-level hide therefore
  stays the kingdom's to lift even while it is passed down to local.
  Author-only visibility is **not** enough to unhide an officer's hide.
  The answer is not an oracle: anyone who could not change the flag — no
  officer authority over the recipient (non-officers, the recipient, a
  non-officer author) or unable to see the rec (a park officer facing a
  kingdom-level hide that is not passed down) — gets exactly the response a
  nonexistent id gets (`Recommendation not found.`), before the rec's state is
  consulted. An officer who can see the rec gets real answers (Success,
  "already visible", or the stored-level refusal for a passed-down kingdom hide).
  Bust caches, `dangeraudit`. Wired as a Recs Manager row action and
  `Recommendations/bulk` actions `monarchyonly` / `unmonarchyonly`.
- **Pass down** — unchanged write; effective level does the rest. The park
  pass-down notification text stays generic (it already exposes no detail).
- **Acting on a rec by id** — every Player write that takes a rec (or second)
  id checks visibility for the token's user FIRST and, for a hidden rec they
  cannot see, returns that method's own "not found" response:
  `DeleteAwardRecommendation`, `Snooze`/`UnsnoozeAwardRecommendation`,
  `RestoreAwardRecommendation`, `SetRecommendationPassedToLocal`,
  `EditAwardRecommendationReason`, `AddSecondToRecommendation`,
  `EditSecondNotes`, `WithdrawSecond` (the last two even for the second's own
  supporter). So the recipient cannot dismiss or probe hidden recs about
  themselves, and a park officer cannot act on a kingdom-level hide.
  `ResolveRecommendationCluster` skips hidden members the caller cannot see.
  A committed court line's OWN rec is the exception: `Court::commitStagedAward`
  closes it directly through `Player::resolve_court_line_recommendation`
  (in-process, underscored so not JSON-callable; that one id, only while live,
  only when it is about the line's recipient). The line authorizes acting on its
  own rec, so a park court that grants a kingdom-level hide closes it (Granted
  notification, seconds cascade) and it cannot be granted again. Committing a
  court line closes the line's own linked rec even when it was granted at a
  different rank than recommended: the line fulfills that rec.

## Read paths to filter

| Reader | Treatment |
|---|---|
| `Report::PlayerAwardRecommendations` | carry columns; filter in `applyViewerFlags` by token viewer |
| `Report::PlayerAwardRecommendationsPage` | SQL predicate in `$base` (rows, `Total`) and in the cluster `SupportCount` |
| `Report::PlayerAwardRecommendationsCount` (tab badge) | Kingdom-wide public count cached (viewer-agnostic); a `RecommendedBy`-scoped count (one user's own recs) is not cached — its key was never busted, so a rec hidden after caching was counted twice. Hidden portion counted uncached per request via SQL predicate, skipped when the scope has no live hidden rec |
| `Report::DeletedAwardRecommendations` | SQL predicate; no longer cached |
| `Player::GetPeerAwardRecommendations` (dup pre-check) | SQL predicate |
| `GetSecondsForRecommendations` | Drops hidden recs; the unfiltered in-process reader is `seconds_for_recommendations`. The underscore is the JSON guard: `JsonServer` refuses any method name containing `_`, while a lowercase first letter is not a guard (PHP method names are case-insensitive, so `Player/SecondsForRecommendations` would dispatch). |
| `Court::getPendingRecommendations` | inherits via Report (pass token) |
| Legacy `Controller_Player::index`, `Reports::player_award_recommendations` | pass token → filtered |
| Recs Manager CSV export | inherits via Page; adds a `Monarchy Only` column (`""`, `Park`, `Kingdom` from the cluster's `MonarchyOnlyLevel`) |
| `PlayerAjax/recommendations` (profile tab) | pass token → filtered |

Court lines are read for the caller: `getCourtAwards` / `getStagedAwards` take
the session user (threaded from Court detail/record and CourtAjax
`court_state` / `finalize_court`). A line whose Monarchy Only rec the caller
cannot see keeps `RecMonarchyOnly` but comes back with no `RecReason` and no
`RecByPersona` — a kingdom officer may put a kingdom-level hide on a PARK court
(their choice), and that court's park officers see only the notice ("Its reason
is visible only to officers at the recommendation's level."). No caller (0)
sees none. A caller who is the line's RECIPIENT (a court manager on their own
line) gets `RecMonarchyOnly = false`, no reason, no recommender and no rec id
(`RecommendationsId` null / 0, `IsWalkOn` true): they are not told a hidden rec
backs it. Commit, the own-rec close and pass-to-local read the rec id from the
database, never from these caller-filtered rows. `Court::addAward` takes the caller's uid (threaded
from `CourtAjax::requireCourtAuth` through `model.Court::add_award`) and refuses
a recommendation-backed line unless `canUseRecForLine` passes: the rec must be
about that recipient, and a Monarchy Only rec must be visible to the caller
(`canSee`; no caller → refused). Both refusals return one message, so the
answer never reveals a hidden rec.

## Court citation

`public_comment_cleared` is only the officer's explicit "(Clear)". A hidden
rec's reason is withheld by the rec's CURRENT Monarchy Only state wherever the
reason would be used, never by forcing that flag (a forced flag outlived an
unhide, and the line then never inherited its reason). So `addAward` (insert,
revive, reuse) and `updateAward` write the flag as the officer set it, and:

- `commitStagedAward` re-reads `RecMonarchyOnly` (`getAwardCommitFields` /
  `getAwardForCommit`) and falls back to the rec reason only when it is `0`;
  `setAwardId` freezes exactly what was published (nothing, for a hidden rec).
- Hidden at add and at commit → only what the officer typed is published.
  Unhidden before commit → the reason inherits normally.
- A hidden rec's reason is never published as a TYPED citation either. A line
  staged or saved while its rec was public can carry the reason verbatim
  (Grant modal pre-fill, Record view "Given", "(Start from Rec)" + Save, then
  Bulk Record Grants). At commit, `getAwardCommitFields` re-reads the rec's
  reason with its state; if the rec is Monarchy Only now and
  `trim(PublicComment) === trim(rec.reason)`, nothing is published and
  `setAwardId` blanks the stored citation so the Court Report agrees. Text the
  officer actually wrote is published as usual.
- New writes of that text are refused: `stageAward` and `updateAward` refuse a
  `PublicComment` equal (trimmed) to the line's rec reason while that rec is
  Monarchy Only. CourtAjax `grant_award` / `update_award` check first and answer
  `status 1` with `Court::HIDDEN_CITATION_MESSAGE` ("This citation is the text
  of a Monarchy Only recommendation, which is never published. Edit or clear it
  to continue.") — not the reload status, which a reload would never clear.
  Court reads (`getCourtAwards`, `getStagedAwards`) present such a stored
  citation as empty, so the Grant modal does not pre-fill it and Save does not
  re-post it: the next save clears it, and no line is left stuck.
- `SetRecommendationMonarchyOnly`, and `SetRecommendationPassedToLocal` for a
  Monarchy Only rec (its effective level changes), bump `row_version` on every
  still-open court line drawn from the rec (`Court::bumpOpenLinesForRec`).

**Planner client.** `addAward`'s response and `getCourtAwards` rows carry
`PublicCommentCleared` and `RecMonarchyOnly`. The Monarchy Only field carries
the line's persisted cleared flag (so a save posts it back unchanged). The
heartbeat's version stamp is built from court_award rows only, so a visibility
change reaches an open planner through that `row_version` bump: the next poll
re-sends the line and the reconcile rebuilds the field (hidden or unhidden),
and any stale write still carrying the old `RowVersion` (Save, Grant, Record
view citation) is refused with "This row changed — reload". For a `RecMonarchyOnly` line the planner (`Court_detail.tpl`, server- and JS-built rows) shows the reason only as
officer-only reference text: no ghost hint, no (Start from Rec)/(Undo clear),
the Grant modal's reason is never pre-filled, the herald sheet and Complete-modal
count never treat it as an inherited citation, and the Record view
(`Court_record.tpl`) does not pre-fill it.

## UI

- **Add Rec modals** (`Playernew_index.tpl` `#pn-rec-*`, `Kingdomnew_index.tpl`
  `#kn-rec-*`, `Parknew_index.tpl` `#pk-rec-*`): a "Monarchy Only" checkbox
  beside Anonymous, with helper text "Only officers will see this
  recommendation; the player will not." Offered on the Kingdom page to an
  officer of the kingdom or of any park in it (`isOfficerInKingdom`), on the
  Park page to an officer of the park or its kingdom, and on the Player page
  against the recipient. Because the Kingdom/Park player search can return a
  player the officer has no authority over, every recipient pick re-checks via
  `KingdomAjax`/`ParkAjax` `monarchyonlyeligible` (the caller's own
  `creatorLevel` over that recipient): not eligible → unticked, disabled,
  `data-tip` and a short inline note (`orkMonarchyOnlyRecheck` in
  `_monarchy_only_assets.tpl`). The server remains the authority. Dark mode checked.
- **Badge** `.rm-badge.rm-badge-monarchy` (+ `html[data-theme="dark"]`
  variant), text `Monarchy Only`, `data-tip`: park → "Visible to park and
  kingdom officers"; kingdom → "Visible only to kingdom officers". Markup comes
  from one output-free PHP helper, `_monarchy_only_badge.tpl`. Its CSS and JS
  twin (for JS-built rows) live in one assets partial, `_monarchy_only_assets.tpl`.
  Shown in `_rm_row.tpl`, Park recs tab, Kingdom recs panel, and Player profile
  recs table (JS-built rows — the JSON carries `MonarchyOnlyLevel`).
- **Recs Manager** Hide/Unhide row action + bulk menu items. The toggle's
  active style lives in `recs-manager.css`.
- **Grant from rec** (Recs Manager modal; Player/Kingdom/Park `*GiveFromRec`
  in `revised.js`): when the rec — for a cluster, the member whose reason would
  be used — is Monarchy Only, the public award note is left empty and a hint
  reads "This recommendation is Monarchy Only — its reason is not copied into
  the public award note." (`orkMonarchyOnlyNoteHint` in
  `_monarchy_only_assets.tpl`, dark mode included).

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
- No What's New entry: this is a general addition, not a release headline.
