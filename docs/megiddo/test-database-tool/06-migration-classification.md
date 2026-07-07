# Test Database Tool — Migration Classification

How `ork.sql` and `db-migrations/` apply to the test database, and which content migrations are included or excluded.

---

## 1. Baseline schema

| Source | Applies to test? | Method |
|--------|------------------|--------|
| `ork.sql` | **Yes** | Full schema in render step 3; `ork_` prefix via `DB_PREFIX` |
| `dbsetup.php` truncate list | **No** | Replaced by template-generated data |

---

## 2. Migration classes

Each file in `db-migrations/` is classified:

| Class | Code | Apply to test? | Description |
|-------|------|----------------|-------------|
| **Schema** | `S` | Yes | `CREATE`, `ALTER`, `ADD COLUMN`, indexes, new tables |
| **Reference content** | `RC` | Yes (filtered) | Inserts into award/class/config catalogs |
| **Production backfill** | `PB` | **No** | Data fixes on real prod rows (mundane scrub, XSS scrub, NL dedup) |
| **Environment-specific** | `ES` | **No** | Northern Lights `kingdom_id=20`, prod-only config |
| **Rollback pair** | `RB` | No | Rollback scripts — never auto-applied |
| **Prod canary** | `PC` | Dev only | `_ork_canary_prod` installation |

Classifier lives in `tools/test-database/manifests/migration-classification.json5`. Renderer includes only `S` and selected `RC` migrations in composition order (sorted by date prefix, same as dev workflow).

---

## 3. Classification rules (decision tree)

```
FOR each migration file:
  IF filename contains '-rollback':
    → RB (skip)

  IF creates _ork_canary_prod:
    → PC (dev only)

  IF only DDL (CREATE/ALTER/DROP/ADD INDEX):
    → S (include)

  IF UPDATE/DELETE on ork_mundane, ork_park, ork_kingdom with WHERE on real IDs:
    → PB (exclude)

  IF INSERT INTO ork_award OR ork_class OR ork_pronoun:
    → RC (include)

  IF INSERT INTO ork_kingdomaward WHERE kingdom_id = 20:
    → ES (exclude)

  IF backfill with no WHERE (all rows):
    → PB (exclude) unless table in T1 allowlist

  IF schema change + seed in same file:
    → split: DDL → S, INSERT into T1 → RC, rest → PB/ES
```

**v1 pragmatic approach:** Classify whole files; for split files (e.g. `2026-04-14-custom-titles.sql`), manually extract DDL portion into `templates/schema/` override.

---

## 4. File-by-file classification (current repo)

| File | Class | Include? | Notes |
|------|-------|----------|-------|
| `2017-08-04-current-prod-features` | S | Yes | Historical schema |
| `2017-10-18-set-paragon-flag` | PB | No | Updates real award rows |
| `2017-10-20-set-paragon-wizard` | PB | No | Data fix |
| `2017-10-20-update-masters-to-paragons` | PB | No | Data fix |
| `2017-10-20-update-missing-award-ids` | PB | No | Data fix |
| `2017-10-21-update-v8-masters-to-paragon` | PB | No | Data fix |
| `2017-10-21-update-v8-kingdomaward-master-to-paragon` | PB | No | Data fix |
| `2017-10-27-add-stripped-from-field` | S | Yes | ALTER |
| `2018-06-17-fix-unit-owner` | PB | No | Data fix |
| `2018-06-18-crown-points` | S | Yes | New columns/tables |
| `2018-06-24-ducal-crown-points` | S | Yes | Schema |
| `2018-10-30-google-maps-api-update-…` | PB | No | Prod data |
| `2018-10-31-ghetto-rate-limiting` | S | Yes | Schema |
| `2018-11-12-officer-roles` | S/RC | Partial | Schema yes; role seeds from extract |
| `2019-06-25-add-events-at-local-parks` | S | Yes | Schema |
| `2019-06-25-optimize-date-time-functions` | S | Yes | Attendance date columns |
| `2021-04-12-add-park-member-since-col` | S | Yes | ALTER |
| `2021-04-13-add-player-corpora-columns` | S | Yes | ALTER |
| `2021-04-14-add-dues-table` | S | Yes | New table |
| `2021-04-14-migrate-old-dues-to-new-dues-table` | PB | No | Data migration |
| `2021-08-29-add-recommendations-table` | S | Yes | New table |
| `2021-08-31-add-soft-delete-to-award-recommendations-table` | S | Yes | ALTER |
| `2022-11-16-add-gmr-to-officers` | RC | Yes | Officer enum extension |
| `2022-11-17-pronoun-feature` | S+RC | Partial | Schema yes; pronoun seeds via extract |
| `2026-02-28-add-event-rsvp.sql` | S | Yes | New table |
| `2026-03-06-add-award-recs-public-config.sql` | RC | Yes | Config keys |
| `2026-03-13-add-parkday-online.sql` | S | Yes | ALTER |
| `2026-03-16-add-event-schedule.sql` | S | Yes | New table |
| `2026-03-16-add-event-staff.sql` | S | Yes | New table |
| `2026-03-16-utf8mb4-conversion.sql` | S | Yes | Charset |
| `2026-03-19-add-event-fees.sql` | S | Yes | New table |
| `2026-03-19-add-event-schedule-leads.sql` | S | Yes | New table |
| `2026-03-20-add-suspension-propagates.sql` | S | Yes | ALTER |
| `2026-03-20-fix-award-recs-public-user-setting.sql` | PB | No | User data fix |
| `2026-03-22-add-whats-new-seen.sql` | S | Yes | New table |
| `2026-03-22-unit-managers-edit-to-create.sql` | PB | No | Authorization data fix |
| `2026-04-03-add-attendance-link.sql` | S | Yes | New table |
| `2026-04-03-add-selfreg-link.sql` | S | Yes | Schema |
| `2026-04-08-player-milestones.sql` | S | Yes | New table |
| `2026-04-11-add-event-type.sql` | S | Yes | ALTER |
| `2026-04-11-performance-indexes.sql` | S | Yes | Indexes |
| `2026-04-12-calendar-items.sql` | S | Yes | New table |
| `2026-04-14-custom-titles.sql` | S+RC | Partial | ALTER + Custom Title award — RC via extract |
| `2026-04-14-custom-titles-seed-kingdomawards.sql` | ES | No | Seeds real kingdoms — test uses clone rules |
| `2026-04-21-danger-audit-schema-and-backfill.sql` | S | Partial | Schema only; skip backfill |
| `2026-04-22-recommendation-seconds.sql` | S | Yes | ALTER |
| `2026-04-23-mundane-design-table.sql` | S | Yes | New table |
| `2026-04-23-mundane-font-preferences.sql` | S | Yes | ALTER |
| `2026-04-27-calendar-enhancements-r2.sql` | S | Yes | ALTER |
| `2026-05-05-attendance-monthly-covering-index.sql` | S | Yes | Index |
| `2026-05-05-awards-covering-indexes.sql` | S | Yes | Index |
| `2026-05-07-awards-stripped-from-revoked-index.sql` | S | Yes | Index |
| `2026-05-08-mundane-kingdom-search-index.sql` | S | Yes | Index |
| `2026-05-08-unit-mundane-mundane-index.sql` | S | Yes | Index |
| `2026-05-10-add-event-banner.sql` | S | Yes | ALTER |
| `2026-05-11-collapse-scoped-admin-to-create.sql` | PB | No | Authorization data fix |
| `2026-05-11-scrub-additional-xss-payloads.sql` | PB | No | Mundane data scrub |
| `2026-05-11-scrub-viridian-probe-accounts.sql` | PB | No | Mundane delete |
| `2026-05-15-hero-overlay-widen-for-vignette.sql` | S | Yes | ALTER |
| `2026-05-16-state-of-amtgard-indexes.sql` | S | Yes | Index |
| `2026-05-17-add-entity-banners.sql` | S | Yes | ALTER |
| `2026-05-17-park-weather.sql` | S | Yes | New table |
| `2026-05-19-add-event-links-table.sql` | S | Yes | New table |
| `2026-05-19-mundane-design-hero-gradient.sql` | S | Yes | ALTER |
| `2026-05-21-widen-parkday-description.sql` | S | Yes | ALTER |
| `2026-05-24-add-parkday-every-x-weeks-enum.sql` | S | Yes | ALTER |
| `2026-05-25-mundane-dietary-preferences.sql` | S | Yes | ALTER |
| `2026-05-26-add-unit-active.sql` | S | Yes | ALTER |
| `2026-05-29-mundane-design-name-shadow.sql` | S | Yes | ALTER |
| `2026-05-31-attendance-entry-method.sql` | S | Yes | ALTER |
| `2026-06-01-best-of-weekly-recap.sql` | S | Yes | New table |
| `2026-06-03-add-include-principality-in-statistics-config.sql` | RC | Yes | Config |
| `2026-06-03-kingdomaward-is-title-authoritative.sql` | S | Yes | ALTER |
| `2026-06-03-northern-lights-custom-award-dedup.sql` | ES | No | kingdom_id=20 |
| `2026-06-03-northern-lights-custom-award-dedup-rollback.sql` | RB | No | |
| `2026-06-06-trim-persona-whitespace.sql` | PB | No | Mundane UPDATE |
| `2026-07-01-mundane-design-show-feast-prefs.sql` | S | Yes | ALTER |

**New (planned):** `2026-07-07-add-prod-canary.sql` → `PC`, dev only.

---

## 5. Award and mundane policy (summary)

| Table | Schema migrations | Content migrations | Test data source |
|-------|-------------------|--------------------|------------------|
| `ork_award` | All `S` | `RC` inserts included OR extract | **Extract verbatim** |
| `ork_kingdomaward` | All `S` | `ES` excluded | **Clone from extract** for kingdoms 9001–9005 |
| `ork_awards` | All `S` | `PB` excluded | **Generated** sparse instances |
| `ork_mundane` | All `S` | **All content excluded** | **Hybrid** extract (4 real) + generated |
| `ork_class` | All `S` | `RC` | **Extract verbatim** |
| `ork_configuration` | All `S` | `RC` keys only | **Extract** allowlisted keys |

---

## 6. Keeping classification current

When adding a migration to `db-migrations/`:

1. Add entry to `migration-classification.json5`
2. If `S` — no further action; render picks it up automatically
3. If `RC` — run `extract` for affected catalog table
4. If `PB` / `ES` — document exclusion reason in JSON5 comment
5. If split file — add DDL excerpt to `templates/schema/overrides/`

**CI check (planned TD-7):** Script diffs `db-migrations/` against manifest; fails if unclassified file exists.

---

## 7. Schema parity verification

```bash
bin/ork-db schema-diff
```

Compares `SHOW CREATE TABLE` for all tables between dev `ork` and test `ork_test` (after apply). Reports drift. Sign-off criterion for TD-5.
