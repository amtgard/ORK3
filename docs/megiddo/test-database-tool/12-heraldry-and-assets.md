# Test Database Tool — Heraldry and Assets (TD-11)

Synthetic heraldry for test kingdoms, parks, and players; high ID namespaces to avoid collisions with real mirror/prod assets; asset deploy pipeline integrated with `deploy-sandbox`.

**Related:** [02-data-model.md](./02-data-model.md) · [09-milestone-checklist.md](./09-milestone-checklist.md) · [01-architecture.md](./01-architecture.md) §8

---

## 1. Problem statement

Today the sandbox renders DB rows only:

| Entity | Current IDs | `has_heraldry` | Files on disk |
|--------|-------------|----------------|---------------|
| Kingdoms | `9001`–`9005` | `0` | None |
| Parks | `kingdom_id × 1000 + seq` (e.g. `9001001`) | `0` | None |
| Fake players | `1000+` | `0` | None |
| Real players | `1`, `4`, `43232`, `46193` | Often `1` from extract | None in repo (dirs gitignored) |

[01-architecture.md](./01-architecture.md) §8 deferred asset files in TD-1–TD-10. The UI shows placeholder heraldry (`0000.jpg`, `00000.jpg`, `000000.jpg`) unless both the DB flag **and** a matching file exist under `assets/`.

**Goals for TD-11:**

1. **Shield heraldry** for every test kingdom and park
2. **Placeholder player art** (phoenix on shield — acceptable for both heraldry and portrait)
3. **High ID namespaces** so test asset filenames never collide with real mirror heraldry
4. **Repeatable asset pipeline** — generate, validate, deploy to `assets/` on local tier only

---

## 2. How ORK resolves heraldry (constraints)

Path constants: `config.dev.php` · logic: `system/lib/ork3/class.Heraldry.php`

| Entity | Directory | Filename pattern | Default placeholder |
|--------|-----------|------------------|---------------------|
| Kingdom | `assets/heraldry/kingdom/` | `{kingdom_id}` min 4 digits + `.jpg\|.png` | `0000.jpg` |
| Park | `assets/heraldry/park/` | `{park_id}` min 5 digits + `.jpg\|.png` | `00000.jpg` |
| Player heraldry | `assets/heraldry/player/` | `{mundane_id}` min 6 digits + `.jpg\|.png` | `000000.jpg` |
| Player portrait | `assets/players/` | `{mundane_id}` min 6 digits + `.jpg\|.png` | Falls back to heraldry, then initial |

**Rules:**

- UI checks `has_heraldry` / `has_image` **before** using custom paths
- Padding is minimum width (`sprintf('%04d', 100001)` → `100001.jpg`) — longer IDs are fine
- PNG preferred when transparent; uploads trim transparent borders
- Principality heraldry uses kingdom storage (`9005` → `kingdom/9005.*`) — same after ID migration
- `assets/` is bind-mounted into `ork3app` — files written on the host appear at `http://localhost:19080/`

**Not in scope for TD-11 v1:** event heraldry, unit heraldry, hero banners (`has_banner`).

---

## 3. ID namespace migration (TD-11a)

Move **synthetic** entities into high blocks. **Do not change** real operator `mundane_id` values.

### Proposed IDs

| Entity | Old | New base | Formula | Examples |
|--------|-----|----------|---------|----------|
| **Kingdoms** | `9001`–`9005` | `100000` | Fixed in `kingdoms.json5`: `100001` … `100005` | Ashkara → `100001`, Litavia → `100005` |
| **Parks** | `9001001`, … | `1000000` | `1_000_000 + (kingdom_ordinal × 100) + seq` | K1 park 1 → `1000001`; K5 park 3 → `1000403` |
| **Fake mundanes** | `1000+` | `1000000` | Counter from `1_000_000` | `1000000`, `1000001`, … |
| **Real mundanes** | unchanged | — | Extract pins | `1`, `4`, `43232`, `46193` |
| **Events** *(optional)* | `80000+` | `2000000` | Counter from `2_000_000` | Avoids park block; defer if not needed |

**Kingdom ordinal** = 0-based index in `kingdoms.json5` (0 = first sovereign, 4 = principality).

**Park examples @ content seed 42** (~20 parks):

```
100001 → 1000001–1000004
100002 → 1000101–1000104
100003 → 1000201–1000203
100004 → 1000301–1000306
100005 → 1000401–1000403
```

### Why migrate

Real prod/mirror heraldry uses low IDs (`0042.jpg`, `12345.jpg`). Test assets at `100001+`, `1000001+`, `1000000+` stay in a dedicated namespace. The old `9001`/`9001001` range could overlap plausible real kingdom/park IDs on a dev mirror.

### Code and doc touch list

| File | Change |
|------|--------|
| `templates/stable/kingdoms.json5` | `id`: `100001`–`100005`; `parent_kingdom_id` for Litavia → `100001` |
| `manifests/fingerprints.json5` | Kingdom rows; `park_count_by_seed`; validation ranges |
| `templates/stable/kingdom_awards.json5` | `kingdom_ids` array |
| `Render.php` | Park + fake mundane ID formulas; `has_heraldry` / `has_image` flags |
| `Validate.php` | `BETWEEN 9001 AND 9005` → `100001 AND 100005` |
| `02-data-model.md`, `04-safety-validations.md` | Canary kingdom table |
| Tests + golden hash | All fixtures referencing old IDs |

---

## 4. Heraldry design (TD-11b)

### Format

- **Source:** SVG (diffable, parameterized, committed)
- **Output:** PNG 256×256 or 512×512, transparent background trimmed
- **Shape:** Heater shield (kingdom and park); same silhouette for consistency

### Kingdom shields (5 unique)

| ID | Kingdom | Design |
|----|---------|--------|
| `100001` | Empire of Ashkara | Or eagle on gules |
| `100002` | Kingdom of Meridia | Azure lion rampant |
| `100003` | Sultanate of Zanzibarr | Vert crescent and star |
| `100004` | Tsardom of Vyatka | Sable bear, or border |
| `100005` | Grand Duchy of Litavia | Gules cross on argent |

### Park shields (~10–30 per seed)

- Same heater outline as kingdom
- Inherit kingdom tinctures + simplified charge or park initial
- Defined in manifest keyed by `park_id` (stable across renders — not re-randomized per `anchor_date`)

### Player placeholders

- Single **phoenix on shield** master SVG
- Deployed to every fake player ID as:
  - `assets/heraldry/player/{mundane_id}.png` → `has_heraldry=1`
  - `assets/players/{mundane_id}.png` → `has_image=1` (square crop for avatars)

**Real players** (`megiddo`, Ken, Avery): open decision — phoenix placeholders for sandbox consistency, or keep extract flags and accept missing files until real art is supplied. Default recommendation: **phoenix placeholders in sandbox** so profile/roster pages never show broken images.

---

## 5. Asset generation (TD-11b)

### Repo layout (planned)

```
tools/ork-db/
  templates/heraldry/
    shield.svg                 # shared heater outline
    kingdoms.json5             # tinctures + charge per kingdom id
    park-rules.json5             # inherit kingdom + badge/initial rules
    player-phoenix.svg           # shared placeholder
  generated-assets/              # committed PNG output (v1)
    kingdom/
    park/
    player/
    players/                     # portrait copies for has_image
  manifests/
    asset-manifest.json5         # sha256 per file; used by validate
  GenerateAssets.php             # renderer
```

### CLI

```bash
bin/ork-db generate-assets     # SVG → PNG into generated-assets/
bin/ork-db deploy-assets       # copy generated-assets/* → assets/ (local tier only)
```

**Converter options** (pick one for dev dependency docs):

| Tool | Notes |
|------|-------|
| Inkscape CLI | Best fidelity |
| `rsvg-convert` | Lightweight |
| PHP Imagick | If ext-imagick available |

### Render DB flags (TD-11b)

After ID migration, `Render.php` emits:

- `ork_kingdom.has_heraldry = 1` for all five test kingdoms
- `ork_park.has_heraldry = 1` for all generated parks
- `ork_mundane.has_heraldry = 1`, `has_image = 1` for all fake players
- Real players: per open decision (§4)

---

## 6. Asset deploy pipeline (TD-11c)

### Strategy (recommended v1)

**Commit generated PNGs** under `tools/ork-db/generated-assets/` plus SVG sources. Smallest friction for `deploy-sandbox` sign-off; no Inkscape required on every clone if PNGs are present.

Regenerate when templates change: `bin/ork-db generate-assets`.

### Deploy step

```
bin/ork-db deploy-assets
  → tier check (local only — refuse on production)
  → copy generated-assets/kingdom/*  → assets/heraldry/kingdom/
  → copy generated-assets/park/*     → assets/heraldry/park/
  → copy generated-assets/player/*   → assets/heraldry/player/
  → copy generated-assets/players/*  → assets/players/
  → optional: verify against asset-manifest.json5
```

### Integration with daily workflow

Extend `deploy-sandbox` final steps:

```
… existing pipeline …
  → deploy-assets
  → status (report asset count / manifest ok)
```

`bootstrap` and `apply` remain DB-only; **`deploy-assets` is the filesystem companion**.

### Safety

- **Local tier only** — same refusal rules as `apply`
- Writes only under `assets/heraldry/` and `assets/players/` — never mutates mirror or sandbox DB
- Does not overwrite prod heraldry on production hosts (command refused before copy)

---

## 7. Validation (TD-11c)

Post-apply checks (extend `Validate.php`):

| Check | Pass condition |
|-------|----------------|
| Kingdom heraldry files | For each `ork_kingdom` row `100001`–`100005` with `has_heraldry=1`, file exists |
| Park heraldry files | For each test park row with `has_heraldry=1`, file exists |
| Fake player files | For fake mundanes `≥ 1000000` with flags set, heraldry + portrait files exist |
| Asset manifest *(optional)* | SHA256 matches `asset-manifest.json5` |

Failure remediation hint: `bin/ork-db generate-assets && bin/ork-db deploy-assets`

---

## 8. Testing (TD-11d)

| Test | Scope |
|------|-------|
| Unit — ID formulas | Seed 42 → expected kingdom/park/mundane IDs |
| Unit — `generate-assets` | Output filenames match new ID scheme |
| Unit — `deploy-assets` | Refused on production tier; copies to temp dir in test |
| Golden render | Update `golden-sandbox.sha256` after ID migration |
| Integration | After `deploy-sandbox`, kingdom/park pages show non-default heraldry |
| Integration / e2e | Player roster avatar shows phoenix, not letter fallback |

---

## 9. Sub-milestones

| ID | Title | Deliverable |
|----|-------|-------------|
| **TD-11a** | ID namespace | `100001` kingdoms, `1M` parks/players; validate + tests + docs |
| **TD-11b** | Generate heraldry | SVG templates, `generate-assets`, render flags, committed PNGs |
| **TD-11c** | Deploy assets | `deploy-assets`, `deploy-sandbox` hook, validate file checks |
| **TD-11d** | Visual sign-off | Manual or Playwright smoke on kingdom/park/player pages |

**Branch:** `megiddo/td-11` · **Commit prefix:** `TD-11: …` or `TD-11a: …`

---

## 10. Open decisions (maintainer)

| # | Question | Recommendation |
|---|----------|----------------|
| 1 | Kingdom IDs start at `100001` or `100000`? | **`100001`–`100005`** (`100000` reserved unused) |
| 2 | Park formula | **`1_000_000 + (ordinal × 100) + seq`** — 99 parks per kingdom max |
| 3 | Real player art in sandbox | **Phoenix placeholders** for consistent UI |
| 4 | Commit PNGs vs generate-on-apply | **Commit PNGs** for v1 |
| 5 | Migrate events to `2_000_000` block? | **Defer** unless heraldry added for events |

---

## 11. Acceptance (TD-11 complete)

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox
open http://localhost:19080/orkui/
# Kingdom pages: distinct shield heraldry (not 0000.jpg)
# Park pages: distinct park shields
# Player rosters: phoenix avatars for fake players
php vendor/bin/phpunit -c phpunit.ork-db.xml.dist
```
