# Mutation matrix — what rebase can break

Use during **RB-0** (inventory) and **RB-D\*** / **RB-F** (repair). Status: **ok** · **stale** · **broken**.

Which milestone owns the repair:

| Artifact | Primary RB-* |
|----------|----------------|
| Rebase / conflicts | RB-1 |
| Global PHPUnit / sandbox | RB-2 |
| ds-* §1/§3, plan lines, v-* paths, domain tests, Infection | RB-D1…D4 (or RB-D-{nn}) |
| Fuzzy baselines / setpoint | RB-F |
| Checklist “Last rebase” + links | RB-Z |

## Artifact × failure mode

| Artifact | Typical upstream change | Symptom | Repair |
|----------|-------------------------|---------|--------|
| **ds-* §1 line tables** | Edits in `orkui/` controllers/models | Wrong line ranges / missing methods | Re-read file; update lines + behavior notes; add post-rebase note |
| **03-implementation-plan.md** | Same | Target ID lines wrong | Sync lines with ds-* / current code |
| **ds-* §3 proposed revision** | Upstream already added service/API | Design assumes gap that closed | Amend §3; keep target IDs unless feature gone |
| **validations/v-* §1** | Route/query/template change | Wrong canary URL or skip reason | Fix `pages.json5` + validation doc |
| **validations/v-* §2** | Test file rename/move | Broken test paths | Update paths; keep mutation boundaries intent |
| **PHPUnit unit/integration** | Signature, schema, sandbox seed | Failures / errors | Fix tests/fixtures; full suite green |
| **Playwright e2e** | DOM/copy/auth flow | Spec failures / skips | Fix selectors/flows; E2E preflight first |
| **infection.t*.json5** | File move / new excludes needed | Low MSI or path miss | Update `source.directories`; re-run gate |
| **Fuzzy baselines** | CSS/JS/DOM/layout or seed data | `validate` fail | Re-`record` / `setpoint capture` + publish |
| **setpoint.json** | New capture | Stale `latestBundle` | `setpoint publish`; update v-00 / domain capture notes |
| **ork-db templates/fingerprints** | Schema migrations on master | deploy-sandbox / tests fail | Classify new migrations; refresh sandbox |

## Domain sweep order (RB-D\* batches)

| Batch | Domains | Prefer before |
|-------|---------|---------------|
| RB-D1 | 01–04 RSVP, auth, banner, EventAjax | R-01 start |
| RB-D2 | 05–08 event, kingdom, park, admin | |
| RB-D3 | 09–12 player, reports, search, attendance | |
| RB-D4 | 13–14 infrastructure, lib-service | |

After domain batches, **RB-F** runs global fuzzy (or V-00 page set). **RB-Z** re-checks full PHPUnit.

## Discovery doc checklist (per ds-*)

- [ ] §1 class/method/line tables match current sources  
- [ ] Target IDs still exist (not deleted upstream)  
- [ ] Schema / migration cites still valid  
- [ ] §2 test paths exist under `tests/`  
- [ ] §2.3 Infection paths/filters still valid  
- [ ] §3 revision still accurate (gap vs already-fixed)  
- [ ] Footer links to `validations/v-{nn}-*.md` work  

## Implementation plan checklist

- [ ] Every in-scope target ID line range updated or marked removed  
- [ ] Descriptions still match behavior (not just lines)  

## Test checklist

- [ ] `sh bin/run-unit-tests.sh` exit 0  
- [ ] In-scope `tests/e2e/*.spec.ts` pass (or documented skip with preflight reason)  
- [ ] No deleted characterization coverage without sign-off report entry  

## Infection checklist

- [ ] Config files resolve source paths  
- [ ] Milestone Infection meets documented MSI floor  
- [ ] Archive/checklist Infection one-liners updated if filters changed  

## Fuzzy checklist

- [ ] `pages.json5` ids in validation docs still registered  
- [ ] `validate --all --phase all` (or agreed page set) pass **test** + **mirror**  
- [ ] `setpoint.json` `latestBundle` matches published zip if capture ran  
- [ ] v-00 / v-{nn} capture notes dated post-rebase  

## Quick commands

```bash
# PHPUnit
sh bin/run-unit-tests.sh

# Infection example (RSVP)
sh bin/run-infection.sh --configuration=tools/infection/infection.t01-rsvp.json5

# Fuzzy
bin/ork-db deploy-sandbox
bin/fuzzy-validator validate --all --phase all

# Line-range hunt (example)
rg -n "INSERT INTO.*ork_authorization|function addauth" orkui/
```
