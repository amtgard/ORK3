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

## Procedure:

1. Ensure prior working branch has all work staged and committed.
2. Create a relevant working branch name for this work.
3. Implement the milestone.
4. Implement unit tests to 90% coverage and reasonable integ tests to cover this work
5. Update the milestone checklist
6. Stage and commit all work for these milestone(s) to the local working branch

## Do not

- Add --database, --profile, --port, or --host flags to extract/render/apply
- Allow apply on production tier or against mirror
- Skip deployment tier guard or canary validation
```

---

## Usage notes

- `use prod` = app points at mirror (19306). `use dev` = app points at sandbox (19307).
- On production: extract/render/apply/init all refuse; use dev refuses.
