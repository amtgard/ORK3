# R-* Worker Prompts

One file per milestone. The **orchestrator** reads one file per Task hop; paste a single worker file for manual/debug runs.

**Shared steps:** [_shared-procedure.md](_shared-procedure.md)

| Milestone | File | Branch |
|-----------|------|--------|
| R-01 | [R-01.md](R-01.md) | `megiddo/r-01-rsvp-refactor` |
| R-02 | [R-02.md](R-02.md) | `megiddo/r-02-auth-insert-refactor` |
| R-03 | [R-03.md](R-03.md) | `megiddo/r-03-banner-refactor` |
| R-04 | [R-04.md](R-04.md) | `megiddo/r-04-eventajax-refactor` |
| R-05 | [R-05.md](R-05.md) | `megiddo/r-05-event-refactor` |
| R-06 | [R-06.md](R-06.md) | `megiddo/r-06-kingdom-refactor` |
| R-07 | [R-07.md](R-07.md) | `megiddo/r-07-park-refactor` |
| R-08 | [R-08.md](R-08.md) | `megiddo/r-08-admin-refactor` |
| R-09 | [R-09.md](R-09.md) | `megiddo/r-09-player-refactor` |
| R-10 | [R-10.md](R-10.md) | `megiddo/r-10-reports-refactor` |
| R-11 | [R-11.md](R-11.md) | `megiddo/r-11-search-refactor` |
| R-12 | [R-12.md](R-12.md) | `megiddo/r-12-attendance-refactor` |
| R-13 | [R-13.md](R-13.md) | `megiddo/r-13-infrastructure-refactor` |
| R-14 | [R-14.md](R-14.md) | `megiddo/r-14-lib-service-refactor` |
| R-15 | [R-15.md](R-15.md) | `megiddo/r-15-hasauthority-refactor` |
| R-16 | [R-16.md](R-16.md) | `megiddo/r-16-ghettocache-refactor` |
| R-17 | [R-17.md](R-17.md) | `megiddo/r-17-lib-bypass-refactor` |
| R-18 | [R-18.md](R-18.md) | `megiddo/r-18-residual-db-refactor` |

**Continuation scope:** [10-phase-2-continuation.md](../../10-phase-2-continuation.md) · **Phase 3** = audit after R-18 (no implementation).

**Manual single milestone:** open `R-{nn}.md`, copy the fenced prompt inside, paste into a new agent chat.

**Orchestrated run:** open [../agent-prompt.md](../agent-prompt.md), Ctrl+A, Ctrl+C, paste — orchestrator starts at first unchecked R-* on the checklist (currently **R-15**).
