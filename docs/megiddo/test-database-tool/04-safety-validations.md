# Test Database Tool — Safety Validations

Fail-closed guards that prevent destructive operations against mirror, production, or misconfigured databases.

**Rule:** `bin/ork-db apply` and any wipe/reload **must abort** unless every check in §2 passes. `bin/ork-db validate` runs the same checks without mutating data.

**No database arguments:** `extract`, `render`, and `apply` do not accept a target name. Mirror and sandbox endpoints are hardcoded in `manifests/wiring.json5`. This repo ships to production — a mistyped CLI argument must not be able to aim `apply` at the real database.

---

## 1. Threat model

| Risk | Mitigation |
|------|------------|
| Operator runs `apply` on production server | **Deployment tier detection** — data commands refused on production hosts (§1.1) |
| Operator typos a database name on CLI | **No database CLI args** on extract/render/apply |
| Apply script run against mirror on 19306 | Port lock + DB name + canary checks (hardcoded sandbox only) |
| Sandbox container pointed at prod volume | Separate volume `data-test-db` |
| Prod dump imported into sandbox without canary | Kingdom fingerprint checks block apply |
| Prod canary dropped by this tool | Prod canary table is never touched |
| Host port 3306 (default MySQL) | Hardcoded reject |

### 1.1 Deployment tier guard (first check)

Before connect or mutate, `DeploymentTier::classify()`:

| Tier | Signals | `extract` / `render` / `apply` / `init` |
|------|---------|-------------------------------------------|
| **local** | `127.0.0.1:19307` reachable; mirror `19306` reachable; `config.php` host is local/docker | Allowed (subject to §2) |
| **production** | Production DB host in `config.php`; or sandbox port unreachable; or `APP_STAGE` not local | **Refused immediately** |

`status` always allowed (read-only). `use dev` refused on production (no sandbox).

This is the primary defense for "tool exists on production box."

---

## 2. Pre-apply validation checklist

All checks must **pass**. Any failure → exit code `2` (validation error), no DDL/DML.

### 2.1 Port lock (hardcoded)

```python
ALLOWED_TEST_HOSTS = {"127.0.0.1", "localhost", "ork3-php8-test-db"}
ALLOWED_TEST_PORT = 19307
FORBIDDEN_PORTS = {3306, 19306}
```

| Check | Pass condition |
|-------|----------------|
| Target port | `port == 19307` |
| Not default | `port not in FORBIDDEN_PORTS` |
| Not 3306 on host | If host is `127.0.0.1` or `localhost`, port must not be 3306 |

Override: **none** in v1. No `--port` flag. Values come from `manifests/wiring.json5` only.

### 2.2 Database name lock

| Check | Pass condition |
|-------|----------------|
| Database name | `database == 'ork_test'` |

Never apply to database `ork`.

### 2.3 Canary table gate (both conditions required)

Two sentinel tables — **mutually exclusive** across environments:

| Table | Database | Sentinel row |
|-------|----------|--------------|
| `_ork_canary_prod` | Dev / prod-like `ork` | `(id=1, marker='ORK3_PROD_CANARY_v1', created_at=…)` |
| `_ork_canary_test` | Test `ork_test` | `(id=1, marker='ORK3_TEST_CANARY_v1', created_at=…)` |

**Apply/wipe allowed only when BOTH are true:**

| # | Condition | Rationale |
|---|-----------|-----------|
| A | `_ork_canary_prod` **does not exist** OR has **zero rows** on target connection | Target is not prod/dev |
| B | `_ork_canary_test` **exists** AND has **exactly one row** with `marker='ORK3_TEST_CANARY_v1'` | Target is confirmed test DB |

```
IF prod_canary_present(target):
    ABORT "Production canary detected — refusing to wipe"

IF NOT test_canary_present(target):
    ABORT "Test canary missing — database may be uninitialized or wrong target"
```

**First-time bootstrap:** Empty `ork_test` on fresh container fails check B. Use `bin/ork-db init` which:

1. Runs port + DB name checks only (relaxed canary B — allows missing test canary)
2. Applies schema + canary table + sentinel row
3. Does **not** load full fake data until second `apply` after canary exists

Alternatively, full `apply` on empty DB inserts canary as final step — but pre-apply validate uses **relaxed mode** only for `init`, not `apply --force`.

### 2.4 Kingdom fingerprint

Query:

```sql
SELECT kingdom_id, name, abbreviation
FROM ork_kingdom
WHERE kingdom_id BETWEEN 100001 AND 100005
ORDER BY kingdom_id;
```

| Check | Pass condition |
|-------|----------------|
| Row count | Exactly **5** |
| Full names | Match `manifests/fingerprints.json5` exactly |
| Principality | Exactly one row with `parent_kingdom_id > 0` (100005 → 100001) |
| Abbreviations | Match manifest exactly |

Expected:

```json5
{
  "kingdoms": [
    { "id": 100001, "name": "Empire of Ashkara", "abbreviation": "EAK", "parent_kingdom_id": 0 },
    { "id": 100002, "name": "Kingdom of Meridia", "abbreviation": "KMR", "parent_kingdom_id": 0 },
    { "id": 100003, "name": "Sultanate of Zanzibarr", "abbreviation": "SZ", "parent_kingdom_id": 0 },
    { "id": 100004, "name": "Tsardom of Vyatka", "abbreviation": "TVK", "parent_kingdom_id": 0 },
    { "id": 100005, "name": "Grand Duchy of Litavia", "abbreviation": "GDL", "parent_kingdom_id": 100001 }
  ]
}
```

**Pre-apply on empty DB:** Kingdom check skipped in `init` mode only.

**Post-apply:** Kingdom check required — confirms data loaded correctly.

### 2.5 Park count fingerprint

For render `seed=42`, expected total park count is precomputed (stored in `fingerprints.json5`):

```json5
{
  "park_count_by_seed": {
    "42": 20
  },
  "parks_per_kingdom_range": [2, 6]
}
```

| Check | Pass condition |
|-------|----------------|
| Total parks | `COUNT(*) FROM ork_park WHERE kingdom_id BETWEEN 100001 AND 100005` equals expected for seed |
| Per-kingdom | Each kingdom has 2–6 parks |

If render seed changes, `fingerprints.json5` must be updated (or tool recomputes expected count deterministically from seed without storing).

### 2.6 Negative indicators (must NOT match)

| Check | Fail if |
|-------|---------|
| Real kingdom names | Any of `Northern Lights`, `Dragonspine`, `Wetlands`, … in `ork_kingdom` (maintain blocklist in manifest) |
| Real kingdom ID range | Any `kingdom_id < 8000` with `name NOT IN (canary list)` |
| Prod database on test port | `SELECT COUNT(*) FROM ork_kingdom` &gt; 50 (heuristic — tune with blocklist) |

---

## 3. Dev/prod canary installation

**One-time on dev `ork`:**

```sql
CREATE TABLE IF NOT EXISTS _ork_canary_prod (
  id INT PRIMARY KEY,
  marker VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL
);
INSERT INTO _ork_canary_prod (id, marker, created_at)
VALUES (1, 'ORK3_PROD_CANARY_v1', NOW())
ON DUPLICATE KEY UPDATE marker = VALUES(marker);
```

Delivered via manual migration `db-migrations/YYYY-MM-DD-add-prod-canary.sql` (dev only — **never** applied to test by the test tool).

Test canary is created by apply script section 18 — not manually.

---

## 4. Validation CLI output

```bash
$ bin/ork-db validate
Target:       127.0.0.1:19307/ork_test
Port lock:    PASS (19307)
DB name:      PASS (ork_test)
Prod canary:  PASS (absent)
Test canary:  PASS (ORK3_TEST_CANARY_v1)
Kingdoms:     PASS (5/5 Empire of Ashkara … Grand Duchy of Litavia)
Parks:        PASS (23/23 for seed=42)
Blocklist:    PASS (no real kingdom names)
─────────────────────────────────
RESULT:       SAFE TO APPLY
```

Failure example:

```bash
Prod canary:  FAIL (_ork_canary_prod row present)
RESULT:       ABORT — refusing wipe/replay
Exit code:    2
```

---

## 5. Wipe/replay semantics

`bin/ork-db apply`:

1. `validate` (strict — all checks including test canary if DB already initialized)
2. `render` (unless `--sql path` provided)
3. `docker exec -i ork3-php8-test-db mariadb -u root -proot < rendered.sql`
4. `validate` (post-apply — kingdom + park fingerprints)

**Drop strategy:** Rendered SQL includes `DROP DATABASE IF EXISTS ork_test; CREATE DATABASE ork_test;` — full wipe. No incremental delete.

`--force` skips interactive confirmation prompt only — **does not skip safety checks**.

---

## 6. What this tool never does

- Connect to `mysql.amtgard.com` or any remote host
- Run against port `3306` or `19306`
- Drop database `ork`
- Drop or modify `_ork_canary_prod`
- Auto-install prod canary (documented manual step for dev admins)
