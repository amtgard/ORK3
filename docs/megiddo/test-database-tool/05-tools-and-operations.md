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

```bash
# Setup
docker compose -f docker-compose.php8.yml up -d
bin/ork-db extract
bin/ork-db apply

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
