# Test Database Tool — Data Model

Defines what data lives in `ork_test`, how it is sourced, and the volume rules for generated content.

---

## 1. Content source classes

Every table falls into one of two **content classes**. The renderer uses this to decide extract vs template.

| Class | Description | Source | Example tables |
|-------|-------------|--------|----------------|
| **Type 1 — Fixed catalog** | Immutable or prod-identical reference data — data drivers only | Extract or embedded static SQL | `ork_award`, `ork_class`, `ork_day_convert`, `ork_pronoun`, `ork_parktitle` |
| **Type 2 — Template-driven** | Synthetic or hybrid content composed at render time | Templates + optional extract samples | Everything else — including `ork_configuration`, `ork_kingdomaward` |

**Configuration:** `manifests/extract-sources.json5` declares Type 1 tables explicitly:

```json5
{
  "fixed_extract": ["award", "class", "parktitle", "pronoun"],
  "fixed_embedded": ["day_convert"],
  "configuration_sample": {
    "kingdom_ids": [1],
    "include_park_keys": true
  }
}
```

| Key | Meaning |
|-----|---------|
| `fixed_extract` | `bin/ork-db extract` dumps rows verbatim from mirror → `extracted/{table}.sql` |
| `fixed_embedded` | Static SQL checked into `templates/catalogs/{table}.sql` — never extracted (e.g. `ork_day_convert`, 7 weekday rows) |
| `configuration_sample` | Extract saves **sample rows** from real kingdoms/parks as shape templates — render clones them for fake kingdoms 9001–9005 and generated parks (Type 2, not verbatim) |

Any table not listed in Type 1 config is Type 2 (template-driven). No per-table hardcoding in renderer code — the manifest is the single source of truth.

---

## 2. Table tiers (render pipeline)

Within Type 2, the renderer further classifies tables for composition order and date-shifting behavior:

| Tier | Content class | Description | Source | Example tables |
|------|---------------|-------------|--------|----------------|
| **T0 — Schema** | — | Structure only | `ork.sql` + schema migrations | All `CREATE TABLE` |
| **T1 — Reference catalog** | Type 1 | Fixed data drivers | Extract or embedded | See Type 1 list above |
| **T2 — Synthetic entity** | Type 2 | Fake, stable identity (seed-stable) | Templates (`stable/`) | `ork_kingdom`, `ork_park`, fake `ork_mundane` rows |
| **T3 — Synthetic transactional** | Type 2 | Fake, date-shifted each render | Templates (`shifting/`) | `ork_attendance`, `ork_event_calendardetail`, `ork_awards` (instances), `ork_unit_mundane` |
| **T4 — Hybrid** | Type 2 | Mix of real + fake | Extract + template (`hybrid/`) | Selected `ork_mundane`, `ork_credential`, `ork_authorization`, `ork_mundane_design` |
| **T5 — Empty** | Type 2 | Schema present, zero rows | Truncate / omit INSERT | `ork_log`, `ork_transaction`, `ork_glicko2`, `ork_match`, `ork_bracket`, `ork_danger_audit` (unless test needs rows) |

See [06-migration-classification.md](./06-migration-classification.md) for per-migration tier mapping.

---

## 3. Content seed and random inputs

"Random inputs" (park counts, player counts, name-pool picks, real-operator park assignments, kingdomaward extras) are **not** re-rolled on every render. They are driven by a **content seed** — an integer persisted in `manifests/fingerprints.json5`:

```json5
{
  "content_seed": 42,
  "content_seed_basis": "2026-07-07T00:00:00Z",
  "schema_fingerprint": "sha256:…"
}
```

### What the seed actually does

The seed is **not** a magic constant. It is the argument to PHP's `mt_srand($content_seed)` before any pseudo-random generation. Same integer → same sequence of "random" choices every time.

| With `content_seed: 42` | Effect |
|-------------------------|--------|
| Park counts per kingdom | Same 2–6 draw each render → **23 total parks** (stored in `park_count_by_seed`) |
| Players per park | Same 5–25 draw each render |
| Name-pool picks | Same fake personas assigned to same parks |
| Real-operator park assignment | Same park for megiddo / Ken / Avery every render |
| Kingdomaward extras | Same nonsense rows added on top of the award clone |

**Why 42?** Arbitrary starting default — chosen so early golden-file tests and docs have a stable reference point. The number itself has no domain meaning; **persistence** is what matters. First-time setup runs `bin/ork-db compose`, which writes `content_seed` to `fingerprints.json5`. Change it only when you intentionally want different volume/identity (then update `park_count_by_seed` and golden files).

| Input | Behavior |
|-------|----------|
| **Content seed** | Generated once at compose (or when `schema_fingerprint` changes). Reused on every `render` / `apply`. |
| **Anchor date** | Defaults to today. **Only** input that changes per run — shifts T3 dates (attendance, events, award instances). |
| **Regenerate seed** | `bin/ork-db compose --regenerate-seed` (or edit `fingerprints.json5` + recompute `park_count_by_seed`). |

Renderer calls `mt_srand($content_seed)` before all volume/name/assignment generation. Same seed + same anchor → identical identities and counts; new anchor → same identities, shifted dates only.

CLI override: `--seed N` for one-off experiments; does not persist unless `--persist-seed`.

---

## 4. Volume rules

See §3 for content seed semantics. Volume generation (park/player counts, name picks) is seed-stable; only dates shift per run.

### 4.1 Kingdoms

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

### 4.2 Parks

| Rule | Value |
|------|-------|
| Per kingdom | **2–6** parks (uniform random, seeded) |
| Total parks | **10–30** (expected mean ~20) |
| Players per park | **5–25** (uniform random, seeded) |
| Names | Generated from `templates/stable/park_names.json5` pool |
| `active` | All `Active` |

**Canary check:** `SELECT COUNT(*) FROM ork_park WHERE kingdom_id BETWEEN 9001 AND 9005` must equal the template-defined expected count for the current seed (stored in `manifests/fingerprints.json5`).

### 4.3 Players (`ork_mundane`)

Two populations:

#### Real operator accounts (T4 — hybrid)

Copied from dev DB with credentials intact:

| Username / persona | Notes |
|--------------------|-------|
| `admin` | Global admin; `ork_authorization` row `role=admin`, all scopes 0 |
| `megiddo` | Real account — park assignment seed-stable (same park every render until seed changes) |
| Ken Walker | `mundane_id` **43232** — pinned in `extract-sources.json5` |
| Avery Krouse | `mundane_id` **46193** — pinned in `extract-sources.json5` |

Assignment rule: each real account receives a **seed-stable** `park_id` and matching `kingdom_id` from the 5 fake kingdoms. `admin` retains `park_id=0, kingdom_id=0`.

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
- `100_000_000+` — generated fake players (counter from `100_000_000`; see [12-heraldry-and-assets.md](./12-heraldry-and-assets.md))

### 4.4 Attendance (`ork_attendance`)

| Rule | Value |
|------|-------|
| Per player | **2–36** months with at least one attendance row (uniform random) |
| Window | Trailing **3 years** from `anchor_date` |
| Distribution | 1–4 rows per active month, random weekdays |
| `class_id` | Random from `ork_class` catalog |
| `park_id` / `kingdom_id` | Player's home park |
| `event_id` | 0 for park-day attendance; non-zero when tied to copied major event |

Date shifting: template stores **month offsets** (`months_ago: 14`) not absolute dates. Renderer computes `date = anchor_date - months_ago`.

### 4.5 Units (`ork_unit`, `ork_unit_mundane`)

| Rule | Value |
|------|-------|
| Per player | **0–4** unit memberships (uniform random) |
| Unit types | `Company`, `Household` (no `Event` units in v1) |
| Names | Generated `{ParkAbbrev} {Type} {seq}` |
| Roles | One `captain` per unit; others `member` |

### 4.6 Awards

| Layer | Tier | Rule |
|-------|------|------|
| `ork_award` | T1 | Verbatim extract — identical to dev |
| `ork_kingdomaward` | T2 | **Clone from `ork_award`** — one kingdomaward row per global award × each fake kingdom (9001–9005); then **seed-stable extras** (2–8 nonsense kingdom-specific awards per kingdom) |
| `ork_awards` (instances) | T3 | Sparse — ~10% of fake players receive 1–3 historical awards within the 3-year window |

Award **definitions** (`ork_award`) must match production behavior (ladder flags, title classes). **Kingdom linkage** (`ork_kingdomaward`) is synthetic: derived from the global award catalog, not copied from a real kingdom's kingdomaward table. **Instances** (`ork_awards`) are synthetic transactional rows.

### 4.7 Major events

Copied from dev DB by **name match**, then re-dated:

| Event name (LIKE) | Scope | Notes |
|-------------------|-------|-------|
| `%Gathering of Kingdoms%` | Kingdom | Multiple `ork_event_calendardetail` rows shifted into 3-year window |
| `%Spring War%` | Kingdom or interkingdom | Preserve `event_type` if present |
| `%Olympiad%` | Kingdom | Tournament rows optional in v1 |

Extract step saves event **structure** (fields, fees, schedule shape) without original dates. Renderer places occurrences relative to `anchor_date` (e.g., Spring War → `anchor_date - 45 days` in most recent year).

If dev DB lacks an event name, template provides a **fallback stub** so tests always have at least one row per major event type.

### 4.9 Configuration (`ork_configuration`)

Kingdom- and park-scoped settings — **not** Type 1. Prod rows are tied to real kingdom/park IDs and do not copy verbatim.

| Step | Behavior |
|------|----------|
| **Extract** | Sample rows from one or more real kingdoms (and optionally parks) → `extracted/configuration_samples.json` — captures key names, value shapes, `allowed_values`, `var_type` |
| **Render** | For each fake kingdom 9001–9005: emit one row per sampled key with `type=Kingdom`, `id={kingdom_id}`. For each generated park: emit park-scoped keys with `type=Park`, `id={park_id}`. Values copied from samples; numeric sub-values in JSON blobs may be offset per entity to avoid collisions |

Global/system keys (if any) are omitted in v1 unless a test requires them.

### 4.10 Auth and officers

| Table | Rule |
|-------|------|
| `ork_credential` | Real credentials for T4 accounts; generated for fake players |
| `ork_authorization` | `admin` global; optional kingdom `edit` for `megiddo` (configurable in `real_players.json5`) |
| `ork_officer` | One set of kingdom officers per fake kingdom (randomly assigned fake players) |
| `ork_idp_auth` | Empty in v1 |

---

## 5. ID namespace

To prevent collision with real data if databases are ever miswired:

| Entity | ID range |
|--------|----------|
| Kingdoms | 100001–100005 |
| Parks | 1_000_001+ (`1_000_000 + kingdom_ordinal × 100 + seq`) |
| Fake mundane | ≥ 100_000_000 |
| Fake events | ≥ 80000 |
| Fake units | ≥ 70000 |

Real extracted `mundane_id` values for admin/operators are preserved as-is from source.

---

## 6. Tables explicitly excluded from content

These tables receive schema but **no production data extract** and **no synthetic rows** (T5):

- `ork_log`, `ork_application`, `ork_application_auth`
- `ork_transaction`, `ork_dues` (unless dues tests added later)
- `ork_glicko2`, `ork_match`, `ork_bracket`, `ork_tournament` (v1)
- `ork_danger_audit` (schema only)
- `ork_whats_new_seen`, `ork_attendance_link` (populated by tests if needed)

---

## 7. Extract manifest

`tools/ork-db/manifests/extract-sources.json5`:

```json5
{
  "source": "mirror",
  "fixed_extract": ["award", "class", "parktitle", "pronoun"],
  "fixed_embedded": ["day_convert"],
  "configuration_sample": {
    "kingdom_ids": [1],
    "include_park_keys": true
  },
  "mundane_real": {
    "by_username": ["admin", "megiddo"],
    "by_mundane_id": {
      "ken_walker": 43232,
      "avery_krouse": 46193
    }
  },
  "events_by_name_like": [
    "%Gathering of Kingdoms%",
    "%Spring War%",
    "%Olympiad%"
  ]
}
```

Command: `bin/ork-db extract` (read-only against hardcoded mirror on 19306). Re-run after prod Type 1 catalog changes — see [06-migration-classification.md](./06-migration-classification.md) §8.

---

## 8. Post-apply fingerprint summary

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
