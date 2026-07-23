# Mutation matrix — what a post-refactor rebase can break

Use during **RB-0** (inventory) and **RB-H** / **RB-N** / **RB-F** (repair). Status: **ok** · **stale** · **broken** · **new-violation**.

Which milestone owns the repair:

| Artifact | Primary RB-* |
|----------|----------------|
| Rebase / spirit-preserving conflicts | RB-1 |
| Global PHPUnit / sandbox | RB-2 |
| Overlap hotspot tests, Infection, thin-layer verify | RB-H |
| New upstream frontend `$DB` / `Ork3::$Lib` / domain logic | RB-N |
| Fuzzy baselines / setpoint | RB-F |
| Checklist “Last rebase” + handoff to P3-4 | RB-Z |

## Artifact × failure mode

| Artifact | Typical upstream change | Symptom | Repair |
|----------|-------------------------|---------|--------|
| **Overlap controllers/models** | Feature edits in files Megiddo thinned | Conflict / reintroduced `$DB` / lib bypass | Spirit merge (playbook); finish in RB-H if tests lag |
| **New `orkui/` modules** | Entire new feature (e.g. QualTest) | `$DB` / business logic in frontend | **RB-N** migrate to lib/service; thin frontend |
| **Templates / revised JS** | Large UX diffs on shared pages | Fuzzy fail; auth chrome drift | Merge UX; keep Megiddo flags; RB-F recapture |
| **db-migrations** | New tables/columns | deploy-sandbox / tests fail | Keep migrations; refresh sandbox; fix fixtures |
| **PHPUnit** | Signature, schema, seed | Failures / errors | Fix tests/fixtures; full suite green |
| **Playwright e2e** | DOM/copy/auth flow | Spec failures / skips | Fix selectors/flows; E2E preflight first |
| **tools/infection/*.json5** | File move / new classes | Low MSI or path miss | Update `source.directories`; re-run gate |
| **Fuzzy baselines** | CSS/JS/DOM/layout or seed data | `validate` fail | Re-`record` / `setpoint capture` + publish |
| **Static success gates** | Upstream frontend SQL/lib | `rg` matches in `orkui/` | Must be cleared in RB-N (or waived) |
| **Active docs** | Path / status drift | Stale README / remaining checklist | RB-Z updates Last rebase + remaining work |

## RB-0 overlap inventory (required)

Produce a short table on the checklist:

| Path | Megiddo changed? | Upstream changed? | Class |
|------|------------------|-------------------|-------|
| … | yes/no | yes/no | overlap / megiddo-only / upstream-new |

**overlap** → RB-1 careful merge + RB-H follow-up  
**upstream-new** → take upstream in RB-1 + RB-N spirit scan  
**megiddo-only** → usually clean replay

## RB-H hotspot checklist

For each overlap path from RB-0:

- [ ] Controller/model still has no `$DB->` / `Ork3::$Lib`
- [ ] Upstream behavior present (manual diff vs `origin/master` file)
- [ ] Domain unit/integration tests green (or gap noted)
- [ ] Relevant `tools/infection/infection.t*.json5` gate green (or gap noted)

## RB-N new-code spirit checklist

For each upstream-new (and heavily rewritten) `orkui/` area:

- [ ] Inventory `$DB`, raw SQL, yapo-in-frontend, `Ork3::$Lib`, authorization INSERTs
- [ ] Domain rules not left only in controllers/templates
- [ ] Logic moved to `system/lib/ork3/` (+ `orkservice` as needed)
- [ ] Frontend thinned to service/`Model_*` calls (idiomatic siblings)
- [ ] Characterization tests added or extended for moved behavior
- [ ] Repo-wide: `rg '\$DB->' orkui/` and `rg 'Ork3::\$Lib' orkui/` clean

Prefer migrating clear violations in RB-N over documenting permanent debt. If a module is too large for one hop, stop with `status=blocked` and propose `RB-N2` splits — do not silently leave gates red.

## Test / Infection / fuzzy quick commands

```bash
# PHPUnit
sh bin/run-unit-tests.sh

# Infection example (configs under tools/)
sh bin/run-infection.sh --configuration=tools/infection/infection.t01-rsvp.json5

# Static spirit gates
rg '\$DB->' orkui/ || true
rg 'Ork3::\$Lib' orkui/ || true

# Fuzzy
bin/ork-db deploy-sandbox
bin/fuzzy-validator validate --all --phase all

# Overlap / new-file hunt after fetch
git diff --name-only $(git merge-base HEAD origin/master)..origin/master -- orkui/ system/ db-migrations/
```
