# Schema Drift Audit — 2026-08-28

**Branch:** `feature/officer-admin-expansion`
**Question this audit answers:** three `ork_kingdomaward` columns (`is_ladder`, `max_level`,
`disabled`) were found by accident to exist in dev/prod but in no repo migration. How many more
are there?

**Answer: 173 schema-object divergences between the live dev database and the repo's rendered
schema, of which exactly one finding is DEPLOY-BREAKING — and it is worse than `disabled` was.**

---

## 🚨 DEPLOY-BREAKING — act on this before deploying

### `ork_recommendations.snoozed_by_id` and `ork_recommendations.passed_to_local`

Both columns exist in the live dev database. **Neither exists in `tools/ork-db/rendered/sandbox.sql`,
and neither is created by any file in `db-migrations/` or `migrations/`.** They appear nowhere in
the repo except the one file that reads them.

**Where the repo reads them** — `system/lib/ork3/class.Kingdom.php`, inside
`GetAdminDashboard()` (declared at line 70):

```php
// system/lib/ork3/class.Kingdom.php:117-131
$r = $DB->DataSet(
    "SELECT
        (SELECT COUNT(*) FROM " . DB_PREFIX . "recommendations rc
            JOIN " . DB_PREFIX . "mundane rm ON rm.mundane_id = rc.mundane_id
          WHERE rm.kingdom_id = " . $kingdom_id . "
            AND rc.deleted_at IS NULL
            AND COALESCE(rc.snoozed_by_id, 0) = 0        // <-- line 123, column does not exist in repo schema
            AND COALESCE(rc.passed_to_local, 0) = 0) AS open_recs,   // <-- line 124, same
        ... AS unwaivered_active,
        ... AS waivered_members"
);
```

| Evidence | Location |
|---|---|
| Read of `rc.snoozed_by_id` | `system/lib/ork3/class.Kingdom.php:123` |
| Read of `rc.passed_to_local` | `system/lib/ork3/class.Kingdom.php:124` |
| Introduced by | commit `9a2b8a5c` "Enhancement: Kingdom Admin console usability pass — work queue, layout, navigation" — **on this branch; not on `master`** |
| Not created by | any file in `db-migrations/` (70 files) or `migrations/` (8 files) or `ork.sql` |
| Model membrane | `orkui/model/model.Kingdom.php:166` `get_admin_dashboard()` |

**Why this is worse than `disabled`.** `disabled` was a yapo write, and yapo drops columns it can't
map, so it failed quietly and locally. This is a **raw `DataSet()` read**, and PDO here runs
`ERRMODE_WARNING` (`system/lib/Yapo2/class.YapoMysql.php:23`). On a schema without these columns the
statement errors, `DataSet()` returns `false`, and the guarded block at `class.Kingdom.php:132`
(`if ($r !== false && $r->Next())`) is skipped **entirely** — so all three counters in that query
(`OpenRecommendations`, `UnwaiveredActive`, `WaiveredMembers`) silently vanish. No error surfaces.

**And one of those counters gates a destructive operation:**

```php
// orkui/controller/controller.KingdomAjax.php:554-556
$dash = $this->Kingdom->get_admin_dashboard($kingdom_id);
$waiverCount = (int)($dash['Queue']['WaiveredMembers'] ?? 0);
// ... then Player->reset_waivers() runs and $waiverCount is reported to the operator
```

On a fresh deploy, **Reset Waivers wipes every waiver in the kingdom and reports that it cleared 0
players.** The `?? 0` fallback converts a failed query into a confident, wrong answer. The second
consumer is the Kingdom Admin console itself (`orkui/controller/controller.Admin.php:2169`, inside
`load_kingdom_admin_data()`), where the work queue simply renders blank.

**Options (deliberately not implemented — this audit sizes, it does not fix):**

1. Add a migration creating both columns (plus the four sibling columns from the same unshipped
   feature: `snoozed_monarch_id`, `snoozed_regent_id`, `passed_to_local_at`, `passed_to_local_by`),
   matching the live types below. This is the `2026-08-27-kingdomaward-ladder-columns.sql` pattern.
2. Or drop the two `COALESCE(...)` predicates from `GetAdminDashboard()` if the snooze /
   pass-to-local feature is not part of this branch's shipping scope.

Either way, **also fix `controller.KingdomAjax.php:554`**: a missing `WaiveredMembers` must abort or
warn, never coerce to `0` in front of a destructive reset. That bug outlives the schema fix.

Live types, for whichever route is taken:

| Column | Live type |
|---|---|
| `ork_recommendations.snoozed_by_id` | `int(11)` NULL DEFAULT NULL |
| `ork_recommendations.snoozed_monarch_id` | `int(11)` NULL DEFAULT NULL |
| `ork_recommendations.snoozed_regent_id` | `int(11)` NULL DEFAULT NULL |
| `ork_recommendations.passed_to_local` | `tinyint(4)` NOT NULL DEFAULT 0 |
| `ork_recommendations.passed_to_local_at` | `timestamp` NULL DEFAULT NULL |
| `ork_recommendations.passed_to_local_by` | `int(10) unsigned` NULL DEFAULT NULL |

---

## Method

Comparing "live" to "the repo" needs a definition of the repo's schema. There are **two** migration
pipelines in this repo, which is itself part of the problem:

| Pipeline | Contents | Rendered into `sandbox.sql`? | Covered by `drift-check`? |
|---|---|---|---|
| `db-migrations/` | 70 `.sql` files | Yes, per `tools/ork-db/manifests/migration-classification.json5` | Yes |
| `migrations/` | 8 `.sql` files (officer registry, RBAC, qual-test perms) | **No** | **No** |

The baseline used here is `tools/ork-db/rendered/sandbox.sql` — which is `ork.sql` (the
schema-of-record) plus every classified `db-migrations/` entry plus post-schema index and legacy
supplement blocks. Its schema section (lines 12–2481) was loaded into a throwaway database
`drift_audit_scratch`, and both sides were read out of `information_schema` and diffed
mechanically (tables, columns with type/nullability/default/extra, indexes with column order,
engine and collation). Triage then grepped `system/lib/ork3/`, `orkui/` and `orkservice/` for each
divergent identifier, in **both** spellings the codebase uses — the literal `ork_foo` and the ORM's
`DB_PREFIX . 'foo'`. Tables created by `migrations/` were credited as "tracked" even though they are
absent from the render.

---

## Divergence inventory

| Category | Count |
|---|---|
| Tables live-only (absent from repo render) | 89 |
| Tables repo-only (absent from live) | 1 |
| Columns live-only, on tables present in both | 48 |
| Columns repo-only, on tables present in both | 1 |
| Columns whose type / nullability / default differs | 7 |
| Indexes live-only, on tables present in both | 14 |
| Indexes repo-only, on tables present in both | 1 |
| Tables differing in engine or collation | 12 |
| **Total** | **173** |
| *(plus)* tables referenced by branch code but present in **neither** | 3 |

### Triage

| Class | Count | What |
|---|---|---|
| **DEPLOY-BREAKING** | **1 finding / 2 columns** | `ork_recommendations.snoozed_by_id`, `.passed_to_local` — see above |
| ORPHAN | 82 tables + 46 columns + 13 indexes | Live-only, zero references from branch code |
| TRACKED-BUT-UNRENDERED | 6 tables | Created by `migrations/`, used by branch code, absent from the render |
| REPO-ONLY | 1 table, 1 column, 1 index, 6 engine rows | Migrations that exist but have not been run on the local mirror |
| COSMETIC | 7 column diffs + 6 collation/engine rows | No behavioural consequence on this branch |

#### ORPHAN — live-only, no branch code touches them (low risk)

The local `ork` mirror is shared across branch checkouts, and most live-only objects are other
branches' work sitting in the same volume. Verified zero references on this branch:

- **CMS / OGRE (14 tables)** — `ork_cms_audit`, `_block`, `_grant`, `_media`, `_nav_item`, `_page`,
  `_post`, `_post_tag`, `_redirect`, `_revision`, `_site`, `_tag`, `_theme`, `_view`
- **Voting (9)** — `ork_voting_active_ballot`, `_audit`, `_ballot`, `_choice`,
  `_eligibility_snapshot`, `_event`, `_race`, `_runner`, `_vote`
- **Arts & Sciences (11)** — `ork_as_award`, `_competition`, `_criterion`, `_entry`, `_judge`,
  `_participant`, `_preset_award`, `_preset_taxonomy`, `_result_snapshot`, `_score`, `_taxonomy`,
  `_winner`
- **Modern Tournament module** — tables `ork_tournament_event`, `_reeve`, `_seq`,
  `ork_participant_teams`, `ork_participant_team_members`, `ork_point_score`; **plus 30 of the 48
  live-only columns**, on `ork_bracket` (10), `ork_match` (5), `ork_participant` (13),
  `ork_tournament` (2); plus 12 of the 14 live-only indexes. The Tournament code on this branch is
  the 344-line legacy SOAP version (`system/lib/ork3/class.Tournament.php`,
  `orkui/controller/controller.Tournament.php`) and references none of them.
- **Others** — `ork_court*` (3), `ork_scroll_*` (3), `ork_waiver_*` (3), `ork_treasury_*` (3),
  `ork_inventory_*` (2), `ork_*_design` (3), `ork_*_milestones` (3), `ork_event_site_*` (3),
  `ork_event_meal`, `ork_event_kingdom_share`, `ork_friendship`, `ork_friend_block`,
  `ork_notification`, `ork_shortlink`, `ork_recommendation_support`, `ork_idp_claim_token`,
  `ork_idp_completion_jti`
- **Manual scratch / backup residue** — `_scr_part`, `tmp_fsn`, `bak_awards`, and six
  `*_myisam` copies (`ork_account_myisam`, `ork_attendance_myisam`, `ork_awards_myisam`,
  `ork_credential_myisam`, `ork_event_calendardetail_myisam`, `ork_mundane_myisam`) left by the
  InnoDB conversion work. These are unambiguous junk in the dev mirror. (`_ork_canary_prod`, also live-only, is
  intentional: `db-migrations/2026-07-07-add-prod-canary.sql`, deliberately `render: skip`.)
- **Other live-only columns with zero branch references** — `ork_awards.court_award_id`,
  `ork_awards.source_reason`, `ork_mundane.player_since_override`, `ork_mundane.pronoun_freetext`,
  `ork_mundane_design.{display_coronet,display_master_phoenix,display_paragon_animation,name_shimmer,paragon_frame_class_id}`,
  `ork_event_schedule.site_location_id`, `ork_idp_auth.{idp_mirror_status,idp_mirror_last_attempt}`,
  and the four sibling snooze/pass columns listed in the DEPLOY-BREAKING section.

> ⚠️ `ork_mundane.pronoun_freetext` is worth a second look before the next merge. It is a real,
> documented feature elsewhere in the project and is untracked by any migration here. It is an
> ORPHAN *on this branch only* — whichever branch owns the pronouns feature has the same
> `disabled`-class exposure this audit was commissioned to find.

#### TRACKED-BUT-UNRENDERED — real dependency, wrong pipeline (medium risk)

Referenced by branch code, created by a tracked repo `.sql` file, but invisible to the ork-db render
and to `drift-check`:

| Table | Created by | Referenced at |
|---|---|---|
| `ork_role` | `migrations/rbac-tables.sql:24` | `system/lib/ork3/class.RBACService.php:336,733,849` |
| `ork_permission` | `migrations/rbac-tables.sql:9` | `system/lib/ork3/class.PermissionRegistry.php:536` |
| `ork_role_permission` | `migrations/rbac-tables.sql:42` | `system/lib/ork3/class.RBACService.php:51` |
| `ork_user_role` | `migrations/rbac-tables.sql:55` | `system/lib/ork3/class.RBACService.php` (13 sites) |
| `ork_rbac_audit` | `migrations/rbac-tables.sql:77` | `system/lib/ork3/class.RBACService.php:1535` |
| `ork_officer_position`, `_alias` | `migrations/officer-position.sql:15,40` | `system/lib/ork3/class.OfficerPosition.php` |
| `ork_officer_history` | `migrations/officer_history.sql:4` | `class.Kingdom.php:1385`, `class.Park.php:1240` |

`ork_officer_position`, `ork_officer_position_alias` and `ork_officer_history` exist in **neither**
live nor the render — `migrations/` has not been applied to the local mirror either. That is a local
environment gap, not a deploy gap, *provided* the deploy runs `migrations/`. **Confirm that it
does.** If the deploy pipeline only walks `db-migrations/`, all eight of these tables are a second
deploy-breaking finding of much greater size than the first.

#### REPO-ONLY — migration exists, mirror has not run it (no deploy risk)

- Table `ork_signin_tally` — `db-migrations/2026-08-22-signin-tally.sql`
- Column `ork_officer.position_id` + index `idx_kingdom_park_position` — added to `ork.sql` by
  commit `77cb2965`, paired with `migrations/officer-position.sql`
- Engine `MyISAM` → `InnoDB` on `ork_park`, `ork_officer`, `ork_authorization`, `ork_unit`,
  `ork_unit_mundane`, `ork_dues` — exactly the six tables in
  `db-migrations/2026-08-21-innodb-merge-tables.sql:40-45`.
  **Deploy-order note:** the transactional rollback work on this branch is a silent no-op on those
  tables until that migration runs. It is idempotent; run it.
- Live-only index `ork_officer.kingdom_id (kingdom_id, park_id, role)` is the pre-`position_id`
  index the same officer work replaces.

#### COSMETIC

Seven column definition differences, all on legacy tournament tables that this branch's code does
not touch (`ork_bracket.method/seeding/style` ENUM value sets, `ork_match.result` ENUM and
`.score` `varchar(20)` vs `double(12,4)`, `ork_participant.bracket_id` and
`ork_participant_mundane.bracket_id` nullability). Plus `ork_session` collation
(`utf8mb3_general_ci` live vs `utf8mb3_uca1400_ai_ci` rendered — a MariaDB 12 default, not a repo
change), and `ork_bracket`/`ork_match`/`ork_participant`/`ork_tournament`/`ork_seed` MyISAM in the
render vs InnoDB live.

---

## Root cause: nothing was ever checking for this

`bin/ork-db drift-check` **does not compare live schema to repo schema**, despite the reassuring
`OK    live mirror schema/catalog match committed fingerprints` line it prints:

```
tools/ork-db/manifests/fingerprints.json5:28
  "schema_fingerprint": null,
```

`DriftCheck::checkLiveMirror()` reads that value and short-circuits:

```php
// tools/ork-db/DriftCheck.php:139-143
if ($recordedSchema === '' || $recordedSchema === 'null') {
    // Not baselined yet — live schema drift is reported by schema-diff after apply.
} elseif ($liveSchema !== $recordedSchema) { ... }
```

So the only thing verified is catalog **data** hashes. The `OK` on that line is about row content,
not structure. That is precisely why `is_ladder` / `max_level` / `disabled` survived undetected long
enough to reach production — and why `snoozed_by_id` / `passed_to_local` did too.

Two contributing gaps:

1. `MigrationClassifier` scans only `$repoRoot . '/db-migrations'` (`tools/ork-db/lib/MigrationClassifier.php:21`).
   The `migrations/` directory is structurally invisible to the coverage check.
2. `bin/ork-db schema-diff` *does* compare structure, but it compares the mirror against the
   **sandbox** `ork_test`, which is only as fresh as the last `apply`. It is a manual command, not a
   gate, and it currently reports `ork_kingdomaward` as differing purely because the sandbox is stale.

---

## Verification of the two new migrations

Both migrations were applied to a throwaway database built from the schema section of
`rendered/sandbox.sql` **with their own `ALTER` blocks stripped**, i.e. a genuine pre-migration
fresh build.

```bash
# fresh pre-migration schema -> drift_audit_scratch2, then apply each migration twice
docker compose exec -T ork3db mariadb -uroot -proot drift_audit_scratch2 \
    < db-migrations/2026-08-27-kingdomaward-ladder-columns.sql   # rc=0 (pass 1), rc=0 (pass 2)
docker compose exec -T ork3db mariadb -uroot -proot drift_audit_scratch2 \
    < db-migrations/2026-08-27-zodiac-month.sql                  # rc=0 (pass 1), rc=0 (pass 2)
```

| Check | Result |
|---|---|
| Applies cleanly to a fresh pre-migration schema | ✅ both, rc=0, no stderr |
| Idempotent (second run) | ✅ both, rc=0, no stderr — `ADD COLUMN IF NOT EXISTS` |
| `ADD COLUMN IF NOT EXISTS` is safe here | ✅ MariaDB-only syntax, but established precedent: 35 uses across 28 existing migrations; live server is `12.2.2-MariaDB` |
| Column types match live exactly | ✅ verified against `information_schema` — see below |
| Classified in `migration-classification.json5` | ✅ both, lines 94–95, `{ "class": "S", "render": "full" }` |
| `bin/ork-db drift-check` migration coverage | ✅ `OK    migration coverage (92 files classified)` |
| Already folded into `rendered/sandbox.sql` | ✅ lines 2392–2429 — the render was regenerated after the migrations were added |

Resulting definitions, fresh-build vs live — identical on all five columns:

```
drift_audit_scratch2  ork_kingdomaward.is_ladder     tinyint(1)  NO  0
ork                   ork_kingdomaward.is_ladder     tinyint(1)  NO  0
drift_audit_scratch2  ork_kingdomaward.max_level     tinyint(1)  NO  0
ork                   ork_kingdomaward.max_level     tinyint(1)  NO  0
drift_audit_scratch2  ork_kingdomaward.disabled      tinyint(1)  NO  0
ork                   ork_kingdomaward.disabled      tinyint(1)  NO  0
drift_audit_scratch2  ork_awards.zodiac_month        tinyint(2)  NO  0
ork                   ork_awards.zodiac_month        tinyint(2)  NO  0
drift_audit_scratch2  ork_recommendations.zodiac_month  tinyint(2)  NO  0
ork                   ork_recommendations.zodiac_month  tinyint(2)  NO  0
```

The earlier `INT(11)` draft concern is confirmed resolved: the committed migration produces
`tinyint(1)`, matching live. `drift-check`'s only failure is the known local-only `class` catalog
hash mismatch, unrelated to this work.

---

## What to do about it

**Before the next deploy — required:**

1. Resolve `ork_recommendations.snoozed_by_id` / `.passed_to_local` (migration, or remove the
   predicates). Nothing else on this list blocks a deploy.
2. Fix `orkui/controller/controller.KingdomAjax.php:554` so a missing `WaiveredMembers` cannot be
   silently coerced to `0` ahead of a destructive Reset Waivers. This is a real bug independent of
   the schema question.
3. Confirm the deploy pipeline runs `migrations/` and not just `db-migrations/`. If it does not,
   eight tables the branch depends on will not exist.
4. Run `db-migrations/2026-08-21-innodb-merge-tables.sql` on any environment still on MyISAM, or the
   branch's transactional rollbacks are decorative.

**To stop this recurring — recommended:**

5. Baseline `schema_fingerprint` in `tools/ork-db/manifests/fingerprints.json5`. It is `null`, which
   silently disables the only live-vs-repo structural check and makes `drift-check` print `OK` for a
   comparison it never performed. A `null` here should print `SKIP`, not `OK`.
6. Fold `migrations/` into `MigrationClassifier` (`tools/ork-db/lib/MigrationClassifier.php:21`), or
   merge the directory into `db-migrations/`. Two pipelines where one is unaudited is how the next
   one of these happens.
7. Add a CI gate that fails when branch code references a column absent from the rendered schema.
   Both landmines found here are one grep away from being caught mechanically; the audit script used
   for this report is a working prototype.

**Deliberately not done:** no migrations were written for anything in this report. This audit sizes
the problem; the fixes are separate, reviewable changes.

---

## Honest limits of this audit

State these when quoting the numbers.

1. **This compares against the local dev mirror, not production.** No production schema was
   available. The mirror is a shared volume that other branches' work has written to, so the 89
   live-only tables **overstate** production drift considerably. More importantly it can also
   **understate** it: a column that exists in production but not in this local mirror is invisible
   to this audit entirely. **Re-running this comparison against a production schema dump is the
   single highest-value follow-up**, and it is the only way to know whether the `disabled` class of
   problem is fully bounded.
2. **The repo baseline is the rendered sandbox, not a literal replay of `db-migrations/`.** Twelve
   migrations are `render: override` — the render substitutes a template rather than the migration
   itself. Every `render: skip` migration was checked for DDL (only the deliberately dev-only
   `2026-07-07-add-prod-canary.sql` has any), and the overrides' `ADD` clauses were diffed against
   their originals with no real gaps found. But this is a strong approximation, not a proof of
   equivalence.
3. **Code-reference triage is grep-based and the ORM is dynamic.** yapo reaches columns as dynamic
   properties (`$obj->some_column`) and tables as `DB_PREFIX . 'name'`. Both spellings were covered,
   but a table or column named through a variable would be missed. An identifier classified ORPHAN
   here means "no literal reference found", not "provably unused".
4. **Column-level coverage is complete only for columns that exist live.** A column that repo code
   writes and that exists in neither database would be missed except where it appeared in a raw SQL
   string; that scan surfaced 32 further candidates, all of which resolved to query aliases or to
   `migrations/`-created tables.
5. `ork_day_convert` is created by a render template rather than the schema section, so it appears
   as live-only in the raw table diff. It is not a divergence; it is counted in the 89 but is
   correctly tracked.

---

*Scratch database `drift_audit_scratch` and `drift_audit_scratch2` were created on the `ork3db`
container for this audit and dropped afterwards. No `ALTER`, `INSERT`, `UPDATE`, `DELETE` or `DROP`
was run against the `ork` or `ork_test` schemas — this audit read `information_schema` only.*
