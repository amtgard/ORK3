# Test Database Tool — Tools and Operations

Operational guide. **Canonical CLI reference:** [10-cli-reference.md](./10-cli-reference.md).

---

## The two databases (plain names)

| Nickname | Port | DB name | What's in it |
|----------|------|---------|--------------|
| **Mirror** | 19306 | `ork` | Your imported local copy — real kingdoms, real players |
| **Sandbox** | 19307 | `ork_test` | Fake data — Empire of Ashkara, generated players |

The tool **hardcodes** these endpoints. You never type a database name on `extract`, `render`, or `apply`.

---

## Command palette

```bash
bin/ork-db use prod          # website → mirror (19306)
bin/ork-db use dev           # website → sandbox (19307)

bin/ork-db extract           # read mirror (always 19306)
bin/ork-db render            # build sandbox SQL file (no DB)
bin/ork-db apply             # wipe + reload sandbox (always 19307)

bin/ork-db status            # what's wired, what tier am I on
```

**On production servers:** `extract`, `render`, and `apply` all refuse. See [10-cli-reference.md](./10-cli-reference.md) § deployment tier.

---

## Typical workflows (local workstation)

### First-run bootstrap (new developer)

```bash
# Start all services — mirror (19306) + sandbox (19307) + app
docker compose -f docker-compose.php8.yml up -d

# One-time mirror setup (if ork @ 19306 is empty):
#   import dev dump, then apply db-migrations/2026-07-07-add-prod-canary.sql

# Sandbox bootstrap (TD-7)
bin/ork-db bootstrap --yes    # init + extract + apply (idempotent)

bin/ork-db validate --mode post-apply
```

`init` alone does **not** load fake data — it only prepares the sandbox schema. A full first run always ends with `extract` + `apply`.

### Daily use

```bash
# Daily test reset
bin/ork-db apply --yes
sh bin/run-unit-tests.sh

# Browse fake data in browser
bin/ork-db use dev
# … browse …
bin/ork-db use prod
```

---

## Safety

[04-safety-validations.md](./04-safety-validations.md) — tier guard, port lock, canaries, kingdom fingerprints. `apply` cannot target mirror or production regardless of what the operator types.
