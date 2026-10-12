# Consolidated Recommendation Grouping — Design

**Date:** 2026-06-11
**Branch:** `feature/court-planner`
**Status:** Approved design, pending spec review

## Summary

The Recommendations Manager renders one row per recommendation. In production, the same player
is frequently recommended for the same award by many different people — independently, as
separate rows rather than as seconds on one rec. This both clutters triage and **inverts the
signal**: strong consensus (15 people want this) looks like 15 scattered weak items.

This feature makes the Manager render **one row per cluster** — grouping parallel recommendations
by `(recipient, kingdomaward_id, rank)` — showing the combined advocate count and group-level
actions. Granting a grouped row resolves the entire cluster and (via the in-app notifications
feature) thanks every advocate at once. The same cluster-resolution applies when the award is
granted at court.

## Why (validated against production-shaped data)

A query over the live recommendation set found **3,657 clusters** with more than one live rec for
the same `(recipient, kingdomaward_id, rank)`, totaling **5,157 redundant rows** out of ~29,157
live recs (~18%). The largest clusters are **15 distinct recommenders** for one award on one
player. The "seconds" feature was meant to consolidate this but doesn't, because people keep
filing fresh recs instead of seconding.

## Goals

- Collapse parallel recommendations into a single, consolidated grid row.
- Surface a **support count** (number of distinct advocates) as a sortable headline — the
  consensus/priority signal, for free.
- **Group-grant**: grant the award once → resolve the whole cluster → notify every advocate.
- Apply the same cluster-resolution when the award is granted at court (fixes today's gap where a
  court grant leaves the other parallel recs dangling).

## Non-Goals (YAGNI for v1)

- **No destructive merge.** The underlying rec rows are never combined or rewritten; grouping is a
  presentation concern. No migration.
- C2 ("players who are due" suggestions) and C3 (smarter snooze) — separate follow-ups.
- No separate flat/grouped toggle — expanding a row reveals its members.
- No change to how recommendations are *created* or *seconded*.

## Grouping Model

- **Cluster key:** `(MundaneId, KingdomAwardId, Rank)`. For non-ladder awards `Rank` is 0, so the
  key is effectively `(recipient, award)`. A cluster of one renders like a normal row today —
  **one uniform row model**, no special-casing.
- **Built server-side** in `controller.Recommendations::manage`: after loading `$Recommendations`
  (from `Reports->recommended_awards`), build `$Groups`, each carrying:
  - the cluster key fields + display fields (Persona, AwardName, IsLadder, eligibility flags —
    identical across members since the award/rank/recipient match);
  - `Members[]` — the underlying recs (each with RecommendationsId, RecommendedByName/Id, Reason,
    DateRecommended, Seconds[]);
  - `SupportCount` — the number of **distinct advocates**: the union of the members'
    recommenders (`RecommendedById`) and all members' seconders (`Seconds[].SupporterMundaneId`),
    excluding the recipient. (This is the consensus headline; it de-duplicates a person who both
    recommended and seconded, and a person who seconded two of the parallel recs.)
  - `OldestAgeDays` — age of the earliest member (the cluster has been pending this long);
  - `OnCourt` — true if **any** member is on a court (with the court badge data from `CourtMap`);
  - `MemberRecIds[]` — the live RecommendationsId list, for group actions.
- The template renders one row per group; **Expand** lists each member: recommender + reason +
  that member's seconds (reusing the existing seconds-rendering parity).

## Shared Cluster Resolution

A single operation resolves an entire cluster when its award is granted:

**`ResolveRecommendationCluster(recipientMundaneId, kingdomAwardId, rank, grantedById)`** (new, on
`system/lib/ork3/class.Player.php`):
1. Select all live recs (`deleted_at IS NULL`) matching `(mundane_id, kingdomaward_id, rank)`.
2. For each, in this order (matching the established Thread-B ordering):
   a. `Ork3::$Lib->notification->notifyRecommendationGranted(recId, grantedById)` (non-blocking,
      wrapped) — captures recommender + live seconders **before** any soft-delete;
   b. soft-delete the rec (`deleted_by`, `deleted_at`) and audit it;
   c. cascade soft-delete its `recommendation_seconds`.
3. Returns the count resolved.

This reuses the exact per-rec resolution semantics already shipped in
`DeleteAwardRecommendation`/`CourtAjax::grant_award`; it simply applies them across the cluster.

### Call sites
- **Manager group-grant** (new AJAX): `Recommendations_manage.tpl`'s grant flow does
  `Admin/addaward` once (writes the award), then calls a **new** endpoint
  `resolverecommendationcluster` on `controller.KingdomAjax` / `controller.ParkAjax`
  (`MundaneId`, `KingdomAwardId`, `Rank`) → `Model_Player->resolve_player_recommendation_cluster`
  → `class.Player::ResolveRecommendationCluster`. Authority: same park/kingdom check the existing
  `dismissrecommendation` endpoint applies. Two calls total, regardless of cluster size.
- **Court grant** (`controller.CourtAjax::grant_award`): replace today's single-rec soft-delete +
  seconds cascade with one `ResolveRecommendationCluster` call keyed on the granted court award's
  `mundane_id` / `kingdomaward_id` / `rank` and the acting `$uid`. (This supersedes the
  single-rec block added in the integration-QA fix.)

`grantedById` is excluded from notifications by the existing helper, so an officer granting a
cluster they also recommended/seconded won't self-notify.

## Manager UI (rework of `Recommendations_manage.tpl`)

The grid becomes group-oriented. Each group row:
- **Recipient** (persona link + park) · **Award** (name + rank/non-ladder + eligibility badges) ·
  **Recommended** (oldest date + `OldestAgeDays`; "+N recommenders" when the cluster has >1
  member) · **Support** (`SupportCount` chip; expands to the member list) · **Court** badge ·
  **Actions**.
- **Expand** (Support chip / a "members" affordance): an inline detail row listing each member —
  recommender persona + reason + that member's seconds — reusing the current expand-row styling so
  column alignment holds.
- **Actions** (operate on the whole cluster):
  - **Grant** — `Admin/addaward` once → `resolverecommendationcluster`. On success the group row is
    removed. When the cluster is already on a court, the existing "already on a court" 3-way modal
    (Grant & Remove / Grant & Leave / Go Back) still applies; "Leave on Court" marks the court
    award given as today.
  - **Add to Court** — adds **one** `court_award` (recipient/award/rank), linking the **oldest**
    member's `RecommendationsId` for traceability. The cluster still resolves on the eventual court
    grant by recipient/award/rank, so the single link is cosmetic.
  - **Snooze** / **Unsnooze** and **Dismiss** — loop the `MemberRecIds[]` over the existing
    single-rec endpoints (client-side sequential loop, matching today's bulk pattern). Dismiss is a
    plain dismissal (no `Granted` flag → no notifications).
- **Selection + bulk bar** continue to work, now selecting groups; bulk Add-to-Court / Snooze /
  Dismiss loop members.

**Filters & sort** operate on group rows:
- Search (recipient), Eligibility, Court, Park filters — applied to the group (eligibility/award
  are uniform within a cluster; Court = any member on a court; Park = recipient's park).
- Sort: Recipient, Award, oldest date, age, and **Support count** (newly meaningful — high
  consensus sorts to the top).

## Data Flow

1. `controller.Recommendations::manage` loads recs, builds `$Groups` (+ existing CourtMap / Courts
   / Parks), renders the template.
2. The grid renders one row per group; filter/sort/expand/selection are client-side over group
   rows.
3. Group-grant → `Admin/addaward` + `resolverecommendationcluster` (or, at court,
   `grant_award` → `ResolveRecommendationCluster`) → all member recs resolved + every advocate
   notified.

## Error Handling / Edge Cases

- A cluster whose members span being on/off a court: the Court badge reflects "any member on a
  court"; resolution clears all members regardless.
- If `Admin/addaward` fails, the cluster is **not** resolved (no notifications, group stays).
- `resolverecommendationcluster` and `ResolveRecommendationCluster` no-op cleanly on an empty
  cluster (e.g., a race where members were already resolved).
- Notification writes remain non-blocking — a notify failure never aborts a grant or a soft-delete.
- Empty states unchanged ("no recommendations" vs "no rows match filters").

## Testing

- **Grouping correctness:** members with identical `(recipient, kingdomaward_id, rank)` collapse to
  one row; different ranks of the same ladder award stay separate; singletons render normally.
- **SupportCount:** equals distinct advocates (recommenders ∪ seconders, recipient excluded), with
  a person counted once across multiple member recs and across recommend+second.
- **Group-grant resolves the cluster:** granting a 15-member cluster soft-deletes all 15 recs +
  cascades their seconds + writes 15+ notifications (one per recommender + each seconder), verified
  by DB read-back; the award is written once.
- **Court grant resolves the cluster:** `grant_award` on an award whose recipient/award/rank has N
  parallel recs clears all N + notifies (verified by inspection where court data is absent locally;
  by DB read-back where present).
- **Dismiss group** does not notify; **Snooze group** snoozes all members.
- **Add to Court** creates exactly one court award for the group.
- **Filters/sort:** support-count sort orders clusters by consensus; eligibility/court/park filters
  apply at the group level.
- **Conventions:** `$DB->Clear()` before raw execute; `.tpl` plain PHP; `tnConfirm` not native;
  dark-mode walk of the regrouped grid + expand rows; no native `title` tooltips.

## Build Sequence

1. `class.Player::ResolveRecommendationCluster` (+ `Model_Player` passthrough
   `resolve_player_recommendation_cluster`).
2. `KingdomAjax`/`ParkAjax` `resolverecommendationcluster` endpoint.
3. `CourtAjax::grant_award` switched to `ResolveRecommendationCluster`.
4. `controller.Recommendations::manage` builds `$Groups`.
5. `Recommendations_manage.tpl` regrouped grid: group rows, member expand, group actions, adapted
   filters/sort.
6. Verification (curl-auth + DB read-back of cluster resolution + notifications; dark-mode walk).

## Risks / Notes

- **Largest change of the three threads** — it reworks the Manager grid + its client JS. Keep the
  group row's data attributes a superset of today's so the existing filter/sort JS adapts with
  minimal change.
- **Court path can't be fully exercised locally** when no court data exists; the cluster resolver is
  shared with the Manager path (which *is* locally testable), so the core logic is covered.
- `ResolveRecommendationCluster` must preserve the **notify-before-soft-delete** ordering per
  member, or seconders are lost — same invariant as Thread B.
- Supersedes the single-rec soft-delete + seconds-cascade block in `CourtAjax::grant_award` (added
  during integration QA); that block is replaced, not kept alongside.
