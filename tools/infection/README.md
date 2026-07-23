# Infection configs

Mutation-testing configuration for ORK3 (Infection PHP). Kept out of the repo root intentionally.

| File | Purpose |
|------|---------|
| `infection.json5` | Default full scope (`system/lib/ork3/` + `orkservice/`) |
| `infection.tNN-*.json5` | Milestone-scoped MSI gates (T-*/R-* sprints) |

## Usage

From repo root:

```bash
# Default full-scope config
bin/run-infection.sh

# Milestone config (short name or full path both work)
bin/run-infection.sh --configuration=infection.t01-rsvp.json5
bin/run-infection.sh --configuration=tools/infection/infection.t01-rsvp.json5 \
  --only-covered --filter=class.Event.php
```

Paths inside these configs (`bootstrap`, `phpUnit.configDir`, `logs`, `tmpDir`) are relative to the **repo root** (where `bin/run-infection.sh` runs), not this directory.

See [06-test-framework.md](../../docs/megiddo/refactor/06-test-framework.md).
