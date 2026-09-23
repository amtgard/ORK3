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

## Daily dev entry (target — TD-10)

One command after Docker is up:

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox
```

This automatically:

- Detects first-time vs first-run vs current sandbox state
- Runs `init` and/or `bootstrap` as needed
- **Validates and halts** with remediation steps if anything is wrong
- Switches the app to sandbox (`use dev`)
- Runs `extract` → `render` → `apply` if the last render was before today

See [07-implementation-plan.md](./07-implementation-plan.md) §9 and [10-cli-reference.md](./10-cli-reference.md) § deploy-sandbox.

---

## Command palette

```bash
bin/ork-db deploy-sandbox   # daily dev entry (TD-10 — planned)
bin/ork-db use prod         # website → mirror (19306)
bin/ork-db use dev          # website → sandbox (19307)

bin/ork-db extract          # read mirror (always 19306)
bin/ork-db render           # build sandbox SQL file (no DB)
bin/ork-db apply            # wipe + reload sandbox (always 19307)
bin/ork-db bootstrap        # sandbox-only init + extract + apply

bin/ork-db status           # tier, wiring, canaries
bin/ork-db validate         # safety checks on sandbox
```

**On production servers:** data commands refuse. See [10-cli-reference.md](./10-cli-reference.md) § deployment tier.

---

## Typical workflows (local workstation)

### New developer (until TD-10 ships)

```bash
docker compose -f docker-compose.php8.yml up -d

# One-time mirror setup (if ork @ 19306 is empty):
#   import dev dump, then apply db-migrations/2026-07-07-add-prod-canary.sql

bin/ork-db bootstrap --yes
bin/ork-db use dev
bin/ork-db validate --mode post-apply
```

### New developer (after TD-10)

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox
```

### Daily use (after TD-10)

```bash
bin/ork-db deploy-sandbox    # no-op data pipeline if already refreshed today
sh bin/run-unit-tests.sh

bin/ork-db use prod          # switch back to mirror when needed
```

### Manual reset (low-level)

```bash
bin/ork-db apply --yes
bin/ork-db use dev
```

---

## Safety

[04-safety-validations.md](./04-safety-validations.md) — tier guard, port lock, canaries, kingdom fingerprints. `apply` cannot target mirror or production regardless of what the operator types.

Post-implementation backlog: [11-post-implementation-tasks.md](./11-post-implementation-tasks.md).
