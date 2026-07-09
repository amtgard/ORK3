# Conflict playbook — Megiddo rebase onto master

## Strategy

Default: **`git rebase origin/master`** on `megiddo/rebase-{date}` during **RB-1**.

## Conflict ownership

| When | Milestone |
|------|-----------|
| During `git rebase` | **RB-1** — finish rebase; defer test fixes to RB-2 |
| After rebase, suite red | **RB-2** — not a reason to abort RB-1 |
| Domain line docs only | **RB-D\*** — do not reopen RB-1 |

Stop and ask the user if:

- Conflicts span both a Megiddo R-* code change and upstream behavior change (rare pre-R-*)
- Binary/fuzzy baseline conflicts are unmanageable (prefer delete baselines + restore/recapture)
- More than ~one hour of conflict soup with no clear “ours/theirs” rule

## Path rules

| Path | During conflict |
|------|-----------------|
| `orkui/**`, `system/**`, `orkservice/**` | Take **upstream** (`--theirs` during rebase) unless Megiddo commit clearly owns an R-* migration already merged |
| `tests/**` | Take **Megiddo** tests; then fix compile errors against upstream APIs |
| `infection*.json5`, `phpunit*.xml*`, `bin/run-*.sh` | Prefer Megiddo; re-check paths after |
| `tools/ork-db/**`, `tools/fuzzy-validator/**` | Prefer Megiddo tooling; manually merge if upstream touched same files |
| `docs/megiddo/refactor/ds-*.md`, `validations/**`, `03-implementation-plan.md` | Prefer Megiddo; refresh lines in redocument phase |
| `docs/megiddo/**/archive/**` | Prefer Megiddo archive; fix links only |
| `db-migrations/**` | Take **both** — never drop upstream migrations |
| `composer.lock` / `package-lock.json` | Prefer regenerate: take one side, then `composer install` / `npm install` and commit lockfiles in redocument commit if needed |

During a rebase, “ours” = upstream branch being replayed onto; “theirs” = the commit being applied. When unsure, open both stages:

```bash
git show :2:path/to/file > /tmp/ours
git show :3:path/to/file > /tmp/theirs
diff -u /tmp/ours /tmp/theirs | less
```

## Fuzzy / binary baselines

Do **not** hand-merge PNGs or large baseline JSON.

```bash
# Accept neither; clear and restore after rebase
git checkout --theirs -- tools/fuzzy-validator/baselines/ 2>/dev/null || true
rm -rf tools/fuzzy-validator/baselines/test tools/fuzzy-validator/baselines/mirror
# After rebase finishes:
bin/fuzzy-validator setpoint restore --bundle <known-good.zip>
# Then validate; re-record if needed
```

If `setpoint.json` conflicts: keep Megiddo structure; set `latestBundle` after a fresh capture.

## db-migrations

If both sides add files: keep all. If same filename conflicts: merge carefully; schema must remain applicable on mirror + sandbox (`bin/ork-db drift-check --strict` after rebase).

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
sh bin/run-unit-tests.sh    # early smoke before full redocument
```
