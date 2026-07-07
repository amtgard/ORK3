# Test Database Tool — Agent Milestone Prompt

Copy the prompt below into a new agent session. Replace `{{MILESTONE}}` with a specific milestone (e.g. `TD-1`, `TD-5`).

---

## Prompt (copy from here)

```
You are working on the ORK3 Test Database Tool. The repo ships to PRODUCTION — safety is paramount.

## Critical safety rule

extract, render, and apply take NO database argument. Targets are hardcoded in manifests/wiring.json5.
Deployment tier detection refuses data commands on production hosts.

Commands:
  bin/ork-db use prod|dev     # only command with a target name (app container switching)
  bin/ork-db extract          # always reads mirror (19306/ork)
  bin/ork-db render           # no DB — builds sandbox SQL file
  bin/ork-db apply            # always writes sandbox (19307/ork_test)

## Documentation

docs/megiddo/test-database-tool/ — read 10-cli-reference.md and 04-safety-validations.md first.

## Milestone

{{MILESTONE}}

Branch: megiddo/td-N. Commit: TD-N: <description>.

## Do not

- Add --database, --profile, --port, or --host flags to extract/render/apply
- Allow apply on production tier or against mirror
- Skip deployment tier guard or canary validation
```

---

## Usage notes

- `use prod` = app points at mirror (19306). `use dev` = app points at sandbox (19307).
- On production: extract/render/apply/init all refuse; use dev refuses.
- Maintainer fills ken_walker / avery_krouse mundane_id before TD-3.
