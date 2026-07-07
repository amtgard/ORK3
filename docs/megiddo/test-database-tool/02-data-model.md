# Test Database Tool — Data Model

Defines what data lives in `ork_test`, how it is sourced, and the volume rules for generated content.

---

## 1. Table tiers

Tables are classified into four tiers. The renderer and migration classifier treat each tier differently.

| Tier | Description | Source | Example tables |
|------|-------------|--------|----------------|
| **T0 — Schema** | Structure only | `ork.sql` + schema migrations | All `CREATE TABLE` |
| **T1 — Reference catalog** | Identical to real DB | Extracted verbatim from dev `ork` | `ork_award`, `ork_class`, `ork_parktitle`, `ork_configuration` (keys only), `ork_pronoun` |
| **T2 — Synthetic entity** | Entirely fake, stable identity | Templates (`stable/`) | `ork_kingdom`, `ork_park`, fake `ork_mundane` rows |
| **T3 — Synthetic transactional** | Fake, date-shifted each render | Templates (`shifting/`) | `ork_attendance`, `ork_event_calendardetail`, `ork_awards` (instances), `ork_unit_mundane` |
| **T4 — Hybrid** | Mix of real + fake | Extract + template (`hybrid/`) | Selected `ork_mundane`, `ork_credential`, `ork_authorization`, `ork_mundane_design` |
| **T5 — Empty** | Schema present, zero rows | Truncate / omit INSERT | `ork_log`, `ork_transaction`, `ork_glicko2`, `ork_match`, `ork_bracket`, `ork_danger_audit` (unless test needs rows) |

See [06-migration-classification.md](./06-migration-classification.md) for per-migration tier mapping.

---

## 2. Volume rules

All randomness uses a **fixed seed** (`TEST_DB_RENDER_SEED=42` default) so renders are reproducible until the seed or `anchor_date` changes.

### 2.1 Kingdoms

| Rule | Value |
|------|-------|
| Count | **5** kingdom rows (fixed) — 4 sovereigns + 1 principality |
| Display names | `moniker` + proper name — e.g. `Empire of Ashkara` |
| Monikers | Mix of `Kingdom of`, `Empire of`, and culturally distinct titles |
| Abbreviations | Stable 3–5 letter codes |
| `active` | All `Active` |
| Principality | Exactly **one** — `parent_kingdom_id` points at its sovereign |

Each kingdom row stores:

- `name` — full display string (`Empire of Ashkara`) — used in fingerprint validation
- `abbreviation` — short code
- `parent_kingdom_id` — `0` for sovereigns; sovereign's `kingdom_id` for principality
- `description` — one-line flavor text

**Canary kingdoms** (see [04-safety-validations.md](./04-safety-validations.md)):

| `kingdom_id` | Type | Moniker | Name | Full name | Abbr | Parent |
|--------------|------|---------|------|-----------|------|--------|
| 9001 | Sovereign | Empire of | Ashkara | **Empire of Ashkara** | `EAK` | — |
| 9002 | Sovereign | Kingdom of | Meridia | **Kingdom of Meridia** | `KMR` | — |
| 9003 | Sovereign | Sultanate of | Zanzibarr | **Sultanate of Zanzibarr** | `SZ` | — |
| 9004 | Sovereign | Tsardom of | Vyatka | **Tsardom of Vyatka** | `TVK` | — |
| 9005 | Principality | Grand Duchy of | Litavia | **Grand Duchy of Litavia** | `GDL` | 9001 |

**Principality structure (ORK-native):**

- `9005` has `parent_kingdom_id = 9001` (Empire of Ashkara)
- Litavia has its own parks (2–6, same volume rules) and players
- Empire parks and principality parks are distinct `park_id` namespaces
- Officer/authorization inheritance follows existing `class.Kingdom` / `class.Principality` behavior — enables principality rollup tests (`OfficerDirectoryTest`, stats inclusion toggles)

**Moniker variety** — templates may also draw from a pool for future expansion:

| Moniker style | Example | Culture echo |
|---------------|---------|--------------|
| Kingdom of | Meridia | Western feudal |
| Empire of | Ashkara | Levantine / caliphal |
| Sultanate of | Zanzibarr | Swahili coast |
| Tsardom of | Vyatka | Rus' |
| Grand Duchy of | Litavia | Baltic (historical Grand Duchy of Lithuania) |
| Khanate of | *(pool)* | Steppe |
| Raj of | *(pool)* | South Asian |
| Shogunate of | *(pool)* | Japanese |

v1 uses the five fixed rows above; the pool documents direction for later renders.

IDs `9001–9005` are reserved in templates and never collide with real kingdom IDs (real IDs are typically &lt; 100).

### 2.2 Parks

| Rule | Value |
|------|-------|
| Per kingdom | **2–6** parks (uniform random, seeded) |
| Total parks | **10–30** (expected mean ~20) |
| Players per park | **5–25** (uniform random, seeded) |
| Names | Generated from `templates/stable/park_names.json5` pool |
| `active` | All `Active` |

**Canary check:** `SELECT COUNT(*) FROM ork_park WHERE kingdom_id BETWEEN 9001 AND 9005` must equal the template-defined expected count for the current seed (stored in `manifests/fingerprints.json5`).

### 2.3 Players (`ork_mundane`)

Two populations:

#### Real operator accounts (T4 — hybrid)

Copied from dev DB with credentials intact:

| Username / persona | Notes |
|--------------------|-------|
| `admin` | Global admin; `ork_authorization` row `role=admin`, all scopes 0 |
| `megiddo` | Real account — park assignment randomized per render |
| Ken Walker | `mundane_id` pinned in `manifests/extract-sources.json5` (maintainer supplies) |
| Avery Krouse | `mundane_id` pinned in `manifests/extract-sources.json5` (maintainer supplies) |

Assignment rule: each real account receives a random `park_id` and matching `kingdom_id` from the 5 fake kingdoms. `admin` retains `park_id=0, kingdom_id=0`.

#### Generated fake players (T2)

| Rule | Value |
|------|-------|
| Per park | Fill remaining slots to reach 5–25 total per park |
| Personas | From `fake_players.json5` name pool (unique per park) |
| Credentials | Shared test password (`test-db-player` — documented, not secret) |
| Email | `{persona_slug}@test.ork.local` |

`mundane_id` allocation:

- `1` — `admin` (fixed)
- `2–5` — real operators (fixed order from extract)
- `1000+` — generated players (stable IDs across renders for FK integrity within a render; may shift if volume rules change)

### 2.4 Attendance (`ork_attendance`)

| Rule | Value |
|------|-------|
| Per player | **2–36** months with at least one attendance row (uniform random) |
| Window | Trailing **3 years** from `anchor_date` |
| Distribution | 1–4 rows per active month, random weekdays |
| `class_id` | Random from `ork_class` catalog |
| `park_id` / `kingdom_id` | Player's home park |
| `event_id` | 0 for park-day attendance; non-zero when tied to copied major event |

Date shifting: template stores **month offsets** (`months_ago: 14`) not absolute dates. Renderer computes `date = anchor_date - months_ago`.

### 2.5 Units (`ork_unit`, `ork_unit_mundane`)

| Rule | Value |
|------|-------|
| Per player | **0–4** unit memberships (uniform random) |
| Unit types | `Company`, `Household` (no `Event` units in v1) |
| Names | Generated `{ParkAbbrev} {Type} {seq}` |
| Roles | One `captain` per unit; others `member` |

### 2.6 Awards

| Layer | Tier | Rule |
|-------|------|------|
| `ork_award` | T1 | Verbatim extract — identical to dev |
| `ork_kingdomaward` | T1 + generated | Global awards copied; per-kingdom awards cloned for fake kingdoms 9001–9005 from a reference kingdom extract |
| `ork_awards` (instances) | T3 | Sparse — ~10% of fake players receive 1–3 historical awards within the 3-year window |

Award **definitions** must match production behavior (ladder flags, title classes). Award **instances** are synthetic.

### 2.7 Major events

Copied from dev DB by **name match**, then re-dated:

| Event name (LIKE) | Scope | Notes |
|-------------------|-------|-------|
| `%Gathering of Kingdoms%` | Kingdom | Multiple `ork_event_calendardetail` rows shifted into 3-year window |
| `%Spring War%` | Kingdom or interkingdom | Preserve `event_type` if present |
| `%Olympiad%` | Kingdom | Tournament rows optional in v1 |

Extract step saves event **structure** (fields, fees, schedule shape) without original dates. Renderer places occurrences relative to `anchor_date` (e.g., Spring War → `anchor_date - 45 days` in most recent year).

If dev DB lacks an event name, template provides a **fallback stub** so tests always have at least one row per major event type.

### 2.8 Auth and officers

| Table | Rule |
|-------|------|
| `ork_credential` | Real credentials for T4 accounts; generated for fake players |
| `ork_authorization` | `admin` global; optional kingdom `edit` for `megiddo` (configurable in `real_players.json5`) |
| `ork_officer` | One set of kingdom officers per fake kingdom (randomly assigned fake players) |
| `ork_idp_auth` | Empty in v1 |

---

## 3. ID namespace

To prevent collision with real data if databases are ever miswired:

| Entity | ID range |
|--------|----------|
| Kingdoms | 9001–9005 |
| Parks | 91001–91999 (kingdom_id × 1000 + seq) |
| Fake mundane | ≥ 1000 |
| Fake events | ≥ 80000 |
| Fake units | ≥ 70000 |

Real extracted `mundane_id` values for admin/operators are preserved as-is from source.

---

## 4. Tables explicitly excluded from content

These tables receive schema but **no production data extract** and **no synthetic rows** (T5):

- `ork_log`, `ork_application`, `ork_application_auth`
- `ork_transaction`, `ork_dues` (unless dues tests added later)
- `ork_glicko2`, `ork_match`, `ork_bracket`, `ork_tournament` (v1)
- `ork_danger_audit` (schema only)
- `ork_whats_new_seen`, `ork_attendance_link` (populated by tests if needed)

---

## 5. Extract manifest (one-time from dev)

`tools/test-database/manifests/extract-sources.json5`:

```json5
{
  "source_profile": "prod",  // 127.0.0.1:19306/ork
  "tables_verbatim": ["award", "class", "parktitle", "pronoun"],
  "configuration_keys": ["*"],
  "mundane_real": {
    "by_username": ["admin", "megiddo"],
    "by_mundane_id": {
      "ken_walker": null,    // maintainer fills e.g. 12345
      "avery_krouse": null   // maintainer fills e.g. 67890
    }
  },
  "events_by_name_like": [
    "%Gathering of Kingdoms%",
    "%Spring War%",
    "%Olympiad%"
  ]
}
```

Command: `bin/ork-db extract` (read-only against hardcoded mirror on 19306).

---

## 6. Post-apply fingerprint summary

After apply, `bin/ork3-test-db status` reports:

```
Database:     ork_test @ 127.0.0.1:19307
Canary:       _ork_canary_test OK
Prod canary:  absent OK
Kingdoms:     5 (Empire of Ashkara … Grand Duchy of Litavia ⊂ 9001)
Parks:        23 (expected 23 for seed=42)
Players:      287 fake + 4 real
Attendance:   2018–2026 (anchor 2026-07-07)
Major events: GOK×3, Spring War×3, Olympiad×2
```

Expected park count for seed=42 is precomputed and stored in `fingerprints.json5`.
