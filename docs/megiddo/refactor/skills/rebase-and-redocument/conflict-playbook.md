# Conflict playbook — Post-refactor Megiddo rebase onto master

## Strategy

Default: **`git rebase origin/master`** on `megiddo/rebase-{date}` during **RB-1**.

This is **not** a pre-R-* rebase. Megiddo already owns thinned `orkui/` and domain services. Blind “take upstream” on shared files undoes the refactor.

## Conflict ownership

| When | Milestone |
|------|-----------|
| During `git rebase` | **RB-1** — finish rebase with spirit-preserving merges; defer test fixes to RB-2 |
| After rebase, suite red | **RB-2** — not a reason to abort RB-1 |
| Overlap file test / Infection / thin-layer repair | **RB-H** |
| New upstream modules with frontend DB/business logic | **RB-N** (may start notes during RB-1; migration work in RB-N) |

Stop and ask the user if:

- Two shipped product behaviors disagree (not just “where the SQL lives”)
- Binary/fuzzy baseline conflicts are unmanageable (prefer delete baselines + restore/recapture in RB-F)
- More than ~one hour of conflict soup with no clear merge rule
- A single conflicted file needs a full domain redesign to keep both behaviors

## Path rules (post-R-*)

| Path | During conflict |
|------|-----------------|
| **New upstream-only** `orkui/**` / `system/**` files (no Megiddo edits) | Take **upstream**. Schedule for **RB-N** spirit scan / migration. |
| **Overlap** `orkui/controller/**`, `orkui/model/**` | **Never** take upstream wholesale. Keep Megiddo thin controller/`Model_*` call shape. Port upstream behavioral deltas into `system/lib/ork3/` (+ `orkservice` if needed), then call from the thin frontend. |
| **Overlap** `orkui/template/**` | Manual merge. Keep Megiddo auth-flag / presentation patterns from idiom work. Add upstream UI features (new sections, scripts, copy). Do not reintroduce PHP `$DB` / domain rules into templates. |
| `system/lib/ork3/**` (existing Megiddo domain classes) | Prefer **Megiddo structure**. Add upstream methods/SQL into the domain class (or a new lib class for a new module). Do not push domain SQL back into `orkui/`. |
| `orkservice/**` | Prefer Megiddo service surface; extend registrations/definitions for new APIs needed by thinned controllers. |
| `tests/**` | Prefer **Megiddo** tests; fix against upstream APIs; add coverage for upstream behavior you keep. |
| `tools/infection/**`, `phpunit*.xml*`, `bin/run-*.sh` | Prefer Megiddo; re-check paths after. |
| `tools/ork-db/**`, `tools/fuzzy-validator/**` | Prefer Megiddo tooling; manually merge if upstream touched same files. |
| `docs/megiddo/refactor/**` | Prefer Megiddo active docs; do not resurrect archived operational dumps into the active tree. |
| `db-migrations/**` | Take **both** — never drop upstream migrations. |
| `composer.lock` / `package-lock.json` | Prefer regenerate: take one side, then `composer install` / `npm install` and commit lockfiles when needed. |

During a rebase, “ours” = upstream branch being replayed onto; “theirs” = the commit being applied. When unsure, open both stages:

```bash
git show :2:path/to/file > /tmp/ours
git show :3:path/to/file > /tmp/theirs
diff -u /tmp/ours /tmp/theirs | less
```

### Overlap merge recipe (controllers)

1. Diff both sides; list **upstream behavioral deltas** (new endpoints, new queries, new rules).
2. Keep Megiddo’s thin method stubs / `load_model` / service calls.
3. Implement missing behavior on the appropriate `system/lib/ork3/class.*.php` (new class OK for a new module).
4. Expose via existing `Model_*` or `orkservice` patterns used by siblings.
5. Leave a short conflict note on the RB-1 checklist: file → where logic landed.

### Overlap merge recipe (templates / JS)

1. Accept upstream markup/UX when it does not encode domain SQL.
2. Preserve Megiddo-precomputed auth flags and idiom-aligned helpers.
3. If upstream template embeds business rules or queries, move that logic to lib/service and keep the template presentational.

## Fuzzy / binary baselines

Do **not** hand-merge PNGs or large baseline JSON.

```bash
# Accept neither; clear and restore after rebase
git checkout --theirs -- tools/fuzzy-validator/baselines/ 2>/dev/null || true
rm -rf tools/fuzzy-validator/baselines/test tools/fuzzy-validator/baselines/mirror
# After rebase finishes:
bin/fuzzy-validator setpoint restore --bundle <known-good.zip>
# Then validate; re-record in RB-F if needed
```

If `setpoint.json` conflicts: keep Megiddo structure; set `latestBundle` after a fresh capture in RB-F.

## db-migrations

If both sides add files: keep all. If same filename conflicts: merge carefully; schema must remain applicable on mirror + sandbox (`bin/ork-db drift-check --strict` after rebase / during RB-2).

## Abort / retry

```bash
git rebase --abort          # back to pre-rebase tip
# optional: rebase a smaller range, or ask user about merge
```

## After last conflict

```bash
git rebase --continue
# repeat until clean
git status
# Optional early smoke — full green is RB-2’s job:
sh bin/run-unit-tests.sh
```
