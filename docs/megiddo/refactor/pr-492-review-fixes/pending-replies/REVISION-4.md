## Revision 4

Addresses **Batch 5** adversarial review from @baltinerdist at `20b0f61f` (12 inline findings + inventory ask). Work landed on `fix-pr-492` (to mirror onto `megiddo/fuzzy-validator-v2`).

### Fixes (C-19…C-30)

| ID | Topic | Commit |
|----|-------|--------|
| C-19 | RemoveRsvp client trust flag | `71ddcd29` |
| C-20 | GetRsvpList Token + manage/attendance | `53fffd48` |
| C-21 | Player display/grant getters Token | `8574690a` |
| C-22 | New Report getters Token | `96445e11` |
| C-23 | GetServerHealthWeatherSummary admin gate | `a3aec596` |
| C-24 | GetServerHealthDbStatus Wanted whitelist | `9a6b9b78` |
| C-25 | AddAttendance reactivation flag + audit | `2654ca7b` |
| C-26 | QualTest getreports reporters | `56a3220a` |
| C-27 | Park ladder-grid master parity | `6834b1cd` |
| C-28 | ScopedPlayerSearch KD: sort | `ea4a6608` |
| C-29 | Calendar coords either-zero sentinel | `ae4f0864` |
| C-30 | Ladder Awards before custom options | `6d06e4c0` |

### Inventory (C-31)

Analysis-only doc: `docs/megiddo/refactor/pr-492-review-fixes/auth-inventory-rev4.md` (`d9a17b36`). Named Batch 5 holes closed; remaining branch-introduced RSVP count/helper reads listed as follow-ups; no mass-gating of pre-existing master surfaces.

### Validation note

Full PHPUnit was **not** executed in the Rev4 agent environment (no local `php` / `vendor/bin/phpunit` / Docker DB). Characterization tests were added/updated per item; please run `sh bin/run-unit-tests.sh` before merge.

Per-thread replies drafted under `docs/megiddo/refactor/pr-492-review-fixes/pending-replies/` (C-19…C-31) for posting when `gh` auth is available.

Tip: `2c00ebcd`
